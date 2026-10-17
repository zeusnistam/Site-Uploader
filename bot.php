<?php
/**
 * Zeus Uploader - Telegram Bot Webhook
 * تمام دستورات ربات تلگرام برای کنترل سایت
 */
require_once __DIR__ . '/config.php';

// ── Setup Webhook (GET ?setup=1) ────────────────────────────
// یک‌بار در مرورگر باز کن: https://دامنه-تو/bot.php?setup=1
if (isset($_GET['setup']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $webhookUrl = siteUrl('/bot.php');
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/setWebhook');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['url' => $webhookUrl]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode(['webhook_url' => $webhookUrl, 'telegram' => json_decode((string)$res, true), 'curl_error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// فقط POST از تلگرام
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$raw    = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) {
    http_response_code(400);
    die('Invalid JSON');
}

// ── پردازش انواع آپدیت ──────────────────────────────────────
if (isset($update['message'])) {
    handleMessage($update['message']);
} elseif (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

http_response_code(200);
echo 'ok';
exit;

// ═══════════════════════════════════════════════════════════════
//  MESSAGE HANDLER
// ═══════════════════════════════════════════════════════════════
function handleMessage(array $msg): void {
    $chatId = $msg['chat']['id'] ?? null;
    $text   = trim($msg['text'] ?? '');

    if (!$chatId) return;

    // بررسی دسترسی ادمین
    if ((string)$chatId !== (string)TG_ADMIN_ID) {
        tgSend($chatId, '⛔ شما دسترسی به این ربات ندارید.');
        return;
    }

    // پارس دستور
    $parts   = explode(' ', $text, 3);
    $command = strtolower($parts[0] ?? '');
    $arg1    = $parts[1] ?? '';
    $arg2    = $parts[2] ?? '';

    switch ($command) {
        case '/start':
        case '/help':
            sendHelp($chatId);
            break;

        case '/stats':
            sendStats($chatId);
            break;

        case '/files':
            sendFilesList($chatId, (int)($arg1 ?: 1));
            break;

        case '/users':
            sendUsersList($chatId);
            break;

        case '/maintenance':
            toggleMaintenance($chatId, strtolower($arg1));
            break;

        case '/setmsg':
            setMaintenanceMsg($chatId, implode(' ', array_slice($parts, 1)));
            break;

        case '/backup':
            sendBackupLink($chatId);
            break;

        case '/delfile':
            deleteFile($chatId, (int)$arg1);
            break;

        case '/deluser':
            deleteUser($chatId, (int)$arg1);
            break;

        case '/setlimit':
            setUserLimit($chatId, (int)$arg1, (int)$arg2);
            break;

        case '/settings':
            sendSettings($chatId);
            break;

        case '/setguest':
            updateSetting($chatId, 'max_guest_mb', (int)$arg1, 'حداکثر حجم آپلود مهمان');
            break;

        case '/setmember':
            updateSetting($chatId, 'max_member_mb', (int)$arg1, 'حداکثر حجم آپلود عضو');
            break;

        case '/allowguest':
            allowGuest($chatId, strtolower($arg1));
            break;

        case '/sitename':
            $newName = implode(' ', array_slice($parts, 1));
            if ($newName) {
                setSetting('site_name', $newName);
                tgSend($chatId, "✅ نام سایت به <b>" . htmlspecialchars($newName) . "</b> تغییر کرد.");
            } else {
                tgSend($chatId, "❌ نام جدید وارد کنید: /sitename نام‌جدید");
            }
            break;

        case '/menu':
            sendMainMenu($chatId);
            break;

        default:
            tgSend($chatId, "❓ دستور شناخته نشد. /help برای راهنما");
    }
}

// ═══════════════════════════════════════════════════════════════
//  CALLBACK HANDLER (inline keyboard)
// ═══════════════════════════════════════════════════════════════
function handleCallback(array $cb): void {
    $chatId = $cb['message']['chat']['id'] ?? null;
    $msgId  = $cb['message']['message_id'] ?? null;
    $data   = $cb['data'] ?? '';
    $cbId   = $cb['id'] ?? '';

    if (!$chatId || (string)$chatId !== (string)TG_ADMIN_ID) {
        answerCallback($cbId, '⛔ دسترسی ندارید');
        return;
    }

    answerCallback($cbId);

    switch ($data) {
        case 'menu_stats':
            sendStats($chatId);
            break;
        case 'menu_files':
            sendFilesList($chatId, 1);
            break;
        case 'menu_users':
            sendUsersList($chatId);
            break;
        case 'menu_settings':
            sendSettings($chatId);
            break;
        case 'menu_backup':
            sendBackupLink($chatId);
            break;
        case 'maintenance_on':
            setSetting('maintenance_mode', '1');
            tgSend($chatId, "🔴 حالت تعمیر <b>فعال</b> شد.\nسایت برای کاربران بسته است.");
            break;
        case 'maintenance_off':
            setSetting('maintenance_mode', '0');
            tgSend($chatId, "🟢 حالت تعمیر <b>غیرفعال</b> شد.\nسایت در دسترس عموم است.");
            break;
        case 'allow_guest_on':
            setSetting('allow_guest', '1');
            tgSend($chatId, "✅ آپلود مهمان <b>فعال</b> شد.");
            break;
        case 'allow_guest_off':
            setSetting('allow_guest', '0');
            tgSend($chatId, "❌ آپلود مهمان <b>غیرفعال</b> شد.");
            break;
        default:
            tgSend($chatId, "❓ عمل ناشناخته: $data");
    }
}

// ═══════════════════════════════════════════════════════════════
//  FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function sendHelp(int $chatId): void {
    $msg = <<<TXT
⚡ <b>Zeus Uploader Bot — راهنما</b>

📊 <b>آمار و مشاهده:</b>
/stats — آمار کلی سایت
/files [صفحه] — لیست آخرین فایل‌ها
/users — لیست کاربران
/settings — تنظیمات فعلی

⚙️ <b>تنظیمات:</b>
/maintenance on|off — حالت تعمیر
/setmsg پیام — پیام حالت تعمیر
/allowguest on|off — اجازه آپلود مهمان
/setguest [MB] — سقف آپلود مهمان
/setmember [MB] — سقف آپلود عضو
/sitename [نام] — تغییر نام سایت

👥 <b>مدیریت کاربران:</b>
/deluser [ID] — حذف کاربر
/setlimit [user_id] [MB] — سقف اختصاصی (0=پیش‌فرض)

📁 <b>مدیریت فایل‌ها:</b>
/delfile [ID] — حذف فایل

💾 <b>بک‌آپ:</b>
/backup — لینک دانلود بک‌آپ

🎛️ /menu — منوی اصلی
TXT;

    tgSend($chatId, $msg, ['reply_markup' => json_encode([
        'inline_keyboard' => [[
            ['text' => '🎛️ منوی اصلی', 'callback_data' => 'menu_stats'],
        ]]
    ])]);
}

function sendMainMenu(int $chatId): void {
    $mm  = getSetting('maintenance_mode') === '1';
    $ag  = getSetting('allow_guest') === '1';
    $mmIcon = $mm ? '🔴 تعمیر: روشن' : '🟢 تعمیر: خاموش';
    $agIcon = $ag ? '✅ مهمان: مجاز' : '❌ مهمان: ممنوع';

    tgSend($chatId, "🎛️ <b>منوی کنترل Zeus Uploader</b>\n\n$mmIcon\n$agIcon", [
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '📊 آمار', 'callback_data' => 'menu_stats'],
                    ['text' => '📁 فایل‌ها', 'callback_data' => 'menu_files'],
                ],
                [
                    ['text' => '👥 کاربران', 'callback_data' => 'menu_users'],
                    ['text' => '⚙️ تنظیمات', 'callback_data' => 'menu_settings'],
                ],
                [
                    ['text' => '💾 بک‌آپ', 'callback_data' => 'menu_backup'],
                ],
                [
                    ['text' => $mm ? '🟢 خاموش‌کردن تعمیر' : '🔴 روشن‌کردن تعمیر',
                     'callback_data' => $mm ? 'maintenance_off' : 'maintenance_on'],
                ],
                [
                    ['text' => $ag ? '❌ غیرفعال‌کردن مهمان' : '✅ فعال‌کردن مهمان',
                     'callback_data' => $ag ? 'allow_guest_off' : 'allow_guest_on'],
                ],
            ]
        ])
    ]);
}

