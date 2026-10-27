# OspitiWebLite

Versione **"lite"** di *OspitiWebServer*: stessa base PHP 8.3 + MySQL, **senza** framework né passo di build.
Contiene le funzionalità "di base" per la gestione ospiti (CRUD, sync ICS/Airbnb, registrazione a 2 step,
lookups, esportazioni, camere, login, privacy). Le funzionalità avanzate sono mantenibili nella
versione **completa** di *OspitiWebServer*.

- Requisiti: **PHP 8.3+**, **MySQL 8 / MariaDB 10.6+**, **Node 20+** (frontend) e **Docker** (dev/serve).
- `vendor/` **è committato** e l'immagine Docker **non** esegue `composer install`.
- Dipendenze runtime (Composer): `symfony/yaml ^6.0`, `vlucas/phpdotenv ^5.5` (vedi `composer.json`).

---

## 1. Installazione e avvio

### 1.1 Prerequisiti

| Componente | Versione | Dove |
|---|---|---|
| Docker + Docker Compose | recente | host |
| PHP | 8.3+ | dentro l'immagine `php:8.3-apache` (niente setup locale) |
| MySQL / MariaDB | 10.6+ | immagine `mariadb:11` gestita da Compose |
| Node.js | 20+ | `client/` (solo in dev) |

L'unico setup lato host è **Docker**.

### 1.2 Avvio (dev / local)

```bash
git clone https://github.com/Roberto89-web/OspitiWebLite
cd OspitiWebLite
docker compose up -d --build
```

Al primo avvio Compose importa automaticamente `db/install.sql` + `db/seed_admin.sql` (idempotenti) e crea l'immagine da `Dockerfile` (PHP 8.3 + Apache + DocumentRoot `public/`).

- Backend: `http://localhost:8081` (porta mappata in `docker-compose.yml`)
- Pannello admin: `http://localhost:8081/web/login` — credenziali seed `admin` / `Admin@2026!` (**cambiala** al primo login)
- Verifica: `curl -i http://localhost:8081/login` deve rispondere `200` (pagina login HTML)

### 1.3 Frontend (Angular) — due opzioni

**Opzione A — dev (consigliata)**: SPA su :4200, proxy verso :8081 (`client/proxy.conf.json`).

```bash
cd client
npm install
npm start          # serve http://localhost:4200, proxy /web → http://localhost:8081
```

**Opzione B — build unica in Apache**: output della build dentro `public/ospiti/` (già servito dal container). Richiede il container in stop per la copia.

```bash
cd client
npm install
npm run build                       # output in ../public/ospiti/browser/, baseHref /ospiti/
docker compose stop app
cp -r public/ospiti/browser ./.build
docker cp ./.build app:/var/www/html/public/ospiti/
docker compose start app
# → SPA accessibile a http://localhost:8081/ospiti/
```

> In dev, **Opzione A** è più semplice: il CSP `frame-ancestors 'none'` di `public/index.php` non blocca la SPA servita su :4200 (altro origin). In produzione con TLS + reverse proxy si usa opzione B.

> **Nota**: la password `Admin@2026!` è solo per il seed di `db/seed_admin.sql` (dev). In produzione cambiarla in DB direttamente (o rigenerare il seed con una password differente) prima del go-live:
> ```sql
> UPDATE users SET password = '<hashed>' WHERE username = 'admin';
> ```

### 1.4 Deploy (reverse proxy con TLS)

Il backend **non** è pensato per essere esposto direttamente su internet: dietro un reverse proxy con certificato TLS, `Host/DNS` pubblici e `X-Forwarded-For` corretto.

```apache
# Apache / Nginx — esempi
ProxyPass        /web/ http://app:8081/web/
ProxyPassReverse /web/ http://app:8081/web/
# per CORS cross-origin aggiungere:
# ProxyPassReverseCookieDomain /web/ app.robertoruzzi.com
```

