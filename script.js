/* Navigation and page controls. All server communication goes through api.php. */
(() => {
  'use strict';
  let csrf = '';
  async function api(action, data) {
    const options = {credentials: 'same-origin', cache: 'no-store'};
    if (data) Object.assign(options, {method: 'POST', body: data, headers: {'X-CSRF-Token': csrf}});
    let response;
    try { response = await fetch('api.php?action=' + encodeURIComponent(action), options); }
    catch { throw new Error('Ingen forbindelse. Prøv igen.'); }
    let result;
    try { result = await response.json(); }
    catch { throw new Error('Åbn siden på en PHP-server for at bruge projekter og login.'); }
    if (!response.ok) { const error = new Error(result.error || 'Handlingen mislykkedes.'); error.status = response.status; throw error; }
    if (result.csrf) csrf = result.csrf;
    return result;
  }
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function status(node, message = '', kind = '') {
    if (!node) return;
    node.textContent = message;
    node.className = `status ${kind}`.trim();
  }
  // Database dates use YYYY-MM-DD, so lexical comparison is chronological.
  // Category pages pass 'asc' (oldest first); home/dashboard pass 'desc' (newest first).
  function sortProjectsByDate(posts, direction = 'desc') {
    const factor = direction === 'asc' ? 1 : -1;
    return [...posts].sort((a, b) => {
      const dateA = String(a.date || '');
      const dateB = String(b.date || '');
      const dateDifference = dateA.localeCompare(dateB);
      if (dateDifference !== 0) return factor * dateDifference;
      const createdA = String(a.created_at || a.createdAt || '');
      const createdB = String(b.created_at || b.createdAt || '');
      const createdDifference = createdA.localeCompare(createdB);
      if (createdDifference !== 0) return -createdDifference;
      return String(a.title || '').localeCompare(String(b.title || ''), 'da');
    });
  }
  function formatDate(date) {
    return new Intl.DateTimeFormat('da-DK', {day: 'numeric', month: 'long', year: 'numeric'}).format(new Date(`${date}T12:00:00`));
  }
  function formatBytes(bytes) {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }
  function categoryName(category) { return category === 'dansk' ? 'Dansk' : 'Mediegrafiker'; }
  function projectCard(post) {
    const card = element('article', 'card project-card');
    if (post.thumbnailUrl) {
      const image = element('img', 'project-image');
      image.src = post.thumbnailUrl; image.alt = `Forsidebillede til ${post.title}`; image.loading = 'lazy';
      card.append(image);
    }
    const body = element('div', 'project-body');
    const meta = element('div', 'project-meta');
    const time = element('time', '', formatDate(post.date)); time.dateTime = post.date;
    meta.append(element('span', 'category-badge', categoryName(post.category)), time);
    body.append(meta, element('h3', '', post.title));
    if (post.description) body.append(element('p', 'project-description', post.description));
    body.append(element('p', 'file-meta', `${post.fileName} · ${formatBytes(post.fileSize)}`));
    const actions = element('div', 'actions');
    const view = element('a', 'button button-secondary', 'Se projekt');
    view.href = post.viewUrl; view.target = '_blank'; view.rel = 'noopener';
    const download = element('a', 'button button-secondary', 'Download');
    download.href = post.fileUrl; download.download = post.fileName;
    actions.append(view, download); body.append(actions); card.append(body);
    return card;
  }
  const page = document.body.dataset.page;
  document.querySelector(`[data-page="${page}"]`)?.setAttribute('aria-current', 'page');
  document.querySelectorAll('[data-year]').forEach(node => { node.textContent = String(new Date().getFullYear()); });
  const authReady = api('session').then(session => {
    // Anonymous page counter (visitors only, no personal data).
    if (!session.loggedIn && !['login', 'dashboard'].includes(page)) { const hit = new FormData(); hit.set('page', page); api('track', hit).catch(() => {}); }
    if (session.loggedIn) {
      const link = document.querySelector('[data-auth-link]');
      if (link) { link.textContent = 'DASHBOARD'; link.href = 'dashboard.html'; if (page === 'dashboard') link.setAttribute('aria-current', 'page'); }
      document.querySelector('[data-logout]').hidden = false;
    } else if (page === 'login') document.querySelector('[data-auth-link]')?.setAttribute('aria-current', 'page');
    return session;
  });
  // Keep the promise available to page scripts while preventing an unhandled rejection on public pages.
  authReady.catch(() => {});
  document.querySelector('[data-logout]')?.addEventListener('click', async event => {
    event.currentTarget.disabled = true;
    try { await api('logout', new FormData()); location.assign('index.html'); }
    catch (error) { alert(error.message); event.currentTarget.disabled = false; }
  });
  window.Portfolio = {api, element, status, formatDate, formatBytes, categoryName, projectCard, sortProjectsByDate, authReady};
})();


