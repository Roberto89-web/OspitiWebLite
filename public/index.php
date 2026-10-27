<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\ErrorHandler;
use App\Request;
use App\Response;
use App\Auth;
use App\AuthService;
use App\GuestManager;
use App\RegistrationService;
use App\IcsImportService;
use App\IcsExportService;
use App\LookupManager;
use App\RateLimiter;
use App\Routing\Router;
use App\Services\GuestExportService;
use App\Services\Ross1000ExportService;
use App\Services\RoomManagementService;
use App\Exceptions\AppException;


// 1. Global Error Handling
ErrorHandler::register();

// 2. Environment & Config (.env opzionale — SetEnv in .htaccess funziona anche)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
Config::getInstance(__DIR__ . '/../config/config.yaml');

// 2b. Fail-fast: se l'ambiente è 'local' ma la richiesta arriva da un host NON
//     locale, rifiuta. Previene di dimenticare `ambiente: local` in produzione.
//     "Locale" = Host è localhost/127.0.0.1 (anche con porta) oppure è stato
//     esplicitamente concesso il deploy (APP_LOCAL_DEPLOY). In dev via Docker
//     l'Host resta `localhost` (proxy), quindi la dev non è mai bloccata.
$cfg = Config::getInstance();
$ambiente = (string) $cfg->get('app.ambiente', 'local');
if ($ambiente === 'local') {
    $explicitDeploy = getenv('APP_LOCAL_DEPLOY') !== false && getenv('APP_LOCAL_DEPLOY') !== '';
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostBase = strtolower(strtok($host, ':'));
    $isLocalHost = in_array($hostBase, ['localhost', '127.0.0.1', '', '.local'], true)
                   || in_array($host, ['localhost', '127.0.0.1'], true)
                   || str_ends_with($hostBase, '.local');
    if (!$explicitDeploy && !$isLocalHost) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Ambiente 'local' non consentito per questa richiesta. Imposta APP_AMBIENTE=production.";
        exit;
    }
}

// Fuso orario dell'app: usato per tutte le finestre di validità.
date_default_timezone_set((string)Config::getInstance()->get('app.timezone', 'UTC'));

// Helper per riferimenti asset nei view: normalizza il prefisso (locale '' vs prod '/public')
// da server.asset_base — override env APP_SERVER_ASSET_BASE.
// I view sono inclusi dentro closure di router quindi le variabili globali non sono
// visibili lì; una function sì.
function asset_url(string $path): string {
    $base  = (string) Config::getInstance()->get('server.asset_base', '');
    $base  = rtrim($base, '/');
    $clean = ltrim((string) $path, '/');
    return ($base === '' ? '' : $base) . '/' . $clean;
}

// 3. CORS Headers — allowlist-driven (cors.allowed_origins / APP_CORS_ALLOWED_ORIGINS).
//    VUOTO → nessuna origine cross-origin è autorizzata (default sicuro).
//    In dev locale (same-origin) l'header non serve; qui lo emiamo solo se
//    l'Origin è nell'allowlist, così non si apre "Access-Control-Allow-Origin: *"
//    per tutti.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedRaw = (string) $cfg->get('cors.allowed_origins', '');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedRaw)), 'strlen'));
$originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 3b. Le pagine gestite qui sono dinamiche e legate allo stato (token, sessioni):
//     impedisce a proxy/CDN (es. nginx in front) di servire copie cacheate stantie.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0");
header("Pragma: no-cache");
header("Expires: 0");

// 3c. Headers di sicurezza — CSP, HSTS (solo TLS), clickjacking, content-type sniffing.
//     CSP: inline-scripts (login_form) e inline-styles (varie viste) richiedono
//     'unsafe-inline'. Nessuna fonte di script esterna.
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$httpsDetermined = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (strtolower($_SERVER['FRONT_END_HTTP_SCHEME'] ?? '') === 'https');
if ($httpsDetermined && $ambiente === 'production') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 4. Router Initialization
$router = new Router();

// --- Public Routes ---

// Login Page (GET — serve il form HTML)
$router->addRoute('GET', '/login', function() {
    include __DIR__ . '/../views/login_form.php';
    exit;
});

