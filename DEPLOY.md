# ⚡ Zeus Uploader — راهنمای نصب روی Railway

## فایل‌ها
config.php (هسته) · index.php (سایت) · bot.php (ربات) · Dockerfile · railway.json

## متغیرهای سرویس سایت (Variables)
- `MYSQLHOST` `MYSQLPORT` `MYSQLDATABASE` `MYSQLUSER` `MYSQLPASSWORD` → رفرنس به سرویس MySQL، مثلاً `${{MySQL.MYSQLHOST}}`
- `ADMIN_USER` / `ADMIN_PASS` → ورود ادمین (اگر `ADMIN_PASS` خالی باشد ورود ادمین غیرفعال است)
- `TG_TOKEN` / `TG_ADMIN_ID` → توکن ربات و آیدی عددی ادمین
- اختیاری: `SITE_NAME`

## فعال‌سازی ربات
بعد از هر دیپلوی که `TG_TOKEN` عوض شده باشد، یک‌بار این آدرس را در مرورگر باز کن:
`https://دامنه-تو/bot.php?setup=1`
(وبهوک با رمز مخفی ثبت می‌شود تا هیچ‌کس نتواند به ربات درخواست جعلی بفرستد.)

## نکته
فایل‌های آپلودشده روی دیسک سرویس ذخیره می‌شوند و با دیپلوی جدید پاک می‌شوند؛ برای ماندگاری یک Volume به مسیر `/var/www/html/uploads` وصل کن.