(() => {
  'use strict';
  if (!(document.querySelector('#posts'))) return;
  const {api, element, status, projectCard, sortProjectsByDate} = window.Portfolio;
  const container = document.querySelector('#posts');
  const message = document.querySelector('#projects-status');
  const retry = document.querySelector('#retry-posts');
  let loading = false;
  async function load() {
    if (loading) return;
    loading = true; retry.hidden = true;
    status(message, 'Henter projekter…');
    try {
      const response = await api('posts');
      // Home: newest first. Dansk and Mediegrafiker category pages: oldest first.
      const direction = container.dataset.category ? 'asc' : 'desc';
      let posts = sortProjectsByDate(response.posts, direction);
      if (container.dataset.category) posts = posts.filter(post => post.category === container.dataset.category);
      const count = document.querySelector('#post-count');
      if (count) count.textContent = `${posts.length} ${posts.length === 1 ? 'projekt' : 'projekter'}`;
      if (container.dataset.latest) posts = posts.slice(0, Number(container.dataset.latest));
      container.replaceChildren(...posts.map(projectCard));
      status(message);
      if (!posts.length) {
        container.append(element('p', 'empty-state', container.dataset.category ? 'Der er endnu ingen projekter i denne kategori.' : 'Mine første projekter kommer snart.'));
      }
    } catch (error) { status(message, error.message, 'error'); retry.hidden = false; }
    finally { loading = false; }
  }
  retry.addEventListener('click', load);
  // Load again when returning to a page, including a browser back/forward cache restore.
  window.addEventListener('pageshow', load);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') load(); });
  // Refresh when projects are changed in another tab of this browser.
  setInterval(() => { if (document.visibilityState === 'visible') load(); }, 30000);
  load();
})();


(() => {
  'use strict';
  if (!(document.body.dataset.page === 'login')) return;
  const {api, authReady, status} = window.Portfolio;
  const form = document.querySelector('#login-form');
  const submit = document.querySelector('#login-submit');
  const message = document.querySelector('#login-status');
  authReady.then(session => {
    if (session.loggedIn) { location.replace('dashboard.html'); return; }
    if (!session.configured) { status(message, 'Vælg en adgangskode i api.php først. Se README.', 'error'); return; }
    status(message); submit.disabled = false;
  }).catch(error => status(message, error.message, 'error'));
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (submit.disabled) return;
    submit.disabled = true; status(message, 'Logger ind…');
    try { await api('login', new FormData(form)); location.replace('dashboard.html'); }
    catch (error) {
      status(message, error.message, 'error');
      form.elements.password.value = '';
      form.elements.password.focus(); submit.disabled = false;
    }
  });
})();