// Login API (POST — accetta JSON e restituisce token)
$router->addRoute('POST', '/login', function() {
    $input = Request::getJsonInput();
    $authService = new AuthService();
    $result = $authService->login($input);
    Response::json($result);
});

// Guest Registration Form
$router->addRoute('GET', '/register/([a-f0-9]{64})', function($token) use ($httpsDetermined) {
    $registrationService = new RegistrationService();
    $tokenData = $registrationService->validateToken($token);
    if (!$tokenData) {
        Response::json(['error' => 'The registration link is invalid or has expired'], 403);
    }

    // Issue a short-lived session token for lookups
    $sessionToken = Auth::generateSessionToken();
    // 'secure' solo su HTTPS: su HTTP non-localhost (es. dev su LAN/VPS) i
    // browser non accettano cookie Secure → la registrazione ospite si
    // bloccherebbe. In prod (TLS) è attivo.
    setcookie('lookup_session', $sessionToken, [
        'expires' => time() + 7200,
        'path' => '/',
        'httponly' => true,
        'secure' => (bool) $httpsDetermined,
        'samesite' => 'Strict',
    ]);

    // Token CSRF una-tantum, vincolato alla sessione lookup (cookie Strict).
    $csrfToken = Auth::issueCsrf($sessionToken);

    // Make available to view
    $tokenData = $tokenData;
    include __DIR__ . '/../views/register_form.php';
    exit;
});

// Guest Registration Submission (Step 1 — persona + residenza)
$router->addRoute('POST', '/api/register/submit', function() {
    $input = Request::getJsonInput();
    $token = $input['token'] ?? '';
    $csrf  = $input['csrf'] ?? '';
    $persons = $input['persons'] ?? [];
    if (empty($token) || empty($persons)) {
        Response::json(['error' => 'Missing token or data'], 400);
    }
    // Rate limit sul token di registrazione (anti-abuso dello stesso link).
    \App\RateLimiter::guard(\App\RateLimiter::REGISTER_SUBMIT, $token);
    // CSRF: token dal campo hidden. Il replay è coperto dal token di registrazione
    // one-shot + cookie lookup_session (Strict); qui verifiamo solo validità/scadenza.
    if (!Auth::validateCsrf($csrf)) {
        Response::json(['error' => 'Token di sicurezza (CSRF) non valido o scaduto. Ricarica la pagina.'], 403);
    }
    $registrationService = new RegistrationService();
    $registrationService->submitRegistration($token, $persons);

    Response::json([
        'message'  => 'Dati ricevuti. Ora completa lo Step 2.',
        'next_step'=> 2,
        'next_url' => '/register/travel/' . rawurlencode($token),
    ]);
});

// ─────────────────────────────────────────────
// FASE 1.5 — STEP 2: DATI DI VIAGGIO (ROSS 19/20)
// ─────────────────────────────────────────────

// Step 2 page — stato token decide: success / Step 1 / rendi la pagina
$router->addRoute('GET', '/register/travel/([a-f0-9]{64})', function($token) {
    $registrationService = new RegistrationService();
    $meta = $registrationService->getTokenMeta($token);

    if (!$meta || strtotime($meta['expires_at'] ?? '1970-01-01') < time()) {
        Response::json(['error' => 'Token non valido o scaduto'], 403);
    }

    // Step 2 già chiuso → pagina di conferma finale (idempotente)
    if (!empty($meta['used_at'])) {
        include __DIR__ . '/../views/register_success.php';
        exit;
    }

    // Step 1 non ancora fatto → torna al form dati persona + residenza
    if (empty($meta['phase1_at'])) {
        header('Location: /register/' . $token);
        exit;
    }

    // Dati viaggio + opzioni (lookup) renderizzate lato server: nessuna dipendenza client-side da auth/cookie
    $travelData = $registrationService->getTravelData($token);

    // Token CSRF per l'invio della step 2 (una-tantum).
    $sessionToken = Request::getSessionToken() ?? '';
    $csrfToken = Auth::issueCsrf($sessionToken);

    $tokenData   = $meta;
    $travelData  = $travelData;
    $travelToken = $token;
    include __DIR__ . '/../views/register_travel.php';
    exit;
});

