<?php
/**
 * Zeus Uploader — فایل اصلی سایت
 * مسیرها: / (آپلود فایل)، ?p=text، login، register، panel، admin*، /f/{token}، /t/{token}
 */
require_once __DIR__ . '/config.php';
ensureSchema();

// ── مسیریابی ────────────────────────────────────────────────
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';

if (preg_match('#^/f/([A-Za-z0-9_-]{16,64})/?$#', $uri, $m))      { $page = 'file';     $_GET['t'] = $m[1]; }
elseif (preg_match('#^/t/([A-Za-z0-9_-]{16,64})/?$#', $uri, $m))  { $page = 'textview'; $_GET['t'] = $m[1]; }

// کاربری که توسط ادمین حذف شده، دیگر لاگین نیست
if (isLoggedIn()) {
    $chk = getDB()->prepare("SELECT 1 FROM `users` WHERE `id` = ?");
    $chk->execute([currentUserId()]);
    if (!$chk->fetchColumn()) { session_destroy(); session_start(); }
}

// حالت تعمیر
if (isMaintenanceMode() && !isAdmin() && $page !== 'login') {
    http_response_code(503);
    echo renderLayout('در حال بروزرسانی', statePage('🛠️', 'سایت در حال بروزرسانی است', getSetting('maintenance_msg')), 'maintenance');
    exit;
}

// API و دانلود (بدون قالب)
switch ($page) {
    case 'api_upload':      apiUpload();      exit;
    case 'api_text':        apiText();        exit;
    case 'backup_download': backupDownload(); exit;
}

$routes = [
    'home' => 'pageHome', 'text' => 'pageText', 'login' => 'pageLogin', 'register' => 'pageRegister',
    'logout' => 'pageLogout', 'panel' => 'pagePanel', 'admin' => 'pageAdmin', 'admin_users' => 'pageAdminUsers',
    'admin_settings' => 'pageAdminSettings', 'admin_backup' => 'pageAdminBackup',
    'file' => 'pageFile', 'textview' => 'pageTextView',
];
$titles = [
    'home' => 'آپلود فایل', 'text' => 'آپلود متن', 'login' => 'ورود', 'register' => 'ثبت‌نام', 'panel' => 'پنل من',
    'admin' => 'مدیریت', 'admin_users' => 'کاربران', 'admin_settings' => 'تنظیمات', 'admin_backup' => 'بکاپ',
    'file' => 'دانلود فایل', 'textview' => 'مشاهده متن',
];
if (isset($routes[$page])) {
    $body = $routes[$page]();
} else {
    http_response_code(404);
    $body = statePage('🧭', 'صفحه پیدا نشد', 'آدرسی که وارد کردی وجود ندارد.');
}
echo renderLayout($titles[$page] ?? 'Zeus Uploader', $body, $page);
exit;

// ═══════════════════════════════════════════════════════════════
//  کمکی‌های صفحه
// ═══════════════════════════════════════════════════════════════
function u(string $q = ''): string { return '/' . ($q !== '' ? '?' . $q : ''); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function requireAdminPage(): void { if (!isAdmin()) redirect(u('p=login')); }

function csrfField(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf()) . '">'; }

