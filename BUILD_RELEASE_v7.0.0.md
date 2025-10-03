# ساخت Release Package v7.0.0

## فایل‌های شامل شده در Release:
- تمام فایل‌های ضروری plugin
- مستندات کامل
- فایل‌های CHANGELOG
- راهنمای نصب و راه‌اندازی

## فایل‌های حذف شده:
- فایل‌های .git
- فایل‌های development (tests/, tools/)
- فایل‌های backup
- دایرکتوری akismet غیرضروری

## دستورات ساخت Package:

```bash
# ایجاد دایرکتوری موقت
mkdir arshline-v7.0.0-release
cd arshline-v7.0.0-release

# کپی فایل‌های ضروری
git archive --format=tar --remote=https://github.com/fooladvandgth/arshline.git v7.0.0 | tar -x

# حذف فایل‌های غیرضروری  
rm -rf .git tests/ tools/
rm -rf akismet/ tmp_context.txt dash.htm

# ساخت ZIP
zip -r arshline-v7.0.0.zip .
```

## بررسی نهایی:
- ✅ Version number در arshline.php
- ✅ مستندات کامل
- ✅ CHANGELOG ها
- ✅ فایل‌های امنیتی
- ✅ حذف فایل‌های غیرضروری

Release آماده برای دانلود و نصب!