// Step 2 — GET dati viaggio da compilare/prefill
$router->addRoute('GET', '/api/register/travel', function() {
    $token = Request::getQuery('token') ?? '';
    if (empty($token)) {
        Response::json(['error' => 'Token mancante'], 400);
        return;
    }
    $registrationService = new RegistrationService();
    Response::json($registrationService->getTravelData($token));
});

// Step 2 — POST invio dati viaggio (chiude il token → ultimo passo dei dati)
$router->addRoute('POST', '/api/register/travel', function() {
    $input = Request::getJsonInput();
    $token           = $input['token'] ?? '';
    $csrf            = $input['csrf'] ?? '';
    $tourType        = $input['tour_type'] ?? '';
    $transportMean   = $input['transport_mean'] ?? '';

    if (empty($token)) {
        Response::json(['error' => 'Token mancante'], 400);
        return;
    }

    // Rate limit sul token di registrazione (anti-abuso dello stesso link).
    \App\RateLimiter::guard(\App\RateLimiter::REGISTER_TRAVEL, $token);

    // CSRF dal campo hidden (valide/scaduta). Consumata solo a successo, così
    // un retry/re-render riusa lo stesso token già convalidato.
    if (!Auth::validateCsrf($csrf)) {
        Response::json(['error' => 'Token di sicurezza (CSRF) non valido o scaduto. Ricarica la pagina.'], 403);
    }

    $registrationService = new RegistrationService();
    $result = $registrationService->submitTravel($token, $tourType, $transportMean);

    // Completamento finale: consumo il CSRF e chiudo la sessione lookup ospite
    // (token + csrf) così il cookie non resta valido dopo la registrazione.
    Auth::consumeCsrf($csrf);
    Auth::revokeSession(Request::getSessionToken() ?? '');

    Response::json([
        'message'          => $result['message'] ?? 'Dati di viaggio salvati.',
        'already_complete' => $result['already_complete'] ?? false,
    ]);
});

// --- Logout ---
// POST /api/logout: revoca Bearer token e/o cookie lookup_session.
// Rotte pubblica (non richiede autenticazione): revoca se i token sono validi,
// no-op se non lo sono. Sicurezza lato server oltre al "scarta in locale".
$router->addRoute('POST', '/api/logout', function() {
    $bearer = Request::getBearerToken();
    if ($bearer !== null && Auth::validateToken($bearer) !== null) {
        Auth::revokeToken($bearer);
    }
    $session = Request::getSessionToken();
    if ($session !== null && Auth::validateSessionToken($session)) {
        Auth::revokeSession($session);
    }
    Response::json(['message' => 'Disconnesso.']);
});

// --- Authenticated Routes ---

// Helper to wrap authenticated routes
$authRoute = function($method, $path, $handler) use ($router) {
    $router->addRoute($method, $path, function(...$args) use ($handler) {
        Auth::requireToken();
        return $handler(...$args);
    });
};

// Helper to wrap admin-only routes (rifiuta la session cookie ospite, id = -1)
$adminRoute = function($method, $path, $handler) use ($router) {
    $router->addRoute($method, $path, function(...$args) use ($handler) {
        Auth::requireAdmin(); // getta se non admin; popola getCurrentUserId()
        return $handler(...$args);
    });
};

// Helper: esegue una chiamata service e serializza (ok | err).
function roomsCall(callable $fn): void {
    try {
        Response::json(['ok' => true, 'data' => $fn()]);
    } catch (\App\Exceptions\AppException $e) {
        Response::json(['ok' => false, 'err' => $e->getMessage()], (int)$e->getHttpStatusCode());
    } catch (\Throwable $e) {
        Response::json(['ok' => false, 'err' => 'Errore interno: ' . $e->getMessage()], 500);
    }
}

