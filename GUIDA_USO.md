# Guida all'uso

Flusso operativo end-to-end: dall'impostazione iniziale fino all'export per
**ROSS1000** e **Portale Alloggiati**.

> Il percorso è lineare: prepara la struttura → importa le prenotazioni
> dall'ICS di Airbnb → completa i dati di ogni ospite → genera il link di
> registrazione → l'ospite compila il form → quando la registrazione è
> confermata, esporta i file per la comunicazione agli organi competenti.

---

## Indice

1. [Accesso al sistema](#1-accesso-al-sistema)
2. [Impostazione iniziale: camere e calendario Airbnb](#2-impostazione-iniziale-camere-e-calendario-airbnb)
3. [Sincronizzazione del calendario (import ICS)](#3-sincronizzazione-del-calendario-import-ics)
4. [Compilazione di un ospite](#4-compilazione-di-un-ospite)
5. [Invio del link di registrazione all'ospite](#5-invio-del-link-di-registrazione-allaspite)
6. [Compilazione del form da parte dell'ospite](#6-compilazione-del-form-da-parte-dellaspite)
7. [Export per ROSS1000 e Portale Alloggiati](#7-export-per-ross1000-e-portale-alloggiati)
8. [Stati e badge dell'ospite](#8-stati-e-badge-dell-ospite)

---

## 1. Accesso al sistema

1. Apri la pagina di accesso (URL `/login` oppure la root del sito, che viene
   reindirizzata lì).
2. Inserisci **Username** e **Password** dell'account amministratore.
3. Premi **Accedi**.

Una volta autenticato, in alto a destra trovi la barra di navigazione:

- **Ospiti** — elenco degli ospiti (pagina principale).
- **Amministrazione** — impostazioni struttura (camere, letti, ecc.).
- **Esci** — termina la sessione.

> Le credenziali di default sono create al primo avvio del database; sono da
> modificare al primo accesso.

---

## 2. Impostazione iniziale: camere e calendario Airbnb

Prima di importare le prenotazioni servono due prerequisiti:

### 2.1 Creare il tipo di camera

1. Vai in **Amministrazione** → **Camera e letti**.
2. Crea il **tipo di camera** (es. *Doppia*, *Matrimoniale*, *Suite*).
3. Crea una o più **camere** collegate al tipo, indicando il **numero di letti**
   disponibile in ciascuna.

I letti totali e le camere dichiarati qui vengono calcolati a runtime e
utilizzati nelle esportazioni (es. conteggio posti in ROSS1000).

### 2.2 Impostare il calendario ICS di Airbnb

1. Da Airbnb, esporta il link **ICS** del calendario prenotazioni della tua
   struttura (URL che termina con `.ics`).
2. Imposta l'URL del calendario nella configurazione (`airbnb.ics_url`).
3. Verifica con un test che il link sia raggiungibile: l'applicazione lo
   scaricherà automaticamente in ogni sincronizzazione.

> **Nota su URL/asset**: il link ICS va salvato nella configurazione, non
> hardcodato nelle viste. Eventuali prefissi di URL sono gestiti dal sistema in
> modo coerente.

---

## 3. Sincronizzazione del calendario (import ICS)

La sincronizzazione scarica le prenotazioni dall'ICS di Airbnb e le crea come
ospiti (origine *Airbnb*).

1. Nella pagina **Ospiti**, premi il pulsante **Sincronizza calendario** (icona
   di aggiornamento / "sync").
2. Conferma la procedura nella finestra di richiesta.
3. Al termine si apre il **riepilogo**:
   - **Importati** — quante prenotazioni sono state importate.
   - **Sovrapposizioni** — prenotazioni che si sovrapposono ad altre: vengono
     comunque importate e marcate come sovrapposizione (l'ultimo evento valido
     vale per la disponibilità).
   - **Conservati (anomalia)** — prenotazioni rimosse dall'ICS ma ancora legate
     a un link di registrazione attivo: sono conservate e segnalate.
4. Chiudi il riepilogo. Le nuove prenotazioni compaiono nell'elenco.

> Il calendario viene **rigenerato** a ogni sincronizzazione: le prenotazioni
> Airbnb presenti ora sostituiscono quelle del turno precedente. I dati inseriti
> manualmente (ospiti "manuali") non vengono toccati.

---

## 4. Compilazione di un ospite

Ogni prenotazione importata arriva con date e numeri, ma **senza anagrafica**:
è l'host a completare il capofamiglia.

1. Nell'elenco ospiti, individua la riga dell'ospite (es. importato da
   calendario) e premi **Edit** (icona matita / tasto *Edit*).
2. Si apre il form del capofamiglia. Compila i campi:
   - **Nome Capofamiglia** *
   - **Cognome Capofamiglia** *
   - **Email** *
   - **Numero Ospiti** *
   - **Tipo Ospite** * — selezione dal menu (es. camera singola, doppia, ecc.)
   - **Data Arrivo** *
   - **Data Partenza** *
   - **Giorni Soggiorno** — calcolato automaticamente (solo lettura).
3. Verifica che il pulsante **Salva** (o **Aggiorna Ospite**) sia attivo.
4. Premi **Salva Ospite** / **Aggiorna Ospite**.

> I campi contrassegnati con \** sono obbligatori: il pulsante di salvataggio
> resta disabilitato finché non sono tutti compilati correttamente.

Dopo il salvataggio ritorni all'elenco; l'ospite ora ha i dati del capofamiglia.

---

## 5. Invio del link di registrazione all'ospite

Con l'ospite compilato, generi il link che l'ospite userà per inserire i propri
dati personali.

1. Nell'elenco ospiti, sulla riga dell'ospite, premi il pulsante di link
   (**Generate & Copy Link** / icona catena).
2. Il sistema genera il link di registrazione e lo **copia automaticamente
   negli appunti**; il pulsante cambia in **Copied! ✅**.
3. Invia il link all'ospite (e-mail, WhatsApp, ecc.).

Il link è **unico e a scadenza**: l'ospite lo usa per completare la
registrazione. Quando la registrazione sarà confermata, quel pulsante non sarà
più attivo (l'ospite mostra il badge *Registrazione confermata*).

> Il link di registrazione dà accesso al profilo **ospite** (non amministratore)
> solo per la compilazione dei dati: non consente modifiche alle prenotazioni.

---

## 6. Compilazione del form da parte dell'ospite

L'ospite apre il link ricevuto e completa il modulo in due step.

### Step 1 — Dati della persona e residenza

- Nome, cognome, data e luogo di nascita
- Residenza (comune, provincia, stato)
- Cittadinanza
- Documento (tipo, numero, città e data di rilascio)
- Recapiti (e-mail, telefono)

### Step 2 — Dati di viaggio

- Dati relativi alla viaggio/soggiorno richiesti dalla normativa (es. mezzi e
  dettagli del soggiorno).

### Invio e conferma

1. Alla fine dello Step 1 l'ospite viene guidato allo Step 2.
2. Dopo l'invio dello Step 2, si apre la **pagina di conferma** ("dati
   ricevuti").
3. Da questo momento, per l'host, la riga dell'ospite mostra il badge
   **Registrazione confermata** e il menu di esportazione diventa attivo
   (colore verde).

> I dati inviati dall'ospite sono **definitivi** ai fini della comunicazione:
> verificali prima dell'export.

---

## 7. Export per ROSS1000 e Portale Alloggiati

Quando la registrazione è confermata, generi i file da trasmettere agli organi
competenti.

1. Nell'elenco ospiti, sulla riga dell'ospite **confermato**, premi il menu
   **Export TXT** (icona download / freccia).
2. Dal menu a tendina scegli la destinazione:
   - **Portale Alloggiati** — scarica il file
     `alloggiati_<id_registrazione>.txt`.
   - **ROSS1000** — scarica il file `ross1000_<id_registrazione>.txt`.
3. Salva il file e utilizzalo nella piattaforma di destinazione.

### Requisiti per l'export

- **Portale Alloggiati** — la registrazione deve essere confermata.
- **ROSS1000** — in configurazione è necessario aver impostato il
  `codice_posizione` (campo obbligatorio): è un placeholder che deve essere
  compilato prima dell'export. Le camere/letti totali sono calcolati a runtime
  da *camere* e *room_types* (vedi §2.1).

> Il pulsante di export resta **disabilitato** (grigio) fino a quando la
> registrazione non è confermata.

---

## 8. Stati e badge dell'ospite

Nell'elenco ogni ospite può mostrare questi indicatori:

| Badge | Significato | Azione |
|---|---|---|
| **Registrazione confermata** (verde) | L'ospite ha completato il form. | Puoi esportare (ROSS1000 / Portale Alloggiati). |
| **⚠ Anomalia** (ambra) | La prenotazione non è più presente nel calendario ICS ma ha un link di registrazione attivo: è stata **conservata**. | Verifica manualmente prima di modificarla/eliminarla. |
| **⚠ Sovrapposizione** (arancione) | Il periodo sovrappone un'altra prenotazione: è stata comunque importata come ultimo evento valido. | Controlla i doppioni e regola le date se serve. |

Se nella riga vedi invece il link non attivo e nessun badge di conferma, la
registrazione è ancora **in attesa**: genera il link (§5) e aspetta l'ospite.

---

## Riepilogo del flusso

```
Camere + ICS Airbnb
        └──▶ Sincronizza calendario (import ICS)
                    └──▶ Compila l'ospite (Edit → Salva)
                            └──▶ Genera e copia il link di registrazione
                                    └──▶ Ospite compila Step 1 + Step 2 → Invia
                                            └──▶ Registrazione confermata (badge verde)
                                                    └──▶ Export ROSS1000 / Portale Alloggiati
```
