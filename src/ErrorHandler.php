<?php

namespace App;

use App\Exceptions\AppException;
use Throwable;

class ErrorHandler {
    public static function register(): void {
        set_exception_handler([self::class, 'handleException']);
    }

    public static function handleException(Throwable $exception): void {
        $config = Config::getInstance();
        $isProduction = $config->isProduction();

        // Log l'errore indipendentemente dall'ambiente
        error_log(sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));

        $statusCode = 500;
        $message = 'An internal server error occurred';

        if ($exception instanceof AppException) {
            $statusCode = $exception->getHttpStatusCode();
            $message = $exception->getMessage();
        }

        $responseBody = [
            'error' => $message,
        ];

        // In sviluppo/locale aggiungiamo più dettagli per facilitare il debug
        if (!$isProduction) {
            $responseBody['debug'] = [
                'type' => (new \ReflectionClass($exception))->getShortName(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ];
        }

        Response::json($responseBody, $statusCode);
    }

}