// Guests CRUD
$authRoute('GET', '/guests', function() {
    $soloAttivi = (bool) filter_var(Request::getQuery('solo_attivi'), FILTER_VALIDATE_BOOLEAN);
    $annoRaw = Request::getQuery('anno');
    $anno = null;
    if (is_numeric($annoRaw)) {
        $n = (int)$annoRaw;
        if ($n >= 1900 && $n <= 2100) {
            $anno = $n;
        }
    }
    $guestManager = new GuestManager();
    Response::json($guestManager->getAllGuests($soloAttivi, $anno));
});

// Dettaglio guest — indipendente dall'anno (usato da guest-form edit)
$authRoute('GET', '/guests/(\d+)', function($id) {
    $guestManager = new GuestManager();
    $guest = $guestManager->getGuest((int) $id);

    if ($guest === null) {
        Response::json(['ok' => false, 'err' => 'not-found'], 404);
        return;
    }

    Response::json($guest);
});

$authRoute('POST', '/guests', function() {
    $input = Request::getJsonInput();
    $force = !empty($input['force']);
    $guestManager = new GuestManager();
    if (isset($input['id'])) {
        $result = $guestManager->updateGuest((int)$input['id'], $input, $force);
        if ($result['ok'] === false) {
            if (($result['err'] ?? '') === 'not-found') {
                Response::json(['ok' => false, 'err' => 'Guest record not found'], 404);
            } else {
                Response::json(['ok' => false, 'err' => 'overlap-with', 'conflicts' => $result['conflicts'] ?? []], 409);
            }
            return;
        }
        Response::json(['ok' => true, 'message' => 'Guest updated successfully', 'forced' => $force]);
    } else {
        $requiredFields = ['NomeCapofamiglia', 'CognomeCapoFamiglia', 'NumOspiti', 'email', 'DataArrivo', 'DataPartenza'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                Response::json(['ok' => false, 'err' => "Field '$field' is required"], 400);
                return;
            }
        }
        $result = $guestManager->createGuest($input, $force);
        if ($result['ok'] === false) {
            Response::json(['ok' => false, 'err' => 'overlap-with', 'conflicts' => $result['conflicts']], 409);
            return;
        }
        Response::json(['ok' => true, 'message' => 'Guest registered successfully', 'id' => $result['id'], 'forced' => $force], 201);
    }
});

// Admin: soft delete guest (niente rimozione fisica — deleted_at/deleted_by)
// Le tabelle collegate (registrations, registration_tokens) restano intatte:
// solo la riga guest diventa invisibile alle query "calde".
$adminRoute('DELETE', '/guests/(\d+)', function($id) {
    $guestManager = new GuestManager();
    $userId = (int) Auth::getCurrentUserId();
    if ($guestManager->softDelete((int)$id, $userId)) {
        Response::json(['ok' => true, 'message' => 'Guest eliminato']);
    } else {
        Response::json(['ok' => false, 'err' => 'not-found'], 404);
    }
});

// Export Guests (Fixed Width TXT — portale-alloggiati)
$authRoute('GET', '/guests/export', function() {
    $registrationId = Request::getQuery('registration_id');
    $guestManager = new GuestManager();
    $guests = $guestManager->getGuestsForExport($registrationId);

    $exportService = new GuestExportService();
    $content = $exportService->generateFixedWidthExport($guests);
    Response::downloadFile('alloggiati.txt', $content);
});

// Export Guests (ROSS 1000 / ISTAT — 26 campi, 328 char/record, CRLF)
$authRoute('GET', '/guests/export-ross1000', function() {
    $guestId = Request::getQuery('guest_id') !== null
        ? (int) Request::getQuery('guest_id') : null;

    $gm = new GuestManager();
    $rows = $gm->getRegistrationsForRoss1000($guestId);
    if (empty($rows)) {
        throw (new AppException('Nessun record da esportare'))->setHttpStatusCode(400);
    }

    $cfg = [
        'codice_posizione' => (string)Config::getInstance()->get('ross1000.codice_posizione', ''),
        'tassa_soggiorno'  => (string)Config::getInstance()->get('ross1000.tassa_soggiorno', '1'),
        'rooms_total'      => (int)$gm->countActiveRooms(),
        'beds_total'       => (int)$gm->sumActiveBeds(),
    ];

    $content = (new Ross1000ExportService())->generate($rows, $cfg);
    Response::downloadFile('alloggiati.txt', $content);
});

