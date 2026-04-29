=== Studio Fatichenti — Avvisi AdE Bridge ===

Inoltra i submit del modulo Contact Form 7 "Avvisi AdE" (già attivo da anni
sulla pagina /ade-comunicazioni/) al Portale Clienti dello studio
(VM on-premise) firmando ogni richiesta con HMAC SHA-256.
NESSUN servizio terzo coinvolto.

Il plugin NON modifica il modulo CF7 esistente: si aggancia agli hook di CF7
e legge i campi col loro nome attuale (mapping integrato per il form ID 3129).

== Aggiornamenti automatici da GitHub ==

Il plugin si aggiorna leggendo le release pubbliche del repo GitHub
"studiofatichenti/studio-fatichenti-ade-bridge". Quando rilascio una nuova
versione (tag vX.Y.Z), WordPress mostra il pulsante "Aggiorna ora" come
per qualunque altro plugin del repository ufficiale, e tu lo aggiorni
con un click dalla pagina "Plugin installati".

Procedura per rilasciare una nuova versione (lato sviluppatore):
  1. Aggiorna l'header del file PHP:    Version: 1.2.0
  2. Aggiorna anche la costante:        const SFA_VERSION = '1.2.0'
  3. Commit + push delle modifiche
  4. git tag v1.2.0 && git push origin v1.2.0
  5. Su GitHub: Releases → "Draft a new release" → seleziona il tag v1.2.0
     → Publish release. GitHub crea automaticamente lo ZIP del codice;
     non serve allegare uno ZIP custom.
  6. Entro 12 ore (o subito se clicchi "Controlla aggiornamenti" nella
     pagina Plugin di WP), WordPress vede la nuova release e mostra
     "Aggiorna ora" accanto al plugin.

Nessun token, nessuna credenziale: l'API GitHub releases è pubblica
e usa solo il repo già visibile a tutti.

== Cosa fa, in pratica ==

1. Quando un cliente compila il modulo sul sito, CF7 fa il suo lavoro come
   sempre (mail allo studio, eventuale reCAPTCHA, ecc.).

2. Subito DOPO la convalida CF7, questo plugin intercetta il submit, prende
   i dati + l'allegato e li firma con HMAC SHA-256, poi li inoltra al
   Portale via HTTPS server-to-server.

3. Se il Portale è raggiungibile e la firma è valida → il record entra
   nella coda del Portale e ogni 15 minuti viene importato nel CRM.

4. Se il Portale è giù o c'è un errore → la mail di CF7 viene comunque
   inviata allo studio (zero perdite di comunicazioni). L'errore è loggato
   nel pannello del plugin e nel log PHP.

== Difese implementate (tutte ON-PREMISE, niente servizi terzi) ==

  ✅ HMAC SHA-256 firmato lato WordPress, verificato lato Portale
  ✅ Honeypot field iniettato automaticamente nel modulo (invisibile)
  ✅ Time-based check: invio scartato se < 3 secondi dopo l'apertura
  ✅ Anti-replay: timestamp ±5 minuti
  ✅ Rate limit IP: 10 invii/ora sul Portale
  ✅ Origin/Referer check
  ✅ MIME whitelist + max 20 MB
  ✅ Validazione CF/email/data lato server
  ✅ Friction utente: ZERO. L'esperienza del cliente non cambia.

== Installazione ==

1. Comprimi questa cartella in un file ZIP chiamato
   `sfatichenti-ade-bridge.zip`.

2. WP Admin → Plugin → Aggiungi nuovo → Carica plugin → seleziona lo ZIP
   → Installa ora → Attiva.

   In alternativa via FTP: copia la cartella in
   `/wp-content/plugins/sfatichenti-ade-bridge/` e attiva dal pannello.

== Configurazione (3 valori) ==

WP Admin → Impostazioni → "Avvisi AdE Bridge"

  • Abilitato:        ✔
  • ID Form CF7:      3129  (è il modulo già esistente — non cambia)
  • URL endpoint:     https://portalestudio.fatichenti.com/api/ade-avviso
  • API Key:          (la stessa CRM_API_KEY del file .env del Portale)
  • HMAC secret:      (genera con: openssl rand -hex 32)

L'HMAC secret va anche nel file .env del Portale come variabile
ADE_AVVISI_HMAC_SECRET (devono essere identici).

== Mapping dei campi (integrato, non si tocca nulla) ==

Il plugin sa già che il modulo 3129 ha questi nomi e li traduce così:

  your-surname + your-name → nominativo (concatenati)
  your-email               → email
  Telefono                 → telefono
  your-subject             → oggetto
  your-message             → commento
  DataRicezioneAvviso      → data_ricezione_avviso (YYYY-MM-DD → GG/MM/AAAA)
  Allegato (PDF/JPG/PNG/ZIP, max 20 MB) → allegato

Campi che il modulo NON ha e quindi arriveranno vuoti nel CRM
(li compilerai a mano dal CRM dopo l'import):

  - codice_fiscale (per il match al cliente CRM — record "orfano")
  - tipologia_avviso (es. "Avviso bonario", "36-bis"…)
  - modello_dic (es. "Redditi PF", "730"…)
  - anno_imposta

Se in futuro vorrai aggiungere uno o più di questi campi al modulo CF7,
basterà aggiornare il file PHP del plugin per leggerli (poche righe).

== Verifica funzionamento ==

1. Apri /ade-comunicazioni/ in finestra anonima.
2. Compila e invia il modulo (allega un PDF di test).
3. Verifica:
   - Pagina di conferma di CF7 ✓
   - Mail di CF7 ricevuta dallo studio (come sempre) ✓
   - WP Admin → Impostazioni → Avvisi AdE Bridge → "Ultimo evento"
     deve mostrare "[OK] HTTP 200 — ..." ✓
   - CRM → Agenzia delle Entrate → tab "Avvisi AdE" → click
     "🔄 Sincronizza ora" → l'avviso deve comparire nella tabella ✓

== Logging ==

Il plugin scrive un'unica riga "ultimo evento" leggibile dal pannello
WordPress ed esegue anche `error_log()` PHP per la consultazione storica
dal log dell'hosting. Nessun dato sensibile è scritto nei log
(solo codice HTTP e primi 500 caratteri della risposta JSON).

== Sicurezza ==

  - Codice ispezionabile (singolo file ~250 righe).
  - Nessuna dipendenza da librerie esterne o servizi cloud.
  - HMAC con segreto condiviso → solo questo server può firmare richieste valide.
  - L'HMAC copre anche lo SHA-256 dell'allegato → file non modificabile in transito.
  - Anti-replay (timestamp ±5 min) → richieste intercettate non riproducibili.