Prima del live (vedi §12.10):
- `app.ambiente: production` (o `APP_AMBIENTE=production`)
- `cors.allowed_origins` con il dominio pubblico (o `APP_CORS_ALLOWED_ORIGINS`)
- `server.base_url` con l'URL pubblico
- `app.nome_struttura` compilato (popola `/web/privacy`)
- `database.user` ≠ root (crea un user dedicato, GRANT minimi — §12.5)
- `ross1000.codice_posizione` compilato (o `APP_ROSS1000_CODICE_POSIZIONE`) prima di ogni export
- `security.trusted_proxy_ips` con l'IP del reverse proxy (anti-spoofing XFF — §12.3)
- SPA buildata in `public/ospiti/browser/` e `client/src/environments/environment.prod.ts::apiUrl` corretto


---

## 2. Struttura delle cartelle

```
OspitiWebLite/
├── public/
│   ├── index.php              Entrypoint HTTP + router (DocumentRoot = public/)
│   ├── .htaccess              Rewrite → index.php + headers cache
│   └── js/                    Script client (ES modules, no bundler)
│       ├── register_form.js
│       ├── register_travel.js
│       └── register_lang.js
├── src/                       Business logic (PSR-4 `App\`)
│   ├── Config.php             Config YAML + override env (`APP_*`)
│   ├── Database.php           Singlet PDO
│   ├── Auth.php               Autenticazione (Bearer token + cookie session)
│   ├── AuthService.php        Login
│   ├── Request.php            Helper input (JSON, header, query)
│   ├── Response.php           Helper output (JSON, download)
│   ├── ErrorHandler.php       Gestione errori → JSON
│   ├── GuestManager.php       CRUD ospiti + conteggi camere/letti + export
│   ├── RegistrationService.php  Token flussi di registrazione (step 1 e 2)
│   ├── IcsImportService.php   Import da ICS Airbnb
│   ├── IcsExportService.php   Export ICS
│   ├── LookupManager.php      Tabelle lookup (comuni, stati, etc.)
│   ├── Exceptions/            AppException + derived (NotFound, Unauthorized)
│   ├── Routing/Router.php     Router a mano (regex path → handler)
│   └── Services/
│       ├── AvailabilityService.php   Motore overlap/notte (buffer di preparazione) — usato internamente, NON esposto via API
│       ├── GuestExportService.php    Export fixed-width "portale-alloggiati"
│       ├── Ross1000ExportService.php Export ROSS 1000 / ISTAT
│       └── RoomManagementService.php  CRUD camere + tipi-camera
├── views/                     Template PHP server-side
│   ├── login_form.php
│   ├── register_form.php
│   ├── register_travel.php
│   ├── register_success.php
│   ├── privacy_page.php
│   └── privacy_e_termini_ospiti.md
├── config/
│   ├── config.yaml            Config principale (override via env `APP_*`)
│   └── .htaccess              `Require all denied`
├── db/
│   └── install.sql            Schema + seed (16 tabelle, idempotente)
├── vendor/                    Dipendenze Composer (committato)
├── composer.json
└── .htaccess                  Rewrite root → public/index.php
```

---

## 3. Autenticazione

| Helper in `public/index.php` | Implementazione | Significato |
|---|---|---|
| `$router->addRoute(...)`     | pubblica         | **Pubblica** — nessuna auth |
| `$authRoute(...)`            | `Auth::requireToken()` (def. `index.php:291`) | Bearer **o** cookie `lookup_session` (`userId === -1` = ospite) |
| `$adminRoute(...)`           | `Auth::requireAdmin()` (def. `index.php:299`) | Bearer admin, **rifiuta** il cookie ospite `-1` |

> Tutte le rotte sono esposte con prefisso `/web` (vedi §4).

- Token guest (64 esadec. in URL) = flussi di registrazione.
- Token admin (Bearer) = CRUD/export/rooms/lookup.
- `Auth::getCurrentUserId()` popola il soggetto (per i log soft-delete guest).

---

## 4. Rotte — Pubblica

Tutte le rotte sono esposte sotto il prefisso `/web` (normalizzato in `public/index.php` al dispatch):

