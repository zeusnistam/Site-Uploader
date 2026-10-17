<?php
/**
 * Zeus Uploader — هسته: تنظیمات، دیتابیس، توابع مشترک، بکاپ/ریستور، تلگرام
 */

date_default_timezone_set('Asia/Tehran');

// ── Session ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── تنظیمات (همه از Variables ریلوی خوانده می‌شوند) ──────────
define('DB_HOST', getenv('MYSQLHOST')     ?: getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('MYSQLPORT')     ?: getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'zeus_uploader');
define('DB_USER', getenv('MYSQLUSER')     ?: getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '');

define('SITE_NAME',  getenv('SITE_NAME')  ?: 'Zeus Uploader');
define('UPLOAD_DIR', rtrim(getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads', '/') . '/');

define('ADMIN_USERNAME', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASSWORD', getenv('ADMIN_PASS') ?: '');   // خالی = ورود ادمین غیرفعال

define('TG_TOKEN',    getenv('TG_TOKEN')    ?: '');
define('TG_ADMIN_ID', getenv('TG_ADMIN_ID') ?: '');

const BACKUP_TABLES = ['settings', 'users', 'files', 'texts'];
const SCHEMA_VERSION = '2';

// ── دیتابیس ─────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        die('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#09090d;color:#eee;text-align:center;padding:60px;direction:rtl">⚠️ اتصال به دیتابیس برقرار نشد. چند لحظه بعد دوباره امتحان کن.</body>');
    }
}

function installDB(): void {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `username`   VARCHAR(50)  NOT NULL UNIQUE,
        `password`   VARCHAR(255) NOT NULL,
        `email`      VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `max_size`   BIGINT       NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `files` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `token`           VARCHAR(64)  DEFAULT NULL,
        `user_id`         INT          DEFAULT NULL,
        `uploader_cookie` VARCHAR(64)  DEFAULT NULL,
        `original_name`   VARCHAR(255) NOT NULL,
        `stored_name`     VARCHAR(255) NOT NULL,
        `file_size`       BIGINT       NOT NULL,
        `mime_type`       VARCHAR(100) DEFAULT 'application/octet-stream',
        `upload_date`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expiry`          DATETIME     DEFAULT NULL,
        `one_time`        TINYINT(1)   NOT NULL DEFAULT 0,
        `one_time_used`   TINYINT(1)   NOT NULL DEFAULT 0,
        `download_count`  INT          NOT NULL DEFAULT 0,
        KEY `user_id` (`user_id`),
        UNIQUE KEY `uniq_token` (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `texts` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `token`           VARCHAR(64)  DEFAULT NULL,
        `user_id`         INT          DEFAULT NULL,
        `uploader_cookie` VARCHAR(64)  DEFAULT NULL,
        `content`         LONGTEXT     NOT NULL,
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expiry`          DATETIME     DEFAULT NULL,
        `one_time`        TINYINT(1)   NOT NULL DEFAULT 0,
        `one_time_used`   TINYINT(1)   NOT NULL DEFAULT 0,
        `view_count`      INT          NOT NULL DEFAULT 0,
        KEY `user_id` (`user_id`),
        UNIQUE KEY `uniq_token` (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key`   VARCHAR(64) PRIMARY KEY,
        `value` TEXT        NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        'maintenance_mode' => '0',
        'maintenance_msg'  => 'سایت در حال به‌روزرسانی است. به‌زودی برمی‌گردیم!',
        'allow_guest'      => '1',
        'max_guest_mb'     => '500',
        'max_member_mb'    => '2048',
        'site_name'        => SITE_NAME,
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $st->execute([$k, $v]);
}

/** افزودن ستون token به دیتابیس‌های قدیمی و ساخت توکن برای ردیف‌های قدیمی */
function migrateDB(): void {
    $pdo = getDB();
    foreach (['files', 'texts'] as $t) {
        $has = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'token'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `$t` ADD COLUMN `token` VARCHAR(64) DEFAULT NULL");
            try { $pdo->exec("ALTER TABLE `$t` ADD UNIQUE KEY `uniq_token` (`token`)"); } catch (Throwable $e) {}
        }
        $ids = $pdo->query("SELECT `id` FROM `$t` WHERE `token` IS NULL OR `token` = ''")->fetchAll(PDO::FETCH_COLUMN);
        $up  = $pdo->prepare("UPDATE `$t` SET `token` = ? WHERE `id` = ?");
        foreach ($ids as $id) $up->execute([newToken(), $id]);
    }
}

function ensureSchema(): void {
    try {
        $v = getDB()->query("SELECT `value` FROM `settings` WHERE `key` = 'schema_v'")->fetchColumn();
    } catch (Throwable $e) { $v = false; }
    if ($v === SCHEMA_VERSION) return;
    installDB();
    migrateDB();
    setSetting('schema_v', SCHEMA_VERSION);
}

// ── تنظیمات سایت ────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    if (isset($GLOBALS['_zs'][$key])) return $GLOBALS['_zs'][$key];
    try {
        $s = getDB()->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $GLOBALS['_zs'][$key] = ($r !== false ? (string)$r : $default);
    } catch (Throwable $e) { return $default; }
}

function setSetting(string $key, string $value): void {
    getDB()->prepare("REPLACE INTO `settings` (`key`, `value`) VALUES (?, ?)")->execute([$key, $value]);
    $GLOBALS['_zs'][$key] = $value;
}

// ── احراز هویت ──────────────────────────────────────────────
function isLoggedIn(): bool { return !empty($_SESSION['user_id']); }
function isAdmin(): bool    { return !empty($_SESSION['is_admin']); }
function currentUserId(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function isMaintenanceMode(): bool { return getSetting('maintenance_mode', '0') === '1'; }

function getUploaderCookie(): string {
    if (empty($_COOKIE['zeus_uid'])) {
        $v = bin2hex(random_bytes(16));
        setcookie('zeus_uid', $v, time() + 86400 * 365, '/', '', true, true);
        $_COOKIE['zeus_uid'] = $v;
    }
    return $_COOKIE['zeus_uid'];
}

function maxSizeForCurrentUser(): int {
    $member = (int)getSetting('max_member_mb', '2048') * 1048576;
    if (isAdmin()) return $member;
    if (isLoggedIn()) {
        $s = getDB()->prepare("SELECT `max_size` FROM `users` WHERE `id` = ?");
        $s->execute([currentUserId()]);
        $custom = (int)$s->fetchColumn();
        return $custom > 0 ? $custom : $member;
    }
    return (int)getSetting('max_guest_mb', '500') * 1048576;
}

// ── امنیت / CSRF ────────────────────────────────────────────
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function verifyCsrfToken(string $t): bool {
    return $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t);
}
function verifyCsrf(): bool {
    return verifyCsrfToken((string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
}

/** توکن تصادفی ۲۴ کاراکتری (۱۴۴ بیت) برای لینک‌ها — غیرقابل حدس */
function newToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

// ── کمکی‌ها ─────────────────────────────────────────────────
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function formatSize($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min(count($u) - 1, (int)floor(log($bytes) / log(1024)));
    return round($bytes / pow(1024, $i), 2) . ' ' . $u[$i];
}

function siteUrl(string $path = ''): string {
    $proto  = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $proto === 'https';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function fileLink(string $token): string { return siteUrl('/f/' . $token); }
function textLink(string $token): string { return siteUrl('/t/' . $token); }

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function takeFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ── حذف امن فایل‌ها و کاربران ────────────────────────────────
function safeUnlink(string $storedName): void {
    $p = UPLOAD_DIR . basename($storedName);
    if ($storedName !== '' && is_file($p)) @unlink($p);
}

function deleteFileById(int $id): bool {
    $pdo = getDB();
    $s = $pdo->prepare("SELECT `stored_name` FROM `files` WHERE `id` = ?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) return false;
    safeUnlink($row['stored_name']);
    $pdo->prepare("DELETE FROM `files` WHERE `id` = ?")->execute([$id]);
    return true;
}

/** حذف کامل کاربر: خودش + همه‌ی فایل‌ها (از دیسک) + متن‌ها → لینک‌هایش هم از بین می‌رود */
function deleteUserFully(int $uid): array {
    $pdo = getDB();
    $s = $pdo->prepare("SELECT `stored_name` FROM `files` WHERE `user_id` = ?");
    $s->execute([$uid]);
    $files = 0;
    foreach ($s->fetchAll() as $f) { safeUnlink($f['stored_name']); $files++; }
    $pdo->prepare("DELETE FROM `files` WHERE `user_id` = ?")->execute([$uid]);
    $t = $pdo->prepare("DELETE FROM `texts` WHERE `user_id` = ?");
    $t->execute([$uid]);
    $texts = $t->rowCount();
    $pdo->prepare("DELETE FROM `users` WHERE `id` = ?")->execute([$uid]);
    return ['files' => $files, 'texts' => $texts];
}

/** وضعیت لینک: [کلید, برچسب]  — key: active | expired | used */
function linkState(array $row): array {
    if (!empty($row['one_time']) && !empty($row['one_time_used'])) return ['used', 'مصرف‌شده'];
    if (!empty($row['expiry']) && strtotime($row['expiry']) < time()) return ['expired', 'منقضی'];
    return ['active', 'فعال'];
}

// ── بکاپ ────────────────────────────────────────────────────
function backupJson(): string {
    $pdo = getDB();
    $tables = [];
    foreach (BACKUP_TABLES as $t) $tables[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll();
    return json_encode([
        'format'  => 'zeus-backup',
        'version' => 2,
        'created' => date('c'),
        'tables'  => $tables,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
}

function backupSql(): string {
    $pdo = getDB();
    $sql = "-- Zeus Uploader SQL Backup " . date('Y-m-d H:i') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (BACKUP_TABLES as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = '`' . implode('`, `', array_keys($r)) . '`';
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
            $sql .= "INSERT INTO `$t` ($cols) VALUES (" . implode(', ', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
}

function backupFileName(string $ext): string { return 'zeus_backup_' . date('Y-m-d_H-i') . '.' . $ext; }

/** جدا کردن دستورهای SQL با رعایت رشته‌ها و کامنت‌ها */
function splitSqlStatements(string $sql): array {
    $out = []; $buf = ''; $inStr = false; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inStr) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; }
            elseif ($c === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") { $buf .= $sql[++$i]; }
                else { $inStr = false; }
            }
            continue;
        }
        if ($c === "'") { $inStr = true; $buf .= $c; continue; }
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** فقط دستورهای مجاز روی جدول‌های خودِ سایت */
function validateSqlStatements(array $stmts): ?string {
    foreach ($stmts as $s) {
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $s)) continue;
        if (preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO)\s+`?(\w+)`?/i', $s, $m)
            && in_array($m[1], BACKUP_TABLES, true)) continue;
        return 'دستور غیرمجاز در فایل SQL: ' . mb_substr($s, 0, 60);
    }
    return null;
}

/** تحلیل فایل بکاپ بدون اعمال تغییر → [ok, پیام/خلاصه] */
function inspectBackup(string $content): array {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $t = ltrim($content);
    if ($t === '') return [false, 'فایل خالی است.'];
    if ($t[0] === '{') {
        $d = json_decode($content, true);
        if (!is_array($d)) return [false, 'فایل JSON معتبر نیست.'];
        $tables = $d['tables'] ?? $d;
        $parts = []; $known = 0;
        foreach (BACKUP_TABLES as $name) {
            if (isset($tables[$name]) && is_array($tables[$name])) { $known++; $parts[] = $name . ': ' . count($tables[$name]); }
        }
        if (!$known) return [false, 'این فایل بکاپ Zeus Uploader نیست.'];
        return [true, implode(' | ', $parts)];
    }
    $stmts = splitSqlStatements($content);
    if (!$stmts) return [false, 'فایل SQL خالی است.'];
    if ($err = validateSqlStatements($stmts)) return [false, $err];
    $ins = 0;
    foreach ($stmts as $s) if (stripos($s, 'INSERT INTO') === 0) $ins++;
    return [true, 'دستور SQL: ' . count($stmts) . ' | ردیف‌ها: ' . $ins];
}

/** بازگردانی بکاپ JSON یا SQL (جایگزین کل داده‌های فعلی می‌شود) → [ok, پیام] */
function restoreBackup(string $content): array {
    [$ok, $info] = inspectBackup($content);
    if (!$ok) return [false, $info];
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $pdo = getDB();
    try {
        if (ltrim($content)[0] === '{') {
            $d = json_decode($content, true);
            $tables = $d['tables'] ?? $d;
            $pdo->beginTransaction();
            foreach (BACKUP_TABLES as $t) {
                if (!isset($tables[$t]) || !is_array($tables[$t])) continue;
                $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
                $pdo->exec("DELETE FROM `$t`");
                foreach ($tables[$t] as $row) {
                    if (!is_array($row)) continue;
                    if ($t === 'users' && empty($row['password'])) $row['password'] = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                    if (($t === 'files' || $t === 'texts') && empty($row['token'])) $row['token'] = newToken();
                    $use = array_values(array_intersect(array_keys($row), $cols));
                    if (!$use) continue;
                    $sql = "INSERT INTO `$t` (`" . implode('`,`', $use) . "`) VALUES (" . implode(',', array_fill(0, count($use), '?')) . ")";
                    $pdo->prepare($sql)->execute(array_map(fn($c) => $row[$c], $use));
                }
            }
            $pdo->commit();
        } else {
            foreach (splitSqlStatements($content) as $s) $pdo->exec($s);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, 'خطا در بازگردانی: ' . $e->getMessage()];
    }
    $GLOBALS['_zs'] = [];
    installDB();
    migrateDB();
    setSetting('schema_v', SCHEMA_VERSION);
    return [true, $info];
}

// ── تلگرام ──────────────────────────────────────────────────
/** فراخوانی عمومی Bot API؛ $files = ['document' => [مسیر, نام‌فایل]] */
function tgApi(string $method, array $params = [], array $files = []): array {
    if (TG_TOKEN === '') return [];
    $ch   = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/' . $method);
    $opts = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $files ? 60 : 12];
    if ($files) {
        foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        foreach ($files as $k => $f) $params[$k] = new CURLFile($f[0], 'application/octet-stream', $f[1] ?? basename($f[0]));
        $opts[CURLOPT_POSTFIELDS] = $params;
    } else {
        $opts[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    curl_close($ch);
    return is_string($res) ? (json_decode($res, true) ?: []) : [];
}

function tgSend($chatId, string $text, array $extra = []): array {
    return tgApi('sendMessage', array_merge([
        'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
    ], $extra));
}

function tgNotify(string $msg, ?array $markup = null): void {
    if (TG_ADMIN_ID === '' || TG_TOKEN === '') return;
    tgSend(TG_ADMIN_ID, $msg, $markup ? ['reply_markup' => $markup] : []);
}

/** رمز مخفی وبهوک — جلوی جعل درخواست به bot.php را می‌گیرد */
function tgWebhookSecret(): string { return substr(hash('sha256', 'zeus|' . TG_TOKEN), 0, 40); }

// ── پوشه‌ی آپلود (همیشه محافظت‌شده) ─────────────────────────
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
$__ht = UPLOAD_DIR . '.htaccess';
if (is_dir(UPLOAD_DIR) && !is_file($__ht)) {
    @file_put_contents($__ht, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
}
unset($__ht);
