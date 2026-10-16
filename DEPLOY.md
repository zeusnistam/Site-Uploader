# ⚡ Zeus Uploader — راهنمای نصب روی Railway

## 📁 ساختار فایل‌ها (5 فایل)

```
zeus-uploader/
├── config.php      ← تنظیمات + توابع مشترک
├── index.php       ← کل سایت (تمام صفحات)
├── bot.php         ← ربات تلگرام (webhook)
├── Dockerfile      ← تنظیمات Docker
└── railway.json    ← تنظیمات Railway
```

---

## 🚀 مراحل نصب روی Railway

### ۱. ایجاد پروژه جدید
1. به [railway.app](https://railway.app) وارد شوید
2. **New Project** → **Deploy from GitHub Repo**
3. ریپو خود را انتخاب کنید

### ۲. اضافه‌کردن MySQL
1. در پروژه: **+ New** → **Database** → **MySQL**
2. Railway به‌صورت خودکار متغیرهای محیطی را تنظیم می‌کند:
   - `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`

### ۳. تنظیم متغیرهای محیطی (Environment Variables)
در بخش **Variables** سرویس PHP خود، این متغیرها را اضافه کنید:

| متغیر | توضیح | مثال |
|-------|-------|------|
| `ADMIN_USER` | نام کاربری ادمین | `admin` |
| `ADMIN_PASS` | رمز عبور ادمین | `رمزقوی!` |
| `TG_TOKEN` | توکن ربات تلگرام | `7665440430:AAGAM...` |
| `TG_ADMIN_ID` | آیدی عددی تلگرام ادمین | `1601379026` |
| `SITE_NAME` | نام سایت | `Zeus Uploader` |
| `MAX_GUEST_MB` | سقف آپلود مهمان (MB) | `500` |
| `MAX_MEMBER_MB` | سقف آپلود عضو (MB) | `2048` |

> **نکته:** متغیرهای MySQL را خودتان وارد نکنید — Railway آن‌ها را خودکار از سرویس MySQL می‌خواند.

### ۴. تنظیم Webhook ربات تلگرام
پس از deploy شدن، آدرس سایت را بگیرید (مثلاً `https://zeus-abc.up.railway.app`) و این URL را در مرورگر باز کنید:

```
https://zeus-abc.up.railway.app/bot.php?setup=1
```

باید پیام `{"ok":true,"result":true,...}` دریافت کنید.

---

## ⚙️ تنظیمات مهم

### رمز ادمین
پس از اولین deploy، از طریق پنل Railway متغیر `ADMIN_PASS` را به یک رمز قوی تغییر دهید.

### Volume برای فایل‌های آپلود شده
برای حفظ فایل‌ها پس از redeploy:
1. در Railway: **+ New** → **Volume**
2. Mount path: `/var/www/html/uploads`
3. این باعث می‌شود فایل‌های آپلود شده پاک نشوند

---

## 🤖 دستورات ربات تلگرام

```
/start یا /help  — راهنما
/menu            — منوی گرافیکی
/stats           — آمار کلی
/files [صفحه]   — لیست فایل‌ها
/users           — لیست کاربران
/settings        — مشاهده تنظیمات

/maintenance on|off   — حالت تعمیر
/setmsg [پیام]        — پیام حالت تعمیر
/allowguest on|off    — اجازه آپلود مهمان
/setguest [MB]        — سقف مهمان
/setmember [MB]       — سقف عضو
/sitename [نام]       — نام سایت

/delfile [ID]         — حذف فایل
/deluser [ID]         — حذف کاربر
/setlimit [ID] [MB]   — سقف اختصاصی کاربر

/backup               — لینک دانلود بک‌آپ SQL/JSON
```

---

## 🔒 ویژگی‌های امنیتی

- ✅ CSRF token روی تمام فرم‌ها
- ✅ Prepared statements (بدون SQL injection)
- ✅ Session امن (httponly + samesite + secure)
- ✅ نام فایل تصادفی برای ذخیره (hex)
- ✅ .htaccess برای مسدودکردن دانلود مستقیم از /uploads
- ✅ بررسی MIME type
- ✅ Path traversal protection
- ✅ XSS protection با htmlspecialchars
- ✅ رمز عبور با password_hash
