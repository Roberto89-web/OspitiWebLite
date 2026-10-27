# Ospiti Web Client

Single Page Application Angular per la gestione degli ospiti. Hostata su un hosting WordPress condiviso (Apache + cPanel). Il backend API coabita nello stesso spazio web.

---

## Struttura del Progetto

```
ospiti-web-client/
├── src/
│   ├── app/
│   │   ├── core/
│   │   │   ├── components/          # Componenti riutilizzabili (es. notification)
│   │   │   ├── guards/              # Route guards (auth.guard.ts)
│   │   │   ├── interceptors/        # HTTP interceptors (auth.interceptor.ts)
│   │   │   ├── models/              # Interfacce TypeScript API
│   │   │   └── services/            # Servizi singleton (auth, guest, member-type)
│   │   ├── features/
│   │   │   ├── auth/                # Login page e logica autenticazione
│   │   │   ├── guests/              # Lista ospiti, form inserimento, calendario
│   │   │   └── layout/              # Header componente
│   │   ├── app.ts                   # Componente root (standalone)
│   │   ├── app.html
│   │   ├── app.routes.ts            # Routing centrale
│   │   ├── app.config.ts            # App config provider
│   │   └── app.scss
│   ├── environments/
│   │   ├── environment.ts           # Dev — apiUrl: http://localhost:8080
│   │   └── environment.prod.ts      # Prod —.apiUrl: https://api.tuodominio.it
│   ├── main.ts                      # Entry point Angular
│   ├── styles.scss                  # Stili globali
│   └── tailwind.css                 # Configurazione Tailwind
├── public/
│   ├── .htaccess                     # Regole SPA rewrite (copiato in dist/)
│   └── favicon.ico
├── dist/ospiti-web-client/          # Build di produzione (output)
│   ├── index.html
│   ├── *.js, *.css                  # Bundle con hash
│   └── .htaccess
├── angular.json                     # Configurazione build Angular
├── proxy.conf.json                  # Proxy dev per backend locale
├── package.json
└── README.md
```

---

## Struttura sull'Hosting WordPress (raccomandata: sottocartella)

Si consiglia di **non** mescolare frontend e backend nella root del dominio. Usare sottocartelle dedicate:

```
public_html/                          <-- Root WordPress
├── .htaccess                         <-- WordPress (NON toccare)
├── wp-admin/
├── wp-content/
├── wp-includes/
├── index.php                         <-- WordPress
├── ospiti/                           <-- Frontend Angular (SPA)
│   ├── .htaccess                     <-- Regole SPA rewrite
│   ├── index.html
│   ├── *.js                          <-- Bundle Angular
│   └── *.css
└── api/                              <-- Backend API (già esistente o da migrare qui)
    ├── *.php / *.jar / ...           <-- File del backend
    └── .htaccess                     ← regole CORS (se necessario)
```

### Perché una sottocartella?

| Aspetto | Sottocartella (`/ospiti/`) | Root condivisa |
|---------|---------------------------|----------------|
| **Sicurezza** | Separazione totale tra frontend e backend | Rischio conflitti `.htaccess` e file |
| **Manutenzione** | Ogni parte si gestisce indipendentemente | Modifiche a WordPress possono rompere l'app |
| **Deploy** | Basta sostituire i file di `ospiti/` | Bisogna fare attenzione a non sovrascrittore file WordPress |
| **Caching** | Cache separata e configurabile | Cache condivisa, più difficile da gestire |

---

## 1. Prerequisiti

- **Locale:** Node.js (v20+), npm installato
- **Hosting:** Accesso FTP o cPanel File Manager all'hosting WordPress
- **Dominio:** Configurato con certificato SSL attivo (HTTPS)
- **Backend API:** Raggiungibile via HTTPS dall frontend

---

## 2. Buildare l'app in locale

```bash
npm install             # installa le dipendenze (prima volta solo)
npm run build           # genera dist/ospiti-web-client/ con tutti i file di produzione
```

Dopo il build, la cartella `dist/ospiti-web-client/` contiene **tutto** ciò che va caricato sull'hosting:

