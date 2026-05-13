const state = { csrf: document.querySelector('meta[name="csrf-token"]')?.content || '', user: null, services: [], appointments: [], users: [], selectedService: null, month: new Date().toISOString().slice(0, 7), day: new Date().toISOString().slice(0, 10), availability: {}, bookingStep: 'services', pendingBooking: null, appointmentDateFilter: '', closureSettings: { weekly: [], special: [] }, editingAppointmentAvailability: {}, notifications: [], notificationArchive: [], unreadNotifications: 0, notifiedNotificationIds: new Set(), notificationPollTimer: null, pushSubscribed: false, toastTimer: null, appSettings: {} };
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

async function ensureCsrf(force = false) {
  if (state.csrf && !force) return state.csrf;
  if (!state.csrfPromise || force) {
    state.csrfPromise = fetch('api.php?action=csrf')
      .then(response => response.json())
      .then(payload => {
        if (!payload.ok || !payload.csrf) throw new Error(payload.message || 'Token di sicurezza non disponibile.');
        state.csrf = payload.csrf;
        return state.csrf;
      })
      .finally(() => { state.csrfPromise = null; });
  }
  return state.csrfPromise;
}

async function api(action, data = null, options = {}, retryOnCsrf = true) {
  if (data) await ensureCsrf();
  const init = { method: data ? 'POST' : 'GET', headers: { 'X-CSRF-Token': state.csrf }, ...options };
  if (data && !(data instanceof FormData)) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify({ ...data, csrf_token: state.csrf });
  } else if (data) {
    data.set('csrf_token', state.csrf);
    init.body = data;
  }
  const response = await fetch(`api.php?action=${action}`, init);
  const payload = await response.json().catch(() => ({ ok: false, message: 'Risposta non valida.' }));
  if (response.status === 419 && retryOnCsrf) {
    await ensureCsrf(true);
    return api(action, data, options, false);
  }
  if (!response.ok || !payload.ok) throw new Error(payload.message || 'Operazione non riuscita.');
  if (payload.csrf) state.csrf = payload.csrf;
  return payload;
}

function toast(message) {
  const node = $('#toast');
  node.textContent = message;
  node.classList.add('show');
  clearTimeout(state.toastTimer);
  state.toastTimer = setTimeout(() => {
    node.classList.remove('show');
    node.addEventListener('transitionend', () => { if (!node.classList.contains('show')) node.textContent = ''; }, { once: true });
  }, 3200);
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function setTheme(theme) {
  document.documentElement.dataset.theme = theme;
  localStorage.setItem('theme', theme);
  applyThemeSurface();
}

function applyThemeSurface() {
  const background = state.appSettings?.background_color || '#ffffff';
  if (document.documentElement.dataset.theme === 'dark') {
    document.documentElement.style.removeProperty('--bg');
    document.documentElement.style.removeProperty('--card');
    return;
  }
  document.documentElement.style.setProperty('--bg', background);
  document.documentElement.style.setProperty('--card', background);
}

function switchAuth(tab) {
  $$('.tab').forEach(btn => btn.classList.toggle('active', btn.dataset.authTab === tab));
  $$('[data-auth-pane]').forEach(pane => pane.classList.toggle('hidden', pane.dataset.authPane !== tab));
}

async function boot() {
  setTheme(localStorage.getItem('theme') || 'light');
  $('#monthPicker').value = state.month;
  bindEvents();
  const settings = await api('app_settings');
  state.appSettings = settings.settings || {};
  applyAppSettings();
  const me = await api('me');
  state.user = me.user;
  renderSession();
  if (state.user) await refreshAll();
}

function bindEvents() {
  $('#themeToggle').addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));
  $$('.tab').forEach(btn => btn.addEventListener('click', () => switchAuth(btn.dataset.authTab)));
  $('#loginForm').addEventListener('submit', submitAuth('login'));
  $('#registerForm').addEventListener('submit', submitAuth('register'));
  $('#forgotForm').addEventListener('submit', async event => { event.preventDefault(); const res = await api('forgot_password', formData(event.currentTarget)); toast(res.message); if (res.reset_url_demo) console.info('Reset URL demo:', res.reset_url_demo); });
  $('#resetForm').addEventListener('submit', async event => { event.preventDefault(); const res = await api('reset_password', formData(event.currentTarget)); toast(res.message); event.currentTarget.classList.add('hidden'); switchAuth('login'); });
  $('#logoutBtn').addEventListener('click', async () => { await unsubscribeFromPushNotifications(); await api('logout', {}); state.user = null; state.notifications = []; state.notificationArchive = []; state.unreadNotifications = 0; state.notifiedNotificationIds.clear(); state.pushSubscribed = false; stopNotificationPolling(); renderNotifications(); renderNotificationArchive(); renderSession(); });
  $('#profileHeaderBtn').addEventListener('click', () => showView('profile'));
  $('#notificationsBtn').addEventListener('click', openNotifications);
  $('#markNotificationsReadBtn').addEventListener('click', markNotificationsRead);
  $('#openNotificationArchiveBtn').addEventListener('click', openNotificationArchive);
  $('#enableDeviceNotificationsBtn').addEventListener('click', requestDeviceNotifications);
  $$('.nav-item').forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.view)));
  $('#monthPicker').addEventListener('change', async e => { state.month = e.target.value; state.day = `${state.month}-01`; await refreshCalendar(); });
  $('#prevMonth').addEventListener('click', () => shiftCalendar(-1));
  $('#nextMonth').addEventListener('click', () => shiftCalendar(1));
  $('#serviceNextBtn').addEventListener('click', goToCalendarStep);
  $('#backToCalendarBtn').addEventListener('click', () => setBookingStep('calendar'));
  $('#confirmBookingBtn').addEventListener('click', confirmPendingBooking);
  $('#newServiceBtn').addEventListener('click', () => openServiceDialog());
  $('#serviceForm').addEventListener('submit', saveService);
  $('#appointmentEditForm').addEventListener('submit', saveAppointmentEdit);
  $('#userEditForm').addEventListener('submit', saveUserEdit);
  $('#closuresForm').addEventListener('submit', saveClosures);
  $('#addSpecialClosureBtn').addEventListener('click', addSpecialClosure);
  $('#appointmentEditForm').elements.service_id.addEventListener('change', loadAppointmentEditAvailability);
  $('#appointmentEditForm').elements.date.addEventListener('change', renderAppointmentEditTimes);
  $('#clientSearch').addEventListener('input', renderClients);
  $('#appointmentDateFilter').addEventListener('change', async event => {
    state.appointmentDateFilter = event.target.value;
    if (state.appointmentDateFilter && state.appointmentDateFilter.slice(0, 7) !== state.month) {
      state.month = state.appointmentDateFilter.slice(0, 7);
      await refreshCalendar();
    } else {
      renderAppointments();
    }
  });
  $('#clearAppointmentFilterBtn').addEventListener('click', () => { state.appointmentDateFilter = ''; $('#appointmentDateFilter').value = ''; renderAppointments(); });
  $$('[data-close-dialog]').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));
  $('#profileForm').addEventListener('submit', saveProfile);
  $('#appSettingsForm').addEventListener('submit', saveAppSettings);
  window.addEventListener('resize', () => { if (state.user) renderCalendar(); });
  registerServiceWorker();
}


