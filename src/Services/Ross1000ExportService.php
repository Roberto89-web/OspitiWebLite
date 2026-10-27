<?php

namespace App\Services;

use App\Database;
use App\Exceptions\AppException;

/**
 * ROSS 1000 / ISTAT fixed-width export (26 fields, 328 chars/record, CRLF).
 * Tracciato: docs/tracciato_record_movimentazione_istat.md
 *
 * $rows are the registrations JOIN guests rows from
 * GuestManager::getRegistrationsForRoss1000() (already ordered: head 16/17/18
 * before members 19/20 per stay). $cfg carries the config values read by the
 * caller (ross1000.* + gies.camere_totali/letti_totali).
 */
class Ross1000ExportService
{
    private string $italyCode = '100000100';
    private array $validTypes = [16, 17, 18, 19, 20];
    private array $headTypes = [16, 17, 18];

    public function generate(array $rows, array $cfg): string
    {
        $this->assertConfig($cfg);

        $tourTypes   = $this->lookupValues('lk_tipo_turismo');
        $transports  = $this->lookupValues('lk_mezzo_trasporto');
        $stayRooms   = $this->roomsPerStay($rows);

        $lines = [];
        foreach ($rows as $row) {
            $errors = $this->validate($row, $cfg, $tourTypes, $transports);
            if ($errors) {
                error_log('[ROSS1000] Record scartato (guest_id=' . ($row['guest_id'] ?? '?')
                    . ', ' . ($row['member_type_id'] ?? '?') .'): ' . implode('; ', $errors));
                continue;
            }

            $lines[] = $this->buildRecord($row, $cfg, $stayRooms);
        }

        if (empty($lines)) {
            throw (new AppException('Nessun record valido da esportare (verifica campi obbligatori ROSS)'))
                ->setHttpStatusCode(400);
        }

        return implode("\r\n", $lines);
    }

    /**
     * One 328-char record. Field layout (lunghezze totali 328):
     * 2+10+50+30+1+10+9+2+9+9+9+2+9+50+5+20+9+10+30+30+3+3+4+1+10+1
     */
    private function buildRecord(array $r, array $cfg, array $stayRooms): string
    {
        $isHead = in_array((int)$r['member_type_id'], $this->headTypes, true);

        $line  = $this->pad($r['member_type_id'], 2);
        $line .= $this->pad($this->date($r['arrival_date']), 10);
        $line .= $this->pad($r['last_name'], 50);
        $line .= $this->pad($r['first_name'], 30);
        $line .= $this->pad($this->genderCode($r['gender']), 1);
        $line .= $this->pad($this->date($r['birth_date']), 10);
        $line .= $this->pad($r['birth_city_code'], 9);
        $line .= $this->pad($r['birth_prov_code'], 2);
        $line .= $this->pad($r['birth_state_code'], 9);
        $line .= $this->pad($r['citizenship_state_code'], 9);
        $line .= $this->pad($r['res_city_code'], 9);
        $line .= $this->pad($r['res_prov_code'], 2);
        $line .= $this->pad($r['res_state_code'], 9);
        $line .= $this->pad($r['res_address'], 50);
        $line .= $this->pad($r['doc_type'], 5);
        $line .= $this->pad($r['doc_number'], 20);
        $line .= $this->pad($r['doc_issue_city'], 9);
        $line .= $this->pad($this->date($r['departure_date']), 10);
        $line .= $this->pad($r['tour_type'], 30);
        $line .= $this->pad($r['transport_mean'], 30);

        if ($isHead) {
            $rooms = (int)($stayRooms[(int)$r['guest_id']] ?? 1);
            $line  .= $this->pad($rooms, 3);
            $line  .= $this->pad((int)($cfg['rooms_total'] ?? 0), 3);
            $line  .= $this->pad((int)($cfg['beds_total'] ?? 0), 4);
        } else {
            $line .= '   ';  // 21
            $line .= '   ';  // 22
            $line .= '    '; // 23
        }

        $line  .= $this->pad($cfg['tassa_soggiorno'], 1);
        $line  .= $this->pad($cfg['codice_posizione'], 10);
        $line  .= $this->pad('1', 1); // Modalità 1 = Nuovo (costante)

        return $line;
    }

