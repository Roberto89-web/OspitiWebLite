<?php

namespace App;

use App\Services\AvailabilityService;


class IcsImportService {
    private $guestManager;
    private $config;

    private const FETCH_TIMEOUT = 15;
    private const FETCH_ATTEMPTS = 2;
    private const SUMMARY_KEYWORDS = ['reserved', 'confirmed', 'booked'];

    public function __construct() {
        $this->guestManager = new GuestManager();
        $this->config = Config::getInstance();
    }

    /**
     * Imports bookings from the Airbnb ICS URL configured in the system.
     * Fetch → parse → (overlap check) → transactional delete + insert;
     * on failure the DB is untouched.
     *
     * Sovrapposizione (Regola B — ultimo evento vince):
     * - Se ci sono coppie in collisione e `$force === false` → `status='overlap'`
     *   (nessuna scrittura) con `conflicts[]` per la riconferma.
     * - Se `$force === true` → transazione come prima, poi:
     *     1) `refreshImportAnomalies()` (può impostare 'I' per fuori-ICS-con-token)
     *     2) `UPDATE guests SET stato='O'` sulle righe in collisione (ultimo evento)
     *     3) `anomalies[]` con `reason='overlap'` per ogni coppia in conflitto.
     *
     * @param bool $force conferma la scrittura anche in presenza di collisioni
     * @return array status, guests_imported, skipped, conflicts?, anomalies, errors.
     */
    public function importBookings(bool $force = false): array {
        $url = $this->config->get('airbnb.ics_url');
        if (!$url) {
            return ['status' => 'error', 'message' => 'Airbnb ICS URL not configured'];
        }

        $content = $this->fetchIcs($url);
        if ($content === null) {
            return ['status' => 'error', 'message' => 'Failed to fetch ICS file from URL'];
        }

        $events = $this->parseIcs($content);

        // Normalizza le date una tantum; gli eventi senza date valide escono nel batch come errori.
        $importable = [];
        $skipped = 0;
        $errors = [];
        foreach ($events as $event) {
            $start = $this->normalizeIcsDate($event['start'] ?? null);
            $end = $this->normalizeIcsDate($event['end'] ?? null);
            $summary = trim((string)($event['summary'] ?? ''));

            if ($start === null || $end === null) {
                $errors[] = sprintf('Evento "%s": data non riconosciuta (start=%s, end=%s)', $summary, $event['start'] ?? 'assente', $event['end'] ?? 'assente');
                continue;
            }

            if (!$this->isBookingSummary($summary)) {
                $skipped++;
                continue;
            }

            $importable[] = ['start' => $start, 'end' => $end];
        }

        // ── Rilevazione collisioni: vs universo esistente + tra nuovi eventi ──
        // I nuovi eventi non sono ancora in DB: si rilevano con espansione (§5.0)
        // + test intervallo semiaperto in PHP.
        $svc = new AvailabilityService();
        $overlappedSet = [];     // key "start|end" => true (dedup)
        $overlappedPairs = [];   // liste deduplicate
        $conflicts = [];

        $mark = function (array $b) use (&$overlappedSet, &$overlappedPairs) {
            $k = $b['start'] . '|' . $b['end'];
            if (!isset($overlappedSet[$k])) {
                $overlappedSet[$k] = true;
                $overlappedPairs[] = $b;
            }
        };

        // (a) vs universo già in banco (guests NON-'A' espansi + blocchi manuali).
        //     excludeOrigine='A': le righe 'A' in DB sono l'import PRECEDENTE che
        //     deleteImportedGuests() sta per cancellare/rigenerare; contarle
        //     causerebbe un 409 spurio a ogni re-run (ogni booking matcha sé stesso).
        //     Le prenotazioni reali contano invece nel flow manuale
        //     (POST /guests / addBlock), dove excludeOrigine resta null.
        foreach ($importable as $b) {
            if ($svc->overlaps($b['start'], $b['end'], null, 'A')) {
                $mark($b);
                $conflicts[] = [
                    'new_in'    => $b['start'],
                    'new_out'   => $b['end'],
                    'kind'      => 'universe',
                    'details'   => array_map(
                        fn($c) => ['src'=>$c['src'], 'id'=>$c['id'], 'in'=>$c['in'], 'out'=>$c['out'], 'expanded'=>$c['expanded']],
                        $svc->conflicts($b['start'], $b['end'], null, 'A')
                    ),
                ];
            }
        }

        // (b) tra nuovi eventi (non ancora in DB): O(n^2)
        $n = \count($importable);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $A = $importable[$i];
                $B = $importable[$j];
                // overlap ⇔ A.start < B.end AND B.start < A.end (intervallo [in,out))
                if ($A['start'] < $B['end'] && $B['start'] < $A['end']) {
                    $mark($A);
                    $mark($B);
                    $effA = $svc->expand(['in' => $A['start'], 'out' => $A['end']]);
                    $effB = $svc->expand(['in' => $B['start'], 'out' => $B['end']]);
                    $conflicts[] = [
                        'new_in'    => $A['start'],
                        'new_out'   => $A['end'],
                        'kind'      => 'new-vs-new',
                        'details'   => [
                            ['src' => 'ics', 'in' => $A['start'], 'out' => $A['end'], 'eff_in' => $effA['in'], 'eff_out' => $effA['out']],
                            ['src' => 'ics', 'in' => $B['start'], 'out' => $B['end'], 'eff_in' => $effB['in'], 'eff_out' => $effB['out']],
                        ],
                    ];
                }
            }
        }

        if (!empty($overlappedPairs) && !$force) {
            return [
                'status'  => 'overlap',
                'skipped' => $skipped,
                'conflicts' => $conflicts,
                'anomalies' => [],
                'errors'  => $errors,
            ];
        }

        $importedCount = 0;
        $anomalies = [];
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            if (!$this->guestManager->deleteImportedGuests()) {
                throw new \RuntimeException('Failed to clean existing Airbnb imports');
            }

            foreach ($importable as $booking) {
                if ($this->guestManager->createImportedGuest($booking['start'], $booking['end'])) {
                    $importedCount++;
                } else {
                    $errors[] = "Failed to create guest for booking starting {$booking['start']}";
                }
            }

            // Segnala (stato='I') gli import guest non più presenti nell'ICS corrente
            // ma con token attivi: non bloccà l'import, solo segnalazione.
            $validPairs = array_map(
                fn($b) => $b['start'] . '|' . $b['end'],
                $importable
            );
            $this->guestManager->refreshImportAnomalies($validPairs, $anomalies);

            // Regola B (ultimo evento vince): per le coppie in collisione (forzate
            // dal cliente con $force=true) → stato='O'. Si fa DOPO
            // refreshImportAnomalies in modo che 'O' sia il codice corrente.
            foreach (array_unique(array_map(fn($b) => $b['start'] . '|' . $b['end'], $overlappedPairs)) as $pair) {
                [$pIn, $pOut] = explode('|', $pair);
                $stmt = $pdo->prepare("UPDATE guests SET stato = 'O'
                                       WHERE origine = 'A' AND arrival_date = ? AND departure_date = ?");
                $stmt->execute([$pIn, $pOut]);

                // Id della riga (per le anomalies)
                $idStmt = $pdo->prepare("SELECT id FROM guests WHERE origine = 'A' AND arrival_date = ? AND departure_date = ?");
                $idStmt->execute([$pIn, $pOut]);
                $gid = (int)$idStmt->fetchColumn();

                $anomalies[] = [
                    'guest_id'       => $gid,
                    'arrival_date'   => $pIn,
                    'departure_date' => $pOut,
                    'stato'          => 'O',
                    'reason'         => 'overlap',
                ];
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Transazione non andata a buon fine: ' . $e->getMessage();
            return ['status' => 'error', 'message' => 'ICS import failed', 'errors' => $errors];
        }

        return [
            'status' => 'success',
            'guests_imported' => $importedCount,
            'skipped' => $skipped,
            'conflicts' => $conflicts,
            'anomalies' => $anomalies,
            'errors' => $errors,
        ];
    }

    /**
     * Fetches the ICS content with cURL: timeout, User-Agent, retry con backoff,
     * verifica HTTP 200. Ritorna null su fallimento.
     */
    private function fetchIcs(string $url): ?string {
        $lastError = 'unknown';
        for ($attempt = 1; $attempt <= self::FETCH_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep(1);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'OspitiWebServer/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: text/calendar'],
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $httpCode === 200) {
                return $body;
            }

            $lastError = 'HTTP ' . $httpCode . ($error !== '' ? " ({$error})" : '');
        }

        return null;
    }

    /**
     * True se il SUMMARY identifica una prenotazione (case-insensitive).
     */
    private function isBookingSummary(string $summary): bool {
        $s = strtolower($summary);
        foreach (self::SUMMARY_KEYWORDS as $kw) {
            if (stripos($s, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalizza una data ICS (YYYYMMDD o YYYYMMDDTHHMM[SZ]) a "Y-m-d".
     * Ritorna null se non riconosciuta.
     */
    private function normalizeIcsDate(?string $raw): ?string {
        $raw = trim((string)$raw);
        if (preg_match('/^\d{8}T?/', $raw, $m)) {
            $d = substr($raw, 0, 8);
            return sprintf('%s-%s-%s', substr($d, 0, 4), substr($d, 4, 2), substr($d, 6, 2));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return null;
    }

    /**
     * Parses an ICS file and extracts reservation events.
     *
     * @param string $content
     * @return array List of events with start, end, and summary (date grezze; normalizza in importBookings).
     */
    private function parseIcs(string $content): array {
        // 1. Handle Line Folding (RFC 5545)
        // Any line starting with a space or tab is a continuation of the previous line.
        $lines = explode("\n", str_replace("\r", "", $content));
        $unfoldedLines = [];
        if (!empty($lines)) {
            $currentLine = $lines[0];
            for ($i = 1; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (strpos($line, ' ') === 0 || strpos($line, "\t") === 0) {
                    $currentLine .= substr($line, 1);
                } else {
                    $unfoldedLines[] = $currentLine;
                    $currentLine = $line;
                }
            }
            $unfoldedLines[] = $currentLine;
        }

        $events = [];
        $currentEvent = null;

        foreach ($unfoldedLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent !== null) {
                    $events[] = $currentEvent;
                    $currentEvent = null;
                }
            } elseif ($currentEvent !== null) {
                // Extract fields
                if (strpos($line, 'SUMMARY:') === 0) {
                    $currentEvent['summary'] = trim(substr($line, 8));
                } elseif (preg_match('/^DTSTART(;[A-Z]+=([A-Z]+))?:(.+)$/', $line, $m)) {
                    $currentEvent['start'] = $m[3];
                } elseif (preg_match('/^DTEND(;[A-Z]+=([A-Z]+))?:(.+)$/', $line, $m)) {
                    $currentEvent['end'] = $m[3];
                }
            }
        }

        return $events;
    }
}
