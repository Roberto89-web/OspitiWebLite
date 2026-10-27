<?php

namespace App;

use App\Exceptions\AppException;


class RegistrationService {
    private $pdo;
    private ?array $comuniNameIndex = null;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Normalizes a lookup name for exact matching: uppercase, diacritics
     * removed (when intl is available), apostrophes/backticks removed,
     * whitespace collapsed and trimmed.
     */
    private function normalizeLookupName(string $name): string {
        $name = trim((string)$name);
        if (class_exists(\Intl\Transliterator::class)) {
            $t = \Intl\Transliterator::create('Any-Latin; Latin-ASCII; Upper()');
            if ($t && ($converted = $t->transliterate($name)) !== null) {
                $name = $converted;
            }
        }
        $name = strtoupper($name);
        $name = str_replace(["'", '`'], '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * Builds (and caches) a map: normalized comune name => list of codes.
     * A given name can map to several codes (homonymous municipalities across
     * provinces); only entries with a single code are usable for resolving.
     */
    private function getComuniNameIndex(): array {
        if ($this->comuniNameIndex !== null) {
            return $this->comuniNameIndex;
        }
        $index = [];
        $stmt = $this->pdo->query("SELECT codice, descrizione FROM lk_comuni");
        while ($row = $stmt->fetch()) {
            $key = $this->normalizeLookupName((string)$row['descrizione']);
            if ($key === '') continue;
            $code = strtoupper((string)$row['codice']);
            if (!isset($index[$key])) $index[$key] = [];
            if (!in_array($code, $index[$key], true)) $index[$key][] = $code;
        }
        $this->comuniNameIndex = $index;
        return $index;
    }

    /**
     * Fase 6 — rete di sicurezza server-side:
     * se il codice città è vuoto ma il nome, normalizzato, corrisponde UNIQUEMENTE
     * a un comune in lk_comuni, risolve il codice lato server invece di rifiutare.
     * Nessun match, o più di un comune con lo stesso nome → non tocca nulla:
     * le validazioni esistenti decidono. Modifica $persons in-place così il
     * codice risolto viene persistito nell'INSERT.
     */
    private function resolveCityCodesByUniqueName(array &$persons): void {
        $cityFields = [
            ['name' => 'birth_city_name', 'code' => 'birth_city_code'],
            ['name' => 'res_city_name',   'code' => 'res_city_code'],
            ['name' => 'doc_issue_city',  'code' => 'doc_issue_city_code'],
        ];

        $index = null;
        foreach ($persons as $i => $person) {
            foreach ($cityFields as $f) {
                $code = trim((string)($person[$f['code']] ?? ''));
                if ($code !== '') continue;
                $name = trim((string)($person[$f['name']] ?? ''));
                if ($name === '') continue;
                if ($index === null) $index = $this->getComuniNameIndex();
                $key = $this->normalizeLookupName($name);
                if ($key === '' || !isset($index[$key])) continue;
                if (count($index[$key]) === 1) {
                    $persons[$i][$f['code']] = $index[$key][0];
                }
            }
        }
    }

    /**
     * Generates a secure registration link for a guest.
     * Invalidates any existing unused tokens for the same guest.
     */
    public function generateLink(int $guestId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM guests WHERE id = ?");
        $stmt->execute([$guestId]);
        $guest = $stmt->fetch();

        if (!$guest) {
            throw (new AppException('Guest not found'))->setHttpStatusCode(404);
        }

        $token = bin2hex(random_bytes(32));
        $expiryDays = Config::getInstance()->get('app.reg_token_expiry_days', 7);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryDays days"));

        // Invalidate previous unused tokens (solo i token di form: non tocca i selfcheckin)
        $this->pdo->prepare("UPDATE registration_tokens SET expires_at = NOW()
                              WHERE guest_id = ? AND used_at IS NULL AND purpose = 'form'")
                   ->execute([$guestId]);

        // Insert new token
        $stmt = $this->pdo->prepare("INSERT INTO registration_tokens (guest_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$guestId, $token, $expiresAt]);

        return [
            'token'      => $token,
            'expires_at' => $expiresAt,
            'guest'      => $guest,
            'link'       => Config::getInstance()->get('server.base_url', 'https://localhost:8080') . "/web/register/" . $token
        ];
    }

    /**
     * Validates a token and returns combined guest and token data.
     */
    public function validateToken(string $token): ?array {
        $stmt = $this->pdo->prepare("
            SELECT rt.*, rs.guest_count as num_persons, g.first_name, g.last_name, g.id as guest_id, g.member_type_id
            FROM registration_tokens rt
            JOIN guests g ON g.id = rt.guest_id
            JOIN registration_sessions rs ON rs.id = g.registration_id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND rt.used_at IS NULL
              AND rt.purpose = 'form'
        ");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Validates lookup codes against database tables.
     * @param array $persons List of person data arrays
     * @throws Exception if any code is invalid or missing required validation
     */
    private function validateLookupCodes(array $persons): void {
        // Batch fetch all valid codes once (outside the per-person loop)
        $validStates = [];
        $stmt = $this->pdo->query("SELECT codice FROM lk_stati");
        while ($row = $stmt->fetch()) {
            $validStates[strtoupper($row['codice'])] = true;
        }

        $validComuni = [];
        $stmt = $this->pdo->query("SELECT codice FROM lk_comuni");
        while ($row = $stmt->fetch()) {
            $validComuni[strtoupper($row['codice'])] = true;
        }

        // Province codes are stored in the 'provincia' column, not as independent rows.
        $validProvinces = [];
        $stmt = $this->pdo->query("SELECT DISTINCT provincia FROM lk_comuni WHERE provincia IS NOT NULL");
        while ($row = $stmt->fetch()) {
            $validProvinces[strtoupper($row['provincia'])] = true;
        }

        foreach ($persons as $index => $person) {
            // ── member_type_id: always required (Tipo Ospite) ──
            if (empty($person['member_type_id'])) {
                $msg = "Persona " . ($index + 1) . ": il 'Tipo Ospite' deve essere selezionato.";
                throw (new AppException($msg))->setHttpStatusCode(400);
            }

            // ── res_state_code: always required (Stato di Residenza) ──
            if (empty($person['res_state_code'])) {
                $msg = "Persona " . ($index + 1) . ": lo 'Stato di Residenza' deve essere selezionato.";
                throw (new AppException($msg))->setHttpStatusCode(400);
            }

            // citizenship_state_code: must exist in lk_stati when provided (universal lookup)
            if (!empty($person['citizenship_state_code'])) {
                $code = strtoupper(trim($person['citizenship_state_code']));
                if (!isset($validStates[$code])) {
                    $msg = "Cittadinanza '" . ($person['citizenship_state_name'] ?? '') . "' non valida. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // birth_state_code: must exist in lk_stati when provided (universal lookup)
            if (!empty($person['birth_state_code'])) {
                $code = strtoupper(trim($person['birth_state_code']));
                if (!isset($validStates[$code])) {
                    $msg = "Stato di nascita '" . ($person['birth_state_name'] ?? '') . "' non valido. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // birth_prov_code: must exist in lk_comuni.provincia (Italian province codes) when provided
            if (!empty($person['birth_prov_code'])) {
                $code = strtoupper(trim($person['birth_prov_code']));
                if (!isset($validProvinces[$code]) && !isset($validComuni[$code])) {
                    $msg = "Provincia di nascita '" . ($person['birth_prov_name'] ?? '') . "' non valida. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // birth_city_code: must exist in lk_comuni when provided
            if (!empty($person['birth_city_code'])) {
                $code = strtoupper(trim($person['birth_city_code']));
                if (!isset($validComuni[$code])) {
                    $msg = "Città di nascita '" . ($person['birth_city_name'] ?? '') . "' non valida. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // ── Residenza (ROSS campi 11-13): stesse regole di lookup della nascita ──

            // res_state_code: must exist in lk_stati when provided (universal lookup)
            if (!empty($person['res_state_code'])) {
                $code = strtoupper(trim($person['res_state_code']));
                if (!isset($validStates[$code])) {
                    $msg = "Stato di residenza '" . ($person['res_state_name'] ?? '') . "' non valido. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // res_prov_code: must exist in lk_comuni.provincia (Italian province codes) when provided
            if (!empty($person['res_prov_code'])) {
                $code = strtoupper(trim($person['res_prov_code']));
                if (!isset($validProvinces[$code]) && !isset($validComuni[$code])) {
                    $msg = "Provincia di residenza '" . ($person['res_prov_name'] ?? '') . "' non valida. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // res_city_code: must exist in lk_comuni when provided
            if (!empty($person['res_city_code'])) {
                $code = strtoupper(trim($person['res_city_code']));
                if (!isset($validComuni[$code])) {
                    $msg = "Città di residenza '" . ($person['res_city_name'] ?? '') . "' non valida. Seleziona un valore dai suggerimenti.";
                    throw (new AppException($msg))->setHttpStatusCode(400);
                }
            }

            // ── Document-field validation (solo capofamiglia: member_type_id 16/17/18) ──
            $isCapoFamiglia = in_array((int)$person['member_type_id'], [16, 17, 18], true);

            if ($isCapoFamiglia) {
                // doc_release_state_code: must exist in lk_stati when provided (universal lookup)
                if (!empty($person['doc_release_state_code'])) {
                    $code = strtoupper(trim($person['doc_release_state_code']));
                    if (!isset($validStates[$code])) {
                            $msg = "Stato rilascio documento '" . ($person['doc_release_state_name'] ?? '') . "' non valido. Seleziona un valore dai suggerimenti.";
                        throw (new AppException($msg))->setHttpStatusCode(400);
                    }
                }

                // doc_issue_city_code: must exist in lk_comuni when provided (Italian city release)
                if (!empty($person['doc_issue_city_code'])) {
                    $code = strtoupper(trim($person['doc_issue_city_code']));
                    // doc_issue_city_code might be a state code for non-Italy releases, so allow both
                    if (!isset($validComuni[$code]) && !isset($validStates[$code])) {
                        $msg = "Città rilascio documento non valida per il documento rilasciato in '" . ($person['doc_release_state_name'] ?? '') . "'.";
                        throw (new AppException($msg))->setHttpStatusCode(400);
                    }
                }

                // ── Cross-field validation: citizenship dictates required release fields ──
                $citCode = strtoupper(trim($person['citizenship_state_code'] ?? ''));
                $isItalyCitizenship = ($citCode === '100000100') || (strtoupper(trim($person['citizenship_state_name'] ?? '')) === 'ITALIA');

                if ($isItalyCitizenship) {
                    // Cittadinanza = ITALIA → doc_issue_city_code è obbligatorio
                    if (empty($person['doc_issue_city_code'])) {
                        $msg = "Persona " . ($index + 1) . ": se la cittadinanza è ITALIA, la 'Città Rilascio Documento' deve essere compilata. Seleziona un valore dai suggerimenti.";
                        throw (new AppException($msg))->setHttpStatusCode(400);
                    }
                } else {
                    // Cittadinanza != ITALIA → doc_release_state_code è obbligatorio
                    if (empty($person['doc_release_state_code'])) {
                        $msg = "Persona " . ($index + 1) . ": se la cittadinanza non è ITALIA, lo 'Stato Rilascio Documento' deve essere compilato. Seleziona un valore dai suggerimenti.";
                        throw (new AppException($msg))->setHttpStatusCode(400);
                    }
                }
            }
        }
    }

    /**
     * Adjusts document release fields based on citizenship for non-Italian citizens.
     * When citizenship is NOT ITALIA: copies doc_release_state_name into doc_issue_city,
     * so the server receives the state name as the issue city text.
     * @param array $persons List of person data arrays (modified in-place)
     */
    private function adjustDocFieldsForCitizenship(array &$persons): void {
        foreach ($persons as &$person) {
            // Document fields exist only for capofamiglia (member_type_id 16/17/18)
            if (!in_array((int)$person['member_type_id'], [16, 17, 18], true)) continue;

            $citCode = strtoupper(trim($person['citizenship_state_code'] ?? ''));
            $isItalyCitizenship = ($citCode === '100000100') || (strtoupper(trim($person['citizenship_state_name'] ?? '')) === 'ITALIA');

            if (!$isItalyCitizenship) {
                // Cittadinanza non italiana: il form frontend invia solo doc_release_state_*,
                // ma il DB si aspetta anche doc_issue_city/doc_issue_city_code. Copiamo lo stato
                // di rilascio in entrambi i campi.
                $person['doc_issue_city']     = trim($person['doc_release_state_name'] ?? '');
                $person['doc_issue_city_code'] = trim($person['doc_release_state_code'] ?? '');
            }
        }
    }

    /**
     * Validates that when birth state is ITALIA, both province and city codes are provided (not just names).
     * Without this check, the DB would receive null codes even if the name fields were filled.
     * Also validates that birth_state_code is populated when birth_state_name contains "ITALIA".
     * @param array $persons List of person data arrays
     * @throws Exception if required Italy birth fields are missing or codes not synced
     */
    private function validateItalyBirthFields(array $persons): void {
        foreach ($persons as $index => $person) {
            $stateName = trim($person['birth_state_name'] ?? '');
            $stateCode = trim($person['birth_state_code'] ?? '');
            $provCode = trim($person['birth_prov_code'] ?? '');
            $cityCode = trim($person['birth_city_code'] ?? '');

            // Determine if birth state is ITALIA (from code or name)
            $isItaly = false;
            if (!empty($stateCode) && strtoupper($stateCode) === '100000100') {
                $isItaly = true;
            } elseif (strtoupper($stateName) === 'ITALIA') {
                // Name says ITALIA but check code is synced — this catches manual typing without lookup selection
                $isItaly = true;
            }

            // If birth state is ITALIA → all three codes must be populated
            if ($isItaly && (!$stateCode || !$provCode || !$cityCode)) {
                $missing = [];
                if (empty($stateCode)) $missing[] = 'Stato di nascita';
                if (empty($provCode)) $missing[] = 'Provincia di nascita';
                if (empty($cityCode)) $missing[] = 'Città di nascita';
                $msg = "Persona " . ($index + 1) . ": " . implode(', ', $missing) . "(i) non compilato/i. Seleziona i valori dai suggerimenti per garantire la corretta memorizzazione dei dati.";
                throw ((new AppException($msg))->setHttpStatusCode(400));
            }
        }
    }

    /**
     * Validates that when residence state is ITALIA, both province and city codes are provided (ROSS campi 11/12 = obbligatori se 13 = Italia).
     * Mirrors validateItalyBirthFields. res_address (ROSS 14) is optional.
     * @param array $persons List of person data arrays
     * @throws Exception if required Italy residence fields are missing or codes not synced
     */
    private function validateItalyResidenceFields(array $persons): void {
        foreach ($persons as $index => $person) {
            $stateName = trim($person['res_state_name'] ?? '');
            $stateCode = trim($person['res_state_code'] ?? '');
            $provCode = trim($person['res_prov_code'] ?? '');
            $cityCode = trim($person['res_city_code'] ?? '');

            // Determine if residence state is ITALIA (from code or name)
            $isItaly = false;
            if (!empty($stateCode) && strtoupper($stateCode) === '100000100') {
                $isItaly = true;
            } elseif (strtoupper($stateName) === 'ITALIA') {
                $isItaly = true;
            }

            // If residence state is ITALIA → province and city codes must be populated
            if ($isItaly && (!$provCode || !$cityCode)) {
                $missing = [];
                if (empty($provCode)) $missing[] = 'Provincia di residenza';
                if (empty($cityCode)) $missing[] = 'Città di residenza';
                $msg = "Persona " . ($index + 1) . ": " . implode(', ', $missing) . "(i) non compilato/i. Se lo stato di residenza è ITALIA, seleziona i valori dai suggerimenti per garantire la corretta memorizzazione dei dati.";
                throw ((new AppException($msg))->setHttpStatusCode(400));
            }
        }
    }

    /**
     * Saves registration data and marks token as used.
     */
    public function submitRegistration(string $token, array $persons): bool {
        $tokenData = $this->validateToken($token);
        if (!$tokenData) {
            throw (new AppException('Invalid or expired token'))->setHttpStatusCode(403);
        }

        // Rete di sicurezza (Fase 6): risolve i codici città univoci prima della validazione
        $this->resolveCityCodesByUniqueName($persons);

        // Validazione server-side dei codici lookup
        $this->validateLookupCodes($persons);

        // Validazione campi obbligatori per nascite in ITALIA
        $this->validateItalyBirthFields($persons);

        // Validazione campi residenza (ROSS 11/12 obbl. se stato = ITALIA)
        $this->validateItalyResidenceFields($persons);

        // Adatta i campi documento in base alla cittadinanza (non-ITALIA → doc_issue_city = stato rilascio)
        $this->adjustDocFieldsForCitizenship($persons);

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("
                INSERT INTO registrations (
                    token_id, guest_id, person_index, first_name, last_name,
                    gender, birth_date, birth_city_code, birth_prov_code,
                    birth_state_code, citizenship_state_code, doc_type,
                    doc_number, doc_issue_city, email, phone, member_type_id,
                    res_state_code, res_prov_code, res_city_code, res_address
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($persons as $index => $person) {
                $insert->execute([
                    $tokenData['id'],
                    $tokenData['guest_id'],
                    $index + 1,
                    $person['first_name'],
                    $person['last_name'],
                    $person['gender'] ?? null,
                    $person['birth_date'] ?? null,
                    $person['birth_city_code'] ?? null,
                    $person['birth_prov_code'] ?? null,
                    $person['birth_state_code'] ?? null,
                    $person['citizenship_state_code'] ?? null,
                    $person['doc_type'] ?? null,
                    $person['doc_number'] ?? null,
                    $person['doc_issue_city_code'] ?? $person['doc_issue_city'] ?? null,
                    $person['email'] ?? null,
                    $person['phone'] ?? null,
                    $person['member_type_id'],
                    $person['res_state_code'] ?? null,
                    $person['res_prov_code'] ?? null,
                    $person['res_city_code'] ?? null,
                    // Residenza: per non-ITALIA la provincia/città non sono richieste (ROSS 11/12 obbl. solo se Italia)
                    $person['res_address'] ?? null
                ]);
            }

            // Step 1 completato: imposta SOLO phase1_at (NON used_at) così il token
            // resta valido per il Step 2 (/api/register/travel). Il token si chiude
            // al completamento del Step 2 (che qui imposta used_at).
            $this->pdo->prepare("UPDATE registration_tokens SET phase1_at = NOW() WHERE id = ?")
                      ->execute([$tokenData['id']]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Loads the full token row (including phase1_at / used_at) without the
     * "unused only" predicate, so the two-step flow can inspect state.
     */
    private function getRawToken(string $token): ?array {
        $stmt = $this->pdo->prepare("SELECT rt.*, g.id as guest_id
                                      FROM registration_tokens rt
                                      JOIN guests g ON g.id = rt.guest_id
                                      WHERE rt.token = ? AND rt.purpose = 'form'");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Step 2 — salva i dati di viaggio (ROSS campi 19/20: tipo turismo, mezzo).
     *
     * Richiede che lo Step 1 sia già completato (phase1_at NOT NULL).
     * Idempotente: se il token è già chiuso (used_at NOT NULL) non modifica
     * nulla e riporta already_complete = true.
     * Chiude il token (used_at = NOW) al completamento.
     */
    public function submitTravel(string $token, string $tourType, string $transportMean): array {
        $raw = $this->getRawToken($token);
        if (!$raw) {
            throw (new AppException("Token non valido o scaduto"))->setHttpStatusCode(403);
        }

        if (empty($raw['phase1_at'])) {
            throw (new AppException("Completare prima lo Step 1 (dati persona e residenza)."))->setHttpStatusCode(400);
        }

        if (!empty($raw['used_at'])) {
            return [
                'already_complete' => true,
                'message' => 'Registrazione già completata.'
            ];
        }

        $tourType = trim($tourType);
        $transportMean = trim($transportMean);

        // ROSS 19/20: valore esatto presente nella lookup (es. "Balneare", "Auto")
        $t = $this->pdo->prepare("SELECT COUNT(*) FROM lk_tipo_turismo WHERE valore = ?");
        $t->execute([$tourType]);
        if ((int)$t->fetchColumn() === 0) {
            throw (new AppException("Tipo di viaggio non valido. Seleziona un valore dalla lista."))->setHttpStatusCode(400);
        }

        $m = $this->pdo->prepare("SELECT COUNT(*) FROM lk_mezzo_trasporto WHERE valore = ?");
        $m->execute([$transportMean]);
        if ((int)$m->fetchColumn() === 0) {
            throw (new AppException("Mezzo di trasporto non valido. Seleziona un valore dalla lista."))->setHttpStatusCode(400);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE guests SET tour_type = ?, transport_mean = ? WHERE id = ?")
                      ->execute([$tourType, $transportMean, $raw['guest_id']]);

            $this->pdo->prepare("UPDATE registration_tokens SET used_at = NOW() WHERE id = ?")
                      ->execute([$raw['id']]);

            $this->pdo->commit();
            return [
                'already_complete' => false,
                'completed' => true,
                'message' => 'Dati di viaggio salvati con successo.'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Step 2 — legge solo i dati di viaggio da compilare/prefill + le lookup.
     * Mai restituisce campi di residenza.
     */
    public function getTravelData(string $token): array {
        $raw = $this->getRawToken($token);
        if (!$raw) {
            return ['guest_id' => null, 'tour_type' => null, 'transport_mean' => null];
        }

        $lookup = new LookupManager();

        $g = $this->pdo->prepare("SELECT tour_type, transport_mean FROM guests WHERE id = ?");
        $g->execute([$raw['guest_id']]);
        $guest = $g->fetch();

        return [
            'guest_id'       => $raw['guest_id'],
            'tour_type'      => $guest['tour_type'] ?? null,
            'transport_mean' => $guest['transport_mean'] ?? null,
            'step1_done'     => !empty($raw['phase1_at']),
            'used'           => !empty($raw['used_at']),
            'options'        => [
                'tour_type'      => $lookup->getValori('lk_tipo_turismo'),
                'transport_mean' => $lookup->getValori('lk_mezzo_trasporto'),
            ]
        ];
    }

    /**
     * Valida il token per il flusso document-upload (face_check): lo Step 2
     * CHIUDE il token (used_at NOT NULL), quindi validateToken() (che richiede
     * used_at IS NULL) lo rifiuterebbe. Questo metodo accetta un token scaduto
     * o con step1 già fatto, purché non expired e con guest esistente.
     * @return array|null token + guest se valido, null altrimenti
     */
    public function validateTokenForUpload(string $token): ?array {
        $stmt = $this->pdo->prepare("
            SELECT rt.token, rt.expires_at, g.id as guest_id, g.first_name, g.last_name
            FROM registration_tokens rt
            JOIN guests g ON g.id = rt.guest_id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND rt.phase1_at IS NOT NULL
              AND rt.purpose = 'form'
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Step 2 page gating — restituisce lo stato del token (fase completate,
     * scadenza) + nome ospite, SENZA richiedere che il token sia "libero".
     * Servono per decidere: reindirizza a success / a Step 1 / rende la pagina.
     */
    public function getTokenMeta(string $token): ?array {
        $raw = $this->getRawToken($token);
        if (!$raw) return null;

        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM guests WHERE id = ?");
        $stmt->execute([$raw['guest_id']]);
        $guest = $stmt->fetch();

        return [
            'guest_id'   => $raw['guest_id'],
            'first_name' => $guest['first_name'] ?? null,
            'last_name'  => $guest['last_name'] ?? null,
            'phase1_at'  => $raw['phase1_at'] ?? null,
            'used_at'    => $raw['used_at'] ?? null,
            'expires_at' => $raw['expires_at'] ?? null,
        ];
    }
}
