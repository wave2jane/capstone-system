(() => {
'use strict';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const state = { csrf: '', cfg: null, user: null, adminMode: false, term: '', alloc: null };
const LABELS = ['Poor', 'Fair', 'Good', 'Very good', 'Excellent'];
const CRITERIA = [
  ['teaching', 'Teaching Effectiveness'],
  ['mastery', 'Subject Mastery'],
  ['punctuality', 'Punctuality'],
];

/* ---------- helpers ---------- */
async function api(action, { body, query } = {}) {
  const qs = new URLSearchParams({ action, ...(query || {}) });
  const opts = { credentials: 'same-origin', headers: {} };
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = state.csrf;
    opts.body = JSON.stringify(body);
  }
  let res, data;
  try {
    res = await fetch('api.php?' + qs, opts);
    data = await res.json();
  } catch (e) {
    throw new Error('Cannot reach the server. Check your connection and that PHP is running.');
  }
  if (!data.ok) {
    const err = new Error(data.error || 'Something went wrong.');
    err.status = res.status;
    throw err;
  }
  return data;
}

let toastTimer;
function toast(msg, ms = 4000) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), ms);
}

function show(id) {
  $$('.view').forEach(v => v.hidden = v.id !== id);
  document.body.classList.toggle('landing', id === 'view-auth');
  window.scrollTo(0, 0);
}

function busy(btn, on) { if (btn) btn.disabled = on; }

function fillSelect(sel, items, placeholder) {
  sel.innerHTML = (placeholder ? `<option value="">${esc(placeholder)}</option>` : '') +
    items.map(i => `<option value="${esc(i.value)}">${esc(i.label)}</option>`).join('');
}
const courseItems = () => state.cfg.courses.map(c => ({ value: c, label: c }));
const blockItems = () => state.cfg.blocks.map(b => ({ value: b, label: 'Block ' + b }));

/* ---------- routing by session state ---------- */
function route() {
  const u = state.user;
  if (!u) return show('view-auth');
  if (u.role === 'admin') { show('view-admin'); return loadAdmin(); }
  if (!u.section_ok) return openSectionPicker();
  show('view-student');
  loadStudent();
}

/* ---------- auth ---------- */
function setAuthTab(which) {
  const signin = which === 'in';
  $('#tab-in').classList.toggle('active', signin);
  $('#tab-up').classList.toggle('active', !signin);
  $('#tab-in').setAttribute('aria-selected', signin);
  $('#tab-up').setAttribute('aria-selected', !signin);
  $('#form-in').hidden = !signin;
  $('#form-up').hidden = signin;
  $('#err-in').textContent = $('#err-up').textContent = '';
}
$('#tab-in').onclick = () => setAuthTab('in');
$('#tab-up').onclick = () => { state.adminMode = false; applyAdminMode(); setAuthTab('up'); };

function applyAdminMode() {
  $('#adminHint').hidden = !state.adminMode;
  $('#in-id-label').textContent = state.adminMode ? 'Admin username' : 'Student ID or email';
  $('#adminToggle').textContent = state.adminMode ? 'Student sign-in' : 'Administrator sign-in';
  $('#in-id').value = '';
}
$('#adminToggle').onclick = () => { state.adminMode = !state.adminMode; applyAdminMode(); };

