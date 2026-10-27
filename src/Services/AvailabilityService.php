<?php

namespace App\Services;

use App\Config;
use App\Database;
use PDO;

/**
 * API Disponibilità: disponibilità pubblica + blocchi manuali admin.
 *
 * Spec: docs/api-disponibilita-spec-v2.md
 * - §2  intervallo [in, out) — overlap ⇔ A < out_j AND in_j < P
 * - §3  GET pubblico {ok, anno, booked, updated} senza PII
 * - §4  Admin: stato, blocchi GET/POST/DELETE, import-ics
 * - §5  Fonte: guests (confermati/import) + availability_blocks
 * - §5.0 buffer di preparazione (prep_before_days / prep_after_days)
 *
 * Il buffer SI applica agli intervalli di guests: l'effettivo emesso nel GET
 * è [in − prep_before, out + prep_after). NON si applica ai blocchi manuali
 * (l'owner ha scelto le notti esatte, §33e).
 *
 * `in`/`out` sono PAROLE RISERVATE MySQL: in ogni SQL vanno backtickate.
 */
class AvailabilityService
{
    private PDO $pdo;
    private int $prepBefore;   // notti occupate PRIMA del check-in (default 1, §5.0)
    private int $prepAfter;    // notti occupate DOPO il check-out (default 1, §5.0)

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $cfg = Config::getInstance();
        // §5.0: interi ≥ 0; valore non numerico/negativo → default 1
        // (la disponibilità non deve morire per un valore errato)
        $this->prepBefore = $this->nonNegativeInt($cfg->get('availability.prep_before_days', 1), 1);
        $this->prepAfter  = $this->nonNegativeInt($cfg->get('availability.prep_after_days', 1), 1);
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    /** Interi ≥ 0; se non valido (negativo, non numerico, null) → $fallback. */
    private function nonNegativeInt(mixed $v, int $fallback): int
    {
        if (!is_numeric($v)) return $fallback;
        $i = (int) $v;
        return $i >= 0 ? $i : $fallback;
    }

    /**
     * Espande un intervallo GREZZO [in,out) nel suo EFFICACE
     * [in − prepBefore, out + prepAfter) (§2, §5.0).
     * Se i buffer sono 0/0 restituisce l'intervallo invariato.
     *
     * Public: usato anche da IcsImportService per la rilevazione degli overlap
     * tra eventi ICS e l'universo di guest già in banco.
     */
    public function expand(array $g): array
    {
        if ($this->prepBefore === 0 && $this->prepAfter === 0) {
            return $g;
        }
        $dIn  = new \DateTime(((string) $g['in']), new \DateTimeZone('UTC'));
        $dOut = new \DateTime(((string) $g['out']), new \DateTimeZone('UTC'));
        if ($this->prepBefore > 0) $dIn->modify("-{$this->prepBefore} days");
        if ($this->prepAfter  > 0) $dOut->modify("+{$this->prepAfter} days");
        return ['in' => $dIn->format('Y-m-d'), 'out' => $dOut->format('Y-m-d')];
    }

    /**
     * @param string|null $d data ISO o null
     * @return string|null data normalizzata 'YYYY-MM-DD' o null
     */
    private function normalizeDate(?string $d): ?string
    {
        $d = (string) $d;
        if (strlen($d) !== 10) return null;
        $t = strtotime($d);
        if ($t === false) return null;
        return date('Y-m-d', $t);
    }

    // =========================================================================
    // PUBBLICO
    // =========================================================================