| Metodo | Path | Handler | Chiama |
|---|---|---|---|
| `GET`  | `/web/login` | `index.php:117` | include `views/login_form.php` |
| `POST` | `/web/login` | `index.php:123` | `AuthService::login(array)` → `Response::json` |
| `GET`  | `/web/register/{token}` | `index.php:131` | `RegistrationService::validateToken($token)` + `Auth::generateSessionToken()` (cookie `lookup_session`) → include `views/register_form.php` |
| `POST` | `/web/api/register/submit` | `index.php:161` | `RegistrationService::submitRegistration($token, $persons)` → `Response::json` (`next_url = /web/register/travel/{token}`) |
| `GET`  | `/web/register/travel/{token}` | `index.php:191` | `RegistrationService::getTokenMeta($token)` → branch: `register_success.php` / redirect a `/web/register/{token}` / `RegistrationService::getTravelData($token)` → `views/register_travel.php` |
| `GET`  | `/web/api/register/travel` | `index.php:226` | `RegistrationService::getTravelData($token)` → `Response::json` |
| `POST` | `/web/api/register/travel` | `index.php:237` | `RegistrationService::submitTravel($token, $tourType, $transportMean)` → `Response::json` |
| `POST` | `/web/api/logout` | `index.php:276` | `Auth::revokeToken()` / `Auth::revokeSession()` → `{"message":"Disconnesso."}` |

> Note:
> - La pagina `register_form.php` usa la **lookup session** (cookie `lookup_session`) emessa in `/web/register/{token}`; non usa Bearer.
> - Il prefisso `/web` è un "URL prefix" applicato al dispatch di `public/index.php`. I `Route` interni usano ancora i path nudi (`/login`, `/guests`, `/api/.../...`) per semplicità — si può vedere il "vero" path solo in `config.yaml`, README e nei client (view/JS/service).

---

## 5. Rotte — Autenticata (auth)

| Metodo | Path | Handler | Chiama |
|---|---|---|---|
| `GET` | `/web/guests` | `index.php:318` | query `solo_attivi`, `anno` → `GuestManager::getAllGuests(bool, ?int)` → `Response::json` |
| `GET` | `/web/guests/{id}` | `index.php:333` | `GuestManager::getGuest(int)` → `Response::json` / 404 |
| `POST`| `/web/guests` | `index.php:345` | se `id` → `GuestManager::updateGuest(id, data, force)` ; altrimenti `GuestManager::createGuest(data, force)` (400/201/409 con `conflicts`) |
| `DELETE` (admin) | `/web/guests/{id}` | `index.php:380` | `GuestManager::softDelete(id, userId)` → 200/404 |
| `GET` | `/web/guests/export` | `index.php:391` | `GuestManager::getGuestsForExport($regId)` → `GuestExportService::generateFixedWidthExport($guests)` → `Response::downloadFile('alloggiati.txt')` |
| `GET` | `/web/guests/export-ross1000` | `index.php:402` | query `guest_id`; `GuestManager::getRegistrationsForRoss1000(?id)` + `getCountActiveRooms()`/`sumActiveBeds()` → `Ross1000ExportService::generate($rows, $cfg)` → `Response::downloadFile('alloggiati.txt')` |
| `GET` | `/web/api/guests/export-ics` | `index.php:424` | `GuestManager::getAllGuests()` → `IcsExportService::generate($guests)` → `Response::downloadFile('bookings.ics','text/calendar')` |
| `POST`| `/web/api/import-ics` | `index.php:433` | `IcsImportService::importBookings(bool $force)` → `Response::json` (success/overlap/500) |
| `POST`| `/web/api/guests/{id}/generate-link` | `index.php:460` | `RegistrationService::generateLink(int $id)` → `Response::json` |
| `GET` | `/web/privacy` | `index.php:493` | legge `views/privacy_e_termini_ospiti.md` → mini-markdown→HTML → include `views/privacy_page.php` |

---

## 6. Rotte — Admin