| File | Scopo |
|------|-------|
| `index.html` | Entry point dell'app |
| `*.js`, `*.css` | Bundle con hash (cache-busting automatico) |
| `.htaccess` | Regole SPA routing (tutte le rotte → index.html) |
| `favicon.ico` | Icona del browser |

---

## 3. Configurare l'ambiente di produzione

### 3.1 API URL nel file environment

Modificare `src/environments/environment.prod.ts`:

```typescript
export const environment = {
  production: true,
  apiUrl: 'https://api.tuodominio.it'   // <-- URL del backend in produzione
};
```

> **Nota:** se il backend rimane nella root condivisa con WordPress, usare `apiUrl: '/api/'` (path-relative) per evitare problemi CORS. Esempio: `https://tuodominio.it/api/endpoint`.

### 3.2 Se l'app va in una sottocartella

Se l'app risiede su `https://tuodominio.it/ospiti/` invece che sulla root, verificare in `angular.json`:

```json
"options": {
  "baseHref": "/ospiti/",
  ...
}
```

Ricostruire con `npm run build` dopo questa modifica.

---

## 4. Caricare i file sull'hosting

### Metodo A — Via cPanel (File Manager)

1. Accedere al **cPanel** dell'hosting WordPress
2. Aprire **File Manager**
3. Navigare nella cartella di destinazione:
   - **Sottocartella (consigliato):** `public_html/ospiti/` (crearla se non esiste)
4. Cliccare su **Carica** e selezionare **tutti i file** da `dist/ospiti-web-client/`
5. Attendere il completamento del caricamento

### Metodo B — Via FTP con FileZilla

1. Connettersi alle credenziali FTP dell'hosting
2. Nella colonna di destra (server), creare la cartella `ospiti/` sotto `public_html/`
3. Nella colonna di sinistra (locale), aprire `dist/ospiti-web-client/`
4. **Selezionare tutti i file** e trascinarli nella colonna di destra
5. Attendere il completamento del trasferimento

---

## 5. Configurare il Backend API sul server

### 5.1 Migrare il backend (se ancora su localhost)

Se il backend è attualmente su `http://localhost:8080`, caricarlo nell'hosting condiviso:

1. Creare la cartella `public_html/api/`
2. Caricare tutti i file del backend (.php, .jar, config, ecc.)
3. Se il backend è basato su PHP: configurare un virtual host o un entry point come `index.php` che accetta richieste HTTP sul path `/api/`
4. Se il backend è un servizio Java/Node: verificare con l'hosting se è possibile avviare processi background (solitamente no su hosting condiviso — valutare VPS o PaaS come Railway/Render)

### 5.2 CORS

Il backend deve accettare richieste dal dominio dell'app:

```
Access-Control-Allow-Origin: https://tuodominio.it
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type
```

### 5.3 URL API nell'ambiente di produzione

Aggiornare `apiUrl` in `src/environments/environment.prod.ts`:

| Deployment backend | apiUrl consigliato |
|--------------------|-------------------|
| Backend in `public_html/api/` | `'https://tuodominio.it/api/'` |
| Backend su dominio separato | `'https://api.tuodominio.it'` |
| Backend su sottodominio | `'https://api.tuodominio.it'` |

---

## 6. Configurare .htaccess

### Caso A: L'app in sottocartella (raccomandato)

Creare il file `.htaccess` **dentro `public_html/ospiti/`** (viene incluso automaticamente dal build grazie a `public/.htaccess`):

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /ospiti/
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
```

Sostituire `/ospiti/` con il nome della sottocartella effettiva.

> **Il `.htaccess` di WordPress in `public_html/` NON va toccato.**

### Caso B: Backend API — regole CORS in `.htaccess`

Se il backend è PHP e non puoi configurare CORS a livello di codice, aggiungere in `public_html/api/.htaccess`:

```apache
<IfModule mod_headers.c>
  SetEnvIfOrigin "https://tuodominio.it$" CORS_ORIGIN=$0
  Header set Access-Control-Allow-Origin %{CORS_ORIGIN}e env=CORS_ORIGIN
  Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
  Header set Access-Control-Allow-Headers "Authorization, Content-Type"
</IfModule>