function sendStats(int $chatId): void {
    $pdo = getDB();

    $totalFiles   = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $totalTexts   = $pdo->query("SELECT COUNT(*) FROM texts")->fetchColumn();
    $totalUsers   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalStorage = (int)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM files")->fetchColumn();
    $totalDl      = (int)$pdo->query("SELECT COALESCE(SUM(download_count),0) FROM files")->fetchColumn();
    $todayFiles   = $pdo->query("SELECT COUNT(*) FROM files WHERE DATE(upload_date) = CURDATE()")->fetchColumn();
    $todayUsers   = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    $mm = getSetting('maintenance_mode') === '1' ? '🔴 روشن' : '🟢 خاموش';
    $ag = getSetting('allow_guest')      === '1' ? '✅ مجاز' : '❌ ممنوع';
    $maxG = getSetting('max_guest_mb', '500') . ' MB';
    $maxM = getSetting('max_member_mb', '2048') . ' MB';
    $storage = formatSize($totalStorage);

    $msg = <<<TXT
📊 <b>آمار Zeus Uploader</b>

📁 کل فایل‌ها: <b>$totalFiles</b>  (+$todayFiles امروز)
📝 متون ذخیره: <b>$totalTexts</b>
👥 کاربران:    <b>$totalUsers</b>  (+$todayUsers امروز)
📦 فضای مصرفی: <b>$storage</b>
⬇️ کل دانلود:  <b>$totalDl</b>

⚙️ <b>تنظیمات:</b>
🔧 حالت تعمیر: $mm
👤 آپلود مهمان: $ag
📤 سقف مهمان: $maxG
📤 سقف عضو:   $maxM
TXT;

    tgSend($chatId, $msg, ['reply_markup' => json_encode([
        'inline_keyboard' => [[
            ['text' => '🔄 به‌روزرسانی', 'callback_data' => 'menu_stats'],
            ['text' => '🎛️ منو', 'callback_data' => 'menu_stats'],
        ]]
    ])]);
}