// Export Guests (ICS)
$authRoute('GET', '/api/guests/export-ics', function() {
    $guestManager = new GuestManager();
    $guests = $guestManager->getAllGuests();
    $icsService = new IcsExportService();
    $content = $icsService->generate($guests);
    Response::downloadFile('bookings.ics', $content, 'text/calendar');
});

// ICS Import
$authRoute('POST', '/api/import-ics', function() {
    $input = Request::getJsonInput();
    $force = !empty($input['force']) || filter_var(Request::getQuery('force'), FILTER_VALIDATE_BOOLEAN);
    $icsService = new IcsImportService();
    $result = $icsService->importBookings($force);
    if ($result['status'] === 'success') {
        Response::json([
            'message' => 'ICS import completed',
            'guests_imported' => $result['guests_imported'],
            'skipped' => $result['skipped'],
            'conflicts' => $result['conflicts'] ?? [],
            'anomalies' => $result['anomalies'],
            'errors' => $result['errors']
        ]);
    } elseif ($result['status'] === 'overlap') {
        Response::json([
            'ok'        => false,
            'err'       => 'overlap-with',
            'conflicts' => $result['conflicts'],
            'errors'    => $result['errors'] ?? []
        ], 409);
    } else {
        Response::json(['error' => $result['message'], 'errors' => $result['errors'] ?? []], 500);
    }
});

// Generate Registration Link
$authRoute('POST', '/api/guests/(\d+)/generate-link', function($id) {
    $registrationService = new RegistrationService();
    $result = $registrationService->generateLink((int)$id);
    Response::json($result);
});

// Camera e tipi-camera ─────────────────────
$adminRoute('GET', '/api/admin/rooms', function() {
    roomsCall(fn() => (new RoomManagementService())->listRooms());
});
$adminRoute('POST', '/api/admin/rooms', function() {
    roomsCall(fn() => (new RoomManagementService())->createRoom(Request::getJsonInput()));
});
$adminRoute('PUT', '/api/admin/rooms/(\d+)', function($id) {
    roomsCall(fn() => (new RoomManagementService())->updateRoom((int)$id, Request::getJsonInput()));
});
$adminRoute('DELETE', '/api/admin/rooms/(\d+)', function($id) {
    roomsCall(fn() => (new RoomManagementService())->deleteRoom((int)$id));
});
$adminRoute('GET', '/api/admin/room-types', function() {
    roomsCall(fn() => (new RoomManagementService())->listRoomTypes());
});
$adminRoute('POST', '/api/admin/room-types', function() {
    roomsCall(fn() => (new RoomManagementService())->createRoomType(Request::getJsonInput()));
});
$adminRoute('PUT', '/api/admin/room-types/(\d+)', function($id) {
    roomsCall(fn() => (new RoomManagementService())->updateRoomType((int)$id, Request::getJsonInput()));
});
$adminRoute('DELETE', '/api/admin/room-types/(\d+)', function($id) {
    roomsCall(fn() => (new RoomManagementService())->deleteRoomType((int)$id));
});

