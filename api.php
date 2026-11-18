<?php
// The only PHP file. All pages and design remain HTML, CSS and JavaScript.
declare(strict_types=1);
const DATA_DIR = __DIR__ . '/../portfolio-data';
// Login settings live outside the web root and outside git: portfolio-data/config.php returns ['user' => ..., 'hash' => password_hash(...)].
$config = is_file(DATA_DIR . '/config.php') ? require DATA_DIR . '/config.php' : [];

function reply(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
function input(string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) reply(['error' => 'Ugyldigt felt.'], 422);
    return trim($value);
}
function public_post(array $row, array $extras = []): array {
    $base = 'api.php?action=file&id=' . $row['id'];
    // The first file lives in the posts row; extra files live in project_files.
    $files = [['name' => $row['file_name'], 'size' => (int)$row['file_size'], 'type' => $row['file_type'], 'url' => $base, 'viewUrl' => $base . '&view=1']];
    foreach ($extras as $x) {
        $u = $base . '&n=' . (int)$x['id'];
        $files[] = ['name' => $x['file_name'], 'size' => (int)$x['file_size'], 'type' => $x['file_type'], 'url' => $u, 'viewUrl' => $u . '&view=1'];
    }
    return ['id' => $row['id'], 'title' => $row['title'], 'description' => $row['description'],
        'category' => $row['category'], 'date' => $row['date'], 'fileName' => $row['file_name'],
        'fileSize' => (int)$row['file_size'], 'fileType' => $row['file_type'],
        'fileUrl' => $base, 'viewUrl' => $base . '&view=1', 'files' => $files,
        'thumbnailUrl' => $row['thumb_key'] ? $base . '&thumbnail=1' : null,
        'status' => $row['status'] ?? 'published', 'publishAt' => $row['publish_at'] ?? null];
}
function extras_of(PDO $db, ?string $postId = null): array {
    $q = $postId === null ? $db->query('SELECT * FROM project_files ORDER BY id') : $db->prepare('SELECT * FROM project_files WHERE post_id=? ORDER BY id');
    if ($postId !== null) $q->execute([$postId]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[$r['post_id']][] = $r;
    return $out;
}
function uploaded(string $field): bool {
    return isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}
// Turns $_FILES['field'] (single file or files[]) into a flat list of single-file arrays.
function normalize_files(string $field): array {
    if (!isset($_FILES[$field])) return [];
    $f = $_FILES[$field]; $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $f;
    return $out;
}
function upload(array $file, bool $image = false): array {
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $tooBig = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new RuntimeException($tooBig ? 'Filen er større end serverens grænse. Se .user.ini.' : 'Uploaden mislykkedes (fejlkode ' . $file['error'] . ').');
    }
    if ($file['size'] < 1) throw new RuntimeException('Filen er tom.');
    if ($image && $file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Billedet må højst fylde 5 MB.');
    $name = substr(basename(str_replace('\\', '/', $file['name'])), 0, 200);
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $images = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($image && (!in_array($mime, $images, true) || !@getimagesize($file['tmp_name']))) throw new RuntimeException('Forsidebilledet skal være JPG, PNG, GIF eller WebP.');
    // Any file type is accepted. Only PDFs and safe image types keep their type (so they can be viewed in the browser);
    // everything else is stored as a plain download.
    if (!in_array($mime, array_merge($images, ['application/pdf']), true) || (in_array($mime, $images, true) && !@getimagesize($file['tmp_name']))) $mime = 'application/octet-stream';
    $key = bin2hex(random_bytes(24));
    if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/' . $key)) throw new RuntimeException('Filen kunne ikke gemmes. Kontrollér serverens skriverettigheder og diskplads.');
    chmod(DATA_DIR . '/' . $key, 0600);
    return ['key' => $key, 'name' => $name !== '' ? $name : 'fil', 'type' => $mime, 'size' => (int)$file['size']];
}
function remove_file(?string $key): void {
    if ($key && preg_match('/^[a-f0-9]{48}$/D', $key) && is_file(DATA_DIR . '/' . $key)) unlink(DATA_DIR . '/' . $key);
}
function start_session(): void {
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    if (!is_dir(DATA_DIR . '/sessions')) mkdir(DATA_DIR . '/sessions', 0700);
    session_save_path(DATA_DIR . '/sessions');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    if (!session_start()) throw new RuntimeException('Cannot start session.');
}
try {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true)) throw new RuntimeException('Cannot create private storage.');
    $db = new PDO('sqlite:' . DATA_DIR . '/projects.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS posts (id TEXT PRIMARY KEY, title TEXT, category TEXT, description TEXT, date TEXT, file_key TEXT, file_name TEXT, file_type TEXT, file_size INTEGER, thumb_key TEXT, thumb_type TEXT, created_at TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT, time INTEGER)');
    // Extra files of a project (the first file stays in posts.file_*, so old projects keep working).
    $db->exec('CREATE TABLE IF NOT EXISTS project_files (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id TEXT, file_key TEXT, file_name TEXT, file_type TEXT, file_size INTEGER)');
    $db->exec('CREATE INDEX IF NOT EXISTS project_files_post ON project_files (post_id)');
    // Migrations: safe to run on every request, existing projects are kept.
    $cols = array_column($db->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('status', $cols, true)) $db->exec("ALTER TABLE posts ADD COLUMN status TEXT NOT NULL DEFAULT 'published'");
    if (!in_array('publish_at', $cols, true)) $db->exec('ALTER TABLE posts ADD COLUMN publish_at TEXT');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, text TEXT, created_at TEXT, is_read INTEGER DEFAULT 0, archived INTEGER DEFAULT 0)');
    $db->exec('CREATE TABLE IF NOT EXISTS visits (day TEXT, page TEXT)');
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) reply(['error' => 'Ugyldig handling.'], 400);
    // Files are shared with every visitor; passwords and database paths never enter the HTML.
    if ($action === 'file') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { http_response_code(404); exit; }
        $query = $db->prepare('SELECT * FROM posts WHERE id=?'); $query->execute([$id]); $row = $query->fetch();
        // Drafts and not-yet-published projects: only the logged-in admin may open their files.
        if ($row && ($row['status'] !== 'published' || ($row['publish_at'] ?? '') > date('Y-m-d'))) { start_session(); if (empty($_SESSION['admin'])) { http_response_code(404); exit; } session_write_close(); }
        $thumbnail = ($_GET['thumbnail'] ?? '') === '1';
        $n = $_GET['n'] ?? '';
        $key = $mime = $fileName = null;
        if ($row && $thumbnail) { $key = $row['thumb_key']; $mime = $row['thumb_type']; $fileName = 'forsidebillede'; }
        elseif ($row && $n !== '') {
            if (!is_string($n) || !ctype_digit($n)) { http_response_code(404); exit; }
            $q = $db->prepare('SELECT * FROM project_files WHERE id=? AND post_id=?'); $q->execute([(int)$n, $id]); $x = $q->fetch();
            if ($x) { $key = $x['file_key']; $mime = $x['file_type']; $fileName = $x['file_name']; }
        } elseif ($row) { $key = $row['file_key']; $mime = $row['file_type']; $fileName = $row['file_name']; }
        if (!$key || !preg_match('/^[a-f0-9]{48}$/D', $key) || !is_file(DATA_DIR . '/' . $key)) { http_response_code(404); exit; }
        $inline = $thumbnail || (($_GET['view'] ?? '') === '1' && ($mime === 'application/pdf' || str_starts_with((string)$mime, 'image/')));
        header('Content-Type: ' . ($mime ?: 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'"); header('Cache-Control: no-store');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="project"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        readfile(DATA_DIR . '/' . $key); exit;
    }
    start_session();
    if (time() - ($_SESSION['active'] ?? time()) > 7200) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if (!empty($_SESSION['admin'])) $_SESSION['active'] = time();
    if ($action === 'session') reply(['loggedIn' => !empty($_SESSION['admin']), 'configured' => !empty($config['hash']), 'csrf' => $_SESSION['csrf']]);
    // Visitors only see published projects whose publish date has arrived; the admin sees everything.
    if ($action === 'posts') {
        $where = empty($_SESSION['admin']) ? " WHERE status='published' AND (publish_at IS NULL OR publish_at='' OR publish_at<='" . date('Y-m-d') . "')" : '';
        $extras = extras_of($db);
        reply(['posts' => array_map(fn($r) => public_post($r, $extras[$r['id']] ?? []), $db->query('SELECT * FROM posts' . $where . ' ORDER BY date DESC, created_at DESC')->fetchAll())]);
    }
    if ($action === 'settings') reply(['settings' => $db->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR)]);
    if (in_array($action, ['messages', 'stats'], true)) {
        if (empty($_SESSION['admin'])) reply(['error' => 'Log ind for at fortsætte.'], 401);
        if ($action === 'messages') reply(['messages' => $db->query('SELECT * FROM messages ORDER BY created_at DESC LIMIT 500')->fetchAll()]);
        reply(['stats' => ['total' => (int)$db->query('SELECT COUNT(*) FROM visits')->fetchColumn(),
            'unread' => (int)$db->query('SELECT COUNT(*) FROM messages WHERE is_read=0 AND archived=0')->fetchColumn(),
            'days' => $db->query("SELECT day, COUNT(*) AS n FROM visits WHERE day >= date('now','-29 day') GROUP BY day ORDER BY day")->fetchAll(),
            'pages' => $db->query('SELECT page, COUNT(*) AS n FROM visits GROUP BY page ORDER BY n DESC LIMIT 10')->fetchAll()]]);
    }
    if (!in_array($action, ['login', 'logout', 'track', 'contact', ...['save', 'delete', 'message_update', 'setting_save']], true)) reply(['error' => 'Ukendt handling.'], 404);
    if (in_array($action, ['save', 'delete', 'message_update', 'setting_save'], true) && empty($_SESSION['admin'])) reply(['error' => 'Log ind for at fortsætte.'], 401);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(['error' => 'Brug POST.'], 405);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) reply(['error' => 'Genindlæs siden og prøv igen.'], 403);
    if ($action === 'login') {
        if (empty($config['hash'])) reply(['error' => 'Adgangskoden er ikke sat op endnu. Se README.'], 503);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $query = $db->prepare('DELETE FROM attempts WHERE time < ?'); $query->execute([time() - 900]);
        $db->exec('BEGIN IMMEDIATE');
        $query = $db->prepare('SELECT COUNT(*) FROM attempts WHERE ip=?'); $query->execute([$ip]);
        if ((int)$query->fetchColumn() >= 5) { $db->exec('COMMIT'); reply(['error' => 'For mange forsøg. Prøv igen om 15 minutter.'], 429); }
        $query = $db->prepare('INSERT INTO attempts VALUES (?, ?)'); $query->execute([$ip, time()]); $db->exec('COMMIT');
        $password = $_POST['password'] ?? '';
        if (!hash_equals((string)($config['user'] ?? 'admin'), input('username')) || !is_string($password) || !password_verify($password, $config['hash'])) reply(['error' => 'Forkert brugernavn eller adgangskode.'], 401);
        $query = $db->prepare('DELETE FROM attempts WHERE ip=?'); $query->execute([$ip]);
        session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['active'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(32)); reply(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); reply(['ok' => true]); }
    // Public actions (CSRF-protected): anonymous page counter and contact form.
    if ($action === 'track') {
        if (preg_match('/^[a-z0-9_-]{1,40}$/D', input('page'))) { $q = $db->prepare("INSERT INTO visits VALUES (date('now'), ?)"); $q->execute([input('page')]); }
        reply(['ok' => true]);
    }
    if ($action === 'contact') {
        if (input('website') !== '') reply(['ok' => true]); // honeypot field, bots fill it in
        $ip = 'c:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $q = $db->prepare('DELETE FROM attempts WHERE time < ?'); $q->execute([time() - 900]);
        $q = $db->prepare('SELECT COUNT(*) FROM attempts WHERE ip=?'); $q->execute([$ip]);
        if ((int)$q->fetchColumn() >= 5) reply(['error' => 'For mange beskeder. Prøv igen senere.'], 429);
        [$name, $email, $text] = [input('name'), input('email'), input('text')];
        if (!$name || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200 || !$text || strlen($text) > 5000) reply(['error' => 'Udfyld navn, en gyldig e-mail og en besked.'], 422);
        $q = $db->prepare('INSERT INTO attempts VALUES (?, ?)'); $q->execute([$ip, time()]);
        $q = $db->prepare('INSERT INTO messages (name, email, text, created_at) VALUES (?, ?, ?, ?)'); $q->execute([$name, $email, $text, gmdate('Y-m-d\TH:i:s\Z')]);
        reply(['ok' => true]);
    }
    if (empty($_SESSION['admin'])) reply(['error' => 'Log ind for at fortsætte.'], 401);
    if ($action === 'message_update') {
        $op = input('op'); $mid = (int)input('id');
        $sql = ['read' => 'UPDATE messages SET is_read=1 WHERE id=?', 'unread' => 'UPDATE messages SET is_read=0 WHERE id=?', 'archive' => 'UPDATE messages SET archived=1 WHERE id=?', 'unarchive' => 'UPDATE messages SET archived=0 WHERE id=?', 'delete' => 'DELETE FROM messages WHERE id=?'][$op] ?? null;
        if (!$sql) reply(['error' => 'Ugyldig handling.'], 422);
        $q = $db->prepare($sql); $q->execute([$mid]); reply(['ok' => true]);
    }
    // One endpoint for theme values (accent, bg, card, text, font) and editable page texts (txt_*).
    if ($action === 'setting_save') {
        $k = input('key'); $v = input('value');
        $ok = (in_array($k, ['accent', 'bg', 'card', 'text'], true) && ($v === '' || preg_match('/^#[0-9a-f]{6}$/iD', $v)))
            || ($k === 'font' && ($v === '' || in_array($v, ['arial', 'georgia', 'system'], true)))
            || (preg_match('/^txt_[a-z0-9_]{1,40}$/D', $k) && strlen($v) <= 5000);
        if (!$ok) reply(['error' => 'Ugyldig indstilling.'], 422);
        $q = $db->prepare($v === '' ? 'DELETE FROM settings WHERE k=?' : 'INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)'); $q->execute($v === '' ? [$k] : [$k, $v]); reply(['ok' => true]);
    }
    $id = input('id');
    if ($id !== '' && !preg_match('/^[a-f0-9]{32}$/D', $id)) reply(['error' => 'Ugyldigt projekt.'], 422);
    $db->exec('BEGIN IMMEDIATE'); $newFiles = [];
    try {
        $query = $db->prepare('SELECT * FROM posts WHERE id=?'); $query->execute([$id]); $existing = $query->fetch();
        if ($id !== '' && !$existing) throw new RuntimeException('Projektet findes ikke længere.');
        if ($action === 'delete') {
            if (!$existing) throw new RuntimeException('Vælg et projekt.');
            $extraKeys = array_column(extras_of($db, $id)[$id] ?? [], 'file_key');
            $query = $db->prepare('DELETE FROM project_files WHERE post_id=?'); $query->execute([$id]);
            $query = $db->prepare('DELETE FROM posts WHERE id=?'); $query->execute([$id]); $db->exec('COMMIT');
            remove_file($existing['file_key']); remove_file($existing['thumb_key']);
            foreach ($extraKeys as $k) remove_file($k);
            reply(['ok' => true]);
        }
        if (empty($_POST)) throw new RuntimeException('Uploaden er for stor til serveren (post_max_size). Se .user.ini.');
        $title = input('title'); $category = input('category'); $description = input('description'); $date = input('date');
        $parsed = strlen($date) === 10 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$title || strlen($title) > 480 || strlen($description) > 20000 || !in_array($category, ['dansk', 'mediegrafiker'], true) || !$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Udfyld titel, kategori og en gyldig dato.');
        // Accepts any number of files (files[]); a single 'file' field still works too.
        $uploads = array_merge(normalize_files('files'), normalize_files('file'));
        if (!$existing && !$uploads) throw new RuntimeException('Vælg mindst én projektfil.');
        $saved = [];
        foreach ($uploads as $u) { $f = upload($u); $newFiles[] = $f['key']; $saved[] = $f; }
        $thumb = null;
        if (uploaded('thumbnail')) { $thumb = upload($_FILES['thumbnail'], true); $newFiles[] = $thumb['key']; }
        $removeThumb = input('remove_thumbnail') === '1';
        $row = ['id' => $existing['id'] ?? bin2hex(random_bytes(16)), 'title' => $title, 'category' => $category, 'description' => $description, 'date' => $date,
            'file_key' => $existing['file_key'] ?? $saved[0]['key'], 'file_name' => $existing['file_name'] ?? $saved[0]['name'], 'file_type' => $existing['file_type'] ?? $saved[0]['type'], 'file_size' => $existing['file_size'] ?? $saved[0]['size'],
            'thumb_key' => $thumb['key'] ?? ($removeThumb ? null : ($existing['thumb_key'] ?? null)), 'thumb_type' => $thumb['type'] ?? ($removeThumb ? null : ($existing['thumb_type'] ?? null)), 'created_at' => $existing['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'status' => input('status') === 'draft' ? 'draft' : 'published', 'publish_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', input('publish_at')) ? input('publish_at') : null];
        $keys = array_keys($row);
        $query = $db->prepare('INSERT OR REPLACE INTO posts (' . implode(',', $keys) . ') VALUES (:' . implode(',:', $keys) . ')');
        $query->execute($row);
        // New project: the first file is the main file, the rest are extras. Editing: every new file is added as an extra.
        $ins = $db->prepare('INSERT INTO project_files (post_id, file_key, file_name, file_type, file_size) VALUES (?,?,?,?,?)');
        foreach (($existing ? $saved : array_slice($saved, 1)) as $f) $ins->execute([$row['id'], $f['key'], $f['name'], $f['type'], $f['size']]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK'); foreach ($newFiles as $key) remove_file($key);
        if ($e instanceof RuntimeException) reply(['error' => $e->getMessage()], 422); throw $e;
    }
    if ($existing && $existing['thumb_key'] !== $row['thumb_key']) remove_file($existing['thumb_key']);
    reply(['ok' => true, 'post' => public_post($row, extras_of($db, $row['id'])[$row['id']] ?? [])]);
} catch (Throwable $e) { error_log((string)$e); reply(['error' => 'Serverfejl. Kontrollér PHP SQLite, Fileinfo og skriverettigheder.'], 500); }
