const state = { csrf: document.querySelector('meta[name="csrf-token"]')?.content || '', user: null, services: [], appointments: [], users: [], selectedService: null, month: new Date().toISOString().slice(0, 7), availability: {} };
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
  setTimeout(() => node.classList.remove('show'), 3200);
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function setTheme(theme) {
  document.documentElement.dataset.theme = theme;
  localStorage.setItem('theme', theme);
}

function switchAuth(tab) {
  $$('.tab').forEach(btn => btn.classList.toggle('active', btn.dataset.authTab === tab));
  $$('[data-auth-pane]').forEach(pane => pane.classList.toggle('hidden', pane.dataset.authPane !== tab));
}

async function boot() {
  setTheme(localStorage.getItem('theme') || 'light');
  $('#monthPicker').value = state.month;
  bindEvents();
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
  $('#logoutBtn').addEventListener('click', async () => { await api('logout', {}); state.user = null; renderSession(); });
  $$('.nav-item').forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.view)));
  $('#monthPicker').addEventListener('change', async e => { state.month = e.target.value; await refreshCalendar(); });
  $('#prevMonth').addEventListener('click', () => shiftMonth(-1));
  $('#nextMonth').addEventListener('click', () => shiftMonth(1));
  $('#newServiceBtn').addEventListener('click', () => openServiceDialog());
  $('#serviceForm').addEventListener('submit', saveService);
  $$('[data-close-dialog]').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));
  $('#profileForm').addEventListener('submit', saveProfile);
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
  $$('.admin-only').forEach(el => el.classList.toggle('hidden', !isAdmin()));
  $('#roleLabel').textContent = isAdmin() ? 'Area admin' : 'Area cliente';
  $('#dashboardTitle').textContent = isAdmin() ? 'Calendario appuntamenti' : 'Scegli il servizio';
}

function isAdmin() { return state.user?.role === 'admin'; }

async function refreshAll() {
  const [services, appointments] = await Promise.all([api('services'), api(`appointments&month=${state.month}`)]);
  state.services = services.services;
  state.appointments = appointments.appointments;
  state.selectedService = state.selectedService || state.services[0]?.id || null;
  if (isAdmin()) state.users = (await api('users')).users;
  await refreshCalendar();
  renderServices();
  renderAppointments();
  renderAdminServices();
  renderClients();
  renderProfile();
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
  wrap.classList.toggle('hidden', isAdmin());
  wrap.innerHTML = state.services.map(service => `
    <button class="service-card ${Number(state.selectedService) === Number(service.id) ? 'active' : ''}" data-service="${service.id}" type="button">
      ${service.image_path ? `<img src="${escapeHtml(service.image_path)}" alt="">` : ''}
      <h3>${escapeHtml(service.name)}</h3><p>${escapeHtml(service.description || 'Servizio professionale')}</p>
      <span class="price">€ ${Number(service.price).toFixed(2)} · ${service.duration_minutes} min</span>
    </button>`).join('');
  $$('[data-service]', wrap).forEach(btn => btn.addEventListener('click', async () => { state.selectedService = btn.dataset.service; renderServices(); await refreshCalendar(); }));
}

function renderCalendar() {
  const grid = $('#calendarGrid');
  const date = new Date(`${state.month}-01T12:00:00`);
  $('#calendarTitle').textContent = date.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
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
  grid.innerHTML = cells.join('');
  $$('.day', grid).forEach(btn => btn.addEventListener('click', () => openDay(btn.dataset.day)));
}

function openDay(day) {
  const dialog = $('#slotDialog');
  $('#slotDialogTitle').textContent = new Date(`${day}T12:00:00`).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
  const list = $('#slotList');
  if (isAdmin()) {
    const appts = state.appointments.filter(a => a.starts_at.slice(0, 10) === day);
    list.innerHTML = appts.length ? appts.map(appointmentRow).join('') : '<p class="hint">Nessun appuntamento in questa data.</p>';
    wireAppointmentActions(list);
  } else {
    const slots = state.availability[day]?.slots || [];
    list.innerHTML = slots.length ? slots.map(time => `<button class="slot" data-book="${time}" type="button">${time}</button>`).join('') : '<p class="hint">Nessun posto disponibile.</p>';
    $$('[data-book]', list).forEach(btn => btn.addEventListener('click', async () => { await saveAppointment({ service_id: state.selectedService, date: day, time: btn.dataset.book }); dialog.close(); }));
  }
  dialog.showModal();
}