function submitAuth(action) {
  return async event => {
    event.preventDefault();
    try {
      const res = await api(action, formData(event.currentTarget));
      state.user = res.user;
      toast(action === 'login' ? 'Accesso effettuato.' : 'Account creato.');
      renderSession();
      await refreshAll();
    } catch (error) {
      toast(error.message);
    }
  };
}

function renderSession() {
  const logged = Boolean(state.user);
  $('#authView').classList.toggle('hidden', logged);
  $('#appView').classList.toggle('hidden', !logged);
  $('#logoutBtn').classList.toggle('hidden', !logged);
  $('#profileHeaderBtn').classList.toggle('hidden', !logged);
  $('#notificationsBtn').classList.toggle('hidden', !logged);
  $$('.admin-only').forEach(el => el.classList.toggle('hidden', !isAdmin()));
  $$('.client-only').forEach(el => el.classList.toggle('hidden', isAdmin()));
  if (!isAdmin()) state.bookingStep = 'services';
  $('#roleLabel').textContent = isAdmin() ? 'Area admin' : 'Area cliente';
  renderAppSettings();
  renderBookingStep();
  if (logged) startNotificationPolling(); else stopNotificationPolling();
}


function isAdmin() { return state.user?.role === 'admin'; }

async function refreshAll() {
  const [services, appointments] = await Promise.all([api('services'), api(`appointments&month=${state.month}`)]);
  state.services = services.services;
  state.appointments = appointments.appointments;
  state.selectedService = state.selectedService || null;
  if (isAdmin()) {
    const [users, closures] = await Promise.all([api('users'), api('closure_settings')]);
    state.users = users.users;
    state.closureSettings = { weekly: closures.weekly || [], special: closures.special || [] };
  }
  await refreshCalendar();
  renderServices();
  renderAppointments();
  renderAdminServices();
  renderClients();
  renderClosures();
  renderProfile();
  renderAppSettings();
  renderBookingStep();
  renderNotificationArchive();
  await refreshNotifications();
}

async function refreshCalendar() {
  $('#monthPicker').value = state.month;
  if (!isAdmin() && state.selectedService) {
    const [availability, appointments] = await Promise.all([
      api(`availability&service_id=${state.selectedService}&month=${state.month}`),
      api(`appointments&month=${state.month}`)
    ]);
    state.availability = availability.days;
    state.appointments = appointments.appointments;
  } else {
    state.appointments = (await api(`appointments&month=${state.month}`)).appointments;
  }
  renderCalendar();
  renderAppointments();
}

