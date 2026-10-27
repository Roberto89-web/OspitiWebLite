<?php

namespace App;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Config
{
    private static ?Config $instance = null;
    private array $data = [];

    /**
     * Singleton: il file YAML viene letto una sola volta per request.
     */
    public static function getInstance(string $filePath = null): self
    {
        if (self::$instance === null) {
            // Default path if not provided
            $filePath = $filePath ?? __DIR__ . '/../config/config.yaml';
            self::$instance = new self($filePath);
        }
        return self::$instance;
    }

    private function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File di configurazione non trovato: {$filePath}");
        }

        try {
            $this->data = Yaml::parseFile($filePath) ?? [];
        } catch (ParseException $e) {
            throw new RuntimeException("YAML non valido: " . $e->getMessage());
        }
    }

    /**
     * Legge un valore tramite dot notation.
     *
     * Esempi:
     *   get('database.host')          → 'localhost'
     *   get('cors.origini_consentite') → ['https://myapp.com', ...]
     *   get('server.porta', 8080)     → valore o default
     */
    public function get(string $chiave, mixed $default = null): mixed
    {
        // Prova a leggere da variabile d'ambiente (es: database.host -> APP_DATABASE_HOST)
        $envKey = 'APP_' . strtoupper(str_replace('.', '_', $chiave));
        $envValue = getenv($envKey);

        // Fallback per hosting dove SetEnv non viene esposto via getenv() ma solo tramite $_SERVER
        if ($envValue === false && isset($_SERVER[$envKey])) {
            $envValue = $_SERVER[$envKey];
        }

        if ($envValue !== false) {
            return $envValue;
        }

        $segmenti = explode('.', $chiave);
        $valore   = $this->data;

        foreach ($segmenti as $segmento) {
            if (!is_array($valore) || !array_key_exists($segmento, $valore)) {
                return $default;
            }
            $valore = $valore[$segmento];
        }

        return $valore;
    }

    /**
     * Verifica se l'applicazione è in ambiente di produzione.
     */
    public function isProduction(): bool
    {
        return $this->get('app.ambiente') === 'production';
    }

    /**
     * Restituisce un'intera sezione come array.
     *
     * Esempio: getSezione('database') → ['host' => 'localhost', ...]
     */
    public function getSezione(string $sezione): array
    {
        return $this->data[$sezione] ?? [];
    }

    /**
     * Verifica se una chiave esiste.
     */
    public function ha(string $chiave): bool
    {
        return $this->get($chiave) !== null;
    }
}
