<?php

namespace App;

use App\Exceptions\AppException;

class AuthService {
    /**
     * Authenticate a user and return a token.
     *
     * @param array $credentials ['username' => ..., 'password' => ...]
     * @return array ['token' => ..., 'message' => ...]
     * @throws AppException
     */
    public function login(array $credentials): array {
        if (!isset($credentials['username']) || !isset($credentials['password'])) {
            throw (new AppException("Username and password are required"))->setHttpStatusCode(400);
        }

        // Rate limit per IP: mitigazione brute-force (5 tentativi / 5 min).
        // La soglia è letta da config (rate_limits.login).
        RateLimiter::guard(RateLimiter::LOGIN, Request::getRemoteIp());

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
        $stmt->execute([$credentials['username']]);
        $user = $stmt->fetch();

        if ($user && password_verify($credentials['password'], $user['password'])) {
            $token = Auth::generateToken($user['id']);
            return [
                'token' => $token,
                'message' => 'Login successful'
            ];
        }

        throw (new AppException("Invalid credentials"))->setHttpStatusCode(401);
    }
}