function renderServices() {
  const wrap = $('#serviceCards');
  wrap.classList.toggle('hidden', isAdmin() || state.bookingStep !== 'services');
  wrap.innerHTML = state.services.map(service => `
    <button class="service-card ${Number(state.selectedService) === Number(service.id) ? 'active' : ''}" data-service="${service.id}" type="button">
      ${service.image_path ? `<img src="${escapeHtml(service.image_path)}" alt="">` : ''}
      <h3>${escapeHtml(service.name)}</h3><p>${escapeHtml(service.description || 'Servizio professionale')}</p>
      <span class="price">€ ${Number(service.price).toFixed(2)} · ${service.duration_minutes} min</span>
    </button>`).join('');
  $$('[data-service]', wrap).forEach(btn => btn.addEventListener('click', () => {
    state.selectedService = btn.dataset.service;
    renderServices();
    renderBookingStep();
  }));
}

function renderBookingStep() {
  if (isAdmin()) {
    $('#dashboardTitle').textContent = 'Calendario appuntamenti';
    $('#monthPicker').classList.remove('hidden');
    $('#calendarCard').classList.remove('hidden');
    $('#serviceStepActions').classList.add('hidden');
    $('#confirmStep').classList.add('hidden');
    return;
  }

  const titles = { services: 'Scegli il servizio', calendar: 'Scegli giorno e posto', confirm: 'Conferma prenotazione' };
  $('#dashboardTitle').textContent = titles[state.bookingStep] || titles.services;
  $$('[data-step-dot]').forEach(dot => dot.classList.toggle('active', dot.dataset.stepDot === state.bookingStep));
  $('#serviceCards').classList.toggle('hidden', state.bookingStep !== 'services');
  $('#serviceStepActions').classList.toggle('hidden', state.bookingStep !== 'services');
  $('#calendarCard').classList.toggle('hidden', state.bookingStep !== 'calendar');
  $('#confirmStep').classList.toggle('hidden', state.bookingStep !== 'confirm');
  $('#monthPicker').classList.toggle('hidden', state.bookingStep !== 'calendar');
  $('#serviceNextBtn').disabled = !state.selectedService;
  renderBookingSummary();
}

async function goToCalendarStep() {
  if (!state.selectedService) {
    toast('Seleziona prima un servizio.');
    return;
  }
  await setBookingStep('calendar');
}

async function setBookingStep(step) {
  state.bookingStep = step;
  renderBookingStep();
  if (step === 'calendar') await refreshCalendar();
}

function chooseBookingTime(date, time) {
  state.pendingBooking = { service_id: state.selectedService, date, time };
  setBookingStep('confirm');
}

function renderBookingSummary() {
  const summary = $('#bookingSummary');
  if (!summary || !state.pendingBooking) return;
  const service = state.services.find(item => Number(item.id) === Number(state.pendingBooking.service_id));
  const date = new Date(`${state.pendingBooking.date}T${state.pendingBooking.time}:00`);
  summary.textContent = `${service?.name || 'Servizio'} · ${date.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} alle ${state.pendingBooking.time}`;
}

async function confirmPendingBooking() {
  if (!state.pendingBooking) return;
  await saveAppointment(state.pendingBooking);
  state.pendingBooking = null;
  state.bookingStep = 'services';
  state.selectedService = null;
  renderServices();
  renderBookingStep();
  showView('appointments');
}
function renderCalendar() {
  if (isMobileCalendar()) {
    renderDayCalendar();
    return;
  }
  const grid = $('#calendarGrid');
  const date = new Date(`${state.month}-01T12:00:00`);
  $('#calendarTitle').textContent = date.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
  $('.weekdays').classList.remove('hidden');
  const firstWeekday = (date.getDay() || 7) - 1;
  const first = new Date(date); first.setDate(date.getDate() - firstWeekday);
  const cells = [];
  for (let i = 0; i < 42; i++) {
    const day = new Date(first); day.setDate(first.getDate() + i);
    const iso = localIso(day);
    const inMonth = iso.slice(0, 7) === state.month;
    const appts = state.appointments.filter(a => a.starts_at.slice(0, 10) === iso);
    const available = state.availability[iso]?.available || 0;
    const today = iso === localIso(new Date());
    cells.push(`<button class="day ${inMonth ? '' : 'muted'} ${available ? 'available' : ''} ${today ? 'today' : ''}" data-day="${iso}" type="button">
      <strong>${day.getDate()}</strong>
      ${isAdmin() ? appts.slice(0, 3).map(a => `<span class="appt">${a.starts_at.slice(11, 16)} ${escapeHtml(a.first_name)}</span>`).join('') : ''}
      ${!isAdmin() && inMonth ? `<span class="badge">${available} posti</span>` : ''}
      ${isAdmin() && appts.length ? `<span class="badge">${appts.length} app.</span>` : ''}
    </button>`);
  }
  grid.className = 'calendar-grid';
  grid.innerHTML = cells.join('');
  $$('.day', grid).forEach(btn => btn.addEventListener('click', () => openDay(btn.dataset.day)));
}

