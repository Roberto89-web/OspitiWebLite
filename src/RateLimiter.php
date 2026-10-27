<?php

namespace App;

use App\Exceptions\AppException;

/**
 * Rate limiting generico basato su DB (tabella `rate_limits`).
 *
 * Window fissa per (action, chiave): il contatore si reinizializza quando la
 * prima hit è più vecchia di `window` secondi. Le soglie e le finestre sono
 * lette da `rate_limits.<action>.max` / `.window` in config.yaml (override env
 * APP_RATE_LIMITS_<ACTION>_MAX / _WINDOW).
 *
 * È inteso per mitigare brute-force e abuso delle rotte pubbliche (login,
 * invio registrazione). Non è una fonte di trust per l'autenticazione.
 */
class RateLimiter {
    public const LOGIN           = 'login';
    public const REGISTER_SUBMIT = 'register_submit';
    public const REGISTER_TRAVEL = 'register_travel';

    /**
     * Incrementa il contatore per (action, chiave) e getta 429 se la soglia
     * è superata. Transazionale per evitare race su incrementi concorrenti.
     *
     * @param string $action chiave logica (LOGIN / REGISTER_SUBMIT / REGISTER_TRAVEL)
     * @param string $key    chiave di conteggio (IP, token di registrazione, ...)
     * @throws AppException (HTTP 429) se il limite è superato
     */
    public static function guard(string $action, string $key): void {
        $max    = (int) Config::getInstance()->get('rate_limits.' . $action . '.max', 10);
        $window = (int) Config::getInstance()->get('rate_limits.' . $action . '.window', 3600);
        if ($max < 1) $max = 1;
        if ($window < 1) $window = 1;
        if ($key === '') $key = 'empty';

        $pdo = Database::getInstance();
        $now = \time();
        $counter = 1;
        $firstHit = $now;

        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare(
                "SELECT first_hit_at, counter FROM rate_limits WHERE action = ? AND `key` = ? FOR UPDATE"
            );
            $sel->execute([$action, $key]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);

            if ($row && ((int) $row['first_hit_at']) >= $now - $window) {
                $counter = (int) $row['counter'] + 1;
                $firstHit = (int) $row['first_hit_at'];
            }

            if ($row) {
                $upd = $pdo->prepare(
                    "UPDATE rate_limits SET first_hit_at = ?, counter = ? WHERE action = ? AND `key` = ?"
                );
                $upd->execute([$firstHit, $counter, $action, $key]);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO rate_limits (action, `key`, first_hit_at, counter) VALUES (?, ?, ?, ?)"
                );
                $ins->execute([$action, $key, $firstHit, $counter]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($counter > $max) {
            throw (new AppException('Troppi tentativi. Riprova tra qualche minuto.'))->setHttpStatusCode(429);
        }
    }
}