$$('.eye').forEach(b => b.onclick = () => {
  const i = document.getElementById(b.dataset.target);
  const show = i.type === 'password';
  i.type = show ? 'text' : 'password';
  b.textContent = show ? 'Hide' : 'Show';
  b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

$('#form-in').onsubmit = async e => {
  e.preventDefault();
  const btn = e.submitter, err = $('#err-in');
  err.textContent = '';
  busy(btn, true);
  try {
    const d = await api('login', { body: {
      mode: state.adminMode ? 'admin' : 'student',
      identifier: $('#in-id').value.trim(),
      password: $('#in-pw').value,         // sent exactly as typed
    }});
    state.user = d.user;
    $('#in-pw').value = '';
    route();
  } catch (ex) { err.textContent = ex.message; }
  busy(btn, false);
};

$('#form-up').onsubmit = async e => {
  e.preventDefault();
  const btn = e.submitter, err = $('#err-up');
  err.textContent = '';
  const pw = $('#up-pw').value;
  if (pw !== $('#up-pw2').value) { err.textContent = 'Passwords do not match.'; return; }
  busy(btn, true);
  try {
    const d = await api('register', { body: {
      name: $('#up-name').value, email: $('#up-email').value,
      sid: $('#up-sid').value, password: pw,
    }});
    e.target.reset();
    setAuthTab('in');
    state.adminMode = false; applyAdminMode();
    $('#in-id').value = d.email;
    $('#in-pw').focus();
    toast(d.message);
  } catch (ex) { err.textContent = ex.message; }
  busy(btn, false);
};

$$('.logout').forEach(b => b.onclick = logout);
async function logout() {
  try { const d = await api('logout', { body: {} }); state.csrf = d.csrf; } catch (_) {}
  state.user = null;
  setAuthTab('in');
  route();
}

/* ---------- section picker ---------- */
function openSectionPicker() {
  fillSelect($('#sel-course'), courseItems(), 'Select course');
  fillSelect($('#sel-block'), blockItems(), 'Select block');
  $('#sel-course').value = state.user.course || '';
  $('#sel-block').value = state.user.block || '';
  $('#err-section').textContent = '';
  show('view-section');
}
$('#changeSection').onclick = openSectionPicker;
$('#form-section').onsubmit = async e => {
  e.preventDefault();
  const course = $('#sel-course').value, block = $('#sel-block').value, err = $('#err-section');
  if (!course || !block) { err.textContent = 'Please choose both a course and a block.'; return; }
  busy(e.submitter, true);
  try {
    const d = await api('select_section', { body: { course, block } });
    state.user = d.user;
    route();
  } catch (ex) { err.textContent = ex.message; }
  busy(e.submitter, false);
};

/* ---------- student dashboard ---------- */
async function loadStudent() {
  const u = state.user;
  $('#st-name').textContent = u.name;
  $('#st-meta').textContent = `${u.course} · Block ${u.block} · ${u.sid || ''}`;
  try {
    const d = await api('my_professors');
    state.term = d.term;
    renderProfs(d.professors);
  } catch (ex) { toast(ex.message); if (ex.status === 401) { state.user = null; route(); } }
}

function renderProfs(list) {
  const done = list.filter(p => p.done).length;
  $('#termText').textContent = state.term;
  $('#progText').textContent = list.length ? `${done} of ${list.length} evaluations completed` : '';
  $('#progBar').style.width = list.length ? (done / list.length * 100) + '%' : '0';
  $('#profEmpty').hidden = list.length > 0;
  $('#profGrid').innerHTML = list.map(p => `
    <article class="prof">
      <span class="badge ${p.done ? 'done' : ''}">${p.done ? 'Submitted ✓' : 'Pending'}</span>
      <h3>${esc(p.professor)}</h3>
      <p class="subj">${esc(p.subject)}</p>
      ${p.done ? '' : `<button class="btn ghost" data-eval="${p.id}" data-prof="${esc(p.professor)}" data-subj="${esc(p.subject)}" type="button">Evaluate</button>`}
    </article>`).join('');
  $$('[data-eval]').forEach(b => b.onclick = () => openEval(b.dataset));
}

/* ---------- evaluation form ---------- */
function openEval(d) {
  state.alloc = d.eval;
  $('#ev-prof').textContent = d.prof;
  $('#ev-subject').textContent = d.subj;
  $('#form-eval').innerHTML = CRITERIA.map(([k, label]) => `
    <fieldset>
      <legend>${label}</legend>
      <div class="scale">${[1,2,3,4,5].map(n => `
        <input type="radio" name="${k}" id="${k}${n}" value="${n}" required>
        <label for="${k}${n}">${n}<small>${LABELS[n-1]}</small></label>`).join('')}
      </div>
    </fieldset>`).join('') + `
    <label for="ev-comment">Comments (optional)</label>
    <textarea id="ev-comment" maxlength="1000" placeholder="Share feedback that can help your professor improve."></textarea>
    <p class="error" id="err-eval" role="alert"></p>
    <button class="btn primary" type="submit">Submit evaluation</button>`;
  show('view-eval');
}
$('#evalBack').onclick = () => { show('view-student'); loadStudent(); };

$('#form-eval').onsubmit = async e => {
  e.preventDefault();
  const err = $('#err-eval');
  const body = { allocation_id: Number(state.alloc), comment: $('#ev-comment').value };
  for (const [k, label] of CRITERIA) {
    const c = e.target.querySelector(`input[name="${k}"]:checked`);
    if (!c) { err.textContent = `Please rate "${label}".`; return; }
    body[k] = Number(c.value);
  }
  err.textContent = '';
  busy(e.submitter, true);
  try {
    const d = await api('submit_evaluation', { body });
    show('view-student');
    await loadStudent();
    if (d.email) {
      toast(`Email sent to ${d.email.to}: "${d.email.body}"`, 9000);
    } else {
      toast('Evaluation submitted. Thank you!');
    }
  } catch (ex) { err.textContent = ex.message; }
  busy(e.submitter, false);
};

/* ---------- admin ---------- */
$$('.admin-tabs .tab').forEach(t => t.onclick = () => {
  $$('.admin-tabs .tab').forEach(x => x.classList.toggle('active', x === t));
  ['assign', 'profs', 'evals'].forEach(n => $('#tab-' + n).hidden = n !== t.dataset.tab);
  if (t.dataset.tab === 'evals') loadEvals();
});

async function loadAdmin() {
  try {
    const d = await api('admin_overview');
    state.term = d.term;
    $('#ad-term').textContent = 'Active term: ' + d.term;
    $('#term-input').value = d.term;
    fillSelect($('#al-prof'), d.professors.map(p => ({ value: p.id, label: p.full_name })), 'Select professor');
    fillSelect($('#al-course'), courseItems(), 'Course');
    fillSelect($('#al-block'), blockItems(), 'Block');
    fillSelect($('#ev-course'), courseItems(), 'Select course');
    fillSelect($('#ev-block'), [{ value: '', label: 'All blocks' }, ...blockItems()]);

    $('#profTable').innerHTML = `<tr><th>Name</th><th>Classes</th><th></th></tr>` +
      (d.professors.map(p => `<tr><td>${esc(p.full_name)}</td><td>${p.assignments}</td>
        <td><button class="btn ghost small" data-edit="${p.id}" data-name="${esc(p.full_name)}" type="button">Rename</button>
        <button class="btn danger small" data-delprof="${p.id}" data-name="${esc(p.full_name)}" type="button">Delete</button></td></tr>`).join('')
        || `<tr><td colspan="3" class="muted">No professors yet.</td></tr>`);

    $('#allocTable').innerHTML = `<tr><th>Course</th><th>Block</th><th>Professor</th><th>Subject</th><th>Term</th><th></th></tr>` +
      (d.allocations.map(a => `<tr><td>${esc(a.course)}</td><td>Block ${esc(a.block)}</td><td>${esc(a.professor)}</td><td>${esc(a.subject)}</td><td>${esc(a.term)}</td>
        <td><button class="btn danger small" data-delalloc="${a.id}" type="button">Remove</button></td></tr>`).join('')
        || `<tr><td colspan="6" class="muted">No assignments yet.</td></tr>`);

    $$('[data-edit]').forEach(b => b.onclick = () => {
      $('#pr-id').value = b.dataset.edit; $('#pr-name').value = b.dataset.name;
      $('#pr-cancel').hidden = false; $('#pr-name').focus();
    });
    $$('[data-delprof]').forEach(b => b.onclick = async () => {
      if (!confirm(`Delete ${b.dataset.name}? Their class assignments and all evaluations received will also be deleted.`)) return;
      await adminCall('delete_professor', { id: Number(b.dataset.delprof) });
    });
    $$('[data-delalloc]').forEach(b => b.onclick = async () => {
      if (!confirm('Remove this assignment? Evaluations already submitted for it will also be deleted.')) return;
      await adminCall('delete_allocation', { id: Number(b.dataset.delalloc) });
    });
  } catch (ex) { toast(ex.message); if (ex.status === 401 || ex.status === 403) { state.user = null; route(); } }
}

async function adminCall(action, body, errEl) {
  try { await api(action, { body }); if (errEl) errEl.textContent = ''; await loadAdmin(); return true; }
  catch (ex) { if (errEl) errEl.textContent = ex.message; else toast(ex.message); return false; }
}

$('#form-term').onsubmit = async e => {
  e.preventDefault();
  if (await adminCall('set_term', { term: $('#term-input').value })) toast('Term updated.');
};
$('#form-prof').onsubmit = async e => {
  e.preventDefault();
  if (await adminCall('save_professor', { id: Number($('#pr-id').value) || 0, name: $('#pr-name').value }, $('#err-prof'))) {
    $('#pr-id').value = ''; $('#pr-name').value = ''; $('#pr-cancel').hidden = true;
  }
};
$('#pr-cancel').onclick = () => { $('#pr-id').value = ''; $('#pr-name').value = ''; $('#pr-cancel').hidden = true; };
$('#form-alloc').onsubmit = async e => {
  e.preventDefault();
  if (await adminCall('save_allocation', {
    professor_id: Number($('#al-prof').value), course: $('#al-course').value,
    block: $('#al-block').value, subject: $('#al-subject').value,
  }, $('#err-alloc'))) $('#al-subject').value = '';
};

const avg = v => v === null ? '–' : Number(v).toFixed(2);
async function loadEvals() {
  const course = $('#ev-course').value, block = $('#ev-block').value, out = $('#evResults');
  if (!course) { out.innerHTML = '<p class="muted center">Choose a course to view its evaluations.</p>'; return; }
  try {
    const d = await api('evaluations', { query: { course, block } });
    if (!d.groups.length) { out.innerHTML = '<p class="muted center">No classes found for this selection.</p>'; return; }
    let html = '', current = null;
    for (const g of d.groups) {
      if (g.block !== current) { current = g.block; html += `<h3 class="block-title">${esc(course)} · Block ${esc(g.block)}</h3>`; }
      html += `<article class="res">
        <h4>${esc(g.professor)}</h4><p class="muted">${esc(g.subject)} · ${g.responses} response${g.responses === 1 ? '' : 's'}</p>
        <div class="stats">
          <div class="stat"><b>${avg(g.teaching)}</b><span>Teaching</span></div>
          <div class="stat"><b>${avg(g.mastery)}</b><span>Mastery</span></div>
          <div class="stat"><b>${avg(g.punctuality)}</b><span>Punctuality</span></div>
        </div>
        ${g.entries.map(en => `<div class="entry">
          <div class="who">${esc(en.full_name)} <span class="muted">(${esc(en.sid || '')})</span></div>
          <div class="sc">Teaching ${en.teaching} · Mastery ${en.mastery} · Punctuality ${en.punctuality} · ${esc(en.created_at)}</div>
          ${en.comment ? `<div>${esc(en.comment)}</div>` : ''}</div>`).join('')}
      </article>`;
    }
    out.innerHTML = html;
  } catch (ex) { toast(ex.message); }
}
$('#ev-course').onchange = $('#ev-block').onchange = loadEvals;

/* ---------- full screen toggle ---------- */
const fs = $('#fsBtn');
if (!document.documentElement.requestFullscreen) fs.hidden = true;
fs.onclick = () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen().catch(() => {});

/* ---------- boot ---------- */
(async function init() {
  try {
    const d = await api('config');
    state.csrf = d.csrf; state.cfg = d; state.user = d.user;
    $('#up-sid').placeholder = d.sid_example;
    route();
  } catch (ex) {
    show('view-auth');
    $('#err-in').textContent = ex.message;
  }
})();
})();