// Privacy Policy Page (authenticated via lookup_session or Bearer token)
$authRoute('GET', '/privacy', function() {
    $markdown = file_get_contents(__DIR__ . '/../views/privacy_e_termini_ospiti.md');
    if ($markdown === false) {
        Response::json(['error' => 'Privacy policy file not found'], 500);
        return;
    }
    // Sostituisce il placeholder {{NOME_STRUTTURA}} con il valore in config
    // (app.nome_struttura). Il valore viene HTML-escaped per evitare
    // iniezioni; il resto dello parsing è già protetto da htmlspecialchars.
    $nomeStruttura = htmlspecialchars(
        trim((string) Config::getInstance()->get('app.nome_struttura', '')),
        ENT_QUOTES
    );
    $markdown = str_replace('{{NOME_STRUTTURA}}', $nomeStruttura, $markdown);

    // Convert markdown → HTML (no external dependencies)
    $lines = explode("\n", $markdown);
    $html = '';
    $inList = false;

    foreach ($lines as $line) {
        // Horizontal rule
        if (preg_match('/^---+$/', trim($line))) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= "<hr>\n";
            continue;
        }

        // Blank line — close list if open
        if (trim($line) === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            continue;
        }

        // Headings
        if (preg_match('/^### (.+)$/', trim($line), $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= "<h3>" . htmlspecialchars_decode($m[1], ENT_QUOTES) . "</h3>\n";
            continue;
        }
        if (preg_match('/^## (.+)$/', trim($line), $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= "<h2>" . htmlspecialchars_decode($m[1], ENT_QUOTES) . "</h2>\n";
            continue;
        }
        if (preg_match('/^# (.+)$/', trim($line), $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= "<h1>" . htmlspecialchars_decode($m[1], ENT_QUOTES) . "</h1>\n";
            continue;
        }

        // List item (- or *)
        if (preg_match('/^\s*[-\*] (.+)$/', trim($line), $m)) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $val = htmlspecialchars_decode(trim($m[1], "「」"), ENT_QUOTES);
            // Convert bold inside list items
            $val = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $val);
            $html .= "<li>{$val}</li>\n";
            continue;
        }

        // Blockquote
        if (preg_match('/^> (.+)$/', trim($line), $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $val = htmlspecialchars_decode($m[1], ENT_QUOTES);
            $val = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $val);
            $html .= "<blockquote>{$val}</blockquote>\n";
            continue;
        }

        // Bold/italic/inline code (within regular text)
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $content = htmlspecialchars_decode($line, ENT_QUOTES);
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
        $content = preg_replace('/`(.+?)`/', '<code>$1</code>', $content);
        $html .= "<p>{$content}</p>\n";
    }

    if ($inList) { $html .= '</ul>'; }

    include __DIR__ . '/../views/privacy_page.php';
    exit;
});

// Lookup Data for Registration Form
$lookupManager = new LookupManager();

$authRoute('GET', '/api/lookups/comuni', function() use ($lookupManager) {
    Response::json($lookupManager->getLookups('lk_comuni'));
});

$authRoute('GET', '/api/lookups/comuni/all', function() use ($lookupManager) {
    Response::json($lookupManager->getComuni());
});

$authRoute('GET', '/api/lookups/stati', function() use ($lookupManager) {
    Response::json($lookupManager->getLookups('lk_stati'));
});

$authRoute('GET', '/api/lookups/documenti', function() use ($lookupManager) {
    Response::json($lookupManager->getLookups('lk_documenti'));
});

$authRoute('GET', '/api/lookups/tipo_alloggiato', function() use ($lookupManager) {
    Response::json($lookupManager->getLookups('lk_tipo_alloggiato'));
});

$authRoute('GET', '/api/lookups/tipo_turismo', function() use ($lookupManager) {
    Response::json($lookupManager->getValori('lk_tipo_turismo'));
});

$authRoute('GET', '/api/lookups/mezzo_trasporto', function() use ($lookupManager) {
    Response::json($lookupManager->getValori('lk_mezzo_trasporto'));
});

$authRoute('GET', '/api/lookups/provincie', function() use ($lookupManager) {
    Response::json($lookupManager->getProvinces());
});

$authRoute('GET', '/api/lookups/comuni/provincia', function() use ($lookupManager) {
    $provincia = Request::getQuery('provincia');
    if (empty($provincia)) {
        Response::json(['error' => 'Province parameter is required'], 400);
    }
    Response::json($lookupManager->getComuniByProvince($provincia));
});

// 5. Dispatch
// Tutte le rotte API/registration/private sono esposte sotto il prefisso `/web`.
// Qui si normalizza: il resto del file continua a usare i path "nudi" che erano
// già nei view/JS/service (il prefisso è un dettaglio di URL, non di logica).
//   /web/login              → /login
//   /web/api/guests/export  → /api/guests/export
//   /web (nudo)             → '' → 404 (nessuna rotta root)
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';
if (str_starts_with($uri, '/web')) {
    $suffix = substr($uri, 4);           // tolge il prefisso /web
    $uri = ($suffix === '' ? '/' : $suffix);
}

$router->dispatch($method, $uri);
