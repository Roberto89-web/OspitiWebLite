# OspitiWebLite — API Reference

PHP 8.3 · MySQL · no framework · composer deps: `symfony/yaml`, `vlucas/phpdotenv`

Base URL: `https://app.robertoruzzi.com` (prod) / `http://localhost:8080` (Docker dev)

---

## Convenzioni

- **Auth**: tutte le rotte "auth" accettano **Bearer token** (`Authorization: Bearer <token>`)
  oppure il cookie `lookup_session` (sessione ospite `-1`). Le rotte **admin** rifiutano il cookie ospite.
- **JSON**: `Content-Type: application/json` su tutte le rotte API (GET lookup/parametri via query string).
- **Codici**: `200` ok · `201` creato · `400` input valido · `403` token scaduto · `404` non trovato · `409` overlap · `500` interno.
- **Rooms/RoomTypes** (via `roomsCall`): success → `{ok:true, data:[...]}` · error → `{ok:false, err:"..."}` con status appropriato.

---

## Pubblica

### `GET /login`

Page HTML. Include `views/login_form.php`.

### `POST /login`

```
Request:  {"username": "...", "password": "..."}
Response: {"token": "<64-hex-bearer>", "message": "Login successful"}
Status:   200 | 401 (credentziali errate)
```

Chiama `AuthService::login(array)`.

---

### `GET /register/{token}`

Page HTML ospite. Include `views/register_form.php`.

- `token` = 64 esadec. (generato da `POST /api/guests/{id}/generate-link`).
- Emesso cookie `lookup_session` (HttpOnly, Secure, SameSite=Strict, TTL 2 h) che il form utilizza per chiamate lookup.
- 403 se token invalido/scaduto.

### `POST /api/register/submit`

**STEP 1** — persona + residenza.

```
Request:  {
  "token":    "<64-hex>",
  "persons": [
    {
      "Nome":     "...",
      "Cognome":  "...",
      "DataNascita":"YYYY-MM-DD",
      "Sesso":    "M|F",
      "Paese":    "IT",
      "Provincia":"RM",
      "Comune":   "Roma",
      "TipoAlloggiato": 1,
      "Documento":  1,
      "NumeroDocumento": "...",
      "ValiditaDocumento": "YYYY-MM-DD",
      "TipoDocumento":"..."
    }
  ]
}
Response: {"message":"Dati ricevuti...", "next_step":2, "next_url":"/register/travel/<token>"}
Status:   200 | 400 | 403
```

Chiama `RegistrationService::submitRegistration(string $token, array $persons)`.

---

### `GET /register/travel/{token}`

Page HTML STEP 2 (dati viaggio ROSS 19/20). Include one of:

- `views/register_success.php` — se `used_at` già impostato (idempotente).
- `views/register_travel.php`  — se `phase1_at` impostato (form STEP 2).
- redirect a `/register/{token}` — se step 1 non fatto ancora.

### `GET /api/register/travel?token=...`

```
Response: {"guest_id":int|null, "tour_type":string|null, "transport_mean":string|null}
```

Chiama `RegistrationService::getTravelData(string $token)`.

---

### `POST /api/register/travel`

**STEP 2** — dati di viaggio, chiude il token (used_at = NOW, token non riapribile).

```
Request:  {
  "token":          "<64-hex>",
  "tour_type":      "L|E|A|...",
  "transport_mean": "A|T|..."
}
Response: {"message":"Dati di viaggio salvati.", "already_complete":false}
Status:   200 | 400 | 403
```

Chiama `RegistrationService::submitTravel(token, tourType, transportMean)`.

---

### `GET /privacy`

Page HTML (include `views/privacy_page.php`, markdown→HTML server-side).
Auth: `Auth::requireToken()` (Bearer o `lookup_session`).

---

## Autenticata (auth)

> Tutte richiedono `Authorization: Bearer <token>` (admin) **oppure** il cookie `lookup_session` (ospite).

### `GET /guests?solo_attivi=<bool>&anno=<int>`

```
Response: [
  {
    "id":            int,
    "room_id":       int|null,
    "NomeCapofamiglia": "...",
    "CognomeCapoFamiglia": "...",
    "NumOspiti":     int,
    "email":  "a@b.c",
    "DataArrivo":   "YYYY-MM-DD",
    "DataPartenza": "YYYY-MM-DD",
    "origin":       "a|c",       // a=AirBnb/ICS, c=creato ospite/manuale
    "stato":        "A|I|null",  // A=attivo, I=anomaly (in ICS non più presente ma token presente)
    ...
  },
  ...
]
```