    /**
     * Spec sez. 6 + plan: restituisce l'elenco dei campi obbligatori mancanti
     * (vuoto = record valido).
     */
    private function validate(array $r, array $cfg, array $tourTypes, array $transports): array
    {
        $errors = [];
        $type   = (int)($r['member_type_id'] ?? 0);

        if (!in_array($type, $this->validTypes, true)) {
            return ["Tipo Alloggiato '{$r['member_type_id']}' non valido (atteso 16-20)"];
        }

        $required = [
            'arrival_date'           => 'Data di arrivo',
            'last_name'              => 'Cognome',
            'first_name'             => 'Nome',
            'gender'                 => 'Sesso',
            'birth_date'             => 'Data di nascita',
            'birth_state_code'       => 'Codice stato di nascita',
            'citizenship_state_code' => 'Codice cittadinanza',
            'res_state_code'         => 'Codice stato di residenza',
            'tour_type'              => 'Tipo Turismo',
            'transport_mean'         => 'Mezzo di Trasporto',
        ];

        if ($type === 16 || $type === 17 || $type === 18) {
            if (empty($r['departure_date'])) {
                $errors[] = 'Data di partenza';
            }
        }

        foreach ($required as $field => $label) {
            if (empty($r[$field] ?? null)) {
                $errors[] = $label;
            }
        }

        if ($r['gender'] && $this->genderCode($r['gender']) === '') {
            $errors[] = 'Sesso (valore non M/F)';
        }

        // 11/12 obbligatori se stato di residenza = Italia
        if (strtoupper(trim((string)($r['res_state_code'] ?? ''))) === $this->italyCode) {
            if (empty($r['res_city_code'])) {
                $errors[] = 'Codice comune di residenza (obbligatorio in Italia)';
            }
            if (empty($r['res_prov_code'])) {
                $errors[] = 'Provincia di residenza (obbligatoria in Italia)';
            }
        }

        if (!in_array((string)($r['tour_type'] ?? ''), $tourTypes, true)) {
            $errors[] = 'Tipo Turismo non presente nella tabella lk_tipo_turismo';
        }
        if (!in_array((string)($r['transport_mean'] ?? ''), $transports, true)) {
            $errors[] = 'Mezzo di Trasporto non presente nella tabella lk_mezzo_trasporto';
        }

        return $errors;
    }

    private function assertConfig(array $cfg): void
    {
        if (empty(trim((string)($cfg['codice_posizione'] ?? '')))) {
            throw (new AppException('ross1000.codice_posizione non impostato in config.yaml (campo 25 obbligatorio)'))
                ->setHttpStatusCode(400);
        }
    }

    /**
     * Campo 21 — camere occupate da questo soggiorno: `COUNT(DISTINCT room_id)`
     * per guest_id (plan §4.6, D3). oggi modello 1:1 → sempre 1; si estende a
     * N se `guests.room_id` supporterà più camere per soggiorno (futuro).
     * Fallback a 1 se la colonna non esiste ancora (deploy in 2 tempi).
     */
    private function roomsPerStay(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (empty($row['room_id'])) {
                continue;
            }
            $gid = (int)($row['guest_id'] ?? 0);
            if (!isset($map[$gid])) {
                $map[$gid] = [];
            }
            $map[$gid][(int)$row['room_id']] = true;
        }
        $counts = [];
        foreach ($map as $gid => $rooms) {
            $counts[$gid] = max(1, count($rooms));
        }
        return $counts;
    }

    private function lookupValues(string $table): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT valore FROM {$table}");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** Y-m-d → gg/mm/aaaa (blank se assente) */
    private function date(?string $date): string
    {
        if (empty($date)) {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : '';
    }

    private function genderCode(?string $gender): string
    {
        $g = strtoupper(trim((string)$gender));
        if ($g === 'M') return '1';
        if ($g === 'F') return '2';
        return '';
    }

    private function pad($value, int $len): string
    {
        $value = (string)($value ?? '');
        return str_pad($value, $len, ' ', STR_PAD_RIGHT);
    }
}
