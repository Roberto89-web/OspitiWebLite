<?php

namespace App;

class Request {
    /**
     * Get the JSON body of the request as an associative array.
     */
    public static function getJsonInput(): array {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a specific header value.
     */
    public static function getHeader(string $name): ?string {
        $headers = getallheaders();
        // Case-insensitive lookup
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Get the Bearer token from the Authorization header.
     */
    public static function getBearerToken(): ?string {
        $header = self::getHeader('Authorization');
        if (!$header) {
            return null;
        }
        return str_replace('Bearer ', '', $header);
    }

    /**
     * Get the lookup session token from cookies.
     */
    public static function getSessionToken(): ?string {
        return $_COOKIE['lookup_session'] ?? null;
    }

    /**
     * Get a query parameter.
     */
    public static function getQuery(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    /**
     * Resolve l'IP del client reale.
     *
     * `REMOTE_ADDR` è sempre la fonte primaria: è l'IP del peer TCP e NON può
     * essere falsificato dal client. `X-Forwarded-For` è spoofabile da chiunque
     * (basta inviare un header) se il reverse proxy non lo forza/override.
     *
     * La lettura di `X-Forwarded-For` (left-most entry) è quindi abilitata
     * **SOLO** se l'IP di `REMOTE_ADDR` appartiene a una lista di reverse
     * proxy "trusted" dichiarata in config `security.trusted_proxy_ips`.
     * Default: lista vuota → si usa solo `REMOTE_ADDR` (comportamento safe).
     *
     * Implica in deploy:
     *   - dev (Docker, 127.0.0.1) → lista vuota, ok.
     *   - prod dietro Apache reverse proxy su `app.robertoruzzi.com` →
     *     `security.trusted_proxy_ips: 203.0.113.10` (o l'IP del VPS), così
     *     `XFF` (aggiunto dal proxy) è usato per il rate limit e l'IP del
     *     client reale non è l'IP di Apache.
     */
    public static function getRemoteIp(): string {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? '0.0.0.0');

        $cfg = Config::getInstance();
        $trustedRaw = (string) $cfg->get('security.trusted_proxy_ips', '');
        $trusted = array_values(array_filter(array_map('trim', explode(',', $trustedRaw)), 'strlen'));

        if (!empty($trusted) && in_array($remoteAddr, $trusted, true)) {
            $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $parts = array_values(array_filter(array_map('trim', explode(',', $xff)), fn($v) => $v !== ''));
                if ($parts) {
                    return (string) $parts[0];
                }
            }
        }

        return $remoteAddr;
    }
}