Chiama `GuestManager::getAllGuests(bool, ?int)`.

---

### `GET /guests/{id}`

```
Response: { ... guest single ... }
Status:   200 | 404
```

Chiama `GuestManager::getGuest(int)`.

---

### `POST /guests`

**CREATE** (no `id`):

```
Request (minimum):
{
  "NomeCapofamiglia":"...", "CognomeCapoFamiglia":"...",
  "NumOspiti": 1, "email":"a@b.c",
  "DataArrivo": "YYYY-MM-DD", "DataPartenza":"YYYY-MM-DD",
  "force": false   // opzionale; true → supera overlap
}
Response: {"ok":true,"message":"Guest registered successfully","id":<int>,"forced":false}
Status:   201 | 400 (field required) | 409 (overlap)
```

**UPDATE** (con `id`):

```
Request: {"id":<int>, ... same fields as CREATE ..., "force":false}
Response: {"ok":true,"message":"Guest updated successfully","forced":false}
Status:   200 | 404 | 409
```

Chiama `GuestManager::createGuest(data, force)` / `updateGuest(id, data, force)`.

---

### `DELETE /guests/{id}` — **ADMIN ONLY**

Soft delete (`deleted_at`/`deleted_by`). Le tabelle collegate (registrations, tokens) restano intatte.

```
Response: {"ok":true,"message":"Guest eliminato"}
Status:   200 | 404
```

Chiama `GuestManager::softDelete(int $id, int $userId)`.

---

### `GET /guests/export?registration_id=...`

Download `alloggiati.txt` (fixed-width, formato "portale-alloggiati").
Content-Type: `text/plain; charset=utf-8`.

Chiama `GuestManager::getGuestsForExport(?regId)` → `GuestExportService::generateFixedWidthExport($guests)`.

---

### `GET /guests/export-ross1000?guest_id=...`

Download `alloggiati.txt` (formato **ROSS 1000 / ISTAT**: 26 campi, 328 char/record, CRLF).
`rooms_total` e `beds_total` sono calcolati a runtime da `GuestManager::countActiveRooms()` / `sumActiveBeds()` (tabelle `rooms` / `room_types`).

```
config:
  codice_posizione ← ross1000.codice_posizione (Config)
  tassa_soggiorno  ← ross1000.tassa_soggiorno  (Config)
```

Status: 200 (file) | 400 (nessun record).

Chiama `GuestManager::getRegistrationsForRoss1000(?guestId)` → `Ross1000ExportService::generate($rows, $cfg)`.

---

### `GET /api/guests/export-ics`

Download `bookings.ics`. Content-Type: `text/calendar`.

Chiama `GuestManager::getAllGuests()` → `IcsExportService::generate($guests)`.

---

### `POST /api/import-ics`

Import da ICS Airbnb (URL letto da `airbnb.ics_url` in Config).

```
Request:  {"force": false}     // true → skip overlap check
Response (success): {
  "message":       "ICS import completed",
  "guests_imported": int,
  "skipped":         int,
  "conflicts":       [...],
  "anomalies":       [...],
  "errors":          [...]
}
Status:   200 | 409 (overlap) | 500 (error)
```

Chiama `IcsImportService::importBookings(bool $force)`.
Interna: `new GuestManager()`, `new AvailabilityService()` (overlaps/conflicts).

---

### `POST /api/guests/{id}/generate-link`

Genera un nuovo token 64 esadec. per il flusso di registrazione (7 giorni TTL).
Invalida i token precedenti non-`used` dello stesso guest.

```
Response: {"token":"<64-hex>","url":"<base_url>/register/<token>","expires_at":"YYYY-MM-DD HH:MM:SS"}
Status:   200 | 404 (guest not found)
```

Chiama `RegistrationService::generateLink(int $id)`.

---

## Admin — Rooms & Room Types

> Tutte via `roomsCall()` → success: `{ok:true,data:[...]}` · error: `{ok:false,err:"..."}` + status.

### `GET /api/admin/rooms`

```
Response: {"ok":true,"data":[{"id":int,"nome":"Cam 1","codice":"...","beds":1,"room_type_id":1},...]}
```

Chiama `RoomManagementService::listRooms()`.

### `POST /api/admin/rooms`