| Metodo | Path | Handler | Chiama |
|---|---|---|---|
| `GET`    | `/web/api/admin/rooms` | `index.php:467` | `RoomManagementService::listRooms()` → `roomsCall()` → `{ok,data}` / `{ok:false,err}` |
| `POST`   | `/web/api/admin/rooms` | `index.php:470` | `RoomManagementService::createRoom(Request::getJsonInput())` |
| `PUT`    | `/web/api/admin/rooms/{id}` | `index.php:473` | `RoomManagementService::updateRoom(int, array)` |
| `DELETE` | `/web/api/admin/rooms/{id}` | `index.php:476` | `RoomManagementService::deleteRoom(int)` |
| `GET`    | `/web/api/admin/room-types` | `index.php:479` | `RoomManagementService::listRoomTypes()` |
| `POST`   | `/web/api/admin/room-types` | `index.php:482` | `RoomManagementService::createRoomType(array)` |
| `PUT`    | `/web/api/admin/room-types/{id}` | `index.php:485` | `RoomManagementService::updateRoomType(int, array)` |
| `DELETE` | `/web/api/admin/room-types/{id}` | `index.php:488` | `RoomManagementService::deleteRoomType(int)` |

**Helper wrapper** (linea 208, `index.php`): `roomsCall(callable $fn): void` — esegue la chiamata e serializza l'outcome (success → `{ok:true,data}` / `AppException` → `{ok:false,err}` con status / `Throwable` → 500).

---

## 7. Rotte — Lookup (auth)

| Metodo | Path | Handler | Chiama |
|---|---|---|---|
| `GET` | `/web/api/lookups/comuni` | `index.php:573` | `LookupManager::getLookups('lk_comuni')` |
| `GET` | `/web/api/lookups/comuni/all` | `index.php:577` | `LookupManager::getComuni()` |
| `GET` | `/web/api/lookups/stati` | `index.php:581` | `LookupManager::getLookups('lk_stati')` |
| `GET` | `/web/api/lookups/documenti` | `index.php:585` | `LookupManager::getLookups('lk_documenti')` |
| `GET` | `/web/api/lookups/tipo_alloggiato` | `index.php:589` | `LookupManager::getLookups('lk_tipo_alloggiato')` |
| `GET` | `/web/api/lookups/tipo_turismo` | `index.php:593` | `LookupManager::getValori('lk_tipo_turismo')` |
| `GET` | `/web/api/lookups/mezzo_trasporto` | `index.php:597` | `LookupManager::getValori('lk_mezzo_trasporto')` |
| `GET` | `/web/api/lookups/provincie` | `index.php:601` | `LookupManager::getProvinces()` |
| `GET` | `/web/api/lookups/comuni/provincia?provincia=XX` | `index.php:605` | `LookupManager::getComuniByProvince(string)` (400 se param mancante) |

---

## 8. Dipendenze interne (chi istanzia chi)

| Clie | Metodi chiamati (estrazione dal sorgente) |
|---|---|
| `IcsImportService` | `new GuestManager()` (linea 17), `new AvailabilityService()` (linea 75) — `overlaps()`, `conflicts()` |
| `GuestManager` | `new AvailabilityService()` (linee 32, 97) — `expand()`, `overlaps()`, `conflicts()` |
| `RegistrationService` | `new LookupManager()` (linea 540) — per prefill del form step 2 |
| `AvailabilityService` | Motore overlap (buffer di preparazione camera). **Presente ma NON esposto via API** — usato internamente da `GuestManager`/`IcsImportService` per i vincoli di `overlap` su `createGuest`/`updateGuest`/`import-ics`. |

> `AvailabilityService::availabilityForYear / addBlock / removeBlock / listBlocks / adminState / lastUpdated` sono **presenti nel code** ma **non collegati ad alcuna rotta** in questa versione lite (rimossi i consumatori calendar UI / admin-availability). Il motore overlap (`overlaps`/`conflicts`/`expand`) resta invece usato internamente da `GuestManager`/`IcsImportService`. `Response::availability()` — dead code che ospitava un header CORS verso un dominio esterno — è stato rimosso.

