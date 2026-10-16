<?php
/**
 * Zeus Uploader - Config & Core Functions
 * تمام تنظیمات، اتصال دیتابیس و توابع مشترک
 */

// ── Session ──────────────────────────────────────────────────
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

// ── تنظیمات اصلی (از متغیرهای محیطی Railway یا مقدار پیش‌فرض) ─
define('DB_HOST', getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT', getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306');
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'zeus_uploader');
define('DB_USER', getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '');

define('SITE_NAME',   getenv('SITE_NAME')   ?: 'Zeus Uploader');
define('UPLOAD_DIR',  getenv('UPLOAD_DIR')  ?: __DIR__ . '/uploads/');

// حجم آپلود: مهمان ۵۰۰MB، عضو ۲GB
define('MAX_GUEST_SIZE',  (int)(getenv('MAX_GUEST_MB')  ?: 500)  * 1024 * 1024);
define('MAX_MEMBER_SIZE', (int)(getenv('MAX_MEMBER_MB') ?: 2048) * 1024 * 1024);

// اطلاعات ادمین
define('ADMIN_USERNAME', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASSWORD', getenv('ADMIN_PASS') ?: 'admin123');

// تلگرام
define('TG_TOKEN',    getenv('TG_TOKEN')    ?: '7665440430:AAGAM-4E4XOuBN9cbc-RhRAuqXuiuH2KtVM');
define('TG_ADMIN_ID', getenv('TG_ADMIN_ID') ?: '1601379026');

// ── اتصال PDO ──────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(503);
        die(json_encode(['error' => 'DB connection failed']));
    }
}

// ── نصب جداول هنگام اولین اجرا ─────────────────────────────
function installDB(): void {
    $pdo = getDB();
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `users` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `username`   VARCHAR(50)  NOT NULL UNIQUE,
        `password`   VARCHAR(255) NOT NULL,
        `email`      VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `max_size`   BIGINT       NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `files` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`        INT          DEFAULT NULL,
        `uploader_cookie` VARCHAR(64) DEFAULT NULL,
        `original_name`  VARCHAR(255) NOT NULL,
        `stored_name`    VARCHAR(255) NOT NULL,
        `file_size`      BIGINT       NOT NULL,
        `mime_type`      VARCHAR(100) DEFAULT 'application/octet-stream',
        `upload_date`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expiry`         DATETIME     DEFAULT NULL,
        `one_time`       TINYINT(1)   NOT NULL DEFAULT 0,
        `one_time_used`  TINYINT(1)   NOT NULL DEFAULT 0,
        `download_count` INT          NOT NULL DEFAULT 0,
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `texts` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`        INT          DEFAULT NULL,
        `uploader_cookie` VARCHAR(64) DEFAULT NULL,
        `content`        LONGTEXT     NOT NULL,
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expiry`         DATETIME     DEFAULT NULL,
        `one_time`       TINYINT(1)   NOT NULL DEFAULT 0,
        `one_time_used`  TINYINT(1)   NOT NULL DEFAULT 0,
        `view_count`     INT          NOT NULL DEFAULT 0,
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `settings` (
        `key`   VARCHAR(64) PRIMARY KEY,
        `value` TEXT        NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // تنظیمات پیش‌فرض
    $defaults = [
        'maintenance_mode' => '0',
        'maintenance_msg'  => 'سایت در حال به‌روزرسانی است. به زودی برمی‌گردیم!',
        'allow_guest'      => '1',
        'max_guest_mb'     => '500',
        'max_member_mb'    => '2048',
        'site_name'        => SITE_NAME,
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);
}

// ── توابع کمکی ─────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $s = getDB()->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $cache[$key] = ($r !== false ? $r : $default);
    } catch (Throwable $e) { return $default; }
}

function setSetting(string $key, string $value): void {
    getDB()->prepare("REPLACE INTO `settings` (`key`, `value`) VALUES (?, ?)")->execute([$key, $value]);
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}
function isAdmin(): bool {
    return !empty($_SESSION['is_admin']);
}
function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function getUploaderCookie(): string {
    if (empty($_COOKIE['zeus_uid'])) {
        $v = bin2hex(random_bytes(16));
        setcookie('zeus_uid', $v, time() + 86400 * 365, '/', '', true, true);
        $_COOKIE['zeus_uid'] = $v;
    }
    return $_COOKIE['zeus_uid'];
}

function formatSize(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes) / log(1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $u[$i];
}

function siteUrl(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $base . ($path ? '/' . ltrim($path, '/') : '');
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): bool {
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals($_SESSION['csrf'] ?? '', $token);
}

function maxSizeForCurrentUser(): int {
    if (isLoggedIn()) {
        $pdo = getDB();
        $s   = $pdo->prepare("SELECT max_size FROM users WHERE id = ?");
        $s->execute([currentUserId()]);
        $custom = (int) $s->fetchColumn();
        if ($custom > 0) return $custom;
        return (int) getSetting('max_member_mb', '2048') * 1024 * 1024;
    }
    return (int) getSetting('max_guest_mb', '500') * 1024 * 1024;
}

function isMaintenanceMode(): bool {
    return getSetting('maintenance_mode', '0') === '1';
}

// ── ارسال پیام تلگرام ──────────────────────────────────────
function tgSend(string $chatId, string $text, array $extra = []): array {
    $url     = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
    $payload = array_merge(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'], $extra);
    $ch      = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function tgNotify(string $msg): void {
    if (TG_ADMIN_ID && TG_TOKEN) {
        tgSend(TG_ADMIN_ID, $msg);
    }
}

// ── ایجاد پوشه آپلود ──────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    file_put_contents(UPLOAD_DIR . '.htaccess', "Deny from all\n");
}

// ── اجرای نصب خودکار ──────────────────────────────────────
try { installDB(); } catch (Throwable $e) { /* silently skip if already installed */ }