# Gestire le richieste preflight OPTIONS
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_METHOD} ^OPTIONS$
  RewriteRule ^ - [R=204,L]
</IfModule>
```

---

## 7. Verificare HTTPS (SSL)

1. Accedere al pannello di controllo dell'hosting
2. Verificare che il certificato SSL sia attivo (Let's Encrypt o equivalente)
3. Controllare che `https://tuodominio.it/ospiti/` carichi senza errori SSL
4. Assicurarsi che non ci siano avvisi di **mixed content** (risorse HTTP su HTTPS)

---

## 8. Abilitare la compressione Gzip/Brotli

### Via cPanel

- **cPanel → Optimize Website** → spuntare "Compress all content"

### Oppure via `.htaccess` in `public_html/ospiti/`:

```apache
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>
```

---

## 9. Checklist finale prima di rendere pubblico

- [ ] `apiUrl` aggiornato in `src/environments/environment.prod.ts`
- [ ] Build eseguito con `npm run build` (ultimo)
- [ ] File caricati nella cartella `public_html/ospiti/` sull'hosting
- [ ] Backend caricato in `public_html/api/` (se necessario)
- [ ] `.htaccess` SPA configurato in `public_html/ospiti/.htaccess`
- [ ] CORS configurato sul backend per il dominio frontend
- [ ] Certificato SSL attivo (`https://`)
- [ ] Il sito WordPress è ancora accessibile a `https://tuodominio.it/`
- [ ] L'app è accessibile a `https://tuodominio.it/ospiti/` senza errori
- [ ] Le rotte interne funzionano (prova a fare refresh su una pagina interna)
- [ ] Login testato con successo in produzione
- [ ] Compressione Gzip/Brotli attivata
- [ ] Test su dispositivi mobili completato

---

## 10. Flusso di sviluppo → produzione (riepilogo rapido)

```
┌─────────────────────┐     ┌──────────────────┐     ┌─────────────────────┐
│  1. Modificare      │     │  2. Build        │     │  3. Caricare su     │
│  environment.prod.ts│────▶│  npm run build   │────▶│  hosting (FTP/cP)   │
│  con URL API corretto│     │  → dist/         │     │  public_html/ospiti/│
└─────────────────────┘     └──────────────────┘     └─────────────────────┘

┌─────────────────────┐     ┌──────────────────┐     ┌─────────────────────┐
│  4. Configurare     │     │  5. Verificare   │     │  6. Test            │
│  .htaccess SPA      │────▶│  HTTPS + CORS    │────▶│  Login + rotte      │
│  + regole CORS      │     │                  │     │                     │
└─────────────────────┘     └──────────────────┘     └─────────────────────┘
```

---

## 11. Risoluzione problemi comuni

| Problema | Causa | Soluzione |
|----------|-------|-----------|
| Pagina bianca all'accesso | `.htaccess` mancante o errato | Verificare che `.htaccess` sia in `public_html/ospiti/` con regole corrette |
| Errore 404 su rotte interne | Regole SPA non applicate | Controllare `.htaccess` e `baseHref` in `angular.json` |
| Mixed content (HTTP/HTTPS) | `apiUrl` punta a HTTP | Cambiare in `https://` in `environment.prod.ts` |
| CORS error in console | Backend non espone il dominio | Aggiungere `Access-Control-Allow-Origin` sul backend o nel `.htaccess/api/` |
| WordPress va in bianco dopo modifiche `.htaccess` | Regole sovrascritte | Ripristinare il backup di `public_html/.htaccess` |
| File non aggiornati sull'hosting | Cache browser | Cancellare cache; i file JS/CSS hanno hash per cache-busting automatico |
| Backend non raggiungibile | Non migrato sul server | Seguire i passaggi del Sezione 5 |

---

## Flusso di Autenticazione

1. L'utente accede tramite `/login` con username e password
2. Il backend restituisce un token JWT
3. Il token viene salvato in `localStorage` da `AuthService`
4. `AuthInterceptor` aggiunge automaticamente `Authorization: Bearer <token>` a ogni richiesta HTTP (escluso `/login`)
5. Al ricevere un 401, l'interceptor mostra un messaggio e invalida la sessione