---

## 9. Configurazione (chiusure con chi le legge)

Tutte le chiavi sono lette via `Config::get()` (YAML + override env `APP_*`):

| Chiave | Letta da | Default |
|---|---|---|
| `app.nome` | (UI) | `"Ospiti Web Server"` |
| `app.nome_struttura` | `public/index.php` (pagina `/web/privacy`) | `""` (Titolare del trattamento; popola `{{NOME_STRUTTURA}}` in `views/privacy_e_termini_ospiti.md`) |
| `app.ambiente` | `Auth.php`, `AuthService.php` | `"local"` |
| `app.token_expiry_hours` | `Auth.php` | `24` |
| `app.reg_token_expiry_days` | `RegistrationService.php` | `7` |
| `app.timezone` | `public/index.php:32` (`date_default_timezone_set`) | `"Europe/Rome"` |
| `server.base_url` | `RegistrationService.php` | URL pubblico |
| `server.asset_base` | `public/index.php:39` (`asset_url()`) | `""` |
| `database.host` / `.nome` / `.user` / `.pass` / `.charset` | `Database.php` | — |
| `airbnb.ics_url` | `IcsImportService.php` | `""` |
| `cors.allowed_origins` | `public/index.php` (CORS) | `""` (nessuna origine) |
| `security.trusted_proxy_ips` | `Request.php` (`getRemoteIp`, anti-spoofing `XFF`) | `""` (solo `REMOTE_ADDR`) |
| `ross1000.codice_posizione` | `public/index.php:314` | `""` |
| `ross1000.tassa_soggiorno` | `public/index.php:315` | `"1"` |
| `availability.prep_before_days` | `AvailabilityService.php` | `1` |
| `availability.prep_after_days`  | `AvailabilityService.php` | `1` |
| `rate_limits.login` / `.register_submit` / `.register_travel` | `RateLimiter.php` (`max` / `window`) | vedi `config.yaml` |

### Convenzione override env

`APP_` + sezione + chiave, `.` → `_`, **UPPER**:
`database.nome` → `APP_DATABASE_NOME`; `ross1000.codice_posizione` → `APP_ROSS1000_CODICE_POSIZIONE`.

---

## 10. Flussi chiave (raggruppamento delle chiamate)

### A) Login (pannello admin)
```
POST /web/login
  → AuthService::login(['username','password'])
     → Database::getInstance()
     → (verifica credenziali + hash)
     → issue Bearer token (users) o -1 (lookup)
   → { token, user_id, ... }
```

### B) Registrazione ospite — STEP 1 (persona + residenza)
```
GET /web/register/{token}    (l'amministratore ha già generato il token via POST /web/api/guests/{id}/generate-link)
  → RegistrationService::validateToken($token)
  → Auth::generateSessionToken() → setcookie 'lookup_session'
  → include views/register_form.php

POST /web/api/register/submit
  → {token, persons[...]}
  → RegistrationService::submitRegistration($token, $persons)
     → RegistrationService::validateToken → lookup lookup session/cookie
     → insert registrations + registration_tokens (phase1_at = now)
  → {message, next_step:2, next_url:'/web/register/travel/{token}'}
```

### C) Registrazione ospite — STEP 2 (dati viaggio — ROSS 19/20)
```
GET /web/register/travel/{token}
  → RegistrationService::getTokenMeta($token)
     branch:
       used_at set       → include views/register_success.php (finale)
       no phase1_at      → redirect a /web/register/{token} (STEP 1)
       else              → RegistrationService::getTravelData($token)
                           (precompila tipo_turismo, mezzo_trasporto dal lookup)
                           → include views/register_travel.php

POST /web/api/register/travel
  → {token, tour_type, transport_mean}
  → RegistrationService::submitTravel(...)
     → update registrations + set registration_tokens.used_at = now (chiude il token, idempotente)
  → {message, already_complete:false}
```

