<?php

namespace App;

use PDO;
use Exception;

class LookupManager
{
    private PDO $db;
    private array $allowedTables = [
        'lk_comuni',
        'lk_stati',
        'lk_documenti',
        'lk_tipo_alloggiato'
    ];

    private array $allowedValueTables = [
        'lk_tipo_turismo',
        'lk_mezzo_trasporto'
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Retrieves a list of codes and descriptions from the specified lookup table.
     *
     * @param string $table The name of the table to query.
     * @return array An array of associative arrays ['codice' => ..., 'descrizione' => ...].
     * @throws Exception If the table name is not in the whitelist.
     */
    public function getLookups(string $table): array
    {
        if (!in_array($table, $this->allowedTables)) {
            throw new Exception("Invalid table name provided for lookup.");
        }

        $stmt = $this->db->prepare("SELECT codice, descrizione FROM {$table} ORDER BY descrizione ASC");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves lookup values from a "valore"-keyed table (ROSS 1000 travel lookups).
     *
     * These tables use `valore` as the primary key (the exact string exported,
     * e.g. "Balneare", "Auto+Pullman") instead of `codice`/`descrizione`.
     * Map `valore -> codice` so the existing register_form.js `populateSelect`
     * contract (option.value = codice, option.textContent = descrizione) works
     * unchanged.
     *
     * @param string $table The name of the value table to query.
     * @return array An array of associative arrays ['codice' => ..., 'descrizione' => ...].
     * @throws Exception If the table name is not in the value whitelist.
     */
    public function getValori(string $table): array
    {
        if (!in_array($table, $this->allowedValueTables)) {
            throw new Exception("Invalid value table name provided for lookup.");
        }

        $stmt = $this->db->prepare("SELECT valore AS codice, COALESCE(descrizione, valore) AS descrizione FROM {$table} ORDER BY descrizione ASC");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves a distinct list of provinces from the comuni table.
     *
     * @return array An array of strings representing province codes.
     */
    public function getProvinces(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT provincia FROM lk_comuni WHERE provincia IS NOT NULL ORDER BY provincia ASC");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Retrieves a list of municipalities for a given province that are still valid.
     *
     * @param string $provincia The province code.
     * @return array An array of associative arrays ['codice' => ..., 'descrizione' => ...].
     */
    /**
     * Retrieves all municipalities still valid, including the province code
     * so the client can disambiguate homonymous municipalities.
     *
     * @return array An array of ['codice' => ..., 'descrizione' => ..., 'provincia' => ...].
     */
    public function getComuni(): array
    {
        $stmt = $this->db->prepare(
            "SELECT codice, descrizione, provincia FROM lk_comuni
             WHERE (data_fine_val IS NULL OR data_fine_val >= NOW())
             ORDER BY descrizione ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getComuniByProvince(string $provincia): array
    {
        $stmt = $this->db->prepare("SELECT codice, descrizione FROM lk_comuni WHERE provincia = ? AND (data_fine_val IS NULL OR data_fine_val >= NOW()) ORDER BY descrizione ASC");
        $stmt->execute([$provincia]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
