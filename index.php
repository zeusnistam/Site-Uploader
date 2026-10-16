<?php
/**
 * Zeus Uploader - فایل اصلی
 * تمام صفحات سایت: آپلود / ورود / ثبت‌نام / پنل کاربری / ادمین / API
 */
require_once __DIR__ . '/config.php';

// ── مسیریابی ─────────────────────────────────────────────────
$page = $_GET['p'] ?? 'home';

// حالت تعمیر (Maintenance) — فقط ادمین می‌تواند وارد شود
if (isMaintenanceMode() && !isAdmin() && $page !== 'login' && $page !== 'api_upload' && $page !== 'api_text') {
    $msg = getSetting('maintenance_msg', 'سایت در حال به‌روزرسانی است.');
    header('HTTP/1.1 503 Service Unavailable');
    renderMaintenance($msg);
    exit;
}

// ── مسیرهای API (JSON بدون HTML) ────────────────────────────
if ($page === 'api_upload')     { handleUploadApi();  exit; }
if ($page === 'api_text')       { handleTextApi();    exit; }
if ($page === 'download')       { handleDownload();   exit; }
if ($page === 'view_text')      { handleViewText();   exit; }
if ($page === 'backup_download'){ handleBackupDownload(); exit; }

// ── صفحات HTML ───────────────────────────────────────────────
ob_start();

switch ($page) {
    case 'home':      pageHome();      break;
    case 'text':      pageText();      break;
    case 'register':  pageRegister();  break;
    case 'login':     pageLogin();     break;
    case 'logout':    pageLogout();    break;
    case 'panel':     pagePanel();     break;
    case 'admin':     pageAdmin();     break;
    case 'admin_users':  pageAdminUsers();  break;
    case 'admin_settings': pageAdminSettings(); break;
    case 'admin_backup':   pageAdminBackup();   break;
    default:          page404();       break;
}

$content = ob_get_clean();
echo renderLayout($content, $page);
exit;

