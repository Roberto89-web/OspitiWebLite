<?php

namespace App;

use App\Services\AvailabilityService;


class GuestManager {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Create a new guest registration and the head of family record.
     *
     * Sovrapposizione date: se l'intervallo [DataArrivo, DataPartenza) collide
     * con un altro guest (espanso del buffer §5.0) o con un blocco manuale,
     * il risultato è `overlap-with` (HTTP 409 a livello rotta) + `conflicts[]`.
     * `force=true` conferma la scrittura e marca `guests.stato='O'`.
     *
     * @param array $data
     * @param bool  $force conferma l'overlap (alla rotta → 409 prima di questo)
     * @return array ['ok'=>bool, 'id'=>int] oppure
     *               ['ok'=>false, 'err'=>'overlap-with', 'conflicts'=>array]
     */
    public function createGuest($data, bool $force = false) {
        $in  = (string)($data['DataArrivo'] ?? '');
        $out = (string)($data['DataPartenza'] ?? '');

        $svc = new AvailabilityService();
        if ($in && $out && $in < $out && $svc->overlaps($in, $out)) {
            if (!$force) {
                return [
                    'ok'        => false,
                    'err'       => 'overlap-with',
                    'conflicts' => $svc->conflicts($in, $out),
                ];
            }
            $forceOverlap = true;
        } else {
            $forceOverlap = false;
        }

        $token = bin2hex(random_bytes(32));
        $expiryHours = Config::getInstance()->get('app.token_expiry_hours', 24);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));

        // Create registration session
        $stmt = $this->pdo->prepare("INSERT INTO registration_sessions (email, token, guest_count, token_expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['email'], $token, (int)$data['NumOspiti'], $expiresAt]);
        $registrationId = (int)$this->pdo->lastInsertId();

        // Create Head of Family guest
        $stmt = $this->pdo->prepare("INSERT INTO guests (registration_id, first_name, last_name, email, origine, member_type_id, arrival_date, departure_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$registrationId, $data['NomeCapofamiglia'], $data['CognomeCapoFamiglia'], $data['email'], 'M', $data['TipoOspite'] ?? null, $data['DataArrivo'], $data['DataPartenza']]);
        $id = (int)$this->pdo->lastInsertId();

        if ($forceOverlap) {
            $this->pdo->prepare("UPDATE guests SET stato = 'O' WHERE id = ?")->execute([$id]);
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Update an existing guest and their registration session.
     *
     * Sovrapposizione date (Regola B — ultimo evento vince):
     * - date senza collisione  → reset: `stato=NULL WHERE id=? AND stato='O'`
     * - date con collisione e `force=false` → `overlap-with` (409 alla rotta)
     * - date con collisione e `force=true`  → `stato='O'`
     * Guest auto-escluso dal check (altrimenti matcherebbe sé stesso).
     *
     * @param int   $id
     * @param array $data
     * @param bool  $force
     * @return array ['ok'=>true] | ['ok'=>false,'err'=>'not-found'] |
     *               ['ok'=>false,'err'=>'overlap-with','conflicts'=>array]
     */
    public function updateGuest($id, $data, bool $force = false) {
        $stmt = $this->pdo->prepare("SELECT registration_id, email, arrival_date, departure_date FROM guests WHERE id = ?");
        $stmt->execute([$id]);
        $guest = $stmt->fetch();
        if (!$guest) {
            return ['ok' => false, 'err' => 'not-found'];
        }

        // ── Overlap check (solo se cambiano le date) ──
        $datesChanged = isset($data['DataArrivo']) || isset($data['DataPartenza']);
        $overlap = false;
        if ($datesChanged) {
            $inNew  = (string)($data['DataArrivo'] ?? $guest['arrival_date']);
            $outNew = (string)($data['DataPartenza'] ?? $guest['departure_date']);
            if ($inNew && $outNew && $inNew < $outNew) {
                $svc = new AvailabilityService();
                $conflicts = $svc->conflicts($inNew, $outNew, (int)$id);
                if (count($conflicts) > 0) {
                    if (!$force) {
                        return ['ok' => false, 'err' => 'overlap-with', 'conflicts' => $conflicts];
                    }
                    $overlap = true;
                }
            }
        }

        $registrationId = $guest['registration_id'];
        $currentEmail = $guest['email'];

        $sessionExists = false;
        if ($registrationId) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM registration_sessions WHERE id = ?");
            $stmt->execute([$registrationId]);
            if ($stmt->fetch()) {
                $sessionExists = true;
            }
        }

        $newSessionId = null;
        if (!$sessionExists) {
            $token = bin2hex(random_bytes(32));
            $expiryHours = Config::getInstance()->get('app.token_expiry_hours', 24);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));

            $email = $data['email'] ?? $currentEmail;
            $numOspiti = (int)($data['NumOspiti'] ?? 1);

            $stmt = $this->pdo->prepare("INSERT INTO registration_sessions (email, token, guest_count, token_expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$email, $token, $numOspiti, $expiresAt]);
            $newSessionId = (int)$this->pdo->lastInsertId();
        }

        if ($newSessionId) {
            $registrationId = $newSessionId;
        }


        // Update guests table
        $fields = [];
        $params = [];
        if ($newSessionId) { $fields[] = "registration_id = ?"; $params[] = $newSessionId; }
        if (isset($data['NomeCapofamiglia'])) { $fields[] = "first_name = ?"; $params[] = $data['NomeCapofamiglia']; }
        if (isset($data['CognomeCapoFamiglia'])) { $fields[] = "last_name = ?"; $params[] = $data['CognomeCapoFamiglia']; }
        if (isset($data['email'])) { $fields[] = "email = ?"; $params[] = $data['email']; }
        if (isset($data['TipoOspite'])) { $fields[] = "member_type_id = ?"; $params[] = $data['TipoOspite']; }
        if (isset($data['DataArrivo'])) { $fields[] = "arrival_date = ?"; $params[] = $data['DataArrivo']; }
        if (isset($data['DataPartenza'])) { $fields[] = "departure_date = ?"; $params[] = $data['DataPartenza']; }

        if (!empty($fields)) {
            $sql = "UPDATE guests SET " . implode(', ', $fields) . " WHERE id = ?";
            $params[] = $id;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Sincronizza stay_days quando cambiano le date di soggiorno.
        if (isset($data['DataArrivo']) || isset($data['DataPartenza'])) {
            $this->pdo->prepare(
                "UPDATE guests SET stay_days = DATEDIFF(departure_date, arrival_date) WHERE id = ?"
            )->execute([$id]);
        }

        // Sincronizza la riga registrations del capofamiglia quando il tipo ospite cambia
        // (16/17/18), così la join di lettura (hd.member_type_id = guests.member_type_id)
        // continua a pescare i campi documento (doc_number, doc_type, doc_issue_city).
        if (isset($data['TipoOspite']) && in_array((int)$data['TipoOspite'], [16, 17, 18], true)) {
            $newHeadType = (int)$data['TipoOspite'];
            $stmt = $this->pdo->prepare(
                "SELECT id FROM registrations WHERE guest_id = ? AND member_type_id IN (16, 17, 18) ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute([$id]);
            $headRowId = $stmt->fetchColumn();
            if ($headRowId === false) {
                // Fallback per dati anomali (nessuna riga capofamiglia): usa la prima riga.
                $stmt = $this->pdo->prepare(
                    "SELECT id FROM registrations WHERE guest_id = ? ORDER BY id ASC LIMIT 1"
                );
                $stmt->execute([$id]);
                $headRowId = $stmt->fetchColumn();
            }
            if ($headRowId !== false) {
                $this->pdo->prepare("UPDATE registrations SET member_type_id = ? WHERE id = ?")
                    ->execute([$newHeadType, (int)$headRowId]);
            }
        }

        // Update registration_sessions table if needed
        $regFields = [];
        $regParams = [];
        if (isset($data['email'])) { $regFields[] = "email = ?"; $regParams[] = $data['email']; }
        if (isset($data['NumOspiti'])) { $regFields[] = "guest_count = ?"; $regParams[] = (int)$data['NumOspiti']; }

        if (!empty($regFields)) {
            $sql = "UPDATE registration_sessions SET " . implode(', ', $regFields) . " WHERE id = ?";
            $regParams[] = $registrationId;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($regParams);
        }

        // Regola B — ultimo evento vince:
        //  - dates cambiate + collisione confermata (force=true) → stato='O'
        //  - dates cambiate + nessuna collisione          → reset condizioni su 'O'
        if ($datesChanged) {
            if ($overlap) {
                $this->pdo->prepare("UPDATE guests SET stato = 'O' WHERE id = ?")->execute([$id]);
            } else {
                $this->pdo->prepare("UPDATE guests SET stato = NULL WHERE id = ? AND stato = 'O'")->execute([$id]);
            }
        }

        return ['ok' => true];
    }

    /**
     * Delete all guests imported from an external source (Airbnb).
     *
     * Preservazione: qualunque token (form attivo/completato/scaduto, selfcheckin)
     * agganciato all'ospite blocca la cancellazione. Il sync NON può cancellare
     * un guest che ha già un riferimento attivo verso un link (anche scaduto):
     * la decisione resta all'host. Vedi plans/2026-10-08-import-ics.md.
     *
     * @return bool True on success, false on failure
     */
    public function deleteImportedGuests() {
        $sql = "DELETE FROM guests WHERE origine = 'A'
			AND id NOT IN (
				SELECT guest_id FROM registration_tokens
			)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute();
    }

    /**
     * Segnala le anomalie di import ICS sul campo `stato` (dizionario 'I').
     *
     * Per ogni import guest `origine='A'` che ha >= 1 token in
     * `registration_tokens` ma il cui (arrival_date, departure_date) non compare
     * tra le prenotazioni valide dell'ICS corrente, imposta `stato='I'` e lo
     * restituisce in `$anomaliesOut`. Se una riga già in `stato='I'` torna a
     * comparire nell'ICS (reconciliazione), il flag viene azzzerato a NULL.
     *
     * Esclusione già passati: i guest con `departure_date < CURDATE()` non sono
     * candidati: Airbnb non li invia più nell'ICS, quindi il "non essere in ICS"
     * è atteso e NON è un'anomalia. Vengono esclusi a monte dalla SELECT
     * (`departure_date >= CURDATE()`) — né li rimarco 'I' né li resetto: il
     * `stato` eventualmente già impostato resta invariato.
     *
     * Regola B (ultimo evento vince): l'imposta/rimozione è SEMPRE su 'I'
     * come codice corrente. Il reset NON tocca un eventuale `stato='O'`
     * (overlap confermato): `UPDATE ... SET stato=NULL WHERE id=? AND stato='I'`.
     *
     * $validPairs: list of "YYYY-MM-DD|YYYY-MM-DD" already validated in the ICS.
     * $anomaliesOut: by reference, array of
     *   ['guest_id' => int, 'arrival_date' => string|NULL,
     *    'departure_date' => string|NULL, 'stato' => 'I']
     *
     * @param string[] $validPairs
     * @param array    &$anomaliesOut
     */
    public function refreshImportAnomalies(array $validPairs, array &$anomaliesOut) {
        $anomaliesOut = [];

        $stmt = $this->pdo->query(
            "SELECT DISTINCT g.id, g.arrival_date, g.departure_date,
                    g.stato AS cur
             FROM guests g
             INNER JOIN registration_tokens rt ON rt.guest_id = g.id
             WHERE g.origine = 'A'
               AND g.departure_date IS NOT NULL
               AND g.departure_date >= CURDATE()"
        );

        $seenInIcs = array_flip($validPairs);
        $doneIds = [];

        while ($g = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // DISTINCT su (id, dates) può comunque restituire duplicati di id
            // se un guest avesse più righe; deduplico per id.
            if (isset($doneIds[$g['id']])) continue;
            $doneIds[$g['id']] = true;

            $pair = trim((string)$g['arrival_date']) . '|' . trim((string)$g['departure_date']);
            $current = $g['cur'];

            if (isset($seenInIcs[$pair])) {
                // Ancora presente nell'ICS corrente → risolve l'anomalia (se presente).
                // Condizionato su 'I': non cancella un eventuale 'O'.
                if ($current === 'I') {
                    $this->pdo->prepare("UPDATE guests SET stato = NULL WHERE id = ? AND stato = 'I'")->execute([$g['id']]);
                }
            } else {
                // Non più nell'ICS, ma ha token → segna/preserva (overwrite su 'O',
                // Regola B: ultimo evento vince).
                if ($current !== 'I') {
                    $this->pdo->prepare("UPDATE guests SET stato = 'I' WHERE id = ?")->execute([$g['id']]);
                }
                $anomaliesOut[] = [
                    'guest_id'       => (int)$g['id'],
                    'arrival_date'   => $g['arrival_date'],
                    'departure_date' => $g['departure_date'],
                    'stato'          => 'I',
                ];
            }
        }
    }
	
	/**
	 * Create a guest record from an external import (e.g. ICS)
	 *
	 * @param string $arrivalDate
	 * @param string $departureDate
	 * @return bool True on success, false on failure
	 */
    /**
     * Soft delete: imposta `deleted_at`/`deleted_by` sulla riga guest.
     *
     * Non rimuove nulla: le tabelle collegate (registrations,
     * registration_tokens, tassa_soggiorno_incassi, selfcheckin_sessions)
     * restano intatte. Tutte le SELECT "calde" (list, dettaglio, export)
     * filtrano `deleted_at IS NULL` → il guest diventa invisibile.
     *
     * Idempotente: una seconda chiamata sullo stesso id (già eliminato)
     * restituisce `false` (affectedRows = 0).
     *
     * @param int $id     guests.id da eliminare
     * @param int $userId users.id dell'host che elimina
     * @return bool true = riga aggiornata; false = già eliminata o assente
     */
    public function softDelete(int $id, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE guests SET deleted_at = NOW(), deleted_by = ?"
            . " WHERE id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$userId, $id]);
        return $stmt->rowCount() > 0;
    }

 	public function createImportedGuest($arrivalDate, $departureDate) {
 		if (!is_string($arrivalDate) || !is_string($departureDate)) {
 			return false;
 		}
 		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) {
 			return false;
 		}

  		$checkSql = "SELECT COUNT(*) FROM guests WHERE origine = 'A' AND arrival_date = ? AND departure_date = ? AND deleted_at IS NULL";
 		$checkStmt = $this->pdo->prepare($checkSql);
 		$checkStmt->execute([$arrivalDate, $departureDate]);

 		if ($checkStmt->fetchColumn() > 0) {
 			return true;
 		}

 		$stayDays = (new \DateTime($arrivalDate))->diff(new \DateTime($departureDate))->days;
 		$sql = "INSERT INTO guests (email, origine, arrival_date, departure_date, stay_days) VALUES (?, ?, ?, ?, ?)";
 		$stmt = $this->pdo->prepare($sql);
 		return $stmt->execute(['airbnb-import@system.local', 'A', $arrivalDate, $departureDate, $stayDays]);
 	}

    private function guestSelectSql(): string {
        return "SELECT guests.id as id,
            guests.registration_id as id_Registrazione,
            guests.member_type_id as Tipo,
            guests.origine as Origine,
            guests.first_name as NomeCapofamiglia,
            guests.last_name as CognomeCapofamiglia,
            guests.arrival_date as DataArrivo,
            guests.departure_date as DataPartenza,
            DATEDIFF(guests.departure_date, guests.arrival_date) as GiorniSoggiorno,
            guests.email as Email,
            guests.stato as Anomalia,
            hd.doc_type as doc_code,
            hd.doc_number as doc_number,
            d.descrizione as doc_type_desc,
            (SELECT COUNT(*) FROM registration_tokens WHERE guest_id = guests.id AND used_at IS NOT NULL) as is_confirmed,
            (SELECT guest_count FROM registration_sessions WHERE id = guests.registration_id) as NumOspiti
            FROM guests
            LEFT JOIN registrations hd ON hd.guest_id = guests.id AND hd.member_type_id = guests.member_type_id
            LEFT JOIN lk_documenti d ON d.codice = hd.doc_type";
    }

    private function mapGuest($guest) {
        $guest['registrazioneConfermata'] = (bool)$guest['is_confirmed'];
        unset($guest['is_confirmed']);
        $guest['anomaly'] = $guest['Anomalia'] ?? null;
        unset($guest['Anomalia']);
        return $guest;
    }

    /**
     * Get all guests
     *
     * @param bool   $soloAttivi true = solo quelli non ancora partiti (departure_date >= CURDATE());
     *                           false (default) = tutti i soggiorni della finestra di anno
     * @param int|null $anno     anno della finestra (su departure_date); null (default) = anno corrente
     * @return array
     */
    public function getAllGuests(bool $soloAttivi = false, ?int $anno = null) {
        $yearExpr = ($anno !== null) ? ((int) $anno) : 'YEAR(CURDATE())';
        $where = "YEAR(guests.departure_date) = $yearExpr";
        if ($soloAttivi) {
            $where .= " AND guests.departure_date >= CURDATE()";
        }
        $stmt = $this->pdo->query($this->guestSelectSql() . " WHERE $where AND guests.deleted_at IS NULL ORDER BY guests.arrival_date");
        return array_map(fn($g) => $this->mapGuest($g), $stmt->fetchAll());
    }

    /**
     * Get one guest by id (stessa shape di `getAllGuests`).
     *
     * @return array|null Oggetto guest mappato, oppure `null` se non esiste
     */
    public function getGuest(int $id) {
        $stmt = $this->pdo->prepare($this->guestSelectSql() . " WHERE guests.id = ? AND guests.deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapGuest($row) : null;
    }

    /**
     * Get guests sorted for export (grouped by registration, head first)
     *
     * @return array
     */
    public function getGuestsForExport($registrationId = null) {
        $allExported = [];
        $primaryGuests = [];

        if ($registrationId !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM guests WHERE registration_id = ? AND deleted_at IS NULL ORDER BY id ASC");
            $stmt->execute([$registrationId]);
            $primaryGuests = $stmt->fetchAll();
        } else {
            $stmt = $this->pdo->query("SELECT * FROM guests WHERE deleted_at IS NULL ORDER BY arrival_date ASC");
            $primaryGuests = $stmt->fetchAll();
        }

        foreach ($primaryGuests as $head) {
            $stmt = $this->pdo->prepare("SELECT * FROM registrations WHERE guest_id = ? ORDER BY member_type_id ASC");
            $stmt->execute([$head['id']]);
            $members = $stmt->fetchAll();

            if (empty($members)) {
                // Fallback: if no detailed registration records exist, use the head record
                $allExported[] = $head;
            } else {
                // Use the records from 'registrations' as the source of truth for the list of people,
                // enriching them with the common data (dates, etc.) from the 'guests' record.
                foreach ($members as $member) {
                    $member['registration_id'] = $head['registration_id'];
                    $member['arrival_date'] = $head['arrival_date'] ?? null;
                    $member['departure_date'] = $head['departure_date'] ?? null;
                    $member['stay_days'] = $head['stay_days'] ?? null;
                    $allExported[] = $member;
                }
            }
        }

        return $allExported;
    }

    /**
     * Fetches registration rows enriched with stay data for the ROSS 1000
     * fixed-width export (26 fields / 328 chars per record, docs/tracciato-record-ross1000.md).
     *
     * Registrations already hold the ROSS "Tipo Alloggiato" codes in
     * `member_type_id` (16/17/18 = capo, 19/20 = componente). The `guests` row
     * carries the shared stay data: arrival/departure dates, tour_type (19)
     * and transport_mean (20).
     *
     * Ordering: grouped by stay (guest_id), head (16/17/18) before members
     * (19/20) — spec sez. 3. `member_type_id ASC` already puts 16 before 19/20.
     *
     * @param int|null $guestId optional filter by stay (guests.id)
     * @return array
     */
    public function getRegistrationsForRoss1000(?int $guestId = null): array {
        $sql = "SELECT
                    r.member_type_id,
                    r.first_name, r.last_name, r.gender,
                    r.birth_date, r.birth_city_code, r.birth_prov_code, r.birth_state_code,
                    r.citizenship_state_code,
                    r.res_city_code, r.res_prov_code, r.res_state_code, r.res_address,
                    r.doc_type, r.doc_number, r.doc_issue_city,
                    g.arrival_date, g.departure_date, g.tour_type, g.transport_mean,
                    g.room_id,
                    g.id AS guest_id
                FROM registrations r
                JOIN guests g ON g.id = r.guest_id
                WHERE g.deleted_at IS NULL";
        $params = [];
        if ($guestId !== null) {
            $sql .= " AND g.id = ?";
            $params[] = $guestId;
        }
        $sql .= " ORDER BY g.id ASC, r.member_type_id ASC, r.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Numero di camere attive (ROSS 1000 campo 22, struttura ricettiva).
     * Restituisce 0 se la tabella `rooms` non esiste ancora (deploy in 2 tempi).
     */
    public function countActiveRooms(): int
    {
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms'"
        )->fetchColumn();
        if (!$exists) return 0;
        return (int)$this->pdo->query("SELECT COUNT(*) FROM rooms WHERE active = 1")->fetchColumn();
    }

    /**
     * Totale letti fisici attivi (ROSS 1000 campo 23, struttura ricettiva).
     * Restituisce 0 se la tabella `rooms` non esiste ancora (deploy in 2 tempi).
     */
    public function sumActiveBeds(): int
    {
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms'"
        )->fetchColumn();
        if (!$exists) return 0;
        return (int)$this->pdo->query("SELECT COALESCE(SUM(beds), 0) FROM rooms WHERE active = 1")->fetchColumn();
    }
}