### D) Sync Airbnb / ICS (import + overlap)
```
POST /web/api/import-ics   {force?}
  → IcsImportService::importBookings($force)
     → new GuestManager()      (per createGuest, deleteImportedGuests)
     → new AvailabilityService()(per overlaps/conflicts)
     → parse ICS da `airbnb.ics_url` (Config)
     → per booking:
          GuestManager::createImportedGuest(arrival, departure)
          AvailabilityService::overlaps($in, $out)        → 409 con `conflicts[]`
     → GuestManager::deleteImportedGuests()               (soft: stato='I' se token presente)
     → GuestManager::refreshImportAnomalies(validPairs, &anomalies)
  → {message, guests_imported, skipped, conflicts[], anomalies[], errors[]}
     / 409 {ok:false, err:'overlap-with', conflicts[], errors[]}
```

### E) Export ospiti (TXT / ROSS 1000 / ICS)
```
GET /web/guests/export
  → GuestManager::getGuestsForExport(?regId)
  → GuestExportService::generateFixedWidthExport($guests)      → 'alloggiati.txt' (fixed-width)

GET /web/guests/export-ross1000?guest_id=...
  → GuestManager::getRegistrationsForRoss1000(?guestId)
  → GuestManager::countActiveRooms() / sumActiveBeds()          (runtime, da `rooms`/`room_types`)
  → Ross1000ExportService::generate($rows, cfg)
      cfg = { codice_posizione (cfg), tassa_soggiorno (cfg), rooms_total, beds_total }
  → 'alloggiati.txt' (26 campi, 328 char/record, CRLF)

GET /web/api/guests/export-ics
  → GuestManager::getAllGuests()
  → IcsExportService::generate($guests)
  → 'bookings.ics'  (text/calendar)
```

### F) CRUD camere / tipi-camera (admin)
```
GET/POST/PUT/DELETE /web/api/admin/rooms           → RoomManagementService::listRooms / createRoom / updateRoom / deleteRoom
GET/POST/PUT/DELETE /web/api/admin/room-types      → RoomManagementService::listRoomTypes / createRoomType / updateRoomType / deleteRoomType
   (tutte via roomsCall() → {ok,data} | {ok:false,err, status})
```

### G) Lookup (per form)
```
GET /web/api/lookups/{comuni|comuni/all|stati|documenti|tipo_alloggiato|tipo_turismo|mezzo_trasporto|provincie|comuni/provincia?pp=XX}
   → LookupManager::{getLookups|getComuni|getValori|getProvinces|getComuniByProvince}(...)
```

---

## 11. Database (16 tabelle in `db/install.sql`)

| Tavola | Ruolo |
|---|---|
| `users` | utenti admin (login) |
| `user_tokens` | Bearer token per utente |
| `lk_sessions`   | lookup/session |
| `registration_sessions` | sessioni di registrazione attive |
| `guests` | una riga per soggiorno (capofamiglia + `room_id` FK) |
| `registration_tokens` | token 64 esad. (purpose: `form`), `phase1_at`, `used_at`, `expires_at` |
| `registrations` | dati raccolti (1 riga per guest, step 1 + step 2) |
| `lk_tipo_alloggiato` | lookup "tipo ospite" (form step 1) |
| `lk_documenti` | lookup documenti |
| `lk_stati` | stati |
| `lk_comuni` | comuni (IT) con prov |
| `lk_tipo_turismo` | lookup "tipo turismo" (step 2) |
| `lk_mezzo_trasporto` | lookup "mezzo di trasporto" (step 2) |
| `availability_blocks` | blocchi manuali [in, out) (non-OTA) |
| `room_types` | tipologie camera |
| `rooms` | camere fisiche (FK `room_types`) |

Seed incluso: `room_types` STD (capacity 2, beds 1) + `rooms` 'Cam 1' (beds 1).

> Il file usa procedure idempotenti (`safe_add_column`, `safe_add_constraint`, `safe_drop_column`) e `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE` per essere ripetibile.

---

## 12. Sicurezza

Misure applicate a livello di `public/index.php`, `src/Auth.php` e nei servizi, pronte per il deploy pubblico su `app.robertoruzzi.com`.