function renderDayCalendar() {
  const grid = $('#calendarGrid');
  const day = new Date(`${state.day}T12:00:00`);
  const iso = localIso(day);
  if (iso.slice(0, 7) !== state.month) state.month = iso.slice(0, 7);
  $('#monthPicker').value = state.month;
  $('#calendarTitle').textContent = day.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
  $('.weekdays').classList.add('hidden');
  grid.className = 'day-calendar';
  if (isAdmin()) {
    const appts = state.appointments.filter(a => a.starts_at.slice(0, 10) === iso);
    grid.innerHTML = appts.length ? appts.map(appointmentRow).join('') : '<div class="day-empty">Nessun appuntamento in questa data.</div>';
    wireAppointmentActions(grid);
    return;
  }
  const slots = state.availability[iso]?.slots || [];
  grid.innerHTML = `<div class="day-focus"><strong>${day.getDate()}</strong><span>${slots.length} posti disponibili</span></div><div class="slot-list">${slots.length ? slots.map(time => `<button class="slot" data-book="${time}" type="button">${time}</button>`).join('') : '<p class="hint">Nessun posto disponibile.</p>'}</div>`;
  $$('[data-book]', grid).forEach(btn => btn.addEventListener('click', () => chooseBookingTime(iso, btn.dataset.book)));
}
function openDay(day) {
  if (isAdmin()) {
    showAppointmentsForDay(day);
    return;
  }
  const dialog = $('#slotDialog');
  $('#slotDialogTitle').textContent = new Date(`${day}T12:00:00`).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
  const list = $('#slotList');
  const slots = state.availability[day]?.slots || [];
  list.innerHTML = slots.length ? slots.map(time => `<button class="slot" data-book="${time}" type="button">${time}</button>`).join('') : '<p class="hint">Nessun posto disponibile.</p>';
  $$('[data-book]', list).forEach(btn => btn.addEventListener('click', () => { dialog.close(); chooseBookingTime(day, btn.dataset.book); }));
  dialog.showModal();
}

function showAppointmentsForDay(day) {
  state.appointmentDateFilter = day;
  $('#appointmentDateFilter').value = day;
  showView('appointments');
  renderAppointments();
}

function renderAppointments() {
  const base = isAdmin() ? state.appointments : state.appointments.filter(a => Number(a.user_id) === Number(state.user.id));
  const filtered = state.appointmentDateFilter ? base.filter(a => a.starts_at.slice(0, 10) === state.appointmentDateFilter) : base;
  const empty = state.appointmentDateFilter ? `Nessun appuntamento per ${formatDate(state.appointmentDateFilter)}.` : 'Nessun appuntamento nel mese selezionato.';
  $('#appointmentDateFilter').value = state.appointmentDateFilter;
  $('#appointmentList').innerHTML = filtered.length ? filtered.map(appointmentRow).join('') : `<div class="panel form-grid"><p class="hint">${empty}</p></div>`;
  wireAppointmentActions($('#appointmentList'));
}

function appointmentRow(a) {
  const start = new Date(a.starts_at.replace(' ', 'T'));
  const wa = normalizeWa(a.phone || '');
  return `<article class="list-item"><div><h3>${escapeHtml(a.service_name)} · ${start.toLocaleDateString('it-IT')} ${a.starts_at.slice(11,16)}</h3><p>${escapeHtml(a.first_name || '')} ${escapeHtml(a.last_name || '')} · ${escapeHtml(a.phone || '')}</p></div><div class="actions">${isAdmin() && wa ? `<a class="ghost" href="https://wa.me/${wa}" target="_blank" rel="noopener">WhatsApp</a>` : ''}<button class="ghost" data-edit-appt="${a.id}" type="button">Modifica</button><button class="danger" data-del-appt="${a.id}" type="button">Elimina</button></div></article>`;
}

function wireAppointmentActions(root) {
  $$('[data-del-appt]', root).forEach(btn => btn.addEventListener('click', async () => { if (confirm('Eliminare questo appuntamento?')) { await api('appointment_delete', { id: btn.dataset.delAppt }); toast('Appuntamento eliminato.'); await refreshCalendar(); await refreshNotifications(); } }));
  $$('[data-edit-appt]', root).forEach(btn => btn.addEventListener('click', () => {
    const appointment = state.appointments.find(item => Number(item.id) === Number(btn.dataset.editAppt));
    if (appointment) openAppointmentDialog(appointment);
  }));
}

