/* Dashboard extras: inbox, statistics and look. Uses the helpers exported by script.js. */
(() => {
  'use strict';
  if (document.body.dataset.page !== 'dashboard') return;
  const {api, element: el, status, formatDate, authReady} = window.Portfolio;
  const $ = selector => document.querySelector(selector);
  const send = (action, data = {}) => {
    const body = new FormData(); Object.entries(data).forEach(([key, value]) => body.set(key, value));
    return api(action, body);
  };
  // Wraps a task as an event handler with status messages; a task that returns false was cancelled.
  const run = (node, task, ok) => async event => {
    event?.preventDefault(); status(node, 'Arbejder…');
    try { const done = await task(); status(node, done === false ? '' : ok, done === false ? '' : 'success'); }
    catch (error) { if (error.status === 401) location.replace('login.html'); else status(node, error.message, 'error'); }
  };
  // Inbox.
  async function loadInbox() {
    const {messages} = await api('messages'), archived = $('#show-archived').checked;
    const unread = messages.filter(m => !m.is_read && !m.archived).length;
    $('#unread-badge').textContent = unread ? `(${unread})` : '';
    const shown = messages.filter(m => Boolean(m.archived) === archived);
    $('#msg-list').replaceChildren(...shown.map(m => {
      const card = el('article', 'card manage-post' + (m.is_read ? '' : ' unread'));
      card.append(el('p', 'project-meta', `${m.name} · ${m.email} · ${new Date(m.created_at).toLocaleString('da-DK')}`), el('p', 'project-description', m.text));
      const actions = el('div', 'actions'), reply = el('a', 'button button-secondary', 'Svar'); reply.href = 'mailto:' + m.email;
      const act = (label, op) => {
        const button = el('button', 'button button-secondary', label); button.type = 'button';
        button.addEventListener('click', run($('#msg-status'), async () => {
          if (op === 'delete' && !confirm('Slet beskeden?')) return false;
          await send('message_update', {id: m.id, op}); await loadInbox();
        }, ''));
        return button;
      };
      actions.append(reply, act(m.is_read ? 'Markér ulæst' : 'Markér læst', m.is_read ? 'unread' : 'read'), act(m.archived ? 'Gendan' : 'Arkivér', m.archived ? 'unarchive' : 'archive'), act('Slet', 'delete'));
      card.append(actions); return card;
    }));
    if (!shown.length) $('#msg-list').append(el('p', 'empty-state', archived ? 'Ingen arkiverede beskeder.' : 'Ingen beskeder endnu.'));
  }
  $('#show-archived').addEventListener('change', run($('#msg-status'), loadInbox, ''));

  // Statistics: last 30 days (UTC) and most viewed pages.
  async function loadStats() {
    const {stats} = await api('stats'), counts = new Map(stats.days.map(d => [d.day, d.n])), days = [];
    for (let i = 29; i >= 0; i--) { const day = new Date(Date.now() - i * 864e5).toISOString().slice(0, 10); days.push([day, counts.get(day) || 0]); }
    const max = Math.max(1, ...days.map(d => d[1])), chart = el('div', 'chart'), pages = el('ul', 'skills');
    days.forEach(([day, n]) => { const bar = el('span'); bar.style.height = Math.round(n / max * 100) + '%'; bar.title = `${formatDate(day)}: ${n}`; chart.append(bar); });
    stats.pages.forEach(p => pages.append(el('li', '', `${p.page}: ${p.n}`)));
    $('#stats-body').replaceChildren(el('p', '', `Besøg i alt: ${stats.total} · Ulæste beskeder: ${stats.unread}`), el('h3', '', 'Seneste 30 dage'), chart, el('h3', '', 'Mest sete sider'), pages);
  }

    // Look: theme colours, font and editable page texts (elements marked data-edit on the public pages).
  const DEFAULTS = {bg: '#f2f2f2', card: '#ffffff', text: '#272727', accent: '#272727'}, look = $('#look-form');
  const flat = text => text.replace(/\s+/g, ' ').trim(), saveSetting = (key, value) => send('setting_save', {key, value});
  const forget = () => { try { localStorage.removeItem('theme'); } catch {} };
  async function loadLook() {
    const {settings} = await api('settings'), defaults = {};
    Object.entries(DEFAULTS).forEach(([key, value]) => { look.elements[key].value = settings[key] || value; });
    look.elements.font.value = settings.font || 'arial';
    for (const file of ['index.html', 'dansk.html', 'mediegrafiker.html']) {
      const doc = new DOMParser().parseFromString(await (await fetch(file)).text(), 'text/html');
      doc.querySelectorAll('[data-edit]').forEach(node => { defaults[node.dataset.edit] = {text: flat(node.textContent), label: node.dataset.label || node.dataset.edit}; });
    }
    $('#look-texts').replaceChildren(...Object.entries(defaults).flatMap(([key, d]) => {
      const label = el('label', '', d.label), area = el('textarea');
      area.name = 'txt_' + key; area.rows = 3; area.maxLength = 5000; area.value = settings['txt_' + key] || d.text; area.dataset.default = d.text;
      label.htmlFor = area.id = 'txt-' + key; return [label, area];
    }));
  }
  look.addEventListener('submit', run($('#look-status'), async () => {
    // Values equal to the built-in defaults are stored as "empty", so the site keeps following its own defaults.
    for (const [key, value] of Object.entries(DEFAULTS)) await saveSetting(key, look.elements[key].value.toLowerCase() === value ? '' : look.elements[key].value);
    await saveSetting('font', look.elements.font.value === 'arial' ? '' : look.elements.font.value);
    for (const area of $('#look-texts').querySelectorAll('textarea')) await saveSetting(area.name, flat(area.value) === area.dataset.default ? '' : area.value.trim());
    forget();
  }, 'Udseendet er gemt. Genindlæs hjemmesiden for at se det.'));
  $('#look-reset').addEventListener('click', run($('#look-status'), async () => {
    if (!confirm('Nulstil farver, skrifttype og tekster?')) return false;
    for (const key of [...Object.keys(DEFAULTS), 'font', ...[...$('#look-texts').querySelectorAll('textarea')].map(area => area.name)]) await saveSetting(key, '');
    forget(); await loadLook();
  }, 'Alt er nulstillet.'));


  const loaders = {inbox: loadInbox, stats: loadStats, look: loadLook};
  document.querySelectorAll('[data-tab]').forEach(tab => tab.addEventListener('click', () => {
    document.querySelectorAll('[data-panel]').forEach(panel => { panel.hidden = panel.id !== 'panel-' + tab.dataset.tab; });
    document.querySelectorAll('[data-tab]').forEach(other => other.setAttribute('aria-pressed', String(other === tab)));
    const note = $('#' + ({inbox: 'msg', stats: 'stats', look: 'look'}[tab.dataset.tab]) + '-status');
    loaders[tab.dataset.tab]?.().catch(error => note && status(note, error.message, 'error'));
  }));
  authReady.then(session => session.loggedIn && loadInbox()).catch(() => {});
})();