### 12.1 CORS — allowlist (config-driven)

`cors.allowed_origins` in `config.yaml` (lista di origini separate da **virgola**, es. `https://app.robertoruzzi.com,https://api.robertoruzzi.com`). Solo le origini allowlistate ricevono `Access-Control-Allow-Origin` + `Vary: Origin` + `Access-Control-Allow-Credentials: true`; le altre **non** ricevono header CORS (il browser blocca la lettura). `Origin` mancante (stesso-origini/CLI/PHP) non riceve header — comportamento safe-by-default.

Deploy: imposta `cors.allowed_origins: "https://app.robertoruzzi.com"` (o override `APP_CORS_ALLOWED_ORIGINS`).

### 12.2 CSRF — token per sessione lookup + Bearer

- `Auth::issueCsrf(string $sessionToken = ''): string` — genera un token (32 byte) in `csrf_tokens`, emesso su ogni `GET /web/register/{token}` e `GET /web/register/travel/{token}`.
- Le viste `register_form.php` / `register_travel.php` lo espone come `<input type="hidden" name="csrf_token">`; i JS lo leggono e lo inviano nel body JSON.
- POST `/web/api/register/submit` e `/web/api/register/travel`: `Auth::validateCsrf(string $csrf): bool` (lettura, **non** consuma) prima di qualsiasi business logic — mancante/invalido → `403 {"error":"CSRF token mancante o invalido"}`.
- `Auth::consumeCsrf(string $csrf): bool` (UPDATE atomico) **dopo** il successo di `submitTravel` (idempotenza: lo stesso token non può essere riutilizzato per un secondo viaggio).

### 12.3 Rate-limiting — finestra fissa per (azione, chiave)

Tabella `rate_limits` (creata in `db/install.sql` V11). `RateLimiter::guard(action, key, max, window)`:
- `login` — 5 / 300 s per IP.
- `register_submit` — 10 / 3600 s per `csrf_token`.
- `register_travel` — 5 / 3600 s per `csrf_token`.

Chiave: `SHA-256(action|key)`; conteggio aggiornato in transazione (`SELECT … FOR UPDATE` + upsert) → thread-safe. Superato → `AppException` con `setHttpStatusCode(429)`.

**Risoluzione IP anti-spoofing** (`Request::getRemoteIp()`): la fonte è sempre `REMOTE_ADDR` (peer TCP, non falsificabile dal client). `X-Forwarded-For` (left-most) è letto **solo** se l'IP di `REMOTE_ADDR` appartiene alla lista `security.trusted_proxy_ips` (config, vuota di default). Così un client malicioso non può aggirare il rate-limit inviando `X-Forwarded-For` arbitrario; dietro un reverse proxy si dichiara il suo IP in `security.trusted_proxy_ips`.

### 12.4 Fail-fast ambiente (deploy)

`public/index.php` (dopo `require autoload`): se `app.ambiente === 'local'` **e** `APP_LOCAL_DEPLOY` non è impostato **e** `Host` (senza porta) non è `localhost` / `127.0.0.1` / suffisso `.local` / vuoto, il processo risponde `503` e si ferma. In dev Docker funziona; in un deploy sbagliato (immagine con ambiente=local esposta su un dominio) → blocco immediato, niente 500 dopo N minuti.

### 12.5 Non-root DB in produzione (fail-fast)

`src/Database.php` (costruttore): se `Config::isProduction()` e `database.user` ∈ `{root, ''}` → `RuntimeException` con messaggio che indica come impostare `APP_DATABASE_USER`. Niente credenziali nel repo: in dev (Docker) va bene `root`/`''`, in produzione il deploy **deve** avere un user con privilegi minimi:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
       ON `ospiti`.* TO 'app_user'@'%';