```
Request:  {"nome":"Cam 2","codice":"C2","beds":2,"room_type_id":1}
Response: {"ok":true,"data":{"id":int,...}}
Status:   200 | 400 (validazione)
```

Chiama `RoomManagementService::createRoom(array)`.

### `PUT /api/admin/rooms/{id}`

```
Request:  {"nome":"Cam 2","codice":"C2","beds":2,"room_type_id":1}
Response: {"ok":true,"data":{"id":int,...}}
Status:   200 | 404
```

Chiama `RoomManagementService::updateRoom(int, array)`.

### `DELETE /api/admin/rooms/{id}`

```
Response: {"ok":true,"data":null}
Status:   200 | 404
```

Chiama `RoomManagementService::deleteRoom(int)`.

---

### `GET /api/admin/room-types`

```
Response: {"ok":true,"data":[{"id":int,"nome":"STD","capacity":2,"beds":1},...]}
```

Chiama `RoomManagementService::listRoomTypes()`.

### `POST /api/admin/room-types`

```
Request:  {"nome":"STD","capacity":2,"beds":1}
Response: {"ok":true,"data":{"id":int,...}}
Status:   200 | 400
```

Chiama `RoomManagementService::createRoomType(array)`.

### `PUT /api/admin/room-types/{id}`

```
Request:  {"nome":"STD","capacity":2,"beds":1}
Response: {"ok":true,"data":{"id":int,...}}
Status:   200 | 404
```

Chiama `RoomManagementService::updateRoomType(int, array)`.

### `DELETE /api/admin/room-types/{id}`

```
Response: {"ok":true,"data":null}
Status:   200 | 404
```

Chiama `RoomManagementService::deleteRoomType(int)`.

---

## Lookups (auth)

> Tutte richiedono Bearer o `lookup_session`.

| Path | Metodo | Response |
|---|---|---|
| `/api/lookups/comuni` | `GET` | `\[\{"id":int,"nome":"...","provincia":"XX"},...\]` |
| `/api/lookups/comuni/all` | `GET` | `\[\{"id":int,"nome":"..."},...\]` |
| `/api/lookups/stati` | `GET` | `\[\{"id":int,"nome":"..."},...\]` |
| `/api/lookups/documenti` | `GET` | `\[\{"id":int,"nome":"..."},...\]` |
| `/api/lookups/tipo_alloggiato` | `GET` | `\[\{"id":int,"nome":"..."},...\]` |
| `/api/lookups/tipo_turismo` | `GET` | `\[\{"value":"..."},...\]` |
| `/api/lookups/mezzo_trasporto` | `GET` | `\[\{"value":"..."},...\]` |
| `/api/lookups/provincie` | `GET` | `\["AA","BB","CC",...\]` |
| `/api/lookups/comuni/provincia?provincia=XX` | `GET` | `\[\{"id":int,"nome":"..."},...\]` · `400` se `provincia` assente |

Chiama `LookupManager::{getLookups|getComuni|getValori|getProvinces|getComuniByProvince}(...)`.

---

## Configurazione (riferimento chiavi lette)

| Chiava | Letta da | Default |
|---|---|---|
| `app.ambiente` | `Auth.php`, `AuthService.php` | `"local"` |
| `app.token_expiry_hours` | `Auth.php` | `24` |
| `app.reg_token_expiry_days` | `RegistrationService.php` | `7` |
| `app.timezone` | `index.php` (bootstrap) | `"Europe/Rome"` |
| `server.base_url` | `RegistrationService.php` (generateLink) | prod URL |
| `server.asset_base` | `index.php` (`asset_url()`) | `""` |
| `database.host` | `Database.php` | — |
| `database.nome` | `Database.php` | — |
| `database.user` | `Database.php` | — |
| `database.pass` | `Database.php` | — |
| `database.charset` | `Database.php` | `"utf8mb4"` |
| `airbnb.ics_url` | `IcsImportService.php` | `""` |
| `ross1000.codice_posizione` | `index.php:314` (export-ross1000) | `""` |
| `ross1000.tassa_soggiorno` | `index.php:315` (export-ross1000) | `"1"` |
| `availability.prep_before_days` | `AvailabilityService.php` | `1` |
| `availability.prep_after_days` | `AvailabilityService.php` | `1` |

Override env: `APP_` + sezione + chiave, `.` → `_`, UPPER.
Esempio: `APP_ROSS1000_CODICE_POSIZIONE`, `APP_DATABASE_NOME`.
