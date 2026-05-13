# Portale Parrucchieri

Webapp mobile-first in PHP, JavaScript/AJAX e MySQL per gestire prenotazioni di un salone da uomo.

## Funzionalità principali

- Registrazione e accesso cliente con nome, cognome, email, telefono e password.
- Ruoli `admin` e `cliente`.
- Recupero password tramite email o telefono con token temporaneo.
- Dashboard cliente con elenco servizi, calendario mensile e slot disponibili ogni 30 minuti.
- Prenotazione automatica confermata, modifica ed eliminazione appuntamenti.
- Dashboard admin con calendario mensile di tutti gli appuntamenti.
- Gestione appuntamenti, apertura WhatsApp verso il cliente, gestione servizi e clienti.
- Profilo modificabile per clienti e admin, inclusa la password.
- Tema light/dark, layout responsive e palette blu/rosso/bianco/grigi.

## Installazione su Plesk

1. Caricare tutti i file nella document root del dominio o sottodominio.
2. Importare `database.sql` nel database MySQL `portale_parrucchieri`.
3. Verificare le credenziali in `includes/config.php`.
4. Assicurarsi che la cartella `uploads/services` sia scrivibile dal processo PHP per caricare le immagini dei servizi.

## Credenziali admin iniziali

- Email: `admin@salone.local`
- Password: `Admin123!`

Cambiare subito email, telefono e password dall'area profilo admin dopo il primo accesso.

## Configurazione orari

Gli orari di apertura sono definiti in `includes/config.php` nella costante `OPENING_HOURS`. Gli slot sono calcolati ogni 30 minuti e rispettano la durata del servizio scelto.