async function openAppointmentDialog(appointment) {
  const form = $('#appointmentEditForm');
  form.reset();
  form.dataset.appointmentId = appointment.id;
  form.dataset.currentDate = appointment.starts_at.slice(0, 10);
  form.dataset.currentTime = appointment.starts_at.slice(11, 16);
  form.elements.id.value = appointment.id;
  form.elements.user_id.value = appointment.user_id;
  form.elements.service_id.innerHTML = state.services.map(service => `<option value="${service.id}">${escapeHtml(service.name)} · ${service.duration_minutes} min</option>`).join('');
  form.elements.service_id.value = appointment.service_id;
  await loadAppointmentEditAvailability();
  $('#appointmentDialog').showModal();
}

async function loadAppointmentEditAvailability() {
  const form = $('#appointmentEditForm');
  const serviceId = form.elements.service_id.value;
  const appointmentId = form.elements.id.value;
  const currentDate = form.dataset.currentDate;
  const targetMonth = (form.elements.date.value || currentDate || state.month).slice(0, 7);
  const availability = await api(`availability&service_id=${serviceId}&month=${targetMonth}&exclude_appointment_id=${appointmentId}`);
  state.editingAppointmentAvailability = availability.days || {};
  const dates = Object.entries(state.editingAppointmentAvailability).filter(([, data]) => data.available > 0 || data.slots?.length);
  form.elements.date.innerHTML = dates.length
    ? dates.map(([date, data]) => `<option value="${date}">${formatDate(date)} · ${data.slots.length} posti</option>`).join('')
    : '<option value="">Nessuna data disponibile</option>';
  if (currentDate && state.editingAppointmentAvailability[currentDate]?.slots?.length) {
    form.elements.date.value = currentDate;
  }
  renderAppointmentEditTimes();
}

function renderAppointmentEditTimes() {
  const form = $('#appointmentEditForm');
  const date = form.elements.date.value;
  const currentTime = form.dataset.currentTime;
  const slots = state.editingAppointmentAvailability[date]?.slots || [];
  form.elements.time.innerHTML = slots.length
    ? slots.map(time => `<option value="${time}">${time}</option>`).join('')
    : '<option value="">Nessun posto</option>';
  if (slots.includes(currentTime)) form.elements.time.value = currentTime;
}

async function saveAppointmentEdit(event) {
  event.preventDefault();
  await saveAppointment(formData(event.currentTarget));
  $('#appointmentDialog').close();
}

async function saveAppointment(payload) {
  await api('appointment_save', payload);
  toast('Appuntamento confermato.');
  await refreshCalendar();
  await refreshNotifications();
}

function renderAdminServices() {
  if (!isAdmin()) return;
  $('#adminServiceList').innerHTML = state.services.map(s => `<article class="list-item"><div><h3>${escapeHtml(s.name)}</h3><p>€ ${Number(s.price).toFixed(2)} · ${s.duration_minutes} min · ${Number(s.active) ? 'Attivo' : 'Disattivo'}</p></div><div class="actions"><button class="ghost" data-edit-service="${s.id}" type="button">Modifica</button><button class="danger" data-delete-service="${s.id}" type="button">Elimina</button></div></article>`).join('');
  $$('[data-edit-service]').forEach(btn => btn.addEventListener('click', () => openServiceDialog(state.services.find(s => Number(s.id) === Number(btn.dataset.editService)))));
  $$('[data-delete-service]').forEach(btn => btn.addEventListener('click', async () => { if (confirm('Disattivare il servizio?')) { await api('service_delete', { id: btn.dataset.deleteService }); toast('Servizio disattivato.'); await refreshAll(); } }));
}

function openServiceDialog(service = {}) {
  const form = $('#serviceForm'); form.reset();
  ['id','name','description','price','duration_minutes','existing_image'].forEach(name => { if (form.elements[name]) form.elements[name].value = service[name === 'existing_image' ? 'image_path' : name] || ''; });
  form.elements.active.checked = service.active === undefined ? true : Number(service.active) === 1;
  $('#serviceDialog').showModal();
}

async function saveService(event) {
  event.preventDefault();
  const data = new FormData(event.currentTarget);
  await api('service_save', data);
  $('#serviceDialog').close();
  toast('Servizio salvato.');
  await refreshAll();
}

function renderClients() {
  if (!isAdmin()) return;
  const query = ($('#clientSearch')?.value || '').trim().toLowerCase();
  const users = state.users.filter(u => `${u.first_name} ${u.last_name}`.toLowerCase().includes(query));
  $('#clientList').innerHTML = users.length
    ? users.map(u => `<article class="list-item"><div><h3>${escapeHtml(u.first_name)} ${escapeHtml(u.last_name)}</h3><p>${escapeHtml(u.role)} · ${escapeHtml(u.email)} · ${escapeHtml(u.phone)}</p></div><div class="actions"><button class="ghost" data-edit-user="${u.id}" type="button">Modifica</button></div></article>`).join('')
    : '<div class="panel form-grid"><p class="hint">Nessun cliente trovato.</p></div>';
  $$('[data-edit-user]').forEach(btn => btn.addEventListener('click', () => {
    const user = state.users.find(item => Number(item.id) === Number(btn.dataset.editUser));
    if (user) openUserDialog(user);
  }));
}