function renderAppointments() {
  const mine = isAdmin() ? state.appointments : state.appointments.filter(a => Number(a.user_id) === Number(state.user.id));
  $('#appointmentList').innerHTML = mine.length ? mine.map(appointmentRow).join('') : '<div class="panel form-grid"><p class="hint">Nessun appuntamento nel mese selezionato.</p></div>';
  wireAppointmentActions($('#appointmentList'));
}

function appointmentRow(a) {
  const start = new Date(a.starts_at.replace(' ', 'T'));
  const wa = normalizeWa(a.phone || '');
  return `<article class="list-item"><div><h3>${escapeHtml(a.service_name)} · ${start.toLocaleDateString('it-IT')} ${a.starts_at.slice(11,16)}</h3><p>${escapeHtml(a.first_name || '')} ${escapeHtml(a.last_name || '')} · ${escapeHtml(a.phone || '')}</p></div><div class="actions">${isAdmin() && wa ? `<a class="ghost" href="https://wa.me/${wa}" target="_blank" rel="noopener">WhatsApp</a>` : ''}<button class="ghost" data-edit-appt="${a.id}" type="button">Modifica</button><button class="danger" data-del-appt="${a.id}" type="button">Elimina</button></div></article>`;
}

function wireAppointmentActions(root) {
  $$('[data-del-appt]', root).forEach(btn => btn.addEventListener('click', async () => { if (confirm('Eliminare questo appuntamento?')) { await api('appointment_delete', { id: btn.dataset.delAppt }); toast('Appuntamento eliminato.'); await refreshCalendar(); } }));
  $$('[data-edit-appt]', root).forEach(btn => btn.addEventListener('click', async () => {
    const a = state.appointments.find(item => Number(item.id) === Number(btn.dataset.editAppt));
    const date = prompt('Nuova data (AAAA-MM-GG)', a.starts_at.slice(0, 10));
    const time = date && prompt('Nuovo orario (HH:MM)', a.starts_at.slice(11, 16));
    if (date && time) await saveAppointment({ id: a.id, service_id: a.service_id, user_id: a.user_id, date, time });
  }));
}

async function saveAppointment(payload) {
  await api('appointment_save', payload);
  toast('Appuntamento confermato.');
  await refreshCalendar();
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
  $('#clientList').innerHTML = state.users.map(u => `<article class="list-item"><div><h3>${escapeHtml(u.first_name)} ${escapeHtml(u.last_name)}</h3><p>${escapeHtml(u.role)} · ${escapeHtml(u.email)} · ${escapeHtml(u.phone)}</p></div><div class="actions"><button class="ghost" data-edit-user="${u.id}" type="button">Modifica</button></div></article>`).join('');
  $$('[data-edit-user]').forEach(btn => btn.addEventListener('click', async () => {
    const u = state.users.find(item => Number(item.id) === Number(btn.dataset.editUser));
    const first_name = prompt('Nome', u.first_name) || u.first_name;
    const last_name = prompt('Cognome', u.last_name) || u.last_name;
    const email = prompt('Email', u.email) || u.email;
    const phone = prompt('Telefono', u.phone) || u.phone;
    await api('user_save', { ...u, first_name, last_name, email, phone });
    toast('Utente aggiornato.'); await refreshAll();
  }));
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
  $(`#${view}View`).classList.remove('hidden');
}

function shiftMonth(delta) {
  const date = new Date(`${state.month}-01T12:00:00`);
  date.setMonth(date.getMonth() + delta);
  state.month = localIso(date).slice(0, 7);
  refreshCalendar();
}

function localIso(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
function normalizeWa(phone) { return phone.replace(/[^0-9]/g, ''); }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
function escapeAttr(value) { return escapeHtml(value); }

boot().catch(error => toast(error.message));
