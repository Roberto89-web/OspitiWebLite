<?php
/**
 * Database connection configuration
 */
namespace App;

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $config = Config::getInstance();
        $host = $config->get('database.host');
        $db   = $config->get('database.nome');
        $user = $config->get('database.user');
        $pass = $config->get('database.pass');
        $charset = $config->get('database.charset');

        // Fail-fast di sicurezza: in produzione NON connettersi come 'root'.
        // In dev (Docker) user=root è accettabile. Questo impedisce di
        // dimenticare un user privilegiato online.
        if ($config->isProduction() && in_array(strtolower((string)$user), ['root', ''], true)) {
            throw new RuntimeException(
                "In produzione database.user non può essere 'root'. "
                . "Imposta APP_DATABASE_USER con un user dai privilegi minimi "
                . "(GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES). "
                . "Vedi README §Sicurezza."
            );
        }

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [

            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->connection;
    }
}