function openUserDialog(user) {
  const form = $('#userEditForm');
  form.reset();
  ['id', 'first_name', 'last_name', 'email', 'phone', 'role'].forEach(name => { form.elements[name].value = user[name] || ''; });
  form.elements.password.value = '';
  $('#userDialog').showModal();
}

async function saveUserEdit(event) {
  event.preventDefault();
  const data = formData(event.currentTarget);
  if (!data.password) delete data.password;
  await api('user_save', data);
  $('#userDialog').close();
  toast('Utente aggiornato.');
  await refreshAll();
}

function renderClosures() {
  if (!isAdmin()) return;
  const weekdays = [
    [1, 'Lunedì'], [2, 'Martedì'], [3, 'Mercoledì'], [4, 'Giovedì'], [5, 'Venerdì'], [6, 'Sabato'], [7, 'Domenica']
  ];
  $('#weeklyClosures').innerHTML = weekdays.map(([value, label]) => `<label class="check closure-check"><input type="checkbox" value="${value}" ${state.closureSettings.weekly.includes(value) ? 'checked' : ''}> ${label}</label>`).join('');
  $('#specialClosures').innerHTML = state.closureSettings.special.length
    ? state.closureSettings.special.map((item, index) => `<article class="list-item"><div><h3>${formatDate(item.closure_date)}</h3><p>${escapeHtml(item.label || 'Chiusura speciale')}</p></div><div class="actions"><button class="danger" data-remove-special="${index}" type="button">Rimuovi</button></div></article>`).join('')
    : '<p class="hint">Nessun giorno speciale inserito.</p>';
  $$('[data-remove-special]').forEach(btn => btn.addEventListener('click', () => {
    state.closureSettings.special.splice(Number(btn.dataset.removeSpecial), 1);
    renderClosures();
  }));
}

function addSpecialClosure() {
  const date = $('#specialClosureDate').value;
  const label = $('#specialClosureLabel').value.trim();
  if (!date) {
    toast('Seleziona una data speciale.');
    return;
  }
  if (!state.closureSettings.special.some(item => item.closure_date === date)) {
    state.closureSettings.special.push({ closure_date: date, label });
  }
  $('#specialClosureDate').value = '';
  $('#specialClosureLabel').value = '';
  renderClosures();
}

async function saveClosures(event) {
  event.preventDefault();
  const weekly = $$('#weeklyClosures input:checked').map(input => Number(input.value));
  const special = state.closureSettings.special.map(item => ({ date: item.closure_date, label: item.label || '' }));
  await api('closure_save', { weekly, special });
  toast('Chiusure salvate.');
  await refreshAll();
}

async function refreshNotifications(announce = false) {
  if (!state.user) return;
  const previousIds = new Set(state.notifications.map(item => String(item.id)));
  const res = await api('notifications');
  state.notifications = res.notifications || [];
  state.unreadNotifications = res.unread || 0;
  renderNotifications();
  renderNotificationPermissionAction();
  if (announce) notifyDeviceForNewNotifications(state.notifications.filter(item => !previousIds.has(String(item.id))));
  state.notifications.forEach(item => state.notifiedNotificationIds.add(String(item.id)));
}

async function refreshNotificationArchive() {
  if (!state.user) return;
  const res = await api('notifications&archive=1');
  state.notificationArchive = res.notifications || [];
  state.unreadNotifications = res.unread || 0;
  renderNotifications();
  renderNotificationPermissionAction();
  renderNotificationArchive();
}

function notificationItem(item, archived = false) {
  const status = archived && item.read_at ? `<small>Letta il ${formatDateTime(item.read_at)}</small>` : '';
  const action = archived ? '' : `<button class="ghost" data-read-notification="${item.id}" type="button">Letta</button>`;
  return `<article class="list-item notification-item ${archived ? 'archived' : 'unread'}"><div><h3>${escapeHtml(item.title)}</h3><p>${escapeHtml(item.body || '')}</p><small>${formatDateTime(item.created_at)}</small>${status}</div>${action}</article>`;
}

function renderNotifications() {
  const badge = $('#notificationBadge');
  badge.textContent = state.unreadNotifications > 9 ? '9+' : String(state.unreadNotifications);
  badge.classList.toggle('hidden', state.unreadNotifications < 1);
  const list = $('#notificationsList');
  if (!list) return;
  list.innerHTML = state.notifications.length
    ? state.notifications.map(item => notificationItem(item)).join('')
    : '<p class="hint">Nessuna nuova notifica.</p>';
  $$('[data-read-notification]').forEach(btn => btn.addEventListener('click', async () => {
    await api('notifications_read', { id: btn.dataset.readNotification });
    await refreshNotifications();
  }));
}