function badge(string $state, string $label): string {
    $cls = ['active' => 'ok', 'expired' => 'dead', 'used' => 'warn'][$state] ?? 'info';
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function statePage(string $icon, string $title, string $sub = '', string $small = ''): string {
    ob_start(); ?>
    <section class="card state reveal">
      <div class="big"><?= $icon ?></div>
      <h1><?= e($title) ?></h1>
      <?php if ($sub !== ''): ?><p class="sub"><?= e($sub) ?></p><?php endif; ?>
      <?php if ($small !== ''): ?><small class="faint"><?= e($small) ?></small><?php endif; ?>
      <div style="margin-top:22px"><a class="btn btn-ghost" href="<?= u() ?>">🏠 صفحه‌ی اصلی</a></div>
    </section>
    <?php return ob_get_clean();
}

function expiryOptions(): string {
    $opts = [0 => 'هرگز', 1 => '۱ ساعت', 6 => '۶ ساعت', 24 => '۱ روز', 72 => '۳ روز', 168 => '۱ هفته', 720 => '۳۰ روز'];
    $h = '';
    foreach ($opts as $v => $l) $h .= '<option value="' . $v . '">' . e($l) . '</option>';
    return $h;
}

function parseExpiry($hours): ?string {
    $h = max(0, (int)$hours);
    return $h > 0 ? date('Y-m-d H:i:s', time() + $h * 3600) : null;
}

function jsonOut(array $a, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** پاسخ JSON را زودتر می‌فرستد تا کاربر منتظر پیام تلگرام نماند */
function jsonThenContinue(array $a): void {
    ignore_user_abort(true);
    $body = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

// ═══════════════════════════════════════════════════════════════
//  قالب کلی
// ═══════════════════════════════════════════════════════════════
function renderLayout(string $title, string $body, string $page): string {
    $siteName = getSetting('site_name', SITE_NAME);
    $flash    = takeFlash();
    $admin    = isAdmin();
    $logged   = isLoggedIn();
    $isAdminPage = strpos($page, 'admin') === 0;

    $nav = [['home', u(), '📤', 'آپلود فایل'], ['text', u('p=text'), '📝', 'آپلود متن']];
    if ($admin)       { $nav[] = ['admin', u('p=admin'), '🛡️', 'مدیریت']; }
    elseif ($logged)  { $nav[] = ['panel', u('p=panel'), '📁', 'پنل من']; }
    else              { $nav[] = ['login', u('p=login'), '🔐', 'ورود']; $nav[] = ['register', u('p=register'), '👤', 'ثبت‌نام']; }

    $sub = [['admin', 'admin', '📊', 'داشبورد'], ['admin_users', 'admin_users', '👥', 'کاربران'],
            ['admin_settings', 'admin_settings', '⚙️', 'تنظیمات'], ['admin_backup', 'admin_backup', '💾', 'بکاپ']];

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#09090d">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | <?= e($siteName) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%E2%9A%A1%3C/text%3E%3C/svg%3E">
<style><?= cssCode() ?></style>
</head>
<body>
<div class="orbs"><span></span><span></span><span></span></div>

<header class="topbar">
  <div class="container bar">
    <a class="brand" href="<?= u() ?>"><b>Zeus</b>Uploader <span class="bolt">⚡</span></a>
    <nav class="nav">
      <?php foreach ($nav as $n): ?>
        <a href="<?= e($n[1]) ?>" class="<?= $page === $n[0] || ($n[0] === 'admin' && $isAdminPage) ? 'active' : '' ?>"><span><?= $n[2] ?></span><?= e($n[3]) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<main class="container">
  <?php if ($admin && $isAdminPage): ?>
    <nav class="subnav reveal">
      <?php foreach ($sub as $s): ?>
        <a href="<?= u('p=' . $s[0]) ?>" class="<?= $page === $s[1] ? 'active' : '' ?>"><span><?= $s[2] ?></span><?= e($s[3]) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-err' ?> reveal"><?= e($flash[1]) ?></div>
  <?php endif; ?>
  <?= $body ?>
</main>

<footer class="foot">© 2026 <b>⚡ <?= e($siteName) ?></b> · آپلودر حرفه‌ای فایل و متن</footer>

<div class="toast" id="toast"></div>
<div class="modal-bg" id="modal">
  <div class="modal">
    <div class="modal-ico">⚠️</div>
    <p id="mtext"></p>
    <div class="modal-acts">
      <button type="button" class="btn btn-danger" id="mok">بله، انجام بده</button>
      <button type="button" class="btn btn-ghost" id="mno">انصراف</button>
    </div>
  </div>
</div>
<script><?= jsCode() ?></script>
</body>
</html>
<?php
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  صفحه‌ی آپلود فایل
// ═══════════════════════════════════════════════════════════════
function pageHome(): string {
    $max = maxSizeForCurrentUser();
    $canUpload = isLoggedIn() || isAdmin() || getSetting('allow_guest', '1') === '1';
    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head">
        <span class="ico-lg">📤</span>
        <div><h1>آپلود فایل</h1><p class="sub">فایل رو بفرست و یک لینک امن و یکتا بگیر</p></div>
        <span class="chip">حداکثر <b><?= e(formatSize($max)) ?></b></span>
      </div>
      <?php if (!$canUpload): ?>
        <div class="alert alert-err">آپلود مهمان غیرفعال است. برای آپلود ابتدا وارد شو یا ثبت‌نام کن.</div>
        <div class="row2">
          <a class="btn" href="<?= u('p=login') ?>">🔐 ورود</a>
          <a class="btn btn-ghost" href="<?= u('p=register') ?>">👤 ثبت‌نام</a>
        </div>
      <?php else: ?>
      <form id="uploadForm" data-ajax="<?= u('p=api_upload') ?>" data-max="<?= (int)$max ?>" enctype="multipart/form-data">
        <?= csrfField() ?>
        <label class="drop" id="drop">
          <input type="file" name="file" id="fileInput" required>
          <div class="ico">📎</div>
          <div class="drop-t" id="dropT">فایل رو اینجا بنداز یا کلیک کن</div>
          <small class="faint" id="dropS">همه‌ی فرمت‌ها پشتیبانی می‌شود</small>
        </label>
        <div class="row2" style="margin-top:16px">
          <div class="fg"><label>⏳ زمان انقضای لینک</label><select name="expiry_hours"><?= expiryOptions() ?></select></div>
          <div class="fg"><label>🔒 لینک یک‌بار مصرف</label>
            <label class="switch"><input type="checkbox" name="one_time" value="1"><i></i><span>بعد از اولین دانلود باطل شود</span></label>
          </div>
        </div>
        <div class="progress"><i></i></div>
        <button type="submit" class="btn btn-block" style="margin-top:18px">🚀 شروع آپلود</button>
        <div class="result"></div>
      </form>
      <?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function pageText(): string {
    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head">
        <span class="ico-lg">📝</span>
        <div><h1>آپلود متن</h1><p class="sub">متن یا کد رو ذخیره کن و لینک اشتراک‌گذاری بگیر</p></div>
      </div>
      <form data-ajax="<?= u('p=api_text') ?>">
        <?= csrfField() ?>
        <div class="fg"><label>متن</label><textarea name="content" rows="10" required placeholder="متنت رو اینجا بنویس..."></textarea></div>
        <div class="row2">
          <div class="fg"><label>⏳ زمان انقضای لینک</label><select name="expiry_hours"><?= expiryOptions() ?></select></div>
          <div class="fg"><label>🔒 لینک یک‌بار مصرف</label>
            <label class="switch"><input type="checkbox" name="one_time" value="1"><i></i><span>بعد از اولین مشاهده باطل شود</span></label>
          </div>
        </div>
        <button type="submit" class="btn btn-block" style="margin-top:6px">💾 ذخیره و ساخت لینک</button>
        <div class="result"></div>
      </form>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  ورود / ثبت‌نام
// ═══════════════════════════════════════════════════════════════
function pageLogin(): string {
    if (isAdmin())    redirect(u('p=admin'));
    if (isLoggedIn()) redirect(u('p=panel'));
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $un = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if (!verifyCsrf())            $error = 'نشست منقضی شده؛ صفحه را رفرش کن.';
        elseif ($un === '' || $pw === '') $error = 'نام کاربری و رمز عبور را وارد کن.';
        elseif (ADMIN_PASSWORD !== '' && hash_equals(ADMIN_USERNAME, $un) && hash_equals(ADMIN_PASSWORD, $pw)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['username'] = ADMIN_USERNAME;
            redirect(u('p=admin'));
        } else {
            $s = getDB()->prepare("SELECT `id`, `username`, `password` FROM `users` WHERE `username` = ?");
            $s->execute([$un]);
            $user = $s->fetch();
            if ($user && password_verify($pw, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = false;
                redirect(u('p=panel'));
            }
            usleep(500000);
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
    ob_start(); ?>
    <section class="card narrow reveal">
      <div class="card-head"><span class="ico-lg">🔐</span><div><h1>ورود</h1><p class="sub">به حساب خودت وارد شو</p></div></div>
      <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= u('p=login') ?>">
        <?= csrfField() ?>
        <div class="fg"><label>👤 نام کاربری</label><input type="text" name="username" autocomplete="username" required></div>
        <div class="fg"><label>🔑 رمز عبور</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-block" type="submit">ورود</button>
      </form>
      <p class="center faint" style="margin-top:16px">حساب نداری؟ <a class="lnk" href="<?= u('p=register') ?>">ثبت‌نام کن</a></p>
    </section>
    <?php return ob_get_clean();
}

function pageRegister(): string {
    if (isAdmin() || isLoggedIn()) redirect(u('p=panel'));
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $un = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if (!verifyCsrf())                          $error = 'نشست منقضی شده؛ صفحه را رفرش کن.';
        elseif (!preg_match('/^[\p{L}\p{N}_.-]{3,32}$/u', $un)) $error = 'نام کاربری باید ۳ تا ۳۲ کاراکتر (حروف، عدد، _ . -) باشد.';
        elseif (mb_strlen($pw) < 4)                 $error = 'رمز عبور باید حداقل ۴ کاراکتر باشد.';
        elseif (strcasecmp($un, ADMIN_USERNAME) === 0) $error = 'این نام کاربری قبلاً ثبت شده است.';
        else {
            $pdo = getDB();
            $c = $pdo->prepare("SELECT 1 FROM `users` WHERE `username` = ?");
            $c->execute([$un]);
            if ($c->fetchColumn()) $error = 'این نام کاربری قبلاً ثبت شده است.';
            else {
                $pdo->prepare("INSERT INTO `users` (`username`, `password`) VALUES (?, ?)")
                    ->execute([$un, password_hash($pw, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$pdo->lastInsertId();
                $_SESSION['username'] = $un;
                $_SESSION['is_admin'] = false;
                tgNotify('👤 کاربر جدید ثبت‌نام کرد: <b>' . e($un) . '</b>');
                redirect(u('p=panel'));
            }
        }
    }
    ob_start(); ?>
    <section class="card narrow reveal">
      <div class="card-head"><span class="ico-lg">👤</span><div><h1>ثبت‌نام</h1><p class="sub">با حساب کاربری، حجم و امکانات بیشتری داری</p></div></div>
      <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= u('p=register') ?>">
        <?= csrfField() ?>
        <div class="fg"><label>👤 نام کاربری</label><input type="text" name="username" autocomplete="username" required minlength="3" maxlength="32" value="<?= e($_POST['username'] ?? '') ?>"></div>
        <div class="fg"><label>🔑 رمز عبور</label><input type="password" name="password" autocomplete="new-password" required minlength="4"></div>
        <button class="btn btn-block" type="submit">ساخت حساب</button>
      </form>
      <p class="center faint" style="margin-top:16px">قبلاً ثبت‌نام کردی؟ <a class="lnk" href="<?= u('p=login') ?>">وارد شو</a></p>
    </section>
    <?php return ob_get_clean();
}

function pageLogout(): string {
    session_destroy();
    redirect(u());
    return '';
}

// ═══════════════════════════════════════════════════════════════
//  اجزای لیست فایل / متن
// ═══════════════════════════════════════════════════════════════
function fileItem(array $f, string $owner = '', bool $canDelete = true, string $act = ''): string {
    [$st, $stl] = linkState($f);
    $link = fileLink($f['token']);
    ob_start(); ?>
    <div class="item">
      <div class="ico-box">📄</div>
      <div class="info">
        <div class="name"><?= e($f['original_name']) ?></div>
        <div class="meta">
          <span>📦 <?= e(formatSize($f['file_size'])) ?></span>
          <span>🗓 <?= e(date('Y/m/d H:i', strtotime($f['upload_date']))) ?></span>
          <span>⬇️ <?= (int)$f['download_count'] ?></span>
          <?php if ($f['expiry']): ?><span>⏳ <?= e(date('Y/m/d H:i', strtotime($f['expiry']))) ?></span><?php endif; ?>
          <?php if ($f['one_time']): ?><span>🔒 یک‌بار مصرف</span><?php endif; ?>
          <?php if ($owner !== ''): ?><span>👤 <?= e($owner) ?></span><?php endif; ?>
        </div>
      </div>
      <?= badge($st, $stl) ?>
      <div class="acts">
        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($link) ?>">📋 کپی لینک</button>
        <a class="btn btn-ghost btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">🔗 باز کردن</a>
        <?php if ($canDelete): ?>
        <form method="post" data-confirm="این فایل برای همیشه حذف شود؟">
          <?= csrfField() ?><input type="hidden" name="act" value="del_file"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit">🗑 حذف</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}

function textItem(array $t, string $owner = ''): string {
    [$st, $stl] = linkState($t);
    $link = textLink($t['token']);
    $preview = mb_substr(preg_replace('/\s+/u', ' ', $t['content']), 0, 90);
    ob_start(); ?>
    <div class="item">
      <div class="ico-box">📝</div>
      <div class="info">
        <div class="name"><?= e($preview) ?><?= mb_strlen($t['content']) > 90 ? '…' : '' ?></div>
        <div class="meta">
          <span>🗓 <?= e(date('Y/m/d H:i', strtotime($t['created_at']))) ?></span>
          <span>👁 <?= (int)$t['view_count'] ?></span>
          <?php if ($t['expiry']): ?><span>⏳ <?= e(date('Y/m/d H:i', strtotime($t['expiry']))) ?></span><?php endif; ?>
          <?php if ($t['one_time']): ?><span>🔒 یک‌بار مصرف</span><?php endif; ?>
          <?php if ($owner !== ''): ?><span>👤 <?= e($owner) ?></span><?php endif; ?>
        </div>
      </div>
      <?= badge($st, $stl) ?>
      <div class="acts">
        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($link) ?>">📋 کپی لینک</button>
        <a class="btn btn-ghost btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">🔗 باز کردن</a>
        <form method="post" data-confirm="این متن برای همیشه حذف شود؟">
          <?= csrfField() ?><input type="hidden" name="act" value="del_text"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit">🗑 حذف</button>
        </form>
      </div>
    </div>
    <?php return ob_get_clean();
}

function statCard(string $icon, $value, string $label, bool $count = true): string {
    return '<div class="stat"><div class="stat-i">' . $icon . '</div><div class="stat-n' . ($count ? ' count' : '') . '" data-n="' . e($value) . '">' . e($value) . '</div><div class="stat-l">' . e($label) . '</div></div>';
}

// ═══════════════════════════════════════════════════════════════
//  پنل کاربر
// ═══════════════════════════════════════════════════════════════
function pagePanel(): string {
    if (isAdmin()) redirect(u('p=admin'));
    if (!isLoggedIn()) redirect(u('p=login'));
    $pdo = getDB();
    $uid = currentUserId();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['act'] ?? '') === 'del_file') {
            $s = $pdo->prepare("SELECT 1 FROM `files` WHERE `id` = ? AND `user_id` = ?");
            $s->execute([$id, $uid]);
            if ($s->fetchColumn()) { deleteFileById($id); flash('ok', 'فایل حذف شد.'); }
        } elseif (($_POST['act'] ?? '') === 'del_text') {
            $pdo->prepare("DELETE FROM `texts` WHERE `id` = ? AND `user_id` = ?")->execute([$id, $uid]);
            flash('ok', 'متن حذف شد.');
        }
        redirect(u('p=panel'));
    }

    $fs = $pdo->prepare("SELECT * FROM `files` WHERE `user_id` = ? ORDER BY `id` DESC");
    $fs->execute([$uid]);
    $files = $fs->fetchAll();
    $ts = $pdo->prepare("SELECT * FROM `texts` WHERE `user_id` = ? ORDER BY `id` DESC");
    $ts->execute([$uid]);
    $texts = $ts->fetchAll();
    $used = array_sum(array_map(fn($f) => (int)$f['file_size'], $files));
    $max  = maxSizeForCurrentUser();

    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">👋</span><div><h1>سلام <?= e($_SESSION['username'] ?? '') ?></h1><p class="sub">فایل‌ها و متن‌هایی که ساختی اینجاست</p></div>
        <span class="chip">سقف هر فایل <b><?= e(formatSize($max)) ?></b></span></div>
      <div class="stats">
        <?= statCard('📁', count($files), 'فایل') ?>
        <?= statCard('📝', count($texts), 'متن') ?>
        <?= statCard('💽', formatSize($used), 'فضای مصرفی', false) ?>
        <?= statCard('⬇️', array_sum(array_map(fn($f) => (int)$f['download_count'], $files)), 'دانلود') ?>
      </div>
      <div class="row2" style="margin-top:18px">
        <a class="btn" href="<?= u() ?>">📤 آپلود فایل جدید</a>
        <a class="btn btn-ghost" href="<?= u('p=text') ?>">📝 آپلود متن جدید</a>
      </div>
    </section>

    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">📂</span><h2>فایل‌های من</h2></div>
      <div class="list">
        <?php foreach ($files as $f) echo fileItem($f); ?>
        <?php if (!$files): ?><div class="empty">هنوز فایلی آپلود نکردی</div><?php endif; ?>
      </div>
    </section>

    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">🗒️</span><h2>متن‌های من</h2></div>
      <div class="list">
        <?php foreach ($texts as $t) echo textItem($t); ?>
        <?php if (!$texts): ?><div class="empty">هنوز متنی ذخیره نکردی</div><?php endif; ?>
      </div>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  ادمین: داشبورد
// ═══════════════════════════════════════════════════════════════
function pageAdmin(): string {
    requireAdminPage();
    $pdo = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['act'] ?? '') === 'del_file') { deleteFileById($id); flash('ok', 'فایل حذف شد.'); }
        elseif (($_POST['act'] ?? '') === 'del_text') { $pdo->prepare("DELETE FROM `texts` WHERE `id` = ?")->execute([$id]); flash('ok', 'متن حذف شد.'); }
        redirect(u('p=admin&pg=' . max(1, (int)($_GET['pg'] ?? 1))));
    }

    $nFiles = (int)$pdo->query("SELECT COUNT(*) FROM `files`")->fetchColumn();
    $nTexts = (int)$pdo->query("SELECT COUNT(*) FROM `texts`")->fetchColumn();
    $nUsers = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    $bytes  = (int)$pdo->query("SELECT COALESCE(SUM(`file_size`),0) FROM `files`")->fetchColumn();

    $per = 15;
    $pg  = max(1, (int)($_GET['pg'] ?? 1));
    $pages = max(1, (int)ceil($nFiles / $per));
    $pg = min($pg, $pages);
    $files = $pdo->query("SELECT f.*, u.username FROM `files` f LEFT JOIN `users` u ON u.id = f.user_id ORDER BY f.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per))->fetchAll();
    $texts = $pdo->query("SELECT t.*, u.username FROM `texts` t LEFT JOIN `users` u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 10")->fetchAll();

    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">🛡️</span><div><h1>داشبورد مدیریت</h1><p class="sub">نمای کلی سایت</p></div></div>
      <div class="stats">
        <?= statCard('📁', $nFiles, 'فایل آپلود شده') ?>
        <?= statCard('📝', $nTexts, 'متن ذخیره شده') ?>
        <?= statCard('👥', $nUsers, 'کاربر ثبت‌نام شده') ?>
        <?= statCard('💽', formatSize($bytes), 'فضای مصرفی', false) ?>
      </div>
    </section>

    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">📂</span><h2>همه‌ی فایل‌ها</h2></div>
      <div class="list">
        <?php foreach ($files as $f) echo fileItem($f, $f['username'] ?? 'مهمان'); ?>
        <?php if (!$files): ?><div class="empty">هیچ فایلی وجود ندارد</div><?php endif; ?>
      </div>
      <?php if ($pages > 1): ?>
        <div class="pager">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="<?= $i === $pg ? 'on' : '' ?>" href="<?= u('p=admin&pg=' . $i) ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">🗒️</span><h2>آخرین متن‌ها</h2></div>
      <div class="list">
        <?php foreach ($texts as $t) echo textItem($t, $t['username'] ?? 'مهمان'); ?>
        <?php if (!$texts): ?><div class="empty">هیچ متنی وجود ندارد</div><?php endif; ?>
      </div>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  ادمین: کاربران
// ═══════════════════════════════════════════════════════════════
function pageAdminUsers(): string {
    requireAdminPage();
    $pdo = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['act'] ?? '';
        if ($act === 'limit') {
            $mb = max(0, (int)($_POST['max_mb'] ?? 0));
            $pdo->prepare("UPDATE `users` SET `max_size` = ? WHERE `id` = ?")->execute([$mb * 1048576, $id]);
            flash('ok', 'سقف حجم کاربر ذخیره شد.');
        } elseif ($act === 'del_user') {
            $r = deleteUserFully($id);
            flash('ok', "کاربر حذف شد ({$r['files']} فایل و {$r['texts']} متن همراه لینک‌هایشان پاک شد).");
        }
        redirect(u('p=admin_users'));
    }

    $users = $pdo->query("SELECT u.*, COUNT(f.id) AS fc, COALESCE(SUM(f.file_size),0) AS fs
                          FROM `users` u LEFT JOIN `files` f ON f.user_id = u.id
                          GROUP BY u.id ORDER BY u.id DESC")->fetchAll();
    $default = (int)getSetting('max_member_mb', '2048');

    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">👥</span><div><h1>مدیریت کاربران</h1><p class="sub"><?= count($users) ?> کاربر ثبت‌نام شده</p></div></div>
      <div class="list">
        <?php foreach ($users as $us): $custom = (int)round($us['max_size'] / 1048576); ?>
        <div class="item user-item">
          <div class="ico-box">👤</div>
          <div class="info">
            <div class="name"><?= e($us['username']) ?> <small class="faint">#<?= (int)$us['id'] ?></small></div>
            <div class="meta">
              <span>🗓 <?= e(date('Y/m/d', strtotime($us['created_at']))) ?></span>
              <span>📁 <?= (int)$us['fc'] ?> فایل</span>
              <span>💽 <?= e(formatSize($us['fs'])) ?></span>
            </div>
          </div>
          <form method="post" class="inline-form">
            <?= csrfField() ?><input type="hidden" name="act" value="limit"><input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
            <input type="number" name="max_mb" min="0" value="<?= $custom ?>" placeholder="<?= $default ?>" title="سقف هر فایل به مگابایت (۰ = پیش‌فرض: <?= $default ?>)">
            <span class="faint">MB</span>
            <button class="btn btn-ghost btn-sm" type="submit">💾 ذخیره</button>
          </form>
          <form method="post" data-confirm="کاربر «<?= e($us['username']) ?>» حذف شود؟ همه‌ی فایل‌ها و متن‌هایش و لینک‌های دانلودشان هم برای همیشه پاک می‌شود.">
            <?= csrfField() ?><input type="hidden" name="act" value="del_user"><input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">🗑 حذف کاربر</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$users): ?><div class="empty">هنوز کاربری ثبت‌نام نکرده</div><?php endif; ?>
      </div>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  ادمین: تنظیمات
// ═══════════════════════════════════════════════════════════════
function pageAdminSettings(): string {
    requireAdminPage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        setSetting('site_name', mb_substr(trim((string)($_POST['site_name'] ?? '')), 0, 60) ?: SITE_NAME);
        setSetting('maintenance_msg', mb_substr(trim((string)($_POST['maintenance_msg'] ?? '')), 0, 300));
        setSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
        setSetting('allow_guest', isset($_POST['allow_guest']) ? '1' : '0');
        setSetting('max_guest_mb', (string)max(1, (int)($_POST['max_guest_mb'] ?? 500)));
        setSetting('max_member_mb', (string)max(1, (int)($_POST['max_member_mb'] ?? 2048)));
        flash('ok', 'تنظیمات ذخیره شد.');
        redirect(u('p=admin_settings'));
    }
    $mm = getSetting('maintenance_mode') === '1';
    $ag = getSetting('allow_guest') === '1';
    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">⚙️</span><div><h1>تنظیمات سایت</h1><p class="sub">همه‌ی تغییرات همین الان اعمال می‌شود</p></div></div>
      <form method="post">
        <?= csrfField() ?>
        <div class="fg"><label>🏷️ نام سایت</label><input type="text" name="site_name" value="<?= e(getSetting('site_name', SITE_NAME)) ?>" maxlength="60"></div>
        <div class="row2">
          <div class="fg"><label>📤 سقف حجم مهمان (MB)</label><input type="number" name="max_guest_mb" min="1" value="<?= e(getSetting('max_guest_mb', '500')) ?>"></div>
          <div class="fg"><label>📤 سقف حجم عضو (MB)</label><input type="number" name="max_member_mb" min="1" value="<?= e(getSetting('max_member_mb', '2048')) ?>"></div>
        </div>
        <div class="fg"><label>👤 آپلود مهمان</label>
          <label class="switch"><input type="checkbox" name="allow_guest" value="1" <?= $ag ? 'checked' : '' ?>><i></i><span>مهمان‌ها بدون ثبت‌نام آپلود کنند</span></label></div>
        <div class="fg"><label>🔧 حالت تعمیر</label>
          <label class="switch"><input type="checkbox" name="maintenance_mode" value="1" <?= $mm ? 'checked' : '' ?>><i></i><span>سایت برای همه جز ادمین بسته شود</span></label></div>
        <div class="fg"><label>📢 پیام حالت تعمیر</label><textarea name="maintenance_msg" rows="3"><?= e(getSetting('maintenance_msg')) ?></textarea></div>
        <button class="btn" type="submit">💾 ذخیره‌ی تنظیمات</button>
      </form>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  ادمین: بکاپ و بازگردانی
// ═══════════════════════════════════════════════════════════════
function pageAdminBackup(): string {
    requireAdminPage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) { flash('err', 'نشست منقضی شده؛ دوباره امتحان کن.'); redirect(u('p=admin_backup')); }
        $f = $_FILES['backup'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('err', 'فایلی انتخاب نشده یا آپلود با خطا مواجه شد.');
        } elseif (empty($_POST['confirm'])) {
            flash('err', 'برای بازگردانی باید تیک تأیید را بزنی.');
        } else {
            [$ok, $msg] = restoreBackup((string)file_get_contents($f['tmp_name']));
            flash($ok ? 'ok' : 'err', $ok ? 'بازگردانی با موفقیت انجام شد → ' . $msg : $msg);
            if ($ok) { session_regenerate_id(true); }
        }
        redirect(u('p=admin_backup'));
    }
    $c = csrf();
    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">💾</span><div><h1>دریافت بکاپ</h1><p class="sub">کل دیتابیس شامل کاربران، رمزها، فایل‌ها، متن‌ها و تنظیمات</p></div></div>
      <div class="row2">
        <a class="btn" href="<?= u('p=backup_download&type=json&_csrf=' . $c) ?>">📦 دانلود بکاپ JSON</a>
        <a class="btn btn-ghost" href="<?= u('p=backup_download&type=sql&_csrf=' . $c) ?>">🗄️ دانلود بکاپ SQL</a>
      </div>
      <p class="faint" style="margin-top:14px">⚠️ بکاپ فقط اطلاعات دیتابیس رو نگه می‌داره؛ محتوای خود فایل‌های آپلودشده داخلش نیست.</p>
    </section>

    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">♻️</span><div><h1>بازگردانی بکاپ</h1><p class="sub">فایل .json یا .sql که قبلاً گرفتی رو آپلود کن</p></div></div>
      <form method="post" enctype="multipart/form-data" data-confirm="همه‌ی داده‌های فعلی با محتوای بکاپ جایگزین می‌شود. ادامه می‌دهی؟">
        <?= csrfField() ?>
        <label class="drop" id="drop">
          <input type="file" name="backup" id="fileInput" accept=".json,.sql,application/json" required>
          <div class="ico">🗂️</div>
          <div class="drop-t" id="dropT">فایل بکاپ رو اینجا بنداز یا کلیک کن</div>
          <small class="faint" id="dropS">JSON یا SQL</small>
        </label>
        <div class="fg" style="margin-top:16px">
          <label class="switch"><input type="checkbox" name="confirm" value="1"><i></i><span>می‌دانم که داده‌های فعلی جایگزین می‌شود</span></label>
        </div>
        <button class="btn btn-danger" type="submit">♻️ بازگردانی بکاپ</button>
      </form>
    </section>
    <?php return ob_get_clean();
}

function backupDownload(): void {
    if (!isAdmin() || !verifyCsrfToken((string)($_GET['_csrf'] ?? ''))) { http_response_code(403); die('دسترسی ممنوع'); }
    $isSql = ($_GET['type'] ?? 'json') === 'sql';
    $data  = $isSql ? backupSql() : backupJson();
    header('Content-Type: ' . ($isSql ? 'application/sql' : 'application/json') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . backupFileName($isSql ? 'sql' : 'json') . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
}

// ═══════════════════════════════════════════════════════════════
//  لینک‌های عمومی: فایل و متن
// ═══════════════════════════════════════════════════════════════
function deadLinkPage(string $kind, array $row): string {
    [$st] = linkState($row);
    if ($st === 'used') return statePage('⏳', 'این لینک منقضی شده است', '', 'لینک یک‌بار مصرف بود');
    return statePage('⏳', 'این لینک منقضی شده است', '', 'مهلت اعتبار این لینک به پایان رسیده بود');
}

function pageFile(): string {
    $s = getDB()->prepare("SELECT * FROM `files` WHERE `token` = ?");
    $s->execute([(string)($_GET['t'] ?? '')]);
    $f = $s->fetch();
    if (!$f) { http_response_code(404); return statePage('🔍', 'لینک پیدا نشد', 'این لینک وجود ندارد یا حذف شده است.'); }
    [$st] = linkState($f);
    if ($st !== 'active') { http_response_code(410); return deadLinkPage('file', $f); }
    $path = UPLOAD_DIR . basename($f['stored_name']);
    if (!is_file($path)) { http_response_code(404); return statePage('📭', 'فایل در دسترس نیست', 'این فایل از روی سرور پاک شده است.'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = getDB();
        $up = $pdo->prepare("UPDATE `files` SET `one_time_used` = IF(`one_time` = 1, 1, `one_time_used`), `download_count` = `download_count` + 1
                             WHERE `id` = ? AND (`one_time` = 0 OR `one_time_used` = 0)");
        $up->execute([$f['id']]);
        if ($up->rowCount() === 0) { http_response_code(410); return deadLinkPage('file', array_merge($f, ['one_time_used' => 1])); }
        streamFile($path, $f['original_name']);
        exit;
    }

    ob_start(); ?>
    <section class="card narrow state reveal">
      <div class="big">📦</div>
      <h1 class="fname"><?= e($f['original_name']) ?></h1>
      <div class="meta center">
        <span>📦 <?= e(formatSize($f['file_size'])) ?></span>
        <span>🗓 <?= e(date('Y/m/d', strtotime($f['upload_date']))) ?></span>
        <?php if ($f['expiry']): ?><span>⏳ تا <?= e(date('Y/m/d H:i', strtotime($f['expiry']))) ?></span><?php endif; ?>
      </div>
      <?php if ($f['one_time']): ?><div class="alert alert-warn" style="margin-top:16px">🔒 این لینک یک‌بار مصرف است؛ بعد از شروع دانلود دیگر کار نمی‌کند.</div><?php endif; ?>
      <form method="post" style="margin-top:22px"><button class="btn btn-block" type="submit">⬇️ دانلود فایل</button></form>
    </section>
    <?php return ob_get_clean();
}

function streamFile(string $path, string $name): void {
    @set_time_limit(0);
    while (ob_get_level() > 0) @ob_end_clean();
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $h = fopen($path, 'rb');
    while (!feof($h) && !connection_aborted()) { echo fread($h, 1 << 20); flush(); }
    fclose($h);
}

function pageTextView(): string {
    $pdo = getDB();
    $s = $pdo->prepare("SELECT * FROM `texts` WHERE `token` = ?");
    $s->execute([(string)($_GET['t'] ?? '')]);
    $t = $s->fetch();
    if (!$t) { http_response_code(404); return statePage('🔍', 'لینک پیدا نشد', 'این لینک وجود ندارد یا حذف شده است.'); }
    [$st] = linkState($t);
    if ($st !== 'active') { http_response_code(410); return deadLinkPage('text', $t); }

    // لینک یک‌بار مصرف: ابتدا صفحه‌ی تأیید (تا پیش‌نمایش پیام‌رسان‌ها لینک را نسوزاند)
    if ($t['one_time'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_start(); ?>
        <section class="card narrow state reveal">
          <div class="big">🔒</div>
          <h1>متن یک‌بار مصرف</h1>
          <p class="sub">بعد از نمایش، این لینک برای همیشه باطل می‌شود.</p>
          <form method="post" style="margin-top:22px"><button class="btn btn-block" type="submit">👁 نمایش متن</button></form>
        </section>
        <?php return ob_get_clean();
    }

    $up = $pdo->prepare("UPDATE `texts` SET `one_time_used` = IF(`one_time` = 1, 1, `one_time_used`), `view_count` = `view_count` + 1
                         WHERE `id` = ? AND (`one_time` = 0 OR `one_time_used` = 0)");
    $up->execute([$t['id']]);
    if ($up->rowCount() === 0) { http_response_code(410); return deadLinkPage('text', array_merge($t, ['one_time_used' => 1])); }

    ob_start(); ?>
    <section class="card reveal">
      <div class="card-head"><span class="ico-lg">📝</span><div><h1>متن اشتراکی</h1>
        <p class="sub">👁 <?= (int)$t['view_count'] + 1 ?> بازدید<?= $t['one_time'] ? ' · 🔒 یک‌بار مصرف' : '' ?></p></div>
        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($t['content']) ?>">📋 کپی متن</button></div>
      <pre class="textbox"><?= e($t['content']) ?></pre>
    </section>
    <?php return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
//  API: آپلود فایل و متن
// ═══════════════════════════════════════════════════════════════
function apiUpload(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['success' => false, 'message' => 'درخواست نامعتبر'], 405); return; }
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonOut(['success' => false, 'message' => 'حجم فایل از سقف مجاز سرور بیشتر است'], 413); return;
    }
    if (!verifyCsrf()) { jsonOut(['success' => false, 'message' => 'نشست منقضی شده؛ صفحه را رفرش کن'], 403); return; }
    if (!isLoggedIn() && !isAdmin() && getSetting('allow_guest', '1') !== '1') {
        jsonOut(['success' => false, 'message' => 'برای آپلود ابتدا ثبت‌نام کن'], 403); return;
    }
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) { jsonOut(['success' => false, 'message' => 'فایلی انتخاب نشده']); return; }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $map = [UPLOAD_ERR_INI_SIZE => 'فایل از سقف سرور بزرگ‌تر است', UPLOAD_ERR_FORM_SIZE => 'فایل خیلی بزرگ است',
                UPLOAD_ERR_PARTIAL => 'آپلود ناقص ماند', UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن روی دیسک'];
        jsonOut(['success' => false, 'message' => $map[$f['error']] ?? 'خطای آپلود (' . $f['error'] . ')']); return;
    }
    $max = maxSizeForCurrentUser();
    if ($f['size'] > $max) { jsonOut(['success' => false, 'message' => 'حجم فایل بیشتر از ' . formatSize($max) . ' است']); return; }

    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($f['name'], PATHINFO_EXTENSION)));
    if (in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'htaccess'], true) || strlen($ext) > 10) $ext = '';
    $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $stored)) { jsonOut(['success' => false, 'message' => 'ذخیره‌ی فایل روی سرور ناموفق بود']); return; }

    $mime   = function_exists('mime_content_type') ? (@mime_content_type(UPLOAD_DIR . $stored) ?: 'application/octet-stream') : 'application/octet-stream';
    $expiry = parseExpiry($_POST['expiry_hours'] ?? 0);
    $one    = ($_POST['one_time'] ?? '') === '1' ? 1 : 0;
    $token  = newToken();
    $name   = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 250);
    $uid    = isAdmin() ? null : currentUserId();

    $pdo = getDB();
    $pdo->prepare("INSERT INTO `files` (`token`, `user_id`, `uploader_cookie`, `original_name`, `stored_name`, `file_size`, `mime_type`, `expiry`, `one_time`) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$token, $uid, getUploaderCookie(), $name, $stored, (int)$f['size'], $mime, $expiry, $one]);
    $id = (int)$pdo->lastInsertId();

    jsonThenContinue(['success' => true, 'link' => fileLink($token), 'expiry' => $expiry ? date('Y/m/d H:i', strtotime($expiry)) : null, 'one_time' => (bool)$one]);

    $who = isAdmin() ? 'ادمین' : ($uid ? 'کاربر ' . e($_SESSION['username'] ?? ('#' . $uid)) : 'مهمان');
    tgNotify("📤 <b>فایل جدید</b>\n📄 " . e($name) . "\n📦 " . formatSize($f['size']) . "\n👤 " . $who,
        ['inline_keyboard' => [[
            ['text' => '🔗 باز کردن', 'url' => fileLink($token)],
            ['text' => '🗑 حذف', 'callback_data' => 'fd:' . $id, 'style' => 'danger'],
        ]]]);
}

function apiText(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['success' => false, 'message' => 'درخواست نامعتبر'], 405); return; }
    if (!verifyCsrf()) { jsonOut(['success' => false, 'message' => 'نشست منقضی شده؛ صفحه را رفرش کن'], 403); return; }
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') { jsonOut(['success' => false, 'message' => 'متن نمی‌تواند خالی باشد']); return; }
    if (strlen($content) > 5 * 1048576) { jsonOut(['success' => false, 'message' => 'متن خیلی طولانی است (حداکثر ۵ مگابایت)']); return; }

    $expiry = parseExpiry($_POST['expiry_hours'] ?? 0);
    $one    = ($_POST['one_time'] ?? '') === '1' ? 1 : 0;
    $token  = newToken();
    $uid    = isAdmin() ? null : currentUserId();
    getDB()->prepare("INSERT INTO `texts` (`token`, `user_id`, `uploader_cookie`, `content`, `expiry`, `one_time`) VALUES (?,?,?,?,?,?)")
        ->execute([$token, $uid, getUploaderCookie(), $content, $expiry, $one]);

    jsonOut(['success' => true, 'link' => textLink($token), 'expiry' => $expiry ? date('Y/m/d H:i', strtotime($expiry)) : null, 'one_time' => (bool)$one]);
}

// ═══════════════════════════════════════════════════════════════
//  استایل (CSS)
// ═══════════════════════════════════════════════════════════════
function cssCode(): string {
    return <<<'CSS'
:root{--bg:#09090d;--bd:rgba(255,255,255,.09);--tx:#f4f4f7;--mut:#a3a3b2;--faint:#7d7d8c;--or:#ff7a18;--or2:#ffb347;--red:#ff4d5e;--grn:#2ecc71;--blu:#4da3ff}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{font-family:Vazirmatn,"Segoe UI",Tahoma,system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--tx);direction:rtl;min-height:100vh;display:flex;flex-direction:column;align-items:stretch;line-height:1.75;overflow-x:hidden}
a{color:inherit}
.container{width:100%;max-width:1040px;margin:0 auto;padding:0 16px}
main.container{flex:1;padding-top:22px;padding-bottom:30px;display:flex;flex-direction:column;gap:20px}

/* پس‌زمینه */
.orbs{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
.orbs span{position:absolute;border-radius:50%;filter:blur(95px);opacity:.34;animation:float 20s ease-in-out infinite}
.orbs span:nth-child(1){width:420px;height:420px;background:#ff7a18;top:-140px;right:-120px}
.orbs span:nth-child(2){width:380px;height:380px;background:#7a3cff;bottom:-120px;left:-120px;animation-delay:-7s;opacity:.22}
.orbs span:nth-child(3){width:300px;height:300px;background:#ff3c6e;top:45%;left:35%;animation-delay:-13s;opacity:.12}
@keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(40px,-50px) scale(1.15)}}

/* هدر */
.topbar{position:sticky;top:0;z-index:50;backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);background:rgba(9,9,13,.72);border-bottom:1px solid var(--bd)}
.bar{display:flex;flex-direction:column;align-items:center;gap:12px;padding-top:14px;padding-bottom:14px}
.brand{font-size:1.65rem;font-weight:300;text-decoration:none;letter-spacing:.3px;display:flex;align-items:center;gap:6px;direction:ltr}
.brand b{font-weight:800;color:var(--or)}
.bolt{display:inline-block;animation:pulse 2s ease-in-out infinite;filter:drop-shadow(0 0 10px rgba(255,180,60,.7))}
@keyframes pulse{0%,100%{transform:scale(1) rotate(0)}50%{transform:scale(1.2) rotate(-8deg)}}
.nav,.subnav{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
.nav a,.subnav a{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:999px;color:var(--mut);text-decoration:none;font-size:.93rem;border:1px solid transparent;background:rgba(255,255,255,.035);transition:transform .22s,background .22s,color .22s,box-shadow .22s}
.nav a:hover,.subnav a:hover{color:#fff;background:rgba(255,255,255,.09);transform:translateY(-3px)}
.nav a.active,.subnav a.active{color:#1a1005;font-weight:800;background:linear-gradient(135deg,var(--or),var(--or2));box-shadow:0 8px 22px rgba(255,122,24,.38)}
.subnav{padding:10px;border:1px solid var(--bd);border-radius:22px;background:rgba(255,255,255,.03)}
.subnav a{padding:8px 16px;font-size:.88rem}
@media(min-width:780px){.bar{flex-direction:row;justify-content:space-between}}

/* کارت شیشه‌ای */
.card{background:linear-gradient(145deg,rgba(255,255,255,.065),rgba(255,255,255,.025));border:1px solid var(--bd);border-radius:24px;padding:26px;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);box-shadow:0 18px 50px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.07)}
.card.narrow{max-width:460px;width:100%;margin:0 auto}
.reveal{animation:fadeUp .6s cubic-bezier(.2,.8,.2,1) both}
main > .reveal:nth-child(2){animation-delay:.07s}main > .reveal:nth-child(3){animation-delay:.14s}main > .reveal:nth-child(4){animation-delay:.21s}main > .reveal:nth-child(5){animation-delay:.28s}
@keyframes fadeUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
.card-head{display:flex;align-items:center;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.card-head>div{flex:1;min-width:160px}
.card-head h1,.card-head h2{font-size:1.25rem;line-height:1.4}
.sub{color:var(--mut);font-size:.88rem}
.faint{color:var(--faint);font-size:.8rem}
.center{text-align:center;justify-content:center}
.ico-lg{font-size:1.9rem;width:54px;height:54px;display:grid;place-items:center;border-radius:18px;background:rgba(255,122,24,.13);border:1px solid rgba(255,122,24,.28)}
.chip{padding:6px 14px;border-radius:999px;font-size:.8rem;color:var(--mut);background:rgba(255,255,255,.06);border:1px solid var(--bd)}
.chip b{color:var(--or2)}
.lnk{color:var(--or2);text-decoration:none;font-weight:700}.lnk:hover{text-decoration:underline}

/* دکمه‌ها */
.btn{position:relative;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 24px;border:0;border-radius:15px;font:inherit;font-weight:800;font-size:.95rem;cursor:pointer;text-decoration:none;color:#1a1005;background:linear-gradient(135deg,var(--or),var(--or2));box-shadow:0 10px 26px rgba(255,122,24,.3);transition:transform .22s,box-shadow .22s,filter .22s}
.btn::after{content:"";position:absolute;top:0;left:-70%;width:45%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.4),transparent);transform:skewX(-20deg);transition:left .6s}
.btn:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(255,122,24,.42);filter:brightness(1.06)}
.btn:hover::after{left:130%}
.btn:active{transform:scale(.97)}
.btn:disabled{opacity:.6;cursor:wait;transform:none}
.btn-ghost{color:var(--tx);background:rgba(255,255,255,.07);border:1px solid var(--bd);box-shadow:none}
.btn-ghost:hover{background:rgba(255,255,255,.13);box-shadow:0 10px 24px rgba(0,0,0,.35)}
.btn-danger{color:#fff;background:linear-gradient(135deg,#ff3d57,#ff7a7a);box-shadow:0 10px 26px rgba(255,61,87,.28)}
.btn-danger:hover{box-shadow:0 16px 34px rgba(255,61,87,.42)}
.btn-sm{padding:8px 15px;font-size:.82rem;border-radius:12px}
.btn-block{width:100%}

/* فرم */
.fg{margin-bottom:16px}
.fg>label:first-child{display:block;margin-bottom:7px;font-size:.88rem;color:var(--mut);font-weight:600}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:13px 15px;background:rgba(0,0,0,.35);border:1px solid var(--bd);border-radius:14px;color:var(--tx);font:inherit;outline:0;transition:border-color .2s,box-shadow .2s,background .2s;color-scheme:dark}
input:focus,select:focus,textarea:focus{border-color:var(--or);box-shadow:0 0 0 4px rgba(255,122,24,.16);background:rgba(0,0,0,.5)}
textarea{resize:vertical;min-height:140px;line-height:1.8}
.row2{display:grid;grid-template-columns:1fr;gap:14px}
@media(min-width:640px){.row2{grid-template-columns:1fr 1fr}}
.switch{display:flex!important;align-items:center;gap:12px;cursor:pointer;color:var(--tx)!important;font-size:.9rem!important;font-weight:500!important;min-height:46px}
.switch input{display:none}
.switch i{flex:none;width:50px;height:28px;border-radius:99px;background:rgba(255,255,255,.12);position:relative;transition:background .25s}
.switch i::after{content:"";position:absolute;top:3px;right:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.4);transition:transform .25s}
.switch input:checked+i{background:linear-gradient(135deg,var(--or),var(--or2))}
.switch input:checked+i::after{transform:translateX(-22px)}

/* ناحیه‌ی آپلود */
.drop{display:block;text-align:center;padding:38px 16px;border:2px dashed rgba(255,255,255,.2);border-radius:22px;cursor:pointer;background:rgba(255,255,255,.02);transition:border-color .25s,background .25s,transform .25s}
.drop:hover,.drop.over{border-color:var(--or);background:rgba(255,122,24,.08);transform:scale(1.012)}
.drop input{display:none}
.drop .ico{font-size:2.8rem;animation:bob 2.6s ease-in-out infinite}
.drop-t{font-weight:700;margin-top:4px;word-break:break-all}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
.progress{display:none;height:12px;border-radius:99px;background:rgba(255,255,255,.09);overflow:hidden;margin-top:18px}
.progress i{display:block;height:100%;width:0;border-radius:99px;background:repeating-linear-gradient(45deg,var(--or),var(--or) 10px,var(--or2) 10px,var(--or2) 20px);background-size:28px 28px;animation:stripes .9s linear infinite;transition:width .2s}
@keyframes stripes{to{background-position:28px 0}}

/* نتیجه */
.result{margin-top:18px}
.res-box{padding:18px;border-radius:18px;background:rgba(46,204,113,.09);border:1px solid rgba(46,204,113,.35);animation:fadeUp .45s both}
.res-box h3{margin-bottom:12px;font-size:1rem}
.copy-row{display:flex;gap:8px;flex-wrap:wrap}
.copy-row input{flex:1;min-width:200px;direction:ltr;text-align:left;font-size:.85rem}
.res-meta{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap}

/* لیست‌ها */
.list{display:flex;flex-direction:column;gap:12px}
.item{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 16px;border-radius:18px;border:1px solid var(--bd);background:rgba(255,255,255,.03);transition:transform .22s,border-color .22s,background .22s}
.item:hover{transform:translateY(-3px);border-color:rgba(255,122,24,.45);background:rgba(255,255,255,.055)}
.ico-box{flex:none;width:46px;height:46px;display:grid;place-items:center;font-size:1.4rem;border-radius:14px;background:rgba(255,255,255,.07)}
.item .info{flex:1;min-width:210px}
.item .name{font-weight:700;word-break:break-word}
.meta{display:flex;flex-wrap:wrap;gap:6px 14px;color:var(--faint);font-size:.78rem;margin-top:3px}
.acts{display:flex;flex-wrap:wrap;gap:8px}
.acts form,.inline-form{display:contents}
.inline-form{display:flex;align-items:center;gap:8px}
.inline-form input{width:96px;padding:8px 10px;border-radius:11px}
.badge{padding:3px 12px;border-radius:99px;font-size:.74rem;font-weight:700;border:1px solid}
.badge.ok{color:#58e08f;border-color:rgba(46,204,113,.45);background:rgba(46,204,113,.1)}
.badge.warn{color:#ffc65c;border-color:rgba(255,190,60,.45);background:rgba(255,190,60,.1)}
.badge.dead{color:#ff8c98;border-color:rgba(255,77,94,.45);background:rgba(255,77,94,.1)}
.empty{text-align:center;color:var(--faint);padding:30px 10px;border:1px dashed var(--bd);border-radius:18px}
.pager{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:18px}
.pager a{min-width:40px;height:40px;display:grid;place-items:center;border-radius:12px;text-decoration:none;background:rgba(255,255,255,.06);border:1px solid var(--bd);transition:.2s}
.pager a:hover{transform:translateY(-2px);background:rgba(255,255,255,.12)}
.pager a.on{background:linear-gradient(135deg,var(--or),var(--or2));color:#1a1005;font-weight:800;border-color:transparent}

/* آمار */
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
@media(min-width:780px){.stats{grid-template-columns:repeat(4,1fr)}}
.stat{padding:18px 10px;text-align:center;border-radius:20px;border:1px solid var(--bd);background:rgba(255,255,255,.035);transition:transform .22s,border-color .22s}
.stat:hover{transform:translateY(-4px);border-color:rgba(255,122,24,.5)}
.stat-i{font-size:1.5rem}
.stat-n{font-size:1.7rem;font-weight:800;color:var(--or);direction:ltr}
.stat-l{color:var(--mut);font-size:.82rem}

/* پیام‌ها */
.alert{padding:13px 16px;border-radius:14px;font-size:.9rem;border:1px solid;margin-bottom:16px}
main>.alert{margin-bottom:0}
.alert-ok{background:rgba(46,204,113,.1);border-color:rgba(46,204,113,.4);color:#7ae6a6}
.alert-err{background:rgba(255,77,94,.1);border-color:rgba(255,77,94,.4);color:#ff9aa4}
.alert-warn{background:rgba(255,190,60,.1);border-color:rgba(255,190,60,.4);color:#ffd27a}

/* صفحه‌ی وضعیت (لینک منقضی و ...) */
.state{text-align:center;padding:46px 22px}
.state .big{font-size:4.2rem;animation:bob 2.8s ease-in-out infinite}
.state h1{margin:8px 0 6px;font-size:1.45rem}
.state small{display:block;margin-top:8px}
.fname{word-break:break-all;font-size:1.2rem!important}
.textbox{white-space:pre-wrap;word-break:break-word;direction:auto;text-align:start;padding:18px;border-radius:16px;background:rgba(0,0,0,.4);border:1px solid var(--bd);font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92rem;line-height:1.9;max-height:70vh;overflow:auto}

/* فوتر */
.foot{text-align:center;color:var(--faint);padding:26px 16px 30px;font-size:.85rem;border-top:1px solid var(--bd);background:rgba(9,9,13,.5)}
.foot b{color:var(--or)}

/* مودال و توست */
.modal-bg{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.62);backdrop-filter:blur(7px)}
.modal-bg.show{display:flex;animation:fade .2s}
.modal{width:100%;max-width:400px;text-align:center;padding:28px 24px;border-radius:24px;background:#15151c;border:1px solid var(--bd);box-shadow:0 30px 80px rgba(0,0,0,.6);animation:pop .3s cubic-bezier(.2,1.2,.3,1)}
.modal-ico{font-size:2.6rem}
.modal p{margin:8px 0 22px;color:var(--mut);line-height:1.9}
.modal-acts{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
@keyframes fade{from{opacity:0}to{opacity:1}}
@keyframes pop{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:none}}
.toast{position:fixed;bottom:26px;left:50%;z-index:120;transform:translate(-50%,40px);opacity:0;padding:12px 22px;border-radius:99px;background:#1d1d27;border:1px solid var(--bd);box-shadow:0 14px 40px rgba(0,0,0,.5);pointer-events:none;transition:transform .3s,opacity .3s;font-size:.9rem}
.toast.show{transform:translate(-50%,0);opacity:1}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
CSS;
}

// ═══════════════════════════════════════════════════════════════
//  جاوااسکریپت سمت کاربر
// ═══════════════════════════════════════════════════════════════
function jsCode(): string {
    return <<<'JS'
(function(){
var $=function(s,r){return (r||document).querySelector(s)}, $$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};

function toast(m){var t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(toast.t);toast.t=setTimeout(function(){t.classList.remove('show')},2200)}
function copy(txt){
  if(navigator.clipboard&&window.isSecureContext){return navigator.clipboard.writeText(txt)}
  return new Promise(function(res,rej){var a=document.createElement('textarea');a.value=txt;a.style.cssText='position:fixed;opacity:0';document.body.appendChild(a);a.select();try{document.execCommand('copy');res()}catch(e){rej(e)}a.remove()});
}
function fmt(b){if(!b)return '0 B';var u=['B','KB','MB','GB','TB'],i=Math.min(4,Math.floor(Math.log(b)/Math.log(1024)));return (Math.round(b/Math.pow(1024,i)*100)/100)+' '+u[i]}

document.addEventListener('click',function(e){
  var b=e.target.closest('[data-copy]');
  if(b){e.preventDefault();copy(b.getAttribute('data-copy')).then(function(){toast('✅ کپی شد')},function(){toast('❌ کپی نشد')})}
});

/* تأیید با مودال */
var pending=null,modal=$('#modal');
function ask(msg,ok){$('#mtext').textContent=msg;modal.classList.add('show');pending=ok}
$('#mok').onclick=function(){modal.classList.remove('show');var f=pending;pending=null;if(f)f()};
$('#mno').onclick=function(){modal.classList.remove('show');pending=null};
modal.addEventListener('click',function(e){if(e.target===modal){modal.classList.remove('show');pending=null}});
document.addEventListener('submit',function(e){
  var f=e.target;
  if(f.getAttribute('data-confirm')&&!f.__ok){
    e.preventDefault();
    ask(f.getAttribute('data-confirm'),function(){f.__ok=true;HTMLFormElement.prototype.submit.call(f)});
  }
});

/* شمارنده‌ی انیمیشنی */
$$('.count').forEach(function(el){
  var n=parseInt(el.getAttribute('data-n'),10);if(isNaN(n)||n<1)return;
  var s=null,d=900;
  function step(t){if(!s)s=t;var p=Math.min(1,(t-s)/d);el.textContent=Math.round(n*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(step)}
  el.textContent='0';requestAnimationFrame(step);
});

/* انتخاب فایل و درگ‌اندراپ */
var drop=$('#drop'),fi=$('#fileInput');
if(drop&&fi){
  function show(){if(fi.files&&fi.files[0]){$('#dropT').textContent=fi.files[0].name;$('#dropS').textContent=fmt(fi.files[0].size)}}
  fi.addEventListener('change',show);
  ['dragenter','dragover'].forEach(function(ev){drop.addEventListener(ev,function(e){e.preventDefault();drop.classList.add('over')})});
  ['dragleave','drop'].forEach(function(ev){drop.addEventListener(ev,function(e){e.preventDefault();drop.classList.remove('over')})});
  drop.addEventListener('drop',function(e){if(e.dataTransfer&&e.dataTransfer.files.length){fi.files=e.dataTransfer.files;show()}});
}

/* ارسال فرم‌های آپلود با نوار پیشرفت */
function renderResult(box,r){
  box.innerHTML='';
  var w=document.createElement('div');
  if(!r.success){w.className='alert alert-err';w.textContent='❌ '+(r.message||'خطا');box.appendChild(w);return}
  w.className='res-box';
  var h=document.createElement('h3');h.textContent='✅ آماده است! لینک اشتراک‌گذاری:';w.appendChild(h);
  var row=document.createElement('div');row.className='copy-row';
  var inp=document.createElement('input');inp.type='text';inp.readOnly=true;inp.value=r.link;inp.onclick=function(){inp.select()};
  var cb=document.createElement('button');cb.type='button';cb.className='btn btn-sm';cb.textContent='📋 کپی';cb.setAttribute('data-copy',r.link);
  var ob=document.createElement('a');ob.className='btn btn-ghost btn-sm';ob.textContent='🔗 باز کردن';ob.href=r.link;ob.target='_blank';ob.rel='noopener';
  row.appendChild(inp);row.appendChild(cb);row.appendChild(ob);w.appendChild(row);
  var m=document.createElement('div');m.className='res-meta';
  if(r.expiry){var a=document.createElement('span');a.className='chip';a.textContent='⏳ انقضا: '+r.expiry;m.appendChild(a)}
  if(r.one_time){var b=document.createElement('span');b.className='chip';b.textContent='🔒 یک‌بار مصرف';m.appendChild(b)}
  w.appendChild(m);box.appendChild(w);
}
$$('form[data-ajax]').forEach(function(f){
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var max=parseInt(f.getAttribute('data-max')||'0',10),file=f.querySelector('input[type=file]');
    if(file&&file.files[0]&&max&&file.files[0].size>max){toast('❌ حجم فایل بیشتر از '+fmt(max)+' است');return}
    var btn=f.querySelector('[type=submit]'),prog=f.querySelector('.progress'),bar=prog?prog.firstElementChild:null,box=f.querySelector('.result'),old=btn.innerHTML;
    var x=new XMLHttpRequest();x.open('POST',f.getAttribute('data-ajax'));
    btn.disabled=true;btn.textContent='⏳ در حال ارسال...';box.innerHTML='';
    if(prog){prog.style.display='block';bar.style.width='0'}
    x.upload.onprogress=function(ev){if(ev.lengthComputable&&bar){var p=Math.round(ev.loaded/ev.total*100);bar.style.width=p+'%';btn.textContent='⏳ '+p+'٪'}};
    function done(){btn.disabled=false;btn.innerHTML=old;if(prog)prog.style.display='none'}
    x.onload=function(){
      done();var r;
      try{r=JSON.parse(x.responseText)}catch(err){r={success:false,message:'پاسخ نامعتبر از سرور ('+x.status+')'}}
      renderResult(box,r);
      if(r.success){f.reset();if($('#dropT')){$('#dropT').textContent='فایل رو اینجا بنداز یا کلیک کن';$('#dropS').textContent='همه‌ی فرمت‌ها پشتیبانی می‌شود'}box.scrollIntoView({behavior:'smooth',block:'center'})}
    };
    x.onerror=function(){done();renderResult(box,{success:false,message:'اتصال به سرور قطع شد'})};
    x.send(new FormData(f));
  });
});
})();
JS;
}