function sendFilesList(int $chatId, int $page = 1): void {
    $pdo     = getDB();
    $perPage = 10;
    $off     = ($page - 1) * $perPage;
    $total   = (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $pages   = max(1, ceil($total / $perPage));

    $rows = $pdo->prepare("
        SELECT f.id, f.original_name, f.file_size, f.download_count, f.upload_date,
               u.username
        FROM files f
        LEFT JOIN users u ON f.user_id = u.id
        ORDER BY f.upload_date DESC
        LIMIT ? OFFSET ?
    ");
    $rows->execute([$perPage, $off]);
    $files = $rows->fetchAll();

    if (!$files) {
        tgSend($chatId, "📂 هیچ فایلی موجود نیست.");
        return;
    }

    $msg = "📁 <b>فایل‌ها (صفحه $page از $pages)</b>\n\n";
    foreach ($files as $f) {
        $name  = mb_substr($f['original_name'], 0, 30);
        $size  = formatSize($f['file_size']);
        $user  = $f['username'] ?: 'مهمان';
        $date  = date('m/d H:i', strtotime($f['upload_date']));
        $dl    = $f['download_count'];
        $id    = $f['id'];
        $msg  .= "🆔 <code>$id</code>  📎 <b>$name</b>\n";
        $msg  .= "   📦 $size  👤 $user  ⬇️ $dl  🕐 $date\n";
        $msg  .= "   🗑 /delfile $id\n\n";
    }

    $kb = [];
    $row = [];
    if ($page > 1) $row[] = ['text' => '⬅️ قبلی', 'callback_data' => 'files_' . ($page-1)];
    if ($page < $pages) $row[] = ['text' => 'بعدی ➡️', 'callback_data' => 'files_' . ($page+1)];
    if ($row) $kb[] = $row;

    tgSend($chatId, $msg, $kb ? ['reply_markup' => json_encode(['inline_keyboard' => $kb])] : []);
}

function sendUsersList(int $chatId): void {
    $pdo   = getDB();
    $users = $pdo->query("
        SELECT u.id, u.username, u.email, u.created_at, u.max_size,
               COUNT(f.id) AS fc, COALESCE(SUM(f.file_size),0) AS ts
        FROM users u
        LEFT JOIN files f ON f.user_id = u.id
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT 20
    ")->fetchAll();

    if (!$users) {
        tgSend($chatId, "👥 هیچ کاربری ثبت‌نام نکرده.");
        return;
    }

    $msg = "👥 <b>کاربران (آخرین ۲۰)</b>\n\n";
    foreach ($users as $u) {
        $uname = htmlspecialchars($u['username']);
        $date  = date('Y/m/d', strtotime($u['created_at']));
        $fc    = $u['fc'];
        $ts    = formatSize($u['ts']);
        $limit = $u['max_size'] > 0 ? formatSize($u['max_size']) : 'پیش‌فرض';
        $id    = $u['id'];
        $msg  .= "🆔 <code>$id</code>  👤 <b>$uname</b>\n";
        $msg  .= "   📅 $date  📁 $fc فایل  📦 $ts\n";
        $msg  .= "   📤 سقف: $limit\n";
        $msg  .= "   🗑 /deluser $id | 📤 /setlimit $id [MB]\n\n";
    }

    tgSend($chatId, $msg);
}

function sendSettings(int $chatId): void {
    $mm   = getSetting('maintenance_mode') === '1' ? '🔴 روشن' : '🟢 خاموش';
    $mmsg = getSetting('maintenance_msg');
    $ag   = getSetting('allow_guest') === '1' ? '✅ مجاز' : '❌ ممنوع';
    $gMb  = getSetting('max_guest_mb', '500');
    $mMb  = getSetting('max_member_mb', '2048');
    $site = getSetting('site_name', SITE_NAME);

    $msg = <<<TXT
⚙️ <b>تنظیمات سایت</b>

🏷️ نام سایت: <b>$site</b>
🔧 حالت تعمیر: $mm
📢 پیام تعمیر: <i>$mmsg</i>
👤 آپلود مهمان: $ag
📤 سقف مهمان: <b>{$gMb} MB</b>
📤 سقف عضو:   <b>{$mMb} MB</b>

<i>برای تغییر از دستورات زیر استفاده کنید:</i>
/maintenance on|off
/setmsg [پیام جدید]
/allowguest on|off
/setguest [MB]
/setmember [MB]
/sitename [نام]
TXT;

    tgSend($chatId, $msg, ['reply_markup' => json_encode([
        'inline_keyboard' => [
            [
                ['text' => getSetting('maintenance_mode') === '1' ? '🟢 خاموش‌کردن تعمیر' : '🔴 روشن‌کردن تعمیر',
                 'callback_data' => getSetting('maintenance_mode') === '1' ? 'maintenance_off' : 'maintenance_on'],
            ],
            [
                ['text' => getSetting('allow_guest') === '1' ? '❌ غیرفعال‌کردن مهمان' : '✅ فعال‌کردن مهمان',
                 'callback_data' => getSetting('allow_guest') === '1' ? 'allow_guest_off' : 'allow_guest_on'],
            ],
        ]
    ])]);
}

function sendBackupLink(int $chatId): void {
    $csrf = csrf();
    $url  = siteUrl("?p=backup_download&type=sql&_csrf=$csrf");
    $urlJ = siteUrl("?p=backup_download&type=json&_csrf=$csrf");

    // آمار
    $pdo = getDB();
    $fc  = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $tc  = $pdo->query("SELECT COUNT(*) FROM texts")->fetchColumn();
    $uc  = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $storage = formatSize((int)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM files")->fetchColumn());

    $msg = <<<TXT
💾 <b>بک‌آپ دیتابیس</b>

📁 فایل‌ها: $fc
📝 متون: $tc
👥 کاربران: $uc
📦 فضای مصرفی: $storage

⚠️ توجه: این لینک‌ها موقتی هستند (یک‌بار مصرف نیستند اما token دارند)

🔗 <a href="$url">دانلود SQL Backup</a>
🔗 <a href="$urlJ">دانلود JSON Backup</a>
TXT;

    tgSend($chatId, $msg);
}

function deleteFile(int $chatId, int $fileId): void {
    if ($fileId <= 0) {
        tgSend($chatId, "❌ شناسه نامعتبر. مثال: /delfile 42");
        return;
    }
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT original_name, stored_name FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if (!$file) {
        tgSend($chatId, "❌ فایل با شناسه #$fileId یافت نشد.");
        return;
    }

    $path = UPLOAD_DIR . $file['stored_name'];
    if (file_exists($path)) unlink($path);
    $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$fileId]);

    tgSend($chatId, "🗑 فایل #$fileId حذف شد:\n<b>" . htmlspecialchars($file['original_name']) . "</b>");
}

function deleteUser(int $chatId, int $userId): void {
    if ($userId <= 0) {
        tgSend($chatId, "❌ شناسه نامعتبر. مثال: /deluser 5");
        return;
    }
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        tgSend($chatId, "❌ کاربر با شناسه #$userId یافت نشد.");
        return;
    }

    // حذف فایل‌های کاربر
    $files = $pdo->prepare("SELECT stored_name FROM files WHERE user_id = ?");
    $files->execute([$userId]);
    foreach ($files->fetchAll() as $f) {
        $path = UPLOAD_DIR . $f['stored_name'];
        if (file_exists($path)) unlink($path);
    }
    $pdo->prepare("DELETE FROM files WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM texts WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

    tgSend($chatId, "🗑 کاربر #$userId (<b>" . htmlspecialchars($user['username']) . "</b>) و تمام فایل‌هایش حذف شدند.");
}

function setUserLimit(int $chatId, int $userId, int $mb): void {
    if ($userId <= 0) {
        tgSend($chatId, "❌ مثال: /setlimit 5 2048\n(0 = استفاده از پیش‌فرض)");
        return;
    }
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        tgSend($chatId, "❌ کاربر #$userId یافت نشد.");
        return;
    }

    $bytes = $mb > 0 ? $mb * 1024 * 1024 : 0;
    $pdo->prepare("UPDATE users SET max_size = ? WHERE id = ?")->execute([$bytes, $userId]);

    $label = $mb > 0 ? "{$mb} MB" : "پیش‌فرض سیستم";
    tgSend($chatId, "✅ سقف آپلود کاربر <b>" . htmlspecialchars($user['username']) . "</b> به <b>$label</b> تغییر کرد.");
}

function toggleMaintenance(int $chatId, string $state): void {
    if ($state === 'on') {
        setSetting('maintenance_mode', '1');
        tgSend($chatId, "🔴 حالت تعمیر <b>فعال</b> شد.\n\nبرای تغییر پیام: /setmsg [پیام جدید]\nبرای خاموش‌کردن: /maintenance off");
    } elseif ($state === 'off') {
        setSetting('maintenance_mode', '0');
        tgSend($chatId, "🟢 حالت تعمیر <b>غیرفعال</b> شد.\nسایت در دسترس عموم است.");
    } else {
        $current = getSetting('maintenance_mode') === '1' ? '🔴 روشن' : '🟢 خاموش';
        tgSend($chatId, "⚙️ حالت تعمیر فعلی: $current\n\nبرای تغییر: /maintenance on یا /maintenance off", [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🔴 روشن‌کردن', 'callback_data' => 'maintenance_on'],
                    ['text' => '🟢 خاموش‌کردن', 'callback_data' => 'maintenance_off'],
                ]]
            ])
        ]);
    }
}