```

### 12.6 Logout (revoca lato server)

`POST /web/api/logout` (pubblica): revoca in `user_tokens` il Bearer token e in `lk_sessions` + `csrf_tokens` il cookie `lookup_session`. Non è un no-op se i token non sono validi (200 `{"message":"Disconnesso."}`). Il client Angular (`auth.service.ts`) lo chiama **prima** di scartare il token in locale.

**Un solo token attivo per admin**: `Auth::generateToken()` cancella i token precedenti dello stesso `user_id` prima del nuovo `INSERT` (1-login attivo). Il logout revoca quindi sempre lo stato corrente, non un token tra N.

### 12.7 Headers di sicurezza (ogni risposta, prima dell'handler)

- `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'` (`'unsafe-inline'` obbligatorio perché le viste hanno `<script>`/`<style>` inline; `frame-ancestors 'self'` blocca clickjacking).
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` — **solo** se `app.ambiente === 'production'` **e** la request è HTTPS.

### 12.8 Fail-fast ROSS 1000

`Ross1000ExportService::assertConfig()` (linea 160-166): se `ross1000.codice_posizione` è vuoto → `400` con messaggio esplicito. Non produce più file con campo 25 vuoto.

### 12.9 Cookie di sessione — flag di sicurezza

`lookup_session` (cookie lookup/CSRF) emesso in `/web/register/{token}` (`public/index.php`) ha:
- `httponly` — non leggibile via `document.cookie` (mitiga XSS);
- `samesite=Strict` — non inviato in richieste cross-site (mitiga CSRF aggiuntivamente);
- `secure` **attivo solo su HTTPS** (determinato dal contesto TLS della request). Su HTTP non-locale (dev LAN/VPS) il flag è assente, altrimenti i browser non accetterebbero il cookie e la registrazione ospite si bloccherebbe. In produzione (TLS) è sempre attivo → cookie mai in cleartext.

### 12.10 Checklist pre-deploy

- [ ] `app.ambiente: production` in `config.yaml` (o `APP_AMBIENTE=production`)
- [ ] `cors.allowed_origins` con il dominio pubblico
- [ ] `server.base_url` con URL pubblico (`https://app.robertoruzzi.com`)
- [ ] `app.nome_struttura` con il nome reale della struttura (compilato in `config.yaml` o `APP_APP_NOME_STRUTTURA`) — usato in `/web/privacy`
- [ ] `database.user` ≠ root (vedi §12.5)
- [ ] `ross1000.codice_posizione` compilato prima di ogni export (vedi §12.8)
- [ ] Front-end: `apiUrl` in `client/src/environments/environment.prod.ts` corretto per il dominio (stesso host = `""` o assoluto)
- [ ] Ingresso HTTPS (certificato) per abilitare HSTS + CSP
- [ ] `security.trusted_proxy_ips` con l'IP del reverse proxy (anti-spoofing XFF — §12.3)
- [ ] `APP_LOCAL_DEPLOY` **non** impostato

---

## 13. Convenzioni

- Lingua strings/commenti/UI: **italiano**.
- Nuovo routing → `public/index.php`; nuovo service → `src/Services/`.
- `vendor/` committato (come per il progetto parent).
- L'asset base è normalizzato da `asset_url(string $path)` (definita in `public/index.php:38`); i view **non** hardcodano il prefisso statico.
- Le risposte JSON passano sempre da `Response::json`/`Response::downloadFile` (`Response::availability()` rimosso).

---

## Esclusione di garanzie e limiti d'uso

Questo software è fornito "così com'è", senza garanzie di alcun tipo, esplicite o
implicite, incluse (a titolo esemplificativo) garanzie di funzionamento, assenza di
errori, sicurezza o idoneità a uno scopo specifico. L'uso è a proprio rischio.

Questo software non è stato progettato né verificato per il trattamento di dati
sensibili o per ambiti critici (ad esempio sanità, pagamenti, sicurezza di persone o
infrastrutture). Se intendi utilizzarlo in questi contesti, sei tenuto a effettuare
autonomamente tutte le verifiche necessarie (sicurezza, conformità normativa, 
protezione dei dati, test), sotto la tua esclusiva responsabilità.

Per i termini completi si veda il file [LICENSE](LICENSE).
