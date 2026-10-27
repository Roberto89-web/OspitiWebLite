<?php

namespace App;

use App\Exceptions\UnauthorizedException;


class Auth {
    private static ?int $currentUserId = null;

    /**
     * Validates the token from the request headers or cookies.
     * Throws UnauthorizedException if missing or invalid.
     *
     * @return int The validated user ID (or -1 for guest sessions)
     * @throws UnauthorizedException
     */
    public static function requireToken(): int {
        // 1. Try Bearer token (Users/Admins)
        $token = Request::getBearerToken();
        $userId = self::validateToken($token);

        if ($userId) {
            self::$currentUserId = $userId;
            return $userId;
        }

        // 2. Try Session token from cookie (guest lookup access)
        $sessionToken = Request::getSessionToken();

        if (self::validateSessionToken($sessionToken)) {
            self::$currentUserId = -1; // Special ID for guest lookup access
            return -1;
        }

        throw new UnauthorizedException();
    }

    /**
     * Richiede un utente admin autenticato (Bearer token valido).
     * Rifiuta la session cookie ospite (userId = -1) che requireToken()
     * altrimenti accetterebbe: le rotte di gestione DEVONO richiedere
     * l'autenticazione admin (spec §2, §9.7-§9.9).
     *
     * @return int L'ID utente admin corrente
     * @throws UnauthorizedException
     */
    public static function requireAdmin(): int {
        $userId = self::requireToken();

        // -1 = session ospite (lookup cookie): non è un admin
        if ($userId === -1) {
            throw new UnauthorizedException();
        }

        return $userId;
    }

    /**
     * Validate a lookup session token.
     *
     * @param string|null $token
     * @return bool
     */
    public static function validateSessionToken(?string $token): bool {
        if (empty($token)) return false;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT 1 FROM lk_sessions WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        return (bool)$stmt->fetch();
    }

    /**
     * Generate a short-lived lookup session token.
     *
     * @return string
     */
    public static function generateSessionToken(): string {
        $pdo = Database::getInstance();
        $token = bin2hex(random_bytes(32));

        // Session expires in 2 hours
        $expiresAt = date('Y-m-d H:i:s', strtotime("+2 hours"));

        $stmt = $pdo->prepare("INSERT INTO lk_sessions (token, expires_at) VALUES (?, ?)");
        $stmt->execute([$token, $expiresAt]);

        return $token;
    }

    /**
     * Emette un token CSRF una-tantum legato a una sessione lookup esistente
     * (lookup_session). Il token non è prevedibile e, essendo vincolato al cookie
     * SameSite=Strict, mitiga gli attacchi cross-site: una richiesta indotta da
     * un sito esterno non include né il cookie né il token del form.
     *
     * @param string $sessionToken token del cookie lookup_session
     * @return string il token CSRF
     * @throws \App\Exceptions\UnauthorizedException se la sessione lookup non è valida
     */
    public static function issueCsrf(string $sessionToken = ''): string {
        $pdo = Database::getInstance();
        $csrf = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+2 days"));

        $stmt = $pdo->prepare("INSERT INTO csrf_tokens (csrf, lookup_token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$csrf, $sessionToken, $expiresAt]);

        return $csrf;
    }

    /**
     * Verifica e CONSUMA (una tantum) un token CSRF. UPDATE atomico: agisce solo
     * su una riga ancora valida (non usata, non scaduta). affectedRows=0 → token
     * mancante/riutilizzato/scaduto → rifiuto. La single-use + same-site del
     * cookie lookup_session (Strict) mitigano cross-site e replay.
     *
     * @param string $csrf token CSRF dal campo hidden del form
     * @return bool
     */
    public static function consumeCsrf(string $csrf): bool {
        if ($csrf === '') {
            return false;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE csrf_tokens SET used_at = NOW()
             WHERE csrf = ? AND used_at IS NULL AND expires_at > NOW()"
        );
        $stmt->execute([$csrf]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Verifica la validità di un token CSRF SENZA consumarlo (idempotente: si può
     * richiamare più volte, utile per retry/re-render). Il replay reale è
     * comunque coperto dal token di registrazione one-shot e dal cookie
     * lookup_session SameSite=Strict.
     *
     * @param string $csrf token CSRF dal campo hidden del form
     * @return bool
     */
    public static function validateCsrf(string $csrf): bool {
        if ($csrf === '') {
            return false;
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT 1 FROM csrf_tokens
             WHERE csrf = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$csrf]);
        return (bool) $stmt->fetch();
    }

    /**
     * Chiude una sessione lookup e i suoi token CSRF pendenti (chiamato al
     * completamento finale della registrazione: Step 2).
     *
     * @param string $sessionToken token cookie lookup_session
     */
    public static function revokeSession(string $sessionToken): void {
        if ($sessionToken === '') {
            return;
        }
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM csrf_tokens WHERE lookup_token = ?")->execute([$sessionToken]);
        $pdo->prepare("DELETE FROM lk_sessions WHERE token = ?")->execute([$sessionToken]);
    }

    /**
     * Revoca sul server un Bearer token (logout): il token non è più valido
     * finché il client non fa di nuovo login. Sicurezza lato server oltre al
     * semplice "scarta il token in locale".
     *
     * @param string $token Bearer token da revocare
     */
    public static function revokeToken(string $token): void {
        if ($token === '') {
            return;
        }
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM user_tokens WHERE token = ?")->execute([$token]);
    }

    /**
     * Get the currently authenticated user ID.
     */
    public static function getCurrentUserId(): ?int {
        return self::$currentUserId;
    }

    /**
     * Generate a secure token for a user
     *
     * @param int $userId
     * @return string
     */
    public static function generateToken($userId) {
        $pdo = Database::getInstance();
        $token = bin2hex(random_bytes(32));

        // Tokens expire based on config
        $expiryHours = Config::getInstance()->get('app.token_expiry_hours', 24);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));

        // Un solo token attivo per admin: revoca i token precedenti dello
        // stesso user prima di generare uno nuovo. Evita "N login = N token
        // validi" (che renderebbe il logout parziale) e riduce l'esposizione.
        $pdo->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$userId]);

        $stmt = $pdo->prepare("INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $expiresAt]);

        return $token;
    }

    /**
     * Validate a token and return the user ID if valid
     *
     * @param string $token
     * @return int|null
     */
    public static function validateToken($token) {
        if (empty($token)) return null;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        return $user ? (int)$user['user_id'] : null;
    }
}