    /**
     * GET pubblico: restituisce gli intervalli EFFICACI [in, out) occupati
     * (guests espansi del buffer + blocchi manuali grezzi), filtrati per
     * anno Y (toccano l'anno), ordinati per in (§3.4/§3.7/§5.0).
     *
     * @return array{ok:bool, anno:int, booked:array<int,array{in:string,out:string}>, updated:string|null}
     */
    public function availabilityForYear(int $anno): array
    {
        $firstYear = sprintf("%04d-01-01", $anno);
        $lastYear  = sprintf("%04d-12-31", $anno);

        // (a) prenotazioni reali: ogni riga `guests` con date future/presenti
        //     è una camera già riservata — la presenza in `guests` è essa
        //     stessa l'atto di booking (origine='A' OTA o 'M'/NULL manuale),
        //     indipendentemente dal flusso di check-in (used_at).
        //     NIENTE filtro per anno in SQL: il prefiltro sul GREZZO sbaglierebbe
        //     con il buffer, perché un intervallo appena fuori anno
        //     (es. 2025-12-29/2025-12-31) può diventare 2025-12-28→2026-01-01
        //     dopo espansione (§3.7). Il filtro per anno si fa QUI
        //     SUI VALORI EFFICACI, in PHP.
        $sqlBooked = "
            SELECT g.arrival_date AS `in`, g.departure_date AS `out`
            FROM guests g
            WHERE g.arrival_date IS NOT NULL
              AND g.departure_date IS NOT NULL
              AND g.departure_date >= CURDATE()";

        // (b) blocchi manuali (GREZZI, non espansi — §5.0/§33e).
        //     Il prefiltro qui è sull'intervallo grezzo per anno perché
        //     non c'è espansione: se `in`/`out` "toccano l'anno" lo toccano
        //     anche l'effettivo (identico al grezzo).
        $sqlBlocks = "
            SELECT `in`, `out` FROM availability_blocks
            WHERE `in`  <= :lastYear AND `out` >= :firstYear";

        $rows = [];
        // (a) prenotazioni reali → ESPANDIAMO del buffer (§2, §5.0),
        //     poi filtriamo per anno SUI VALORI EFFICACI (§3.7).
        $stmt = $this->pdo->prepare($sqlBooked);
        $stmt->execute();
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $eff = $this->expand(['in' => (string) $r['in'], 'out' => (string) $r['out']]);
            if ($eff['in'] <= $lastYear && $eff['out'] >= $firstYear) {
                $rows[] = $eff;
            }
        }
        // (b) blocchi manuali → già notti esatte scelte dall'owner: NON espandiamo.
        $stmt = $this->pdo->prepare($sqlBlocks);
        $stmt->execute([':firstYear' => $firstYear, ':lastYear' => $lastYear]);
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = ['in' => (string) $r['in'], 'out' => (string) $r['out']];
        }

        // Dedup + sort: (in, out) unica; ordinata per in poi per out
        // (§5.3: UNION, non UNION ALL)
        $seen  = [];
        $uniq  = [];
        foreach ($rows as $r) {
            $k = $r['in'] . '|' . $r['out'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $uniq[]   = $r;
            }
        }
        usort($uniq, fn($a, $b) => [$a['in'], $a['out']] <=> [$b['in'], $b['out']]);

        return [
            'ok'      => true,
            'anno'    => $anno,
            'booked'  => $uniq,
            'updated' => $this->lastUpdated(),
        ];
    }

    /**
     * Ultimo aggiornamento tra i due set (guest attivi + blocchi),
     * in ISO-8601 UTC. null se mai. (Usato nel campo `updated` del GET.)
     */
    public function lastUpdated(): ?string
    {
        // Due query separate (più leggibile di un GREATEST con subquery):
        // ogni riga `guests` con stay in corso/futuro conta (vedi availabilityForYear).
        $sql1 = "SELECT MAX(created_at) FROM guests
                 WHERE departure_date >= CURDATE()";
        $ts1 = $this->pdo->query($sql1)->fetchColumn();
        $ts2 = $this->pdo->query("SELECT MAX(created_at) FROM availability_blocks")->fetchColumn();
        $ts  = ($ts1 !== false && $ts1 !== null) ? $ts1 : null;
        if ($ts2 !== false && $ts2 !== null && ($ts === null || strcmp((string)$ts2, (string)$ts) > 0)) $ts = $ts2;
        if ($ts === false || $ts === null) return null;
        $t = strtotime((string)$ts);
        return gmdate('Y-m-d\TH:i:s\Z', $t); // ISO-8601 UTC con Z (stabile, §10)
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    /**
     * Admin: blocca un intervallo. Sovrapposizione = rifiuto (v1 §2).
     *
     * @return array{ok:bool, id?:int, err?:string}
     */
    public function addBlock(string $in, string $out, ?string $reason, int $userId): array
    {
        $in     = $this->normalizeDate($in);
        $out    = $this->normalizeDate($out);
        $reason = ($reason !== null && $reason !== '') ? substr($reason, 0, 120) : null;

        if (!$in || !$out || $in >= $out) {
            return ['ok' => false, 'err' => 'bad-range'];
        }
        if ($this->overlaps($in, $out)) {
            return ['ok' => false, 'err' => 'overlap-with'];
        }

        // INSERT … ON DUPLICATE UPDATE: se {in, out} già presente con
        // stesso in/out ma diversa reason → aggiorna solo reason, idem id
        $sql = "
            INSERT INTO availability_blocks (`in`, `out`, `reason`, `created_by`)
            VALUES (:in, :out, :reason, :user)
            ON DUPLICATE KEY UPDATE
                `reason`     = VALUES(`reason`),
                `created_by` = VALUES(`created_by`)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':in'     => $in,
            ':out'    => $out,
            ':reason' => $reason,
            ':user'   => $userId,
        ]);

        // rowCount() = 1 → riga nuova inserita
        // rowCount() = 2 → riga esistente UPDATE'd (stesso in/out)
        // rowCount() = 0 → non dovrebbe accadere qui (l'overlap lo ha già scartato)
        // in ogni caso: leggiamo l'id dalla tabella per sicurezza
        $sel = $this->pdo->prepare("SELECT id FROM availability_blocks WHERE `in` = :in AND `out` = :out");
        $sel->execute([':in' => $in, ':out' => $out]);
        $id = (int) $sel->fetchColumn();
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Admin: rimuove un blocco esatto {in,out}. 404 se assente.
     */
    public function removeBlock(string $in, string $out): array
    {
        $in  = $this->normalizeDate($in);
        $out = $this->normalizeDate($out);
        if (!$in || !$out) return ['ok' => false, 'err' => 'bad-range'];

        $sql = "DELETE FROM availability_blocks WHERE `in` = :in AND `out` = :out";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':in' => $in, ':out' => $out]);
        return $stmt->rowCount() > 0
            ? ['ok' => true]
            : ['ok' => false, 'err' => 'not-found'];
    }

    /**
     * Admin: lista dei blocchi manuali per anno
     * (`in <= lastYear AND out >= firstYear` — intervalli che toccano l'anno).
     */
    public function listBlocks(int $anno): array
    {
        $firstYear = sprintf("%04d-01-01", $anno);
        $lastYear  = sprintf("%04d-12-31", $anno);
        $sql = "SELECT `in`, `out`, `reason`, `created_by`, `created_at`
                FROM availability_blocks
                WHERE `in` <= :lastYear AND `out` >= :firstYear
                ORDER BY `in`, `out`";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':firstYear' => $firstYear, ':lastYear' => $lastYear]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Admin: stato completo (booked effettivi + blocks grezzi + prossima data disponibile).
     */
    public function adminState(int $anno): array
    {
        $availability = $this->availabilityForYear($anno);
        $blocks       = $this->listBlocks($anno);

        // prossima data libera: la prima data in cui [A, A+1) NON sovrappone
        // nessun intervallo EFFICACE (§5.3) né blocco manuale, partendo da domani.
        // Scan di 180 giorni: più di quanto serve per una struttura singola.
        $nextFree = null;
        $start    = (new \DateTime('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d');
        for ($i = 0; $i < 180; $i++) {
            $A     = date('Y-m-d', strtotime($start . " +$i days"));
            $P     = date('Y-m-d', strtotime($start . " +".($i + 1)." days"));
            $collide = false;
            foreach ($availability['booked'] as $b) {
                if ($A < $b['out'] && $b['in'] < $P) { $collide = true; break; }
            }
            if (!$collide) { $nextFree = $A; break; }
        }

        return [
            'ok'        => true,
            'anno'      => $anno,
            'booked'    => $availability['booked'],
            'blocks'    => $blocks,
            'updated'   => $availability['updated'],
            'next_free' => $nextFree,
        ];
    }

    /**
     * Sovrapposizione di un blocco candidato [A, P) con l'UNIVERSE occupato
     * = intervalli **efficaci** (guests espansi, §2/§5.0) ∪ blocchi manuali
     * (GREZZI — l'owner li sceglie esatti, §5.0).
     * overlap ⇔ A < out_j AND in_j < P (per almeno un j nell'universo).
     *
     * Nota: il **candidato** NON viene espanso: il buffer è già la
     * "zona cuscinetto" attorno alle prenotazioni reali, quindi un blocco
     * nuovo non può cadere nella prep/turnover di un soggiorno — e
     * viceversa. Espandere il candidato produrrebbe un doppio conteggio.
     *
     * $excludeGuestId   (default null): salta la riga `guests` con quel id.
     *   Serve a `GuestManager::updateGuest` (che altrimenti matcha sé stesso).
     * $excludeOrigine   (default null): salta le righe `guests` con quell'`origine`.
     *   Serve a `IcsImportService` (che cancella/ricrea le righe 'A': non devono
     *   contare come universo per un nuovo booking ICS, altrimenti re-import ogni
     *   booking matcha sé stesso → 409 spurio).
     */
    public function overlaps(string $A, string $P, ?int $excludeGuestId = null, ?string $excludeOrigine = null): bool
    {
        if ($A == $P) return true;         // degenerate: stesso in/out → overlap
        return count($this->conflicts($A, $P, $excludeGuestId, $excludeOrigine)) > 0;
    }

    /**
     * Lista dei conflitti di un intervallo candidato [A, P) contro l'universo
     * occupato (guests espansi del buffer §5.0 + blocchi manuali grezzi).
     * Ritorna [{src:'guest'|'block', id:int|null, in:string, out:string, expanded:bool}]
     * ordinati per in, out. Nessun PII (solo id + date).
     */
    public function conflicts(string $A, string $P, ?int $excludeGuestId = null, ?string $excludeOrigine = null): array
    {
        $out = [];

        // (a) prenotazioni reali efficaci (ogni riga `guests` futura con date conta)
        //     — tranne le righe di $excludeOrigine (es. 'A' in IcsImportService,
        //     che vengono cancellate/rigenerate da quell'import).
        $sql = "SELECT g.id, g.arrival_date AS `in`, g.departure_date AS `out`, g.origine
                FROM guests g
                WHERE g.arrival_date IS NOT NULL AND g.departure_date IS NOT NULL
                  AND g.departure_date >= CURDATE()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($excludeGuestId !== null && (int)$r['id'] === $excludeGuestId) continue;
            if ($excludeOrigine !== null && (string)$r['origine'] === $excludeOrigine) continue;
            $e = $this->expand(['in' => (string) $r['in'], 'out' => (string) $r['out']]);
            if ($A < $e['out'] && $e['in'] < $P) {
                $out[] = [
                    'src'      => 'guest',
                    'id'       => (int)$r['id'],
                    'in'       => $e['in'],
                    'out'      => $e['out'],
                    'expanded' => true,
                ];
            }
        }

        // (b) blocchi manuali (grezzi, non espansi)
        $sql2  = "SELECT id, `in`, `out` FROM availability_blocks";
        $stmt2 = $this->pdo->prepare($sql2);
        $stmt2->execute();
        while (($r = $stmt2->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($A < (string)$r['out'] && (string)$r['in'] < $P) {
                $out[] = [
                    'src'      => 'block',
                    'id'       => (int)$r['id'],
                    'in'       => (string)$r['in'],
                    'out'      => (string)$r['out'],
                    'expanded' => false,
                ];
            }
        }

        usort($out, fn($a, $b) => [$a['in'], $a['out'], $a['id']] <=> [$b['in'], $b['out'], $b['id']]);
        return $out;
    }
}