function setMaintenanceMsg(int $chatId, string $msg): void {
    if (empty(trim($msg))) {
        tgSend($chatId, "❌ پیام نمی‌تواند خالی باشد.");
        return;
    }
    setSetting('maintenance_msg', $msg);
    tgSend($chatId, "✅ پیام حالت تعمیر به‌روز شد:\n<i>$msg</i>");
}

function allowGuest(int $chatId, string $state): void {
    if ($state === 'on') {
        setSetting('allow_guest', '1');
        tgSend($chatId, "✅ آپلود مهمان <b>فعال</b> شد.");
    } elseif ($state === 'off') {
        setSetting('allow_guest', '0');
        tgSend($chatId, "❌ آپلود مهمان <b>غیرفعال</b> شد.\nبرای آپلود باید ثبت‌نام کنند.");
    } else {
        $current = getSetting('allow_guest') === '1' ? '✅ مجاز' : '❌ ممنوع';
        tgSend($chatId, "👤 آپلود مهمان فعلی: $current", [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ فعال‌کردن', 'callback_data' => 'allow_guest_on'],
                    ['text' => '❌ غیرفعال‌کردن', 'callback_data' => 'allow_guest_off'],
                ]]
            ])
        ]);
    }
}

function updateSetting(int $chatId, string $key, int $value, string $label): void {
    if ($value <= 0) {
        tgSend($chatId, "❌ مقدار باید بزرگتر از صفر باشد. مثال: /setguest 500");
        return;
    }
    setSetting($key, (string)$value);
    tgSend($chatId, "✅ <b>$label</b> به <b>{$value} MB</b> تغییر کرد.");
}

// ── Answer Callback ─────────────────────────────────────────
function answerCallback(string $cbId, string $text = ''): void {
    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/answerCallbackQuery';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $cbId, 'text' => $text]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
