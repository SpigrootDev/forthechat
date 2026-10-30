<?php
// The only PHP file. All pages and design remain HTML, CSS and JavaScript.
declare(strict_types=1);
const DATA_DIR = __DIR__ . '/../portfolio-data';
const FILE_LIMIT = 20 * 1024 * 1024;
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
function public_post(array $row): array {
    $base = 'api.php?action=file&id=' . $row['id'];
    return ['id' => $row['id'], 'title' => $row['title'], 'description' => $row['description'],
        'category' => $row['category'], 'date' => $row['date'], 'fileName' => $row['file_name'],
        'fileSize' => (int)$row['file_size'], 'fileType' => $row['file_type'],
        'fileUrl' => $base, 'viewUrl' => $base . '&view=1',
        'thumbnailUrl' => $row['thumb_key'] ? $base . '&thumbnail=1' : null,
        'status' => $row['status'] ?? 'published', 'publishAt' => $row['publish_at'] ?? null];
}
function uploaded(string $field): bool {
    return isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}
function upload(string $field, bool $image = false): array {
    $file = $_FILES[$field];
    if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Uploaden mislykkedes. Kontrollér filstørrelsen.');
    if ($file['size'] < 1 || $file['size'] > ($image ? 5 * 1024 * 1024 : FILE_LIMIT)) throw new RuntimeException($image ? 'Billedet må højst fylde 5 MB.' : 'Projektfilen må højst fylde 20 MB og må ikke være tom.');
    $name = substr(basename(str_replace('\\', '/', $file['name'])), 0, 200);
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['pdf' => ['application/pdf'], 'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
    if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) throw new RuntimeException('Brug PDF, DOCX, PPTX, XLSX, TXT eller et billede.');
    if ($image && !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new RuntimeException('Forsidebilledet skal være JPG, PNG, GIF eller WebP.');
    if (str_starts_with($mime, 'image/') && !@getimagesize($file['tmp_name'])) throw new RuntimeException('Ugyldigt billede.');
    if (in_array($extension, ['docx', 'pptx', 'xlsx'], true)) $mime = $allowed[$extension][1];
    $key = bin2hex(random_bytes(24));
    if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/' . $key)) throw new RuntimeException('Filen kunne ikke gemmes. Kontrollér serverens skriverettigheder.');
    chmod(DATA_DIR . '/' . $key, 0600);
    return ['key' => $key, 'name' => $name, 'type' => $mime, 'size' => (int)$file['size']];
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
        $key = $row ? ($thumbnail ? $row['thumb_key'] : $row['file_key']) : null;
        if (!$key || !preg_match('/^[a-f0-9]{48}$/D', $key) || !is_file(DATA_DIR . '/' . $key)) { http_response_code(404); exit; }
        $mime = $thumbnail ? $row['thumb_type'] : $row['file_type'];
        $inline = $thumbnail || (($_GET['view'] ?? '') === '1' && ($mime === 'application/pdf' || str_starts_with($mime, 'image/')));
        header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'"); header('Cache-Control: no-store');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="project"; filename*=UTF-8\'\'' . rawurlencode($thumbnail ? 'forsidebillede' : $row['file_name']));
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
        reply(['posts' => array_map('public_post', $db->query('SELECT * FROM posts' . $where . ' ORDER BY date DESC, created_at DESC')->fetchAll())]);
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
            $query = $db->prepare('DELETE FROM posts WHERE id=?'); $query->execute([$id]); $db->exec('COMMIT');
            remove_file($existing['file_key']); remove_file($existing['thumb_key']); reply(['ok' => true]);
        }
        if (empty($_POST)) throw new RuntimeException('Uploaden er for stor til serveren. Se README.');
        $title = input('title'); $category = input('category'); $description = input('description'); $date = input('date');
        $parsed = strlen($date) === 10 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$title || strlen($title) > 480 || strlen($description) > 20000 || !in_array($category, ['dansk', 'mediegrafiker'], true) || !$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Udfyld titel, kategori og en gyldig dato.');
        if (!$existing && !uploaded('file')) throw new RuntimeException('Vælg en projektfil.');
        $file = $thumb = null;
        if (uploaded('file')) { $file = upload('file'); $newFiles[] = $file['key']; }
        if (uploaded('thumbnail')) { $thumb = upload('thumbnail', true); $newFiles[] = $thumb['key']; }
        $removeThumb = input('remove_thumbnail') === '1';
        $row = ['id' => $existing['id'] ?? bin2hex(random_bytes(16)), 'title' => $title, 'category' => $category, 'description' => $description, 'date' => $date,
            'file_key' => $file['key'] ?? $existing['file_key'], 'file_name' => $file['name'] ?? $existing['file_name'], 'file_type' => $file['type'] ?? $existing['file_type'], 'file_size' => $file['size'] ?? $existing['file_size'],
            'thumb_key' => $thumb['key'] ?? ($removeThumb ? null : ($existing['thumb_key'] ?? null)), 'thumb_type' => $thumb['type'] ?? ($removeThumb ? null : ($existing['thumb_type'] ?? null)), 'created_at' => $existing['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'status' => input('status') === 'draft' ? 'draft' : 'published', 'publish_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', input('publish_at')) ? input('publish_at') : null];
        $keys = array_keys($row);
        $query = $db->prepare('INSERT OR REPLACE INTO posts (' . implode(',', $keys) . ') VALUES (:' . implode(',:', $keys) . ')');
        $query->execute($row); $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK'); foreach ($newFiles as $key) remove_file($key);
        if ($e instanceof RuntimeException) reply(['error' => $e->getMessage()], 422); throw $e;
    }
    if ($existing && $file) remove_file($existing['file_key']);
    if ($existing && $existing['thumb_key'] !== $row['thumb_key']) remove_file($existing['thumb_key']);
    reply(['ok' => true, 'post' => public_post($row)]);
} catch (Throwable $e) { error_log((string)$e); reply(['error' => 'Serverfejl. Kontrollér PHP SQLite, Fileinfo og skriverettigheder.'], 500); }