(() => {
  'use strict';
  if (!(document.body.dataset.page === 'dashboard')) return;
  document.querySelector('#main').hidden = true;
  const {api, authReady, element, status, formatDate, formatBytes, categoryName, sortProjectsByDate} = window.Portfolio;
  const form = document.querySelector('#project-form');
  const submit = document.querySelector('#publish-submit');
  const message = document.querySelector('#editor-status');
  const managerMessage = document.querySelector('#manage-status');
  const list = document.querySelector('#manage-posts');
  const preview = document.querySelector('#thumbnail-preview');
  const dialog = document.querySelector('#delete-dialog');
  const retry = document.querySelector('#retry-manage');
  let posts = [], pendingDelete = null, previewObjectUrl = null, existingThumb = null, busy = false;
  function today() {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }
  function showPreview(url) {
    if (previewObjectUrl) { URL.revokeObjectURL(previewObjectUrl); previewObjectUrl = null; }
    preview.hidden = !url;
    if (url) preview.src = url; else preview.removeAttribute('src');
  }
  function reset() {
    form.reset(); form.elements.id.value = ''; form.elements.date.value = today();
    form.elements.file.required = true; existingThumb = null; showPreview(null);
    document.querySelector('#editor-title').textContent = 'Nyt projekt';
    document.querySelector('#cancel-edit').hidden = true;
    document.querySelector('#current-file').hidden = true;
    document.querySelector('#file-required-mark').hidden = false;
    document.querySelector('#remove-thumbnail-label').hidden = true;
    submit.textContent = 'Udgiv projekt';
  }
  function edit(post) {
    if (busy) return;
    reset(); status(message);
    form.elements.id.value = post.id; form.elements.title.value = post.title;
    form.elements.category.value = post.category; form.elements.date.value = post.date;
    form.elements.description.value = post.description; form.elements.file.required = false;
    form.elements.status.value = post.status || 'published'; form.elements.publish_at.value = post.publishAt || '';
    document.querySelector('#editor-title').textContent = 'Rediger projekt';
    document.querySelector('#cancel-edit').hidden = false;
    document.querySelector('#file-required-mark').hidden = true;
    const current = document.querySelector('#current-file');
    current.textContent = `Nuværende fil: ${post.fileName}. Vælg kun en ny fil, hvis den skal erstattes.`; current.hidden = false;
    existingThumb = post.thumbnailUrl; showPreview(existingThumb);
    document.querySelector('#remove-thumbnail-label').hidden = !existingThumb;
    submit.textContent = 'Gem ændringer';
    document.querySelector('.editor').scrollIntoView({behavior: 'smooth', block: 'start'});
    form.elements.title.focus({preventScroll: true});
  }
  function manageCard(post) {
    const card = element('article', 'card manage-post');
    const meta = element('p', 'project-meta', `${post.status === 'draft' ? 'Kladde · ' : post.publishAt > today() ? `Udgives ${formatDate(post.publishAt)} · ` : ''}${categoryName(post.category)} · ${formatDate(post.date)}`);
    card.append(meta, element('h3', '', post.title), element('p', 'file-meta', `${post.fileName} · ${formatBytes(post.fileSize)}`));
    const actions = element('div', 'actions');
    const view = element('a', 'button button-secondary', 'Se projekt'); view.href = post.viewUrl; view.target = '_blank'; view.rel = 'noopener';
    if (!['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(post.fileType)) view.download = post.fileName;
    const editButton = element('button', 'button button-secondary', 'Rediger'); editButton.type = 'button'; editButton.disabled = busy;
    editButton.addEventListener('click', () => edit(post));
    const deleteButton = element('button', 'button button-secondary', 'Slet'); deleteButton.type = 'button'; deleteButton.disabled = busy;
    deleteButton.addEventListener('click', () => {
      if (busy) return;
      pendingDelete = post; status(document.querySelector('#delete-status'));
      document.querySelector('#delete-description').textContent = post.title;
      dialog.showModal();
    });
    actions.append(view, editButton, deleteButton); card.append(actions); return card;
  }
  function render() {
    document.querySelector('#total-count').textContent = posts.length;
    document.querySelector('#dansk-count').textContent = posts.filter(post => post.category === 'dansk').length;
    document.querySelector('#media-count').textContent = posts.filter(post => post.category === 'mediegrafiker').length;
    list.replaceChildren();
    if (!posts.length) {
      list.append(element('p', 'empty-state', 'Du har endnu ingen projekter. Upload dit første projekt i formularen.'));
      return;
    }
    // Separate categories make projects easier to locate; newest project dates appear first within each category.
    for (const category of ['dansk', 'mediegrafiker']) {
      const categoryPosts = sortProjectsByDate(posts.filter(post => post.category === category), 'desc');
      if (!categoryPosts.length) continue;
      const group = element('section', 'manage-category');
      group.append(element('h3', 'manage-category-title', categoryName(category)));
      const grid = element('div', 'manage-posts');
      grid.append(...categoryPosts.map(manageCard));
      group.append(grid); list.append(group);
    }
  }
  function setBusy(value) {
    busy = value;
    // Preserve files while locking the form during a request; build FormData before disabling.
    form.querySelectorAll('input,select,textarea,button').forEach(node => { node.disabled = value; });
    render();
    document.querySelector('#confirm-delete').disabled = value;
    document.querySelector('#cancel-delete').disabled = value;
  }
  function authError(error) {
    if (error.status === 401) { location.replace('login.html'); return true; }
    return false;
  }
  async function load() {
    status(managerMessage, 'Henter projekter…'); retry.hidden = true;
    try { posts = sortProjectsByDate((await api('posts')).posts); render(); status(managerMessage); }
    catch (error) { status(managerMessage, error.message, 'error'); retry.hidden = false; }
  }
  retry.addEventListener('click', load);
  document.querySelector('#cancel-edit').addEventListener('click', () => { reset(); status(message); });
  form.elements.thumbnail.addEventListener('change', () => {
    const file = form.elements.thumbnail.files[0];
    if (!file) { showPreview(form.elements.remove_thumbnail.checked ? null : existingThumb); return; }
    if (file.size > 5 * 1024 * 1024) {
      status(message, 'Forsidebilledet må højst fylde 5 MB.', 'error'); form.elements.thumbnail.value = '';
      showPreview(existingThumb); return;
    }
    showPreview(null); previewObjectUrl = URL.createObjectURL(file); preview.src = previewObjectUrl; preview.hidden = false;
    form.elements.remove_thumbnail.checked = false;
  });
  form.elements.remove_thumbnail.addEventListener('change', () => {
    if (form.elements.remove_thumbnail.checked) { form.elements.thumbnail.value = ''; showPreview(null); }
    else showPreview(existingThumb);
  });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || submit.disabled) return;
    if (form.elements.file.files[0]?.size > 20 * 1024 * 1024) { status(message, 'Projektfilen må højst fylde 20 MB.', 'error'); return; }
    const editing = Boolean(form.elements.id.value);
    const payload = new FormData(form); setBusy(true); status(message, 'Gemmer projekt…');
    try {
      const {post} = await api('save', payload);
      posts = sortProjectsByDate([...posts.filter(item => item.id !== post.id), post]);
      reset();
      status(message, editing ? 'Ændringerne er gemt.' : `Projektet er udgivet på ${categoryName(post.category)}.`, 'success');
    } catch (error) { if (!authError(error)) status(message, error.message, 'error'); }
    finally { setBusy(false); }
  });
  document.querySelector('#cancel-delete').addEventListener('click', () => dialog.close());
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  document.querySelector('#confirm-delete').addEventListener('click', async () => {
    if (!pendingDelete || busy) return;
    const post = pendingDelete; const payload = new FormData(); payload.set('id', post.id);
    setBusy(true); status(document.querySelector('#delete-status'), 'Sletter projekt…');
    try {
      await api('delete', payload); posts = posts.filter(item => item.id !== post.id);
      if (form.elements.id.value === post.id) reset();
      dialog.close(); status(managerMessage, 'Projektet er slettet.', 'success');
    } catch (error) { if (!authError(error)) status(document.querySelector('#delete-status'), error.message, 'error'); }
    finally { setBusy(false); }
  });
  reset();
  authReady.then(async session => {
    if (!session.loggedIn) { location.replace('login.html'); return; }
    document.querySelector('#main').hidden = false; submit.disabled = false; await load();
  }).catch(error => status(message, error.message, 'error'));
})();