// ═══════════════════════════════════════════════════════════════
//  LAYOUT
// ═══════════════════════════════════════════════════════════════
function renderLayout(string $body, string $page): string {
    $siteName = htmlspecialchars(getSetting('site_name', SITE_NAME));
    $csrf     = csrf();
    $loggedIn = isLoggedIn();
    $admin    = isAdmin();

    $navHome   = $page === 'home'  ? 'active' : '';
    $navText   = $page === 'text'  ? 'active' : '';
    $navPanel  = $page === 'panel' ? 'active' : '';
    $navAdmin  = strpos($page, 'admin') === 0 ? 'active' : '';

    $userLinks = '';
    if ($loggedIn) {
        $uname     = htmlspecialchars($_SESSION['username'] ?? '');
        $userLinks = "
        <a href='?p=panel' class='nav-link $navPanel'>📁 پنل من</a>
        <a href='?p=logout' class='nav-link nav-logout'>🚪 خروج ($uname)</a>";
    } else {
        $userLinks = "
        <a href='?p=login'    class='nav-link " . ($page==='login'?'active':'') . "'>🔐 ورود</a>
        <a href='?p=register' class='nav-link " . ($page==='register'?'active':'') . "'>👤 ثبت‌نام</a>";
    }
    $adminLink = $admin ? "<a href='?p=admin' class='nav-link nav-admin $navAdmin'>🛡️ ادمین</a>" : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>⚡ {$siteName}</title>
<meta name="_csrf" content="{$csrf}">
<style>
/* ── Reset & Variables ── */
:root{
  --bg:#0d0d0d; --surface:#1a1a1a; --surface2:#222; --surface3:#2a2a2a;
  --orange:#ff6a00; --orange2:#e05a00; --orange-glow:rgba(255,106,0,.25);
  --text:#e8e8e8; --muted:#888; --border:#2e2e2e;
  --danger:#e53935; --success:#00c853; --info:#0288d1;
  --radius:10px; --radius-sm:6px;
  --transition:.25s ease;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  font-family:'Tahoma','Segoe UI',Arial,sans-serif;
  background:var(--bg);color:var(--text);
  min-height:100vh;display:flex;flex-direction:column;
  background-image:
    radial-gradient(ellipse 80% 40% at 50% -10%,rgba(255,106,0,.07) 0%,transparent 70%);
}
a{color:var(--orange);text-decoration:none;transition:color var(--transition)}
a:hover{color:var(--orange2)}
/* ── Header ── */
.site-header{
  background:rgba(26,26,26,.97);border-bottom:2px solid var(--orange);
  position:sticky;top:0;z-index:200;
  box-shadow:0 2px 20px rgba(255,106,0,.12);
  backdrop-filter:blur(8px);
}
.header-inner{
  max-width:1100px;margin:0 auto;padding:14px 20px;
  display:flex;justify-content:space-between;align-items:center;gap:12px;
}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-icon{font-size:1.9rem;animation:pulse 3s infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
.logo-text{color:var(--orange);font-size:1.5rem;font-weight:900;letter-spacing:-.5px}
.logo-text span{color:#fff;font-weight:300}
.site-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.nav-link{
  padding:7px 14px;border-radius:var(--radius-sm);font-size:.88rem;font-weight:600;
  color:var(--text);border:1px solid transparent;transition:all var(--transition);
  white-space:nowrap;
}
.nav-link:hover{background:rgba(255,106,0,.1);border-color:var(--orange);color:var(--orange)}
.nav-link.active{background:var(--orange);color:#000;border-color:var(--orange)}
.nav-admin{border-color:rgba(255,106,0,.4);color:var(--orange)}
.nav-logout{color:var(--muted)}
/* ── Container ── */
.container{max-width:1100px;margin:0 auto;padding:24px 20px;flex:1}
/* ── Cards ── */
.card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);padding:24px;margin-bottom:20px;
  transition:box-shadow var(--transition);
}
.card:hover{box-shadow:0 0 24px rgba(255,106,0,.06)}
.card-header{
  border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:20px;
  display:flex;align-items:center;gap:10px;
}
.card-header h2,.card-header h3{color:var(--orange);font-size:1.2rem}
/* ── Form elements ── */
.form-group{margin-bottom:16px}
.form-group label{display:block;margin-bottom:7px;color:#ccc;font-size:.9rem;font-weight:600}
.form-group input,.form-group select,.form-group textarea{
  width:100%;padding:11px 14px;background:var(--surface2);
  border:2px solid var(--border);color:var(--text);border-radius:var(--radius-sm);
  font-size:.95rem;font-family:inherit;transition:all var(--transition);
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  outline:none;border-color:var(--orange);box-shadow:0 0 0 3px var(--orange-glow)
}
.form-group input[type=file]{padding:9px;cursor:pointer}
.form-group input[type=file]::file-selector-button{
  background:var(--orange);color:#000;border:none;padding:7px 14px;
  border-radius:var(--radius-sm);cursor:pointer;font-weight:700;margin-left:10px;
}
/* ── Buttons ── */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:11px 24px;background:var(--orange);color:#000;
  border:none;border-radius:var(--radius-sm);font-weight:700;font-size:.95rem;
  cursor:pointer;transition:all var(--transition);font-family:inherit;
}
.btn:hover{background:var(--orange2);transform:translateY(-1px);box-shadow:0 4px 14px var(--orange-glow)}
.btn:active{transform:translateY(0)}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}
.btn-sm{padding:7px 14px;font-size:.82rem}
.btn-outline{background:transparent;border:2px solid var(--orange);color:var(--orange)}
.btn-outline:hover{background:var(--orange);color:#000}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{background:#b71c1c}
.btn-success{background:var(--success);color:#000}
.btn-info{background:var(--info);color:#fff}
.btn-full{width:100%}
/* ── Checkbox ── */
.checkbox-row{
  display:flex;align-items:center;gap:10px;background:var(--surface2);
  padding:11px 14px;border-radius:var(--radius-sm);border:2px solid var(--border);
  cursor:pointer;transition:border-color var(--transition);
}
.checkbox-row:hover{border-color:var(--orange)}
.checkbox-row input[type=checkbox]{width:18px;height:18px;accent-color:var(--orange);cursor:pointer}
.checkbox-row label{margin:0;cursor:pointer;font-size:.9rem}
/* ── Progress ── */
.progress-wrap{
  background:var(--surface2);border-radius:var(--radius);height:34px;
  margin-top:14px;overflow:hidden;border:1px solid var(--border);display:none;
}
.progress-bar{
  height:100%;width:0%;transition:width .25s;
  background:linear-gradient(90deg,var(--orange),#ffaa00);
  display:flex;align-items:center;justify-content:center;
  color:#000;font-weight:700;font-size:.85rem;min-width:44px;
}
/* ── Alerts ── */
.alert{
  padding:12px 16px;border-radius:var(--radius-sm);margin:12px 0;
  font-size:.9rem;border-right:4px solid;
}
.alert-success{background:rgba(0,200,83,.1);border-color:var(--success);color:#69f0ae}
.alert-error{background:rgba(229,57,53,.1);border-color:var(--danger);color:#ef9a9a}
.alert-info{background:rgba(2,136,209,.1);border-color:var(--info);color:#80d8ff}
.alert-warning{background:rgba(255,193,7,.1);border-color:#ffc107;color:#ffe57f}
/* ── Tables ── */
.table-wrap{overflow-x:auto;margin-top:14px}
table{width:100%;border-collapse:collapse}
th,td{padding:11px 13px;text-align:right;border-bottom:1px solid var(--border);font-size:.88rem}
th{color:var(--orange);font-weight:700;background:rgba(255,106,0,.05)}
tr:hover td{background:rgba(255,255,255,.02)}
/* ── Copy input ── */
.copy-row{display:flex;gap:8px;margin-top:8px;align-items:center}
.copy-input{
  flex:1;background:var(--bg);border:1px solid var(--border);color:var(--orange);
  padding:8px 10px;border-radius:var(--radius-sm);font-size:.82rem;direction:ltr;text-align:left;
}
/* ── Admin sidebar ── */
.admin-layout{display:grid;grid-template-columns:220px 1fr;gap:20px}
.admin-sidebar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px;height:fit-content;position:sticky;top:90px}
.admin-sidebar a{display:block;padding:9px 12px;border-radius:var(--radius-sm);color:var(--text);font-size:.9rem;margin-bottom:4px;transition:all var(--transition)}
.admin-sidebar a:hover,.admin-sidebar a.active{background:rgba(255,106,0,.15);color:var(--orange)}
.admin-sidebar .divider{border-top:1px solid var(--border);margin:8px 0}
/* ── Stats row ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.stat-card{
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px;text-align:center;transition:all var(--transition);
}
.stat-card:hover{border-color:var(--orange);box-shadow:0 0 16px var(--orange-glow)}
.stat-num{font-size:1.9rem;font-weight:900;color:var(--orange)}
.stat-label{font-size:.8rem;color:var(--muted);margin-top:4px}
/* ── Badge ── */
.badge{
  display:inline-block;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;
}
.badge-orange{background:rgba(255,106,0,.2);color:var(--orange)}
.badge-green{background:rgba(0,200,83,.2);color:var(--success)}
.badge-red{background:rgba(229,57,53,.2);color:var(--danger)}
.badge-gray{background:rgba(255,255,255,.08);color:var(--muted)}
/* ── Maintenance overlay ── */
.maintenance-wrap{
  min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;
  text-align:center;padding:30px;
}
.maintenance-icon{font-size:5rem;margin-bottom:20px;animation:spin 4s linear infinite}
@keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
/* ── Footer ── */
.site-footer{
  text-align:center;padding:18px;border-top:1px solid var(--border);
  color:var(--muted);font-size:.83rem;background:var(--surface);margin-top:auto;
}
.site-footer span{color:var(--orange)}
/* ── Scrollbar ── */
::-webkit-scrollbar{width:7px;height:7px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:var(--orange)}
/* ── Animations ── */
@keyframes fadeInUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.card,.stat-card{animation:fadeInUp .4s ease both}
/* ── Responsive ── */
@media(max-width:768px){
  .header-inner{flex-direction:column;gap:12px}
  .site-nav{justify-content:center}
  .admin-layout{grid-template-columns:1fr}
  .admin-sidebar{position:static}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="?" class="logo">
      <span class="logo-icon">⚡</span>
      <span class="logo-text">Zeus<span>Uploader</span></span>
    </a>
    <nav class="site-nav">
      <a href="?" class="nav-link {$navHome}">📤 آپلود فایل</a>
      <a href="?p=text" class="nav-link {$navText}">📝 آپلود متن</a>
      {$userLinks}
      {$adminLink}
    </nav>
  </div>
</header>

<div class="container">
{$body}
</div>

<footer class="site-footer">
  <p>© 2024 <span>⚡ {$siteName}</span> | آپلودر حرفه‌ای فایل و متن</p>
</footer>

<script>
// CSRF header برای همه fetch ها
const CSRF = document.querySelector('meta[name="_csrf"]')?.content || '';

function copyText(txt, btn) {
  navigator.clipboard.writeText(txt).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✅ کپی شد';
    setTimeout(() => btn.textContent = orig, 2000);
  }).catch(() => alert('لطفاً دستی کپی کنید: ' + txt));
}

function confirmAction(msg, url) {
  if (confirm(msg)) location.href = url;
}

// Fade in cards on scroll
const observer = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) e.target.style.animationPlayState = 'running'; });
}, {threshold: 0.05});
document.querySelectorAll('.card,.stat-card').forEach(el => {
  el.style.animationPlayState = 'paused'; observer.observe(el);
});
</script>
</body>
</html>
HTML;
}

function renderMaintenance(string $msg): void {
    $site = htmlspecialchars(getSetting('site_name', SITE_NAME));
    echo <<<HTML
<!DOCTYPE html><html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>⚙️ در حال بروزرسانی | {$site}</title>
<style>*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0d0d;color:#e8e8e8;font-family:Tahoma,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:30px}
.wrap{max-width:500px}
.icon{font-size:6rem;margin-bottom:20px;display:block;animation:spin 4s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
h1{color:#ff6a00;font-size:2rem;margin-bottom:16px}
p{color:#aaa;font-size:1.1rem;line-height:1.7}
</style></head>
<body><div class="wrap">
<span class="icon">⚙️</span>
<h1>در حال به‌روزرسانی</h1>
<p>{$msg}</p>
</div></body></html>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: HOME (آپلود فایل)
// ═══════════════════════════════════════════════════════════════
function pageHome(): void {
    $maxBytes = maxSizeForCurrentUser();
    $maxMb    = round($maxBytes / 1048576);
    $csrf     = csrf();
    echo <<<HTML
<div class="card">
  <div class="card-header">
    <span style="font-size:1.6rem">📤</span>
    <h2>آپلود فایل جدید</h2>
    <span style="margin-right:auto;font-size:.82rem;color:var(--muted)">حداکثر: <b style="color:var(--orange)">{$maxMb} MB</b></span>
  </div>

  <form id="uploadForm" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <div class="form-group">
      <label>📎 انتخاب فایل (تمام فرمت‌ها پشتیبانی می‌شوند)</label>
      <input type="file" name="file" id="fileInput" required>
    </div>
    <div class="form-group">
      <label>⏳ زمان انقضا</label>
      <select name="expiry_hours" id="expiryHours">
        <option value="0">هرگز</option>
        <option value="1">۱ ساعت</option>
        <option value="24">۱ روز</option>
        <option value="168">۱ هفته</option>
        <option value="720">۱ ماه</option>
      </select>
    </div>
    <div class="form-group">
      <div class="checkbox-row" onclick="document.getElementById('oneTime').click()">
        <input type="checkbox" name="one_time" id="oneTime" value="1">
        <label>🔒 لینک یک‌بار مصرف (پس از اولین دانلود غیرفعال)</label>
      </div>
    </div>
    <button type="submit" class="btn btn-full" id="uploadBtn">🚀 شروع آپلود</button>
  </form>

  <div class="progress-wrap" id="progressWrap">
    <div class="progress-bar" id="progressBar">0%</div>
  </div>
  <div id="uploadResult"></div>
</div>

<script>
document.getElementById('uploadForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const fi = document.getElementById('fileInput');
  if (!fi.files.length) { alert('❌ فایلی انتخاب نشده'); return; }

  const fd = new FormData();
  fd.append('file', fi.files[0]);
  fd.append('expiry_hours', document.getElementById('expiryHours').value);
  fd.append('one_time', document.getElementById('oneTime').checked ? '1' : '0');
  fd.append('_csrf', CSRF);

  const xhr = new XMLHttpRequest();
  const bar = document.getElementById('progressBar');
  const wrap = document.getElementById('progressWrap');
  const btn = document.getElementById('uploadBtn');
  const res = document.getElementById('uploadResult');

  wrap.style.display = 'block';
  btn.disabled = true;
  btn.textContent = '⏳ در حال آپلود...';
  res.innerHTML = '';

  xhr.upload.onprogress = e => {
    if (e.lengthComputable) {
      const p = Math.round(e.loaded / e.total * 100);
      bar.style.width = p + '%';
      bar.textContent = p + '%';
    }
  };

  xhr.onload = () => {
    btn.disabled = false;
    btn.textContent = '🚀 شروع آپلود';
    wrap.style.display = 'none';
    if (xhr.status === 200) {
      try {
        const r = JSON.parse(xhr.responseText);
        if (r.success) {
          res.innerHTML = `<div class="alert alert-success">
            ✅ <strong>آپلود موفق!</strong><br><br>
            <label>🔗 لینک دانلود:</label>
            <div class="copy-row">
              <input class="copy-input" type="text" value="${r.link}" readonly onclick="this.select()">
              <button class="btn btn-sm" onclick="copyText('${r.link}',this)">📋 کپی</button>
            </div>
            ${r.expiry ? `<br><small>⏳ انقضا: ${r.expiry}</small>` : ''}
            ${r.one_time ? '<br><small>🔒 لینک یک‌بار مصرف</small>' : ''}
          </div>`;
        } else {
          res.innerHTML = `<div class="alert alert-error">❌ ${r.message}</div>`;
        }
      } catch { res.innerHTML = '<div class="alert alert-error">❌ پاسخ نامعتبر از سرور</div>'; }
    } else {
      res.innerHTML = '<div class="alert alert-error">❌ خطای شبکه</div>';
    }
  };

  xhr.onerror = () => {
    btn.disabled = false;
    btn.textContent = '🚀 شروع آپلود';
    res.innerHTML = '<div class="alert alert-error">❌ اینترنت را بررسی کنید</div>';
    wrap.style.display = 'none';
  };

  xhr.open('POST', '?p=api_upload');
  xhr.send(fd);
});
</script>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: TEXT UPLOAD
// ═══════════════════════════════════════════════════════════════
function pageText(): void {
    $csrf = csrf();
    echo <<<HTML
<div class="card">
  <div class="card-header">
    <span style="font-size:1.6rem">📝</span>
    <h2>آپلود متن / Paste</h2>
  </div>
  <form id="textForm">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <div class="form-group">
      <label>متن خود را وارد کنید</label>
      <textarea name="content" rows="12" style="resize:vertical;min-height:180px" placeholder="متن، کد، لینک‌ها ... هر چیزی" required></textarea>
    </div>
    <div class="form-group">
      <label>⏳ زمان انقضا</label>
      <select name="expiry">
        <option value="0">هرگز</option>
        <option value="1">۱ ساعت</option>
        <option value="24">۱ روز</option>
        <option value="168">۱ هفته</option>
        <option value="720">۱ ماه</option>
      </select>
    </div>
    <div class="form-group">
      <div class="checkbox-row" onclick="document.getElementById('otText').click()">
        <input type="checkbox" name="one_time" id="otText" value="1">
        <label>🔒 لینک یک‌بار مصرف</label>
      </div>
    </div>
    <button type="submit" class="btn btn-full">🚀 ذخیره متن</button>
  </form>
  <div id="textResult"></div>
</div>

<script>
document.getElementById('textForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('_csrf', CSRF);
  const btn = this.querySelector('button[type=submit]');
  btn.disabled = true; btn.textContent = '⏳ در حال ذخیره...';
  const res = document.getElementById('textResult');
  try {
    const r = await fetch('?p=api_text', {method:'POST', body:fd}).then(x=>x.json());
    if (r.success) {
      res.innerHTML = `<div class="alert alert-success">
        ✅ متن ذخیره شد!<br><br>
        <div class="copy-row">
          <input class="copy-input" type="text" value="${r.link}" readonly onclick="this.select()">
          <button class="btn btn-sm" onclick="copyText('${r.link}',this)">📋 کپی</button>
        </div>
        ${r.expiry ? `<br><small>⏳ انقضا: ${r.expiry}</small>` : ''}
        ${r.one_time ? '<br><small>🔒 یک‌بار مصرف</small>' : ''}
      </div>`;
      this.reset();
    } else {
      res.innerHTML = `<div class="alert alert-error">❌ ${r.message}</div>`;
    }
  } catch { res.innerHTML = '<div class="alert alert-error">❌ خطای اتصال</div>'; }
  btn.disabled = false; btn.textContent = '🚀 ذخیره متن';
});
</script>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: LOGIN
// ═══════════════════════════════════════════════════════════════
function pageLogin(): void {
    if (isAdmin()) { header('Location: ?p=admin'); exit; }
    if (isLoggedIn()) { header('Location: ?p=panel'); exit; }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) { $error = 'توکن امنیتی نامعتبر.'; }
        else {
            $u = trim($_POST['username'] ?? '');
            $p = $_POST['password'] ?? '';
            if (empty($u) || empty($p)) {
                $error = 'نام کاربری و رمز عبور الزامی است.';
            } elseif ($u === ADMIN_USERNAME && hash_equals(ADMIN_PASSWORD, $p)) {
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $_SESSION['username'] = ADMIN_USERNAME;
                header('Location: ?p=admin'); exit;
            } else {
                $pdo  = getDB();
                $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = ?");
                $stmt->execute([$u]);
                $user = $stmt->fetch();
                if ($user && password_verify($p, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']  = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = false;
                    header('Location: ?p=panel'); exit;
                } else {
                    $error = '❌ نام کاربری یا رمز عبور اشتباه است.';
                }
            }
        }
    }

    $csrf = csrf();
    $errHtml = $error ? "<div class='alert alert-error'>$error</div>" : '';
    echo <<<HTML
<div style="max-width:420px;margin:30px auto">
<div class="card">
  <div class="card-header"><span style="font-size:1.6rem">🔐</span><h2>ورود به حساب</h2></div>
  {$errHtml}
  <form method="post">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <div class="form-group"><label>نام کاربری</label>
      <input type="text" name="username" required autofocus autocomplete="username">
    </div>
    <div class="form-group"><label>رمز عبور</label>
      <input type="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-full">🚀 ورود</button>
  </form>
  <p style="margin-top:16px;text-align:center;font-size:.9rem">
    حساب ندارید؟ <a href="?p=register">همین حالا بسازید</a>
  </p>
</div>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: REGISTER
// ═══════════════════════════════════════════════════════════════
function pageRegister(): void {
    if (isLoggedIn() || isAdmin()) { header('Location: ?p=panel'); exit; }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) { $error = 'توکن امنیتی نامعتبر.'; }
        else {
            $u  = trim($_POST['username'] ?? '');
            $p  = $_POST['password'] ?? '';
            $em = trim($_POST['email'] ?? '');
            if (mb_strlen($u) < 3)  { $error = 'نام کاربری باید حداقل ۳ کاراکتر باشد.'; }
            elseif (mb_strlen($p) < 4) { $error = 'رمز عبور باید حداقل ۴ کاراکتر باشد.'; }
            else {
                $pdo  = getDB();
                $chk  = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chk->execute([$u]);
                if ($chk->fetch()) {
                    $error = '❌ این نام کاربری قبلاً ثبت شده است.';
                } else {
                    $hash = password_hash($p, PASSWORD_DEFAULT);
                    $ins  = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?,?,?)");
                    $ins->execute([$u, $hash, $em ?: null]);
                    session_regenerate_id(true);
                    $_SESSION['user_id']  = $pdo->lastInsertId();
                    $_SESSION['username'] = $u;
                    $_SESSION['is_admin'] = false;
                    tgNotify("👤 کاربر جدید ثبت‌نام کرد:\n<b>$u</b>");
                    header('Location: ?p=panel'); exit;
                }
            }
        }
    }
    $csrf    = csrf();
    $errHtml = $error ? "<div class='alert alert-error'>$error</div>" : '';
    echo <<<HTML
<div style="max-width:420px;margin:30px auto">
<div class="card">
  <div class="card-header"><span style="font-size:1.6rem">👤</span><h2>عضویت در Zeus</h2></div>
  {$errHtml}
  <form method="post">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <div class="form-group"><label>نام کاربری (حداقل ۳ حرف)</label>
      <input type="text" name="username" required autocomplete="username" minlength="3">
    </div>
    <div class="form-group"><label>رمز عبور (حداقل ۴ حرف)</label>
      <input type="password" name="password" required autocomplete="new-password" minlength="4">
    </div>
    <div class="form-group"><label>ایمیل (اختیاری)</label>
      <input type="email" name="email" autocomplete="email">
    </div>
    <button type="submit" class="btn btn-full">🚀 ساخت حساب</button>
  </form>
  <p style="margin-top:16px;text-align:center;font-size:.9rem">
    قبلاً ثبت‌نام کرده‌اید؟ <a href="?p=login">وارد شوید</a>
  </p>
</div>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: LOGOUT
// ═══════════════════════════════════════════════════════════════
function pageLogout(): void {
    session_destroy();
    header('Location: ?'); exit;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: USER PANEL
// ═══════════════════════════════════════════════════════════════
function pagePanel(): void {
    if (!isLoggedIn()) { header('Location: ?p=login'); exit; }

    $pdo    = getDB();
    $uid    = currentUserId();
    $uname  = htmlspecialchars($_SESSION['username'] ?? '');

    // حذف توسط کاربر
    if (isset($_GET['del_file']) && verifyCsrf()) {
        $id = intval($_GET['del_file']);
        $f  = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
        $f->execute([$id, $uid]);
        $file = $f->fetch();
        if ($file) {
            $path = UPLOAD_DIR . $file['stored_name'];
            if (file_exists($path)) unlink($path);
            $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$id]);
        }
        header('Location: ?p=panel'); exit;
    }
    if (isset($_GET['del_text']) && verifyCsrf()) {
        $pdo->prepare("DELETE FROM texts WHERE id = ? AND user_id = ?")->execute([intval($_GET['del_text']), $uid]);
        header('Location: ?p=panel'); exit;
    }

    $files  = $pdo->prepare("SELECT * FROM files WHERE user_id = ? ORDER BY upload_date DESC");
    $files->execute([$uid]);
    $fList  = $files->fetchAll();

    $texts  = $pdo->prepare("SELECT * FROM texts WHERE user_id = ? ORDER BY created_at DESC");
    $texts->execute([$uid]);
    $tList  = $texts->fetchAll();

    $totalFiles = count($fList);
    $totalSize  = array_sum(array_column($fList, 'file_size'));
    $maxSize    = maxSizeForCurrentUser();
    $csrf       = csrf();

    $fileRows = '';
    foreach ($fList as $f) {
        $name  = htmlspecialchars($f['original_name']);
        $size  = formatSize($f['file_size']);
        $date  = date('Y/m/d', strtotime($f['upload_date']));
        $exp   = $f['expiry'] ? date('Y/m/d', strtotime($f['expiry'])) : 'ندارد';
        $dl    = (int)$f['download_count'];
        $ot    = $f['one_time'] ? '<span class="badge badge-orange">یک‌بار</span>' : '';
        $link  = htmlspecialchars(siteUrl('?p=download&id=' . $f['id']));
        $fileRows .= "<tr>
          <td>$name</td><td>$size</td><td>$date</td><td>$exp</td>
          <td>$dl $ot</td>
          <td>
            <div style='display:flex;gap:6px;flex-wrap:wrap'>
            <button class='btn btn-sm btn-outline' onclick=\"copyText('$link',this)\">📋 کپی</button>
            <a href='$link' class='btn btn-sm' target='_blank'>⬇ دانلود</a>
            <a class='btn btn-sm btn-danger' href='?p=panel&del_file={$f['id']}&_csrf=$csrf' onclick=\"return confirm('حذف شود؟')\">🗑</a>
            </div>
          </td>
        </tr>";
    }
    if (!$fileRows) $fileRows = "<tr><td colspan='6' style='text-align:center;color:var(--muted)'>هنوز فایلی آپلود نکرده‌اید</td></tr>";

    $textRows = '';
    foreach ($tList as $t) {
        $preview = htmlspecialchars(mb_substr($t['content'], 0, 60));
        $date    = date('Y/m/d', strtotime($t['created_at']));
        $exp     = $t['expiry'] ? date('Y/m/d', strtotime($t['expiry'])) : 'ندارد';
        $views   = (int)$t['view_count'];
        $ot      = $t['one_time'] ? '<span class="badge badge-orange">یک‌بار</span>' : '';
        $link    = htmlspecialchars(siteUrl("?p=view_text&id={$t['id']}"));
        $textRows .= "<tr>
          <td>$preview…</td><td>$date</td><td>$exp</td>
          <td>$views $ot</td>
          <td>
            <div style='display:flex;gap:6px;flex-wrap:wrap'>
            <button class='btn btn-sm btn-outline' onclick=\"copyText('$link',this)\">📋 کپی</button>
            <a href='$link' class='btn btn-sm' target='_blank'>👁 مشاهده</a>
            <a class='btn btn-sm btn-danger' href='?p=panel&del_text={$t['id']}&_csrf=$csrf' onclick=\"return confirm('حذف شود؟')\">🗑</a>
            </div>
          </td>
        </tr>";
    }
    if (!$textRows) $textRows = "<tr><td colspan='5' style='text-align:center;color:var(--muted)'>متنی ذخیره نشده</td></tr>";

    $usedPct = $maxSize > 0 ? min(100, round($totalSize / $maxSize * 100)) : 0;
    $usedFmt = formatSize($totalSize);
    $maxFmt  = formatSize($maxSize);

    echo <<<HTML
<div class="card" style="margin-bottom:14px">
  <h2 style="color:var(--orange);margin-bottom:16px">👋 سلام {$uname}!</h2>
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-num">{$totalFiles}</div><div class="stat-label">فایل آپلود شده</div></div>
    <div class="stat-card"><div class="stat-num">{$usedFmt}</div><div class="stat-label">فضای مصرفی</div></div>
    <div class="stat-card"><div class="stat-num">{$maxFmt}</div><div class="stat-label">سقف فضا</div></div>
  </div>
  <div style="background:var(--surface2);border-radius:6px;height:10px;overflow:hidden;margin-top:4px">
    <div style="width:{$usedPct}%;height:100%;background:linear-gradient(90deg,var(--orange),#ffaa00);transition:width 1s"></div>
  </div>
  <small style="color:var(--muted)">{$usedPct}% از فضا استفاده شده</small>
</div>

<div class="card">
  <div class="card-header"><span style="font-size:1.4rem">📂</span><h3>فایل‌های من</h3>
    <a href="?" class="btn btn-sm" style="margin-right:auto">📤 آپلود جدید</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نام فایل</th><th>حجم</th><th>تاریخ</th><th>انقضا</th><th>دانلود</th><th>عملیات</th></tr></thead>
      <tbody>{$fileRows}</tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><span style="font-size:1.4rem">📝</span><h3>متن‌های من</h3>
    <a href="?p=text" class="btn btn-sm" style="margin-right:auto">📝 ذخیره جدید</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>متن</th><th>تاریخ</th><th>انقضا</th><th>بازدید</th><th>عملیات</th></tr></thead>
      <tbody>{$textRows}</tbody>
    </table>
  </div>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: ADMIN PANEL
// ═══════════════════════════════════════════════════════════════
function pageAdmin(): void {
    if (!isAdmin()) { header('Location: ?p=login'); exit; }

    $pdo = getDB();

    // حذف فایل توسط ادمین
    if (isset($_GET['del_file']) && verifyCsrf()) {
        $id = intval($_GET['del_file']);
        $f  = $pdo->prepare("SELECT stored_name FROM files WHERE id = ?");
        $f->execute([$id]);
        $row = $f->fetch();
        if ($row) {
            $path = UPLOAD_DIR . $row['stored_name'];
            if (file_exists($path)) unlink($path);
            $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$id]);
        }
        header('Location: ?p=admin'); exit;
    }
    if (isset($_GET['del_text']) && verifyCsrf()) {
        $pdo->prepare("DELETE FROM texts WHERE id = ?")->execute([intval($_GET['del_text'])]);
        header('Location: ?p=admin'); exit;
    }

    // آمار کلی
    $totalFiles   = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $totalTexts   = $pdo->query("SELECT COUNT(*) FROM texts")->fetchColumn();
    $totalUsers   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalStorage = $pdo->query("SELECT COALESCE(SUM(file_size),0) FROM files")->fetchColumn();

    $csrf = csrf();

    // فایل‌ها
    $page    = max(1, intval($_GET['page'] ?? 1));
    $perPage = 20;
    $off     = ($page - 1) * $perPage;
    $ttlF    = (int)$totalFiles;
    $pages   = max(1, ceil($ttlF / $perPage));

    $fstmt = $pdo->prepare("
        SELECT f.*, u.username FROM files f
        LEFT JOIN users u ON f.user_id = u.id
        ORDER BY f.upload_date DESC
        LIMIT ? OFFSET ?
    ");
    $fstmt->execute([$perPage, $off]);
    $fList = $fstmt->fetchAll();

    $fileRows = '';
    foreach ($fList as $f) {
        $name  = htmlspecialchars($f['original_name']);
        $size  = formatSize($f['file_size']);
        $date  = date('Y/m/d H:i', strtotime($f['upload_date']));
        $exp   = $f['expiry'] ? date('Y/m/d', strtotime($f['expiry'])) : '—';
        $user  = $f['username'] ? htmlspecialchars($f['username']) : '<span class="badge badge-gray">مهمان</span>';
        $dl    = $f['download_count'];
        $ot    = $f['one_time'] ? '<span class="badge badge-orange">یک‌بار</span>' : '';
        $link  = htmlspecialchars(siteUrl("?p=download&id={$f['id']}"));
        $fileRows .= "<tr>
          <td><small>{$f['id']}</small></td>
          <td>$user</td><td title=\"{$name}\">" . mb_substr($f['original_name'], 0, 24) . "…</td>
          <td>$size</td><td>$date</td><td>$exp</td>
          <td>$dl $ot</td>
          <td>
            <div style='display:flex;gap:4px;flex-wrap:wrap'>
            <button class='btn btn-sm btn-outline' onclick=\"copyText('$link',this)\">📋</button>
            <a href='$link' class='btn btn-sm btn-info' target='_blank'>⬇</a>
            <a class='btn btn-sm btn-danger' href='?p=admin&del_file={$f['id']}&_csrf=$csrf' onclick=\"return confirm('حذف؟')\">🗑</a>
            </div>
          </td>
        </tr>";
    }
    if (!$fileRows) $fileRows = "<tr><td colspan='8' style='text-align:center;color:var(--muted)'>هیچ فایلی موجود نیست</td></tr>";

    // پیجینیشن
    $pagLinks = '';
    for ($i = 1; $i <= $pages; $i++) {
        $cls = $i === $page ? 'btn btn-sm' : 'btn btn-sm btn-outline';
        $pagLinks .= "<a href='?p=admin&page=$i' class='$cls'>$i</a> ";
    }

    $totalSizeFmt = formatSize((int)$totalStorage);

    echo <<<HTML
<div class="admin-layout">
  <aside class="admin-sidebar">
    <strong style="color:var(--orange);font-size:.9rem">🛡️ پنل ادمین</strong>
    <div class="divider"></div>
    <a href="?p=admin" class="active">📂 فایل‌ها و متون</a>
    <a href="?p=admin_users">👥 مدیریت کاربران</a>
    <a href="?p=admin_settings">⚙️ تنظیمات سایت</a>
    <a href="?p=admin_backup">💾 بک‌آپ</a>
    <div class="divider"></div>
    <a href="?">🌐 رفتن به سایت</a>
    <a href="?p=logout" style="color:var(--danger)">🚪 خروج</a>
  </aside>

  <main>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-num">{$totalFiles}</div><div class="stat-label">فایل آپلود شده</div></div>
      <div class="stat-card"><div class="stat-num">{$totalTexts}</div><div class="stat-label">متن ذخیره شده</div></div>
      <div class="stat-card"><div class="stat-num">{$totalUsers}</div><div class="stat-label">کاربر ثبت‌نام شده</div></div>
      <div class="stat-card"><div class="stat-num">{$totalSizeFmt}</div><div class="stat-label">فضای مصرفی</div></div>
    </div>

    <div class="card">
      <div class="card-header"><span style="font-size:1.4rem">📂</span><h3>تمام فایل‌ها</h3></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>کاربر</th><th>نام</th><th>حجم</th><th>تاریخ</th><th>انقضا</th><th>دانلود</th><th>عملیات</th></tr></thead>
          <tbody>{$fileRows}</tbody>
        </table>
      </div>
      <div style="margin-top:14px;display:flex;gap:6px;flex-wrap:wrap">{$pagLinks}</div>
    </div>
  </main>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: ADMIN USERS
// ═══════════════════════════════════════════════════════════════
function pageAdminUsers(): void {
    if (!isAdmin()) { header('Location: ?p=login'); exit; }

    $pdo  = getDB();
    $csrf = csrf();

    // تغییر حجم آپلود کاربر
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $id      = intval($_POST['user_id'] ?? 0);
        $maxMb   = max(0, intval($_POST['max_mb'] ?? 0));
        $maxBytes = $maxMb * 1024 * 1024;
        $pdo->prepare("UPDATE users SET max_size = ? WHERE id = ?")->execute([$maxBytes, $id]);
        header('Location: ?p=admin_users&saved=1'); exit;
    }

    // حذف کاربر
    if (isset($_GET['del_user']) && verifyCsrf()) {
        $id = intval($_GET['del_user']);
        $pdo->prepare("DELETE FROM files WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM texts WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        header('Location: ?p=admin_users'); exit;
    }

    $users = $pdo->query("
        SELECT u.*,
               COUNT(f.id) AS file_count,
               COALESCE(SUM(f.file_size), 0) AS total_size
        FROM users u
        LEFT JOIN files f ON f.user_id = u.id
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ")->fetchAll();

    $savedMsg = isset($_GET['saved']) ? "<div class='alert alert-success' style='margin-bottom:12px'>✅ ذخیره شد!</div>" : '';

    $rows = '';
    foreach ($users as $u) {
        $uname = htmlspecialchars($u['username']);
        $email = htmlspecialchars($u['email'] ?? '—');
        $date  = date('Y/m/d', strtotime($u['created_at']));
        $fc    = (int)$u['file_count'];
        $ts    = formatSize((int)$u['total_size']);
        $custom = $u['max_size'] > 0 ? round($u['max_size'] / 1048576) : 0;
        $maxLabel = $u['max_size'] > 0 ? formatSize($u['max_size']) : 'پیش‌فرض';

        $rows .= "<tr>
          <td>{$u['id']}</td><td>$uname</td><td>$email</td><td>$date</td>
          <td>$fc</td><td>$ts</td><td>$maxLabel</td>
          <td>
            <div style='display:flex;gap:6px;align-items:center;flex-wrap:wrap'>
              <form method='post' style='display:flex;gap:4px;align-items:center'>
                <input type='hidden' name='_csrf' value='{$csrf}'>
                <input type='hidden' name='user_id' value='{$u['id']}'>
                <input type='number' name='max_mb' value='$custom' min='0' max='51200' style='width:80px;padding:5px 8px;background:var(--surface3);border:1px solid var(--border);color:var(--text);border-radius:4px'>
                <span style='font-size:.8rem;color:var(--muted)'>MB</span>
                <button class='btn btn-sm btn-success'>💾</button>
              </form>
              <a class='btn btn-sm btn-danger' href='?p=admin_users&del_user={$u['id']}&_csrf=$csrf' onclick=\"return confirm('کاربر و تمام فایل‌هایش حذف شوند؟')\">🗑</a>
            </div>
          </td>
        </tr>";
    }
    if (!$rows) $rows = "<tr><td colspan='8' style='text-align:center;color:var(--muted)'>هیچ کاربری ثبت‌نام نکرده</td></tr>";

    echo <<<HTML
<div class="admin-layout">
  <aside class="admin-sidebar">
    <strong style="color:var(--orange);font-size:.9rem">🛡️ پنل ادمین</strong>
    <div class="divider"></div>
    <a href="?p=admin">📂 فایل‌ها و متون</a>
    <a href="?p=admin_users" class="active">👥 مدیریت کاربران</a>
    <a href="?p=admin_settings">⚙️ تنظیمات سایت</a>
    <a href="?p=admin_backup">💾 بک‌آپ</a>
    <div class="divider"></div>
    <a href="?">🌐 رفتن به سایت</a>
    <a href="?p=logout" style="color:var(--danger)">🚪 خروج</a>
  </aside>
  <main>
    <div class="card">
      <div class="card-header"><span style="font-size:1.4rem">👥</span><h3>مدیریت کاربران</h3></div>
      {$savedMsg}
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>نام کاربری</th><th>ایمیل</th><th>تاریخ</th><th>فایل‌ها</th><th>فضای مصرفی</th><th>سقف آپلود</th><th>عملیات</th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
      </div>
      <small style="color:var(--muted)">عدد 0 در ستون سقف = استفاده از پیش‌فرض سیستم</small>
    </div>
  </main>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: ADMIN SETTINGS
// ═══════════════════════════════════════════════════════════════
function pageAdminSettings(): void {
    if (!isAdmin()) { header('Location: ?p=login'); exit; }

    $csrf = csrf();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $fields = [
            'maintenance_mode', 'maintenance_msg',
            'allow_guest', 'max_guest_mb', 'max_member_mb', 'site_name'
        ];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                setSetting($f, htmlspecialchars(strip_tags($_POST[$f])));
            }
        }
        // تنظیم مود نگهداری از checkbox
        setSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
        setSetting('allow_guest', isset($_POST['allow_guest']) ? '1' : '0');
        header('Location: ?p=admin_settings&saved=1'); exit;
    }

    $mm      = getSetting('maintenance_mode') === '1' ? 'checked' : '';
    $mmsg    = htmlspecialchars(getSetting('maintenance_msg'));
    $ag      = getSetting('allow_guest') === '1' ? 'checked' : '';
    $mgmb    = htmlspecialchars(getSetting('max_guest_mb', '500'));
    $mmmb    = htmlspecialchars(getSetting('max_member_mb', '2048'));
    $sname   = htmlspecialchars(getSetting('site_name', SITE_NAME));
    $savedMsg = isset($_GET['saved']) ? "<div class='alert alert-success' style='margin-bottom:12px'>✅ تنظیمات ذخیره شد!</div>" : '';

    echo <<<HTML
<div class="admin-layout">
  <aside class="admin-sidebar">
    <strong style="color:var(--orange);font-size:.9rem">🛡️ پنل ادمین</strong>
    <div class="divider"></div>
    <a href="?p=admin">📂 فایل‌ها و متون</a>
    <a href="?p=admin_users">👥 مدیریت کاربران</a>
    <a href="?p=admin_settings" class="active">⚙️ تنظیمات سایت</a>
    <a href="?p=admin_backup">💾 بک‌آپ</a>
    <div class="divider"></div>
    <a href="?">🌐 رفتن به سایت</a>
    <a href="?p=logout" style="color:var(--danger)">🚪 خروج</a>
  </aside>
  <main>
    <div class="card">
      <div class="card-header"><span style="font-size:1.4rem">⚙️</span><h3>تنظیمات سایت</h3></div>
      {$savedMsg}
      <form method="post">
        <input type="hidden" name="_csrf" value="{$csrf}">

        <div class="form-group">
          <label>🏷️ نام سایت</label>
          <input type="text" name="site_name" value="{$sname}" required>
        </div>

        <div class="form-group">
          <div class="checkbox-row" onclick="document.getElementById('mm').click()">
            <input type="checkbox" id="mm" name="maintenance_mode" {$mm}>
            <label>⚙️ حالت تعمیر (Maintenance Mode) - سایت برای عموم بسته می‌شود</label>
          </div>
        </div>

        <div class="form-group">
          <label>📢 پیام حالت تعمیر</label>
          <textarea name="maintenance_msg" rows="3">{$mmsg}</textarea>
        </div>

        <div class="form-group">
          <div class="checkbox-row" onclick="document.getElementById('ag').click()">
            <input type="checkbox" id="ag" name="allow_guest" {$ag}>
            <label>👤 اجازه آپلود به مهمان‌ها (بدون ثبت‌نام)</label>
          </div>
        </div>

        <div class="form-group">
          <label>📦 حداکثر حجم آپلود مهمان (MB)</label>
          <input type="number" name="max_guest_mb" value="{$mgmb}" min="1" max="10240">
        </div>

        <div class="form-group">
          <label>📦 حداکثر حجم آپلود کاربر عضو (MB)</label>
          <input type="number" name="max_member_mb" value="{$mmmb}" min="1" max="51200">
        </div>

        <button type="submit" class="btn">💾 ذخیره تنظیمات</button>
      </form>
    </div>
  </main>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  PAGE: ADMIN BACKUP
// ═══════════════════════════════════════════════════════════════
function pageAdminBackup(): void {
    if (!isAdmin()) { header('Location: ?p=login'); exit; }

    $pdo  = getDB();
    $csrf = csrf();

    // آمار
    $filesCount = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $filesSize  = formatSize((int)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM files")->fetchColumn());
    $textsCount = $pdo->query("SELECT COUNT(*) FROM texts")->fetchColumn();
    $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // حجم واقعی پوشه آپلودها
    $diskSize = '—';
    if (is_dir(UPLOAD_DIR)) {
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $bytes += $file->getSize();
        }
        $diskSize = formatSize($bytes);
    }

    echo <<<HTML
<div class="admin-layout">
  <aside class="admin-sidebar">
    <strong style="color:var(--orange);font-size:.9rem">🛡️ پنل ادمین</strong>
    <div class="divider"></div>
    <a href="?p=admin">📂 فایل‌ها و متون</a>
    <a href="?p=admin_users">👥 مدیریت کاربران</a>
    <a href="?p=admin_settings">⚙️ تنظیمات سایت</a>
    <a href="?p=admin_backup" class="active">💾 بک‌آپ</a>
    <div class="divider"></div>
    <a href="?">🌐 رفتن به سایت</a>
    <a href="?p=logout" style="color:var(--danger)">🚪 خروج</a>
  </aside>
  <main>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-num">{$filesCount}</div><div class="stat-label">فایل در دیتابیس</div></div>
      <div class="stat-card"><div class="stat-num">{$textsCount}</div><div class="stat-label">متن ذخیره شده</div></div>
      <div class="stat-card"><div class="stat-num">{$usersCount}</div><div class="stat-label">کاربر</div></div>
      <div class="stat-card"><div class="stat-num">{$diskSize}</div><div class="stat-label">فضای دیسک آپلودها</div></div>
    </div>

    <div class="card">
      <div class="card-header"><span style="font-size:1.4rem">💾</span><h3>دریافت بک‌آپ</h3></div>
      <p style="color:var(--muted);margin-bottom:20px;font-size:.9rem">
        بک‌آپ شامل تمام اطلاعات دیتابیس (کاربران، فایل‌ها، متون، تنظیمات) به فرمت SQL می‌شود.
        فایل‌های فیزیکی آپلود شده را باید جداگانه از سرور دریافت کنید.
      </p>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="?p=backup_download&type=sql&_csrf={$csrf}" class="btn">
          💾 دریافت بک‌آپ SQL دیتابیس
        </a>
        <a href="?p=backup_download&type=json&_csrf={$csrf}" class="btn btn-outline">
          📋 دریافت بک‌آپ JSON
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span style="font-size:1.4rem">📊</span><h3>مسیر فایل‌های آپلود شده</h3></div>
      <p style="font-family:monospace;background:var(--surface2);padding:10px 14px;border-radius:6px;color:var(--orange);font-size:.9rem">
        {$_SERVER['DOCUMENT_ROOT']}
      </p>
      <p style="color:var(--muted);margin-top:10px;font-size:.85rem">
        پوشه <code>uploads/</code> در کنار index.php قرار دارد. برای بک‌آپ کامل، این پوشه را از طریق SFTP یا پنل هاستینگ دریافت کنید.
      </p>
    </div>
  </main>
</div>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  API: UPLOAD FILE
// ═══════════════════════════════════════════════════════════════
function handleUploadApi(): void {
    header('Content-Type: application/json; charset=utf-8');
    $pdo = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر']); return;
    }

    // بررسی اجازه مهمان
    if (!isLoggedIn() && getSetting('allow_guest', '1') !== '1') {
        echo json_encode(['success' => false, 'message' => 'برای آپلود ابتدا ثبت‌نام کنید']); return;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشده']); return;
    }

    $f   = $_FILES['file'];
    $max = maxSizeForCurrentUser();

    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'فایل از حد سرور بزرگتر است',
        UPLOAD_ERR_FORM_SIZE  => 'فایل از حد فرم بزرگتر است',
        UPLOAD_ERR_PARTIAL    => 'آپلود ناقص',
        UPLOAD_ERR_CANT_WRITE => 'خطای نوشتن روی دیسک',
    ];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = $errMap[$f['error']] ?? 'خطای آپلود کد ' . $f['error'];
        echo json_encode(['success' => false, 'message' => $msg]); return;
    }
    if ($f['size'] > $max) {
        echo json_encode(['success' => false, 'message' => 'حجم فایل از ' . formatSize($max) . ' بیشتر است']); return;
    }

    // نام امن برای ذخیره
    $ext        = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $safeExt    = preg_replace('/[^a-z0-9]/', '', $ext);
    $storedName = bin2hex(random_bytes(16)) . ($safeExt ? '.' . $safeExt : '');
    $dest       = UPLOAD_DIR . $storedName;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل روی سرور']); return;
    }

    $expiryH    = max(0, intval($_POST['expiry_hours'] ?? 0));
    $expiry     = $expiryH > 0 ? date('Y-m-d H:i:s', time() + $expiryH * 3600) : null;
    $oneTime    = ($_POST['one_time'] ?? '') === '1' ? 1 : 0;
    $mime       = mime_content_type($dest) ?: 'application/octet-stream';
    $userId     = currentUserId();
    $cookie     = getUploaderCookie();

    $stmt = $pdo->prepare("INSERT INTO files (user_id, uploader_cookie, original_name, stored_name, file_size, mime_type, expiry, one_time) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$userId, $cookie, $f['name'], $storedName, $f['size'], $mime, $expiry, $oneTime]);
    $fileId = $pdo->lastInsertId();

    $link = siteUrl("?p=download&id=$fileId");
    tgNotify("📤 فایل جدید آپلود شد:\n<b>" . htmlspecialchars($f['name']) . "</b>\n📦 " . formatSize($f['size']) . "\n👤 " . ($userId ? 'کاربر #' . $userId : 'مهمان'));

    echo json_encode([
        'success'  => true,
        'link'     => $link,
        'expiry'   => $expiry ? date('Y-m-d H:i', strtotime($expiry)) : null,
        'one_time' => $oneTime === 1,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  API: TEXT SAVE
// ═══════════════════════════════════════════════════════════════
function handleTextApi(): void {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر']); return;
    }

    $content = trim($_POST['content'] ?? '');
    if (empty($content)) {
        echo json_encode(['success' => false, 'message' => 'متن نمی‌تواند خالی باشد']); return;
    }

    $expiryH = max(0, intval($_POST['expiry'] ?? 0));
    $expiry  = $expiryH > 0 ? date('Y-m-d H:i:s', time() + $expiryH * 3600) : null;
    $oneTime = ($_POST['one_time'] ?? '') === '1' ? 1 : 0;
    $userId  = currentUserId();
    $cookie  = getUploaderCookie();

    $pdo  = getDB();
    $stmt = $pdo->prepare("INSERT INTO texts (user_id, uploader_cookie, content, expiry, one_time) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $cookie, $content, $expiry, $oneTime]);
    $id   = $pdo->lastInsertId();
    $link = siteUrl("?p=view_text&id=$id");

    echo json_encode([
        'success'  => true,
        'link'     => $link,
        'expiry'   => $expiry ? date('Y-m-d H:i', strtotime($expiry)) : null,
        'one_time' => $oneTime === 1,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  DOWNLOAD FILE
// ═══════════════════════════════════════════════════════════════
function handleDownload(): void {
    $pdo = getDB();
    $id  = intval($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); die('شناسه نامعتبر'); }

    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();

    if (!$file)                                         { http_response_code(404); die('فایل یافت نشد'); }
    if ($file['expiry'] && strtotime($file['expiry']) < time()) { http_response_code(410); die('⏰ این لینک منقضی شده است'); }
    if ($file['one_time'] && $file['one_time_used'])    { http_response_code(410); die('🔒 این لینک قبلاً استفاده شده'); }

    $path = UPLOAD_DIR . $file['stored_name'];
    if (!file_exists($path)) { http_response_code(404); die('فایل روی سرور موجود نیست'); }

    // محافظت در برابر path traversal
    if (strpos(realpath($path), realpath(UPLOAD_DIR)) !== 0) { http_response_code(403); die('دسترسی غیرمجاز'); }

    if ($file['one_time']) {
        $pdo->prepare("UPDATE files SET one_time_used=1, download_count=download_count+1 WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE files SET download_count=download_count+1 WHERE id=?")->execute([$id]);
    }

    $origName = addslashes(basename($file['original_name']));
    $mime     = $file['mime_type'] ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $origName . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  VIEW TEXT
// ═══════════════════════════════════════════════════════════════
function handleViewText(): void {
    $pdo = getDB();
    $id  = intval($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM texts WHERE id = ?");
    $stmt->execute([$id]);
    $text = $stmt->fetch();

    if (!$text)                                           { http_response_code(404); die('متن یافت نشد'); }
    if ($text['expiry'] && strtotime($text['expiry']) < time()) { http_response_code(410); die('⏰ این لینک منقضی شده'); }
    if ($text['one_time'] && $text['one_time_used'])      { http_response_code(410); die('🔒 این لینک قبلاً استفاده شده'); }

    if ($text['one_time']) {
        $pdo->prepare("UPDATE texts SET one_time_used=1, view_count=view_count+1 WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE texts SET view_count=view_count+1 WHERE id=?")->execute([$id]);
    }

    $content  = htmlspecialchars($text['content']);
    $raw      = addslashes(htmlspecialchars($text['content'], ENT_QUOTES));
    $views    = (int)$text['view_count'];
    $siteName = htmlspecialchars(getSetting('site_name', SITE_NAME));

    echo <<<HTML
<!DOCTYPE html><html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><title>متن | {$siteName}</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0d0d;color:#e8e8e8;font-family:Tahoma,sans-serif;padding:30px 20px}
.wrap{max-width:800px;margin:0 auto;background:#1a1a1a;border:1px solid #2e2e2e;border-radius:10px;padding:24px}
h2{color:#ff6a00;margin-bottom:16px}
pre{background:#222;padding:16px;border-radius:8px;white-space:pre-wrap;word-wrap:break-word;color:#e0e0e0;max-height:60vh;overflow:auto;line-height:1.6}
.meta{color:#888;font-size:.82rem;margin-top:10px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#ff6a00;color:#000;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:.9rem;margin-top:14px;text-decoration:none}
.btn:hover{background:#e05a00}
a.back{color:#ff6a00;font-size:.9rem}
</style></head>
<body>
<div class="wrap">
  <h2>📄 متن اشتراکی</h2>
  <pre id="txt">{$content}</pre>
  <div class="meta">👁 بازدید: {$views}</div>
  <button class="btn" onclick="navigator.clipboard.writeText(document.getElementById('txt').textContent).then(()=>this.textContent='✅ کپی شد!').catch(()=>{})">📋 کپی متن</button>
  <br><br><a href="?" class="back">⬅ آپلود فایل جدید</a>
</div>
</body></html>
HTML;
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  BACKUP DOWNLOAD
// ═══════════════════════════════════════════════════════════════
function handleBackupDownload(): void {
    if (!isAdmin()) { http_response_code(403); die('دسترسی ممنوع'); }
    if (!verifyCsrf()) { http_response_code(403); die('توکن نامعتبر'); }

    $pdo  = getDB();
    $type = $_GET['type'] ?? 'json';
    $date = date('Y-m-d_H-i');

    if ($type === 'sql') {
        // بک‌آپ SQL
        $tables = ['settings', 'users', 'files', 'texts'];
        $sql    = "-- Zeus Uploader SQL Backup - $date\n-- =============================\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $tbl) {
            // DROP + CREATE
            $create = $pdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_NUM);
            $sql   .= "DROP TABLE IF EXISTS `$tbl`;\n" . $create[1] . ";\n\n";

            // INSERT rows
            $rows = $pdo->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_NUM);
            if ($rows) {
                $cols = array_keys($pdo->query("SELECT * FROM `$tbl` LIMIT 1")->fetch(PDO::FETCH_ASSOC));
                $colList = '`' . implode('`, `', $cols) . '`';
                foreach ($rows as $r) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $r);
                    $sql .= "INSERT INTO `$tbl` ($colList) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        header('Content-Type: application/sql');
        header("Content-Disposition: attachment; filename=\"zeus_backup_{$date}.sql\"");
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }

    // بک‌آپ JSON
    $data = [
        'backup_date' => $date,
        'settings'    => $pdo->query("SELECT * FROM settings")->fetchAll(),
        'users'       => $pdo->query("SELECT id, username, email, created_at, max_size FROM users")->fetchAll(),
        'files'       => $pdo->query("SELECT * FROM files")->fetchAll(),
        'texts'       => $pdo->query("SELECT * FROM texts")->fetchAll(),
    ];
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json');
    header("Content-Disposition: attachment; filename=\"zeus_backup_{$date}.json\"");
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  404
// ═══════════════════════════════════════════════════════════════
function page404(): void {
    http_response_code(404);
    echo <<<HTML
<div style="text-align:center;padding:60px 20px">
  <div style="font-size:5rem;margin-bottom:20px">🔍</div>
  <h2 style="color:var(--orange);font-size:2rem;margin-bottom:14px">صفحه پیدا نشد</h2>
  <p style="color:var(--muted);margin-bottom:24px">صفحه‌ای که دنبالش می‌گردید وجود ندارد.</p>
  <a href="?" class="btn">🏠 برگشت به خانه</a>
</div>
HTML;
}