function renderNotificationArchive() {
  const list = $('#notificationArchiveList');
  if (!list) return;
  list.innerHTML = state.notificationArchive.length
    ? state.notificationArchive.map(item => notificationItem(item, true)).join('')
    : '<div class="panel form-grid"><p class="hint">Nessuna notifica archiviata.</p></div>';
}

async function openNotifications() {
  await refreshNotifications();
  renderNotificationPermissionAction();
  $('#notificationsDialog').showModal();
}

async function openNotificationArchive() {
  $('#notificationsDialog').close();
  await refreshNotificationArchive();
  showView('notificationArchive');
}

async function markNotificationsRead() {
  await api('notifications_read', {});
  await refreshNotifications();
}

function renderNotificationPermissionAction() {
  const btn = $('#enableDeviceNotificationsBtn');
  if (!btn) return;
  const notificationApi = window.Notification;
  const canNotify = Boolean(notificationApi) && 'serviceWorker' in navigator && 'PushManager' in window;
  const permission = canNotify ? notificationApi.permission : 'unsupported';
  btn.textContent = permission === 'granted' ? 'Attiva push su questo dispositivo' : 'Attiva notifiche dispositivo';
  btn.classList.toggle('hidden', !canNotify || permission === 'denied' || state.pushSubscribed);
}

async function requestDeviceNotifications() {
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    toast('Notifiche push non supportate da questo browser.');
    return;
  }
  const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  if (permission !== 'granted') {
    renderNotificationPermissionAction();
    toast('Permesso notifiche non concesso.');
    return;
  }
  try {
    await subscribeToPushNotifications();
    toast('Notifiche push attivate.');
  } catch (error) {
    toast(error.message);
  }
  renderNotificationPermissionAction();
}

async function subscribeToPushNotifications() {
  const key = (await api('push_public_key')).publicKey;
  if (!key) throw new Error('Configura prima le chiavi VAPID sul server.');
  const registration = await navigator.serviceWorker.ready;
  const existing = await registration.pushManager.getSubscription();
  const subscription = existing || await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) });
  await api('push_subscribe', subscription.toJSON());
  state.pushSubscribed = true;
}

async function syncPushSubscriptionState() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const registration = await navigator.serviceWorker.ready.catch(() => null);
  if (!registration) return;
  state.pushSubscribed = Boolean(await registration.pushManager.getSubscription());
  renderNotificationPermissionAction();
}


async function unsubscribeFromPushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const registration = await navigator.serviceWorker.ready.catch(() => null);
  const subscription = registration ? await registration.pushManager.getSubscription() : null;
  if (!subscription) return;
  await api('push_unsubscribe', { endpoint: subscription.endpoint }).catch(() => {});
  await subscription.unsubscribe().catch(() => {});
}

function urlBase64ToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
}

async function notifyDeviceForNewNotifications(items) {
  if (!items.length || !('Notification' in window) || Notification.permission !== 'granted') return;
  const fresh = items.filter(item => !state.notifiedNotificationIds.has(String(item.id)));
  if (!fresh.length) return;
  const registration = 'serviceWorker' in navigator ? await navigator.serviceWorker.ready.catch(() => null) : null;
  fresh.slice(0, 3).forEach(item => {
    const options = { body: item.body || '', icon: 'assets/app-icon.svg', badge: 'assets/app-icon.svg', tag: `barber-notification-${item.id}` };
    if (registration) registration.showNotification(item.title, options);
    else new Notification(item.title, options);
    state.notifiedNotificationIds.add(String(item.id));
  });
}

function startNotificationPolling() {
  if (state.notificationPollTimer) return;
  state.notificationPollTimer = window.setInterval(() => {
    if (state.user) refreshNotifications(true).catch(error => console.warn(error));
  }, 60000);
}

function stopNotificationPolling() {
  if (!state.notificationPollTimer) return;
  window.clearInterval(state.notificationPollTimer);
  state.notificationPollTimer = null;
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js').then(syncPushSubscriptionState).catch(error => console.warn('Service worker non registrato:', error));
  });
}