(() => {
  'use strict';
  const form = document.querySelector('#contact-form');
  if (!form) return;
  const {api, status, authReady} = window.Portfolio, message = document.querySelector('#contact-status'), button = form.querySelector('button');
  form.addEventListener('submit', async event => {
    event.preventDefault(); button.disabled = true; status(message, 'Sender…');
    try {
      await authReady; await api('contact', new FormData(form)); form.reset();
      status(message, 'Tak for din besked! Jeg svarer, så hurtigt jeg kan.', 'success');
    } catch (error) { status(message, error.message, 'error'); }
    finally { button.disabled = false; }
  });
})();


(() => {
  'use strict';
  // Applies the colours, font and texts saved in the dashboard. The last settings are cached to avoid a flash of the defaults.
  const {api} = window.Portfolio;
  const FONTS = {arial: 'Arial, Helvetica, sans-serif', georgia: "Georgia, 'Times New Roman', serif", system: 'system-ui, -apple-system, sans-serif'};
  function apply(settings) {
    const root = document.documentElement.style;
    ['bg', 'card', 'text', 'accent'].forEach(key => settings[key] ? root.setProperty('--' + key, settings[key]) : root.removeProperty('--' + key));
    FONTS[settings.font] ? root.setProperty('--font', FONTS[settings.font]) : root.removeProperty('--font');
    document.querySelectorAll('[data-edit]').forEach(node => { const text = settings['txt_' + node.dataset.edit]; if (text) node.textContent = text; });
  }
  try { const cached = JSON.parse(localStorage.getItem('theme') || 'null'); if (cached) apply(cached); } catch {}
  api('settings').then(({settings}) => { apply(settings); try { localStorage.setItem('theme', JSON.stringify(settings)); } catch {} }).catch(() => {});
})();
