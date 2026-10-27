<?php

namespace App\Services;

use App\Database;
use App\Exceptions\AppException;

/**
 * Gestione amministrativa (CRUD) delle camere e delle tipologie.
 *
 *  - `room_types` : piano tariffario per tipologia (code, name, capacity, beds_default, active).
 *  - `rooms`      : camere fisiche assegnabili (room_type_id, label, beds, active).
 *
 * Soft-delete: `active = 0`. Le righe non vengono mai cancellate fisicamente
 * (vincoli FK su rate_rules / daily_rates / availability_blocks / guests).
 *
 * Convezioni (identiche a PricingService):
 *  - importi come stringa decimale (mai float); DDL DECIMAL(12,2)
 *  - risposte {ok:true,data:...} / {ok:false,err:...} gestite dal caller (index.php)
 *  - errori come AppException::setHttpStatusCode(...)
 */
class RoomManagementService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // ──────────────────── helpers ────────────────────

    private function tableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function requireTable(string $name): void
    {
        if (!$this->tableExists($name)) {
            throw (new AppException("Tabella '$name' assente — eseguire prima la migration Fase 3 (12_rooms.sql)"))->setHttpStatusCode(409);
        }
    }

    private static function positiveInt($v, int $min = 1, int $max = 20): int
    {
        if (!is_numeric($v)) {
            throw (new AppException("Valore intero obbligatorio (tra $min e $max)"))->setHttpStatusCode(400);
        }
        $n = (int)$v;
        if ((float)$v != (float)$n || $n < $min || $n > $max) {
            throw (new AppException("Valore intero obbligatorio (tra $min e $max)"))->setHttpStatusCode(400);
        }
        return $n;
    }

    private static function nonEmptyString($v, int $max = 100): string
    {
        if (!is_string($v) || trim($v) === '') {
            throw (new AppException('Campo testo obbligatorio'))->setHttpStatusCode(400);
        }
        $s = trim($v);
        if (mb_strlen($s) > $max) {
            throw (new AppException("Lunghezza massima $max caratteri (ricevuti " . mb_strlen($s) . ")"))->setHttpStatusCode(400);
        }
        return $s;
    }

    /** Coerisce un valore `active` in 0/1. */
    private static function toActive($v): int
    {
        if ($v === true || $v === 1 || $v === '1') {
            return 1;
        }
        if ($v === false || $v === 0 || $v === '0') {
            return 0;
        }
        throw (new AppException('Valore booleano non valido (usare true/false)'))->setHttpStatusCode(400);
    }

    // ──────────────────── ROOMS ────────────────────

    /**
     * @return array ['rooms'=>Room[], 'room_types'=>RoomType[]]
     */
    public function listRooms(): array
    {
        $this->requireTable('room_types');
        $this->requireTable('rooms');

        $rt = $this->pdo->query('SELECT id, code, name, capacity, beds_default, active FROM room_types ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rt as &$r) {
            $r['capacity'] = (int)$r['capacity'];
            $r['beds_default'] = (int)$r['beds_default'];
            $r['active'] = ((int)$r['active']) === 1;
        }
        unset($r);

        $rooms = $this->pdo->query(
            'SELECT r.id, r.room_type_id, r.label, r.beds, r.active,
                    rt.code AS room_type_code, rt.name AS room_type_name, rt.capacity AS capacity
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             ORDER BY r.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rooms as &$rm) {
            $rm['beds'] = (int)$rm['beds'];
            $rm['capacity'] = $rm['capacity'] !== null ? (int)$rm['capacity'] : null;
            $rm['active'] = ((int)$rm['active']) === 1;
        }
        unset($rm);

        return ['rooms' => $rooms, 'room_types' => $rt];
    }

    /**
     * Crea una camera fisica.
     * @param array $d {room_type_id:int, label:string, beds:int}
     */
    public function createRoom(array $d): array
    {
        $this->requireTable('room_types');
        $this->requireTable('rooms');

        $rtId  = $this->positiveInt($d['room_type_id'] ?? null, 1, 1_000_000);
        $label = self::nonEmptyString($d['label'] ?? '');
        $beds  = self::positiveInt($d['beds'] ?? 1, 1, 20);

        // FK check
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM room_types WHERE id = ?');
        $stmt->execute([$rtId]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw (new AppException('Tipologia camera non valida (room_type_id sconosciuto)'))->setHttpStatusCode(400);
        }

        // UNIQUE (room_type_id, label)
        $dup = $this->pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_type_id = ? AND label = ?');
        $dup->execute([$rtId, $label]);
        if ((int)$dup->fetchColumn() > 0) {
            throw (new AppException('Esiste già una camera con etichetta "' . $label . '" per questa tipologia'))->setHttpStatusCode(409);
        }

        $this->pdo->prepare('INSERT INTO rooms (room_type_id, label, beds, active) VALUES (?, ?, ?, 1)')
            ->execute([$rtId, $label, $beds]);
        $id = (int)$this->pdo->lastInsertId();

        return $this->getRoom($id);
    }

    /**
     * Aggiorna una camera.
     * @param int $id
     * @param array $d {label?, beds?, room_type_id?, active?}
     */
    public function updateRoom(int $id, array $d): array
    {
        $this->requireTable('rooms');
        $current = $this->getRoomRaw($id);
        if ($current === null) {
            throw (new AppException('Camera non trovata'))->setHttpStatusCode(404);
        }

        $label  = array_key_exists('label', $d) && $d['label'] !== null ? self::nonEmptyString($d['label']) : (string)$current['label'];
        $beds   = array_key_exists('beds', $d) && $d['beds'] !== null ? self::positiveInt($d['beds'], 1, 20) : (int)$current['beds'];
        $rtId   = array_key_exists('room_type_id', $d) && $d['room_type_id'] !== null ? $this->positiveInt($d['room_type_id'], 1, 1_000_000) : (int)$current['room_type_id'];
        $active = array_key_exists('active', $d) ? self::toActive($d['active']) : (int)$current['active'];

        // FK check se cambia tipologia
        if ($rtId !== (int)$current['room_type_id']) {
            $fk = $this->pdo->prepare('SELECT COUNT(*) FROM room_types WHERE id = ?');
            $fk->execute([$rtId]);
            if ((int)$fk->fetchColumn() === 0) {
                throw (new AppException('Tipologia camera non valida (room_type_id sconosciuto)'))->setHttpStatusCode(400);
            }
        }

        // UNIQUE (room_type_id, label) — esclusa questa riga
        $dup = $this->pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_type_id = ? AND label = ? AND id <> ?');
        $dup->execute([$rtId, $label, $id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw (new AppException('Esiste già una camera con etichetta "' . $label . '" per questa tipologia'))->setHttpStatusCode(409);
        }

        $this->pdo->prepare('UPDATE rooms SET room_type_id = ?, label = ?, beds = ?, active = ? WHERE id = ?')
            ->execute([$rtId, $label, $beds, $active, $id]);

        return $this->getRoom($id);
    }

    /**
     * Soft-delete di una camera (active=0). 409 se ospita soggiorni attivi.
     */
    public function deleteRoom(int $id): array
    {
        $this->requireTable('rooms');
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rooms WHERE id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw (new AppException('Camera non trovata'))->setHttpStatusCode(404);
        }

        // Occupazione: qualsiasi soggiorno attivo (deleted_at IS NULL) riferito a questa camera.
        $occ = $this->pdo->prepare(
            'SELECT COUNT(*) FROM guests
             WHERE deleted_at IS NULL
               AND room_id = ?
               AND (
                     (arrival_date IS NOT NULL AND departure_date IS NOT NULL
                      AND arrival_date <= CURDATE() AND departure_date > CURDATE())
                  OR arrival_date > CURDATE()
               )'
        );
        $occ->execute([$id]);
        if ((int)$occ->fetchColumn() > 0) {
            throw (new AppException('La camera ha soggiorni in programma o in corso — non può essere disattivata'))->setHttpStatusCode(409);
        }

        $this->pdo->prepare('UPDATE rooms SET active = 0 WHERE id = ?')->execute([$id]);
        return $this->getRoom($id);
    }

    private function getRoomRaw(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function getRoom(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.room_type_id, r.label, r.beds, r.active,
                    rt.code AS room_type_code, rt.name AS room_type_name, rt.capacity AS capacity
             FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            throw (new AppException('Camera non trovata'))->setHttpStatusCode(404);
        }
        $r['beds'] = (int)$r['beds'];
        $r['capacity'] = $r['capacity'] !== null ? (int)$r['capacity'] : null;
        $r['active'] = ((int)$r['active']) === 1;
        return $r;
    }

    // ──────────────────── ROOM_TYPES ────────────────────

    /**
     * @return RoomType[]
     */
    public function listRoomTypes(): array
    {
        $this->requireTable('room_types');
        $rt = $this->pdo->query('SELECT id, code, name, capacity, beds_default, active FROM room_types ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rt as &$r) {
            $r['capacity'] = (int)$r['capacity'];
            $r['beds_default'] = (int)$r['beds_default'];
            $r['active'] = ((int)$r['active']) === 1;
        }
        unset($r);
        return $rt;
    }

    /**
     * Crea una tipologia. 409 su codice duplicato.
     * @param array $d {code, name, capacity, beds_default}
     */
    public function createRoomType(array $d): array
    {
        $this->requireTable('room_types');

        $code = self::nonEmptyString($d['code'] ?? '', 20);
        if (!preg_match('/^[A-Z][A-Z0-9_-]*$/i', $code)) {
            throw (new AppException("Codice non valido (usare lettere/numeri, es. 'STD'). Ricevuto: '$code'"))->setHttpStatusCode(400);
        }
        $name = self::nonEmptyString($d['name'] ?? '');
        $cap  = self::positiveInt($d['capacity'] ?? null, 1, 20);
        $beds = self::positiveInt($d['beds_default'] ?? 1, 1, 20);

        $dup = $this->pdo->prepare('SELECT COUNT(*) FROM room_types WHERE code = ?');
        $dup->execute([$code]);
        if ((int)$dup->fetchColumn() > 0) {
            throw (new AppException("Esiste già una tipologia con codice '$code' (il codice non è modificabile)"))->setHttpStatusCode(409);
        }

        $this->pdo->prepare('INSERT INTO room_types (code, name, capacity, beds_default, active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$code, $name, $cap, $beds]);
        $id = (int)$this->pdo->lastInsertId();

        return $this->getRoomType($id);
    }

    /**
     * Aggiorna una tipologia. Il `code` è IMMODIFICABILE (sarebbe un break contrattuale).
     * @param int $id
     * @param array $d {name?, capacity?, beds_default?, active?}
     */
    public function updateRoomType(int $id, array $d): array
    {
        $this->requireTable('room_types');
        $cur = $this->pdo->prepare('SELECT * FROM room_types WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw (new AppException('Tipologia non trovata'))->setHttpStatusCode(404);
        }
        if (array_key_exists('code', $d)) {
            throw (new AppException('Il codice tipologia non è modificabile (usare una nuova tipologia)'))->setHttpStatusCode(400);
        }

        $name   = array_key_exists('name', $d) && $d['name'] !== null ? self::nonEmptyString($d['name']) : (string)$row['name'];
        $cap    = array_key_exists('capacity', $d) && $d['capacity'] !== null ? self::positiveInt($d['capacity'], 1, 20) : (int)$row['capacity'];
        $beds   = array_key_exists('beds_default', $d) && $d['beds_default'] !== null ? self::positiveInt($d['beds_default'], 1, 20) : (int)$row['beds_default'];
        $active = array_key_exists('active', $d) ? self::toActive($d['active']) : (int)$row['active'];

        $this->pdo->prepare('UPDATE room_types SET name = ?, capacity = ?, beds_default = ?, active = ? WHERE id = ?')
            ->execute([$name, $cap, $beds, $active, $id]);

        return $this->getRoomType($id);
    }

    /**
     * Soft-delete di una tipologia (active=0). 409 se ha camere attive.
     */
    public function deleteRoomType(int $id): array
    {
        $this->requireTable('room_types');
        $chk = $this->pdo->prepare('SELECT COUNT(*) FROM room_types WHERE id = ?');
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() === 0) {
            throw (new AppException('Tipologia non trovata'))->setHttpStatusCode(404);
        }

        // Se ci sono ancora camere ATTIVE di questa tipologia → blocco.
        $rt = $this->pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_type_id = ? AND active = 1');
        $rt->execute([$id]);
        if ((int)$rt->fetchColumn() > 0) {
            throw (new AppException('La tipologia ha ancora camere attive — prima disattiva le stanze (rooms)'))->setHttpStatusCode(409);
        }

        $this->pdo->prepare('UPDATE room_types SET active = 0 WHERE id = ?')->execute([$id]);
        return $this->getRoomType($id);
    }

    private function getRoomType(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name, capacity, beds_default, active FROM room_types WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($r === false) {
            throw (new AppException('Tipologia non trovata'))->setHttpStatusCode(404);
        }
        $r['capacity'] = (int)$r['capacity'];
        $r['beds_default'] = (int)$r['beds_default'];
        $r['active'] = ((int)$r['active']) === 1;
        return $r;
    }
}
