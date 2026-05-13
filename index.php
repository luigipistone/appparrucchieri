<?php
require_once __DIR__ . '/includes/bootstrap.php';
$resetToken = htmlspecialchars((string)($_GET['reset'] ?? ''), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="it" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#335eac">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Barber">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <title><?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css?v=20260513-11">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="#dashboard" aria-label="Homepage portale parrucchieri">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 20 20 4M7 7l10 10M6.5 5.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Zm16 13a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Z"/></svg>
                </span>
                <span><strong>Barber</strong><small>booking</small></span>
            </a>
            <div class="top-actions">
                <button class="icon-btn" id="themeToggle" type="button" aria-label="Cambia tema">
                    <svg viewBox="0 0 24 24"><path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.06 16.94l-1.42 1.42m12.72 0-1.42-1.42M7.06 7.06 5.64 5.64M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/></svg>
                </button>
                <button class="icon-btn hidden notification-btn" id="notificationsBtn" type="button" aria-label="Notifiche">
                    <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8.5 12a3 3 0 0 0 5 0"/></svg>
                    <span class="notification-badge hidden" id="notificationBadge">0</span>
                </button>
                <button class="icon-btn hidden" id="profileHeaderBtn" type="button" aria-label="Profilo">
                    <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0"/></svg>
                </button>
                <button class="ghost hidden" id="logoutBtn" type="button">Esci</button>
            </div>
        </header>

        <main>
            <section id="authView" class="auth-grid">
                <div class="hero-card">
                    <span class="eyebrow">Prenotazioni smart</span>
                    <h1>Il salone da uomo sempre in tasca.</h1>
                    <p>Scegli servizio, giorno e orario in pochi tocchi. L'app è pensata mobile-first, semplice e veloce anche per l'amministratore.</p>
                    <div class="hero-points">
                        <span>Calendario mensile</span><span>Posti ogni 30 min</span><span>WhatsApp admin</span>
                    </div>
                </div>
                <div class="panel auth-panel">
                    <div class="tabs" role="tablist">
                        <button class="tab active" data-auth-tab="login" type="button">Accedi</button>
                        <button class="tab" data-auth-tab="register" type="button">Registrati</button>
                        <button class="tab" data-auth-tab="forgot" type="button">Recupera</button>
                    </div>
                    <form id="loginForm" class="auth-form" data-auth-pane="login">
                        <label>Email o telefono<input name="login" autocomplete="username" required></label>
                        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
                        <button class="primary" type="submit">Entra</button>
                        <p class="hint">Admin demo: admin@salone.local / Admin123!</p>
                    </form>
                    <form id="registerForm" class="auth-form hidden" data-auth-pane="register">
                        <div class="two-cols"><label>Nome<input name="first_name" required></label><label>Cognome<input name="last_name" required></label></div>
                        <label>Email<input name="email" type="email" autocomplete="email" required></label>
                        <label>Telefono<input name="phone" type="tel" autocomplete="tel" required></label>
                        <label>Password<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
                        <button class="primary" type="submit">Crea account</button>
                    </form>
                    <form id="forgotForm" class="auth-form hidden" data-auth-pane="forgot">
                        <label>Email o telefono<input name="identifier" required></label>
                        <label>Canale<select name="channel"><option value="email">Email</option><option value="telefono">Telefono</option></select></label>
                        <button class="primary" type="submit">Invia recupero</button>
                    </form>
                    <form id="resetForm" class="auth-form <?= $resetToken ? '' : 'hidden' ?>">
                        <h2>Imposta nuova password</h2>
                        <input type="hidden" name="token" value="<?= $resetToken ?>">
                        <label>Nuova password<input name="password" type="password" minlength="8" required></label>
                        <button class="primary" type="submit">Aggiorna password</button>
                    </form>
                </div>
            </section>

            <section id="appView" class="hidden">
                <nav class="mobile-nav" aria-label="Navigazione principale">
                    <button class="nav-item active" data-view="dashboard" type="button"><svg viewBox="0 0 24 24"><path d="M8 2v4m8-4v4M3 10h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg><span>Calendario</span></button>
                    <button class="nav-item" data-view="appointments" type="button"><svg viewBox="0 0 24 24"><path d="M9 11l2 2 4-4M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/></svg><span>Appuntamenti</span></button>
                    <button class="nav-item admin-only" data-view="services" type="button"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Zm0 5 8 4 8-4M4 17l8 4 8-4"/></svg><span>Servizi</span></button>
                    <button class="nav-item admin-only" data-view="clients" type="button"><svg viewBox="0 0 24 24"><path d="M16 11a4 4 0 1 0-8 0m8 0a4 4 0 1 1-8 0m8 0h1a4 4 0 0 1 4 4v1M8 11H7a4 4 0 0 0-4 4v1m4 4h10a2 2 0 0 0 2-2 6 6 0 0 0-14 0 2 2 0 0 0 2 2Z"/></svg><span>Clienti</span></button>
                    <button class="nav-item admin-only" data-view="closures" type="button"><svg viewBox="0 0 24 24"><path d="M8 2v4m8-4v4M3 10h18M7 14h4m-4 4h7M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg><span>Chiusure</span></button>
                </nav>

                <section class="view" id="dashboardView">
                    <div class="section-head">
                        <div><span class="eyebrow" id="roleLabel">Area cliente</span><h1 id="dashboardTitle">Scegli il servizio</h1></div>
                        <input type="month" id="monthPicker">
                    </div>
                    <div id="bookingSteps" class="booking-steps client-only">
                        <span data-step-dot="services">1. Servizio</span>
                        <span data-step-dot="calendar">2. Disponibilità</span>
                        <span data-step-dot="confirm">3. Conferma</span>
                    </div>
                    <div id="serviceCards" class="cards"></div>
                    <div id="serviceStepActions" class="step-actions client-only"><button class="primary" id="serviceNextBtn" type="button">Prosegui</button></div>
                    <div class="calendar-card panel" id="calendarCard">
                        <div class="calendar-head"><button class="ghost" id="prevMonth" type="button">←</button><h2 id="calendarTitle"></h2><button class="ghost" id="nextMonth" type="button">→</button></div>
                        <div class="weekdays"><span>Lun</span><span>Mar</span><span>Mer</span><span>Gio</span><span>Ven</span><span>Sab</span><span>Dom</span></div>
                        <div id="calendarGrid" class="calendar-grid"></div>
                    </div>
                    <div id="confirmStep" class="panel confirm-card client-only hidden">
                        <span class="eyebrow">Ultimo step</span>
                        <h2>Conferma prenotazione</h2>
                        <p id="bookingSummary" class="hint"></p>
                        <div class="actions"><button class="ghost" id="backToCalendarBtn" type="button">Indietro</button><button class="primary" id="confirmBookingBtn" type="button">Conferma prenotazione</button></div>
                    </div>
                </section>

                <section class="view hidden" id="appointmentsView">
                    <div class="section-head"><div><span class="eyebrow">Agenda</span><h1>I tuoi appuntamenti</h1></div></div>
                    <div class="panel form-grid appointment-filter"><div class="two-cols"><label>Filtra per data<input id="appointmentDateFilter" type="date"></label><div class="filter-actions"><button class="ghost" id="clearAppointmentFilterBtn" type="button">Mostra tutti</button></div></div></div>
                    <div id="appointmentList" class="list"></div>
                </section>

                <section class="view hidden" id="servicesView">
                    <div class="section-head"><div><span class="eyebrow">Admin</span><h1>Gestione servizi</h1></div><button class="primary" id="newServiceBtn" type="button">Nuovo</button></div>
                    <div id="adminServiceList" class="list"></div>
                </section>

                <section class="view hidden" id="clientsView">
                    <div class="section-head"><div><span class="eyebrow">Admin</span><h1>Clienti</h1></div></div>
                    <div class="panel form-grid search-panel"><label>Cerca cliente<input id="clientSearch" type="search" placeholder="Nome o cognome"></label></div>
                    <div id="clientList" class="list"></div>
                </section>

                <section class="view hidden" id="closuresView">
                    <div class="section-head"><div><span class="eyebrow">Admin</span><h1>Chiusure</h1></div></div>
                    <form id="closuresForm" class="panel form-grid">
                        <label>Giorni di chiusura settimanali</label>
                        <div id="weeklyClosures" class="closure-grid"></div>
                        <label>Giorni speciali</label>
                        <div class="two-cols"><input id="specialClosureDate" type="date"><input id="specialClosureLabel" placeholder="Descrizione es. Natale"></div>
                        <button class="ghost" id="addSpecialClosureBtn" type="button">Aggiungi giorno speciale</button>
                        <div id="specialClosures" class="list compact-list"></div>
                        <button class="primary" type="submit">Salva chiusure</button>
                    </form>
                </section>

                <section class="view hidden" id="profileView">
                    <div class="section-head"><div><span class="eyebrow">Account</span><h1>Profilo</h1></div></div>
                    <form id="profileForm" class="panel form-grid"></form>
                </section>

                <section class="view hidden" id="notificationArchiveView">
                    <div class="section-head"><div><span class="eyebrow">Notifiche</span><h1>Archivio notifiche</h1></div></div>
                    <div id="notificationArchiveList" class="list"></div>
                </section>
            </section>
        </main>
    </div>


    <dialog id="notificationsDialog" class="modal">
        <form method="dialog" class="panel notifications-panel">
            <button class="close" value="cancel" aria-label="Chiudi">×</button>
            <div class="modal-head"><h2>Notifiche</h2><button class="ghost" id="markNotificationsReadBtn" type="button">Segna lette</button></div>
            <button class="ghost notification-permission hidden" id="enableDeviceNotificationsBtn" type="button">Attiva notifiche dispositivo</button>
            <div id="notificationsList" class="list compact-list"></div>
            <button class="archive-link" id="openNotificationArchiveBtn" type="button">Archivio</button>
        </form>
    </dialog>

    <dialog id="slotDialog" class="modal">
        <form method="dialog" class="panel">
            <button class="close" value="cancel" aria-label="Chiudi">×</button>
            <h2 id="slotDialogTitle">Prenota appuntamento</h2>
            <div id="slotList" class="slot-list"></div>
        </form>
    </dialog>


    <dialog id="appointmentDialog" class="modal">
        <form id="appointmentEditForm" class="panel">
            <button class="close" value="cancel" type="button" data-close-dialog>×</button>
            <h2>Modifica appuntamento</h2>
            <input type="hidden" name="id"><input type="hidden" name="user_id">
            <label>Servizio<select name="service_id" required></select></label>
            <div class="two-cols"><label>Data<select name="date" required></select></label><label>Orario<select name="time" required></select></label></div>
            <button class="primary" type="submit">Salva appuntamento</button>
        </form>
    </dialog>

    <dialog id="userDialog" class="modal">
        <form id="userEditForm" class="panel">
            <button class="close" value="cancel" type="button" data-close-dialog>×</button>
            <h2>Modifica utente</h2>
            <input type="hidden" name="id">
            <div class="two-cols"><label>Nome<input name="first_name" required></label><label>Cognome<input name="last_name" required></label></div>
            <label>Email<input name="email" type="email" required></label>
            <label>Telefono<input name="phone" type="tel" required></label>
            <label>Ruolo<select name="role"><option value="cliente">Cliente</option><option value="admin">Admin</option></select></label>
            <label>Nuova password <span class="hint">(opzionale)</span><input name="password" type="password" minlength="8"></label>
            <button class="primary" type="submit">Salva utente</button>
        </form>
    </dialog>

    <dialog id="serviceDialog" class="modal">
        <form id="serviceForm" class="panel" enctype="multipart/form-data">
            <button class="close" value="cancel" type="button" data-close-dialog>×</button>
            <h2>Servizio</h2>
            <input type="hidden" name="id"><input type="hidden" name="existing_image">
            <label>Nome<input name="name" required></label>
            <label>Descrizione<textarea name="description" rows="3"></textarea></label>
            <div class="two-cols"><label>Prezzo<input name="price" type="number" step="0.01" min="0" required></label><label>Minuti<input name="duration_minutes" type="number" step="30" min="30" required></label></div>
            <label>Immagine<input name="image" type="file" accept="image/*"></label>
            <label class="check"><input name="active" type="checkbox" value="1" checked> Attivo</label>
            <button class="primary" type="submit">Salva servizio</button>
        </form>
    </dialog>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script>window.APP_BOOT = { resetToken: "<?= $resetToken ?>" };</script>
    <script src="assets/booking-app.js?v=20260513-11" defer></script>
</body>
</html>
