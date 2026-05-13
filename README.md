# Portale Parrucchieri

Webapp mobile-first in PHP, JavaScript/AJAX e MySQL per gestire prenotazioni di un salone da uomo.

## Funzionalità principali

- Registrazione e accesso cliente con nome, cognome, email, telefono e password.
- Ruoli `admin` e `cliente`.
- Recupero password tramite email o telefono con token temporaneo.
- Dashboard cliente con elenco servizi, calendario mensile e posti disponibili ogni 30 minuti.
- Prenotazione automatica confermata, modifica ed eliminazione appuntamenti.
- Dashboard admin con calendario mensile di tutti gli appuntamenti.
- Chiusure settimanali e giorni speciali configurabili dall'admin.
- Notifiche interne per admin e clienti su nuove prenotazioni, modifiche e cancellazioni, con popup per le non lette e archivio separato per quelle lette.
- Gestione appuntamenti, apertura WhatsApp verso il cliente, gestione servizi e clienti.
- Profilo modificabile per clienti e admin, inclusa la password.
- Tema light/dark, layout responsive e palette blu/rosso/bianco/grigi.
- Supporto PWA installabile con manifest, service worker e notifiche dispositivo mentre l'app è aperta o in background.

## Installazione su Plesk

1. Caricare tutti i file nella document root del dominio o sottodominio.
2. Verificare le credenziali in `includes/config.php`.
3. Applicare le migration SQL in ordine dalla cartella `migrations/` oppure eseguire da terminale `php scripts/migrate.php`.
4. In alternativa, per una prima installazione manuale unica, importare lo snapshot `database.sql`.
5. Assicurarsi che la cartella `uploads/services` sia scrivibile dal processo PHP per caricare le immagini dei servizi.


## Migrations database

Le modifiche database sono versionate nella cartella `migrations/` e vanno applicate in ordine numerico:

1. `001_create_database.sql` crea il database e la tabella `schema_migrations`.
2. `002_create_core_tables.sql` crea utenti, servizi, appuntamenti e recuperi password.
3. `003_seed_initial_data.sql` inserisce admin iniziale e servizi di esempio.
4. `004_create_closure_settings.sql` aggiunge chiusure settimanali e giorni speciali.
5. `005_create_notifications.sql` aggiunge le notifiche interne per admin e clienti.
6. `006_create_push_subscriptions.sql` salva le sottoscrizioni Web Push dei dispositivi.

Da terminale, se Plesk consente l'accesso SSH, puoi eseguire:

```bash
php scripts/migrate.php
```

Se usi phpMyAdmin o il pannello Plesk senza SSH, importa i file `.sql` uno alla volta nello stesso ordine. `database.sql` resta disponibile come snapshot completo per installazioni iniziali rapide, ma gli aggiornamenti futuri andranno aggiunti come nuove migration incrementali.


## PWA e notifiche dispositivo

L'app include `manifest.webmanifest` e `service-worker.js`, quindi può essere installata come PWA dai browser supportati. Dal popup notifiche l'utente può abilitare le notifiche dispositivo: il browser salva una sottoscrizione Push API collegata all'utente e il server invia un Web Push VAPID cifrato con titolo e testo ogni volta che viene creata una notifica interna. In questo modo la notifica può apparire anche se la PWA è chiusa, nei limiti del supporto push del browser/sistema operativo.

Le chiavi VAPID sono già precompilate in `includes/config.php` per evitare errori in prima installazione. Per una configurazione definitiva del tuo dominio è comunque consigliato rigenerarle e sostituire `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` e `VAPID_SUBJECT`:

```bash
php scripts/generate_vapid_keys.php
```

Le notifiche push richiedono HTTPS in produzione; in locale i browser di solito consentono `localhost`. Dopo aver cambiato la chiave pubblica VAPID, gli utenti devono disattivare/riattivare le notifiche sul dispositivo per registrare una nuova sottoscrizione.

## Credenziali admin iniziali

- Email: `admin@salone.local`
- Password: `Admin123!`

Cambiare subito email, telefono e password dall'area profilo admin dopo il primo accesso.

## Configurazione orari

Gli orari di apertura sono definiti in `includes/config.php` nella costante `OPENING_HOURS`. I posti sono calcolati ogni 30 minuti e rispettano la durata del servizio scelto.
