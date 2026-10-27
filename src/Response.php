<?php

namespace App;

class Response {

    /**
     * Anti-cache headers — prevengono caching di dati dinamici da browser, CDN e proxy intermedi.
     * Applicato su tutte le risposte JSON API (Fase 1 + Fase 6 del piano verifica cache).
     */
    private static function cacheControlNoStore()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
    }

    /**
     * Send a JSON response
     *
     * @param array $data The data to return
     * @param int $status The HTTP status code
     */
    public static function json($data, $status = 200) {
        self::cacheControlNoStore();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    /**
     * Send a file download response
     *
     * @param string $filename The name of the file to download
     * @param string $content The content of the file
     * @param string $contentType The MIME type of the file
     */
    public static function downloadFile($filename, $content, $contentType = 'text/plain') {
        self::cacheControlNoStore();
        header("Content-Type: $contentType");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Content-Length: " . strlen($content));
        http_response_code(200);
        echo $content;
        exit;
    }

}