function applyAppSettings() {
  const settings = state.appSettings || {};
  const primary = settings.primary_color || '#335eac';
  const accent = settings.accent_color || '#f42539';
  const background = settings.background_color || '#ffffff';
  document.documentElement.style.setProperty('--blue', primary);
  document.documentElement.style.setProperty('--red', accent);
  applyThemeSurface();
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', primary);
  if (settings.business_name) {
    document.title = settings.business_name;
    $('#brandName').textContent = settings.business_name;
  }
  $('#brandSubtitle').textContent = settings.business_subtitle || '';
  const logo = $('#brandLogo');
  const defaultIcon = $('#brandDefaultIcon');
  const favicon = document.querySelector('link[rel="icon"]');
  const iconPath = settings.app_icon_path || 'assets/app-icon.svg';
  if (favicon) favicon.href = `${iconPath}?v=${Date.now()}`;
  if (settings.logo_path) {
    logo.src = `${settings.logo_path}?v=${Date.now()}`;
    logo.classList.remove('hidden');
    defaultIcon.classList.add('hidden');
  } else {
    logo.classList.add('hidden');
    defaultIcon.classList.remove('hidden');
  }
}

function renderAppSettings() {
  const form = $('#appSettingsForm');
  if (!form) return;
  form.classList.toggle('hidden', !isAdmin());
  if (!isAdmin()) {
    form.innerHTML = '';
    return;
  }
  const settings = state.appSettings || {};
  form.innerHTML = `<span class="eyebrow">Impostazioni app</span><h2>Brand e colori</h2><label>Logo attività <span class="hint">PNG, JPG o WEBP. Aggiorna anche favicon e app icon.</span><input name="logo" type="file" accept="image/png,image/jpeg,image/webp"></label>${settings.logo_path ? `<img class="settings-logo-preview" src="${escapeAttr(settings.logo_path)}?v=${Date.now()}" alt="Logo attuale">` : ''}<div class="two-cols"><label>Nome attività<input name="business_name" value="${escapeAttr(settings.business_name || 'Barber')}" maxlength="80" required></label><label>Sottotitolo<input name="business_subtitle" value="${escapeAttr(settings.business_subtitle || 'booking')}" maxlength="80"></label></div><div class="three-cols"><label>Colore principale<input name="primary_color" type="color" value="${escapeAttr(settings.primary_color || '#335eac')}"></label><label>Colore accento<input name="accent_color" type="color" value="${escapeAttr(settings.accent_color || '#f42539')}"></label><label>Sfondo app<input name="background_color" type="color" value="${escapeAttr(settings.background_color || '#ffffff')}"></label></div><button class="primary" type="submit">Salva impostazioni app</button>`;
}

async function saveAppSettings(event) {
  event.preventDefault();
  const res = await api('app_settings_save', new FormData(event.currentTarget));
  state.appSettings = res.settings || {};
  applyAppSettings();
  renderAppSettings();
  toast('Impostazioni app salvate.');
}

function renderProfile() {
  const u = state.user;
  $('#profileForm').innerHTML = `<div class="two-cols"><label>Nome<input name="first_name" value="${escapeAttr(u.first_name)}" required></label><label>Cognome<input name="last_name" value="${escapeAttr(u.last_name)}" required></label></div><label>Email<input name="email" type="email" value="${escapeAttr(u.email)}" required></label><label>Telefono<input name="phone" value="${escapeAttr(u.phone)}" required></label>${isAdmin() ? '<label>Ruolo<select name="role"><option value="admin">Admin</option><option value="cliente">Cliente</option></select></label>' : ''}<label>Nuova password <span class="hint">(opzionale)</span><input name="password" type="password" minlength="8"></label><button class="primary" type="submit">Salva profilo</button>`;
  if (isAdmin()) $('#profileForm').elements.role.value = u.role;
}

async function saveProfile(event) {
  event.preventDefault();
  const res = await api('profile_save', formData(event.currentTarget));
  state.user = res.user;
  toast('Profilo aggiornato.');
  renderProfile();
}

function showView(view) {
  $$('.nav-item').forEach(btn => btn.classList.toggle('active', btn.dataset.view === view));
  $$('.view').forEach(section => section.classList.add('hidden'));
  const target = $(`#${view}View`);
  if (target) target.classList.remove('hidden');
}

function shiftCalendar(delta) {
  if (isMobileCalendar()) {
    const date = new Date(`${state.day}T12:00:00`);
    date.setDate(date.getDate() + delta);
    state.day = localIso(date);
    state.month = state.day.slice(0, 7);
    refreshCalendar();
    return;
  }
  shiftMonth(delta);
}

function shiftMonth(delta) {
  const date = new Date(`${state.month}-01T12:00:00`);
  date.setMonth(date.getMonth() + delta);
  state.month = localIso(date).slice(0, 7);
  refreshCalendar();
}

function isMobileCalendar() { return window.matchMedia('(max-width: 760px)').matches; }

function localIso(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
function normalizeWa(phone) { return phone.replace(/[^0-9]/g, ''); }
function formatDate(date) { return new Date(`${date}T12:00:00`).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }); }
function formatDateTime(value) { return new Date(String(value).replace(' ', 'T')).toLocaleString('it-IT', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
function escapeAttr(value) { return escapeHtml(value); }

boot().catch(error => toast(error.message));
