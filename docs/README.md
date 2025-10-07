# ابزارهای تشخیص خطاهای JavaScript
*Automated JavaScript Error Detection Tools*

## نمای کلی
این مجموعه ابزار برای تشخیص و رفع خطاهای رایج JavaScript در پلاگین عرشلاین طراحی شده است. این ابزارها به‌خصوص برای خطاهای Syntax Error که در فایل‌های template PHP رخ می‌دهند، مفید هستند.

## فایل‌های موجود

### 1. `js_error_checker.py` (ابزار Python)
**هدف:** تشخیص خودکار خطاهای JavaScript در فایل‌های PHP
**قابلیت‌ها:**
- ✅ تشخیص عدم تعادل براکت‌ها و پرانتزها
- ✅ هشدار برای Arrow Functions (سازگاری ES6)
- ✅ تشخیص Template Literals
- ✅ بررسی دسته‌ای فایل‌ها
- ✅ نمایش محتوای اطراف خطا

### 2. `CheckJavaScriptErrors.ps1` (ابزار PowerShell)
**هدف:** نسخه PowerShell برای کاربران Windows
**قابلیت‌ها:**
- ✅ عملکرد مشابه نسخه Python
- ✅ نمایش رنگی نتایج
- ✅ گزارش‌دهی تفصیلی
- ✅ پشتیبانی از UTF-8

### 3. `JAVASCRIPT_ERROR_TROUBLESHOOTING.md`
**هدف:** راهنمای کامل عیب‌یابی (به فارسی)
**محتویات:**
- 📖 آموزش گام به گام
- 🔧 روش‌های تشخیص دستی
- 💡 راهکارهای پیشگیری
- 📋 مثال‌های عملی

## نحوه استفاده

### Python (توصیه شده)
```bash
# بررسی یک فایل خاص
python js_error_checker.py dashboard-template.php

# بررسی تمام فایل‌های PHP
python js_error_checker.py --all

# بررسی با جزئیات بیشتر
python js_error_checker.py dashboard-template.php --verbose
```

### PowerShell (Windows)
```powershell
# بررسی یک فایل خاص
.\CheckJavaScriptErrors.ps1 -FilePath "dashboard-template.php"

# بررسی تمام فایل‌ها
.\CheckJavaScriptErrors.ps1 -CheckAll

# بررسی با جزئیات بیشتر
.\CheckJavaScriptErrors.ps1 -CheckAll -Verbose
```

## خطاهای رایج که تشخیص داده می‌شوند

### 1. عدم تعادل براکت‌ها
```javascript
// خطا: براکت بسته نشده
function myFunc() {
    if (condition) {
        // missing }
// خطا: پرانتز اضافی
someFunction(param1, param2));
```

### 2. مشکلات ES6 Compatibility
```javascript
// هشدار: Arrow Function
const myFunc = () => {
    // may not work in older browsers
};

// هشدار: Template Literals
const message = `Hello ${name}`;
```

### 3. خطاهای Syntax شایع
```javascript
// خطا: کاما اضافی
const obj = {
    prop1: value1,
    prop2: value2,  // trailing comma
};
```

## خروجی نمونه

### مثال خروجی موفق:
```
🔍 بررسی فایل: dashboard-template.php
==================================================

📝 بررسی Script Block 1:
✅ تعادل براکت‌ها: OK

📝 بررسی Script Block 2:
✅ تعادل براکت‌ها: OK
⚠️  Arrow Function found at line 45 (may not work in older browsers)

✅ تمام بلاک‌های JavaScript سالم هستند
```

### مثال خروجی خطادار:
```
🔍 بررسی فایل: dashboard-template.php
==================================================

📝 بررسی Script Block 1:
❌ Unmatched closing ')' at position 234

📄 Context:
     42: function renderForm() {
     43:     const form = document.getElementById('form');
 >>> 44:     form.addEventListener('click', handleClick));
     45:     return form;
     46: }
```

## ادغام در فرایند توسعه

### 1. Pre-commit Hook
```bash
#!/bin/sh
# .git/hooks/pre-commit
python tools/js_error_checker.py --all
if [ $? -ne 0 ]; then
    echo "❌ JavaScript errors found. Please fix before committing."
    exit 1
fi
```

### 2. VS Code Task
```json
{
    "label": "Check JS Errors",
    "type": "shell",
    "command": "python",
    "args": ["tools/js_error_checker.py", "--all"],
    "group": "test",
    "presentation": {
        "reveal": "always",
        "panel": "new"
    }
}
```

### 3. GitHub Action
```yaml
name: JavaScript Validation
on: [push, pull_request]
jobs:
  validate-js:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    - name: Setup Python
      uses: actions/setup-python@v2
      with:
        python-version: '3.x'
    - name: Check JavaScript Errors
      run: python tools/js_error_checker.py --all
```

## نکات مهم

### ⚠️ محدودیت‌ها
- ابزارها فقط خطاهای Syntax اساسی را تشخیص می‌دهند
- برای تست کامل، همچنان نیاز به اجرای کد در مرورگر است
- خطاهای Runtime تشخیص داده نمی‌شوند

### 💡 توصیه‌ها
1. **استفاده منظم:** این ابزارها را قبل از هر commit اجرا کنید
2. **آموزش تیم:** تمام اعضای تیم باید با این ابزارها آشنا باشند
3. **بروزرسانی مداوم:** با افزوده شدن خطاهای جدید، ابزارها را بروزرسانی کنید

### 🔧 سفارشی‌سازی
برای افزودن تشخیص خطاهای جدید:
1. فایل `js_error_checker.py` را ویرایش کنید
2. متد جدید برای تشخیص خطا اضافه کنید
3. آن را در `check_javascript_errors` فراخوانی کنید

## رفع خطاهای رایج

### خطا: "File not found"
```bash
# بررسی کنید در مسیر صحیح هستید
pwd
ls -la dashboard-template.php
```

### خطا: "Python not found"
```bash
# نصب Python
# Windows: از python.org
# Linux: sudo apt install python3
# MacOS: brew install python3
```

### خطا: "Permission denied"
```bash
# اجازه اجرا به فایل بدهید
chmod +x js_error_checker.py
chmod +x CheckJavaScriptErrors.ps1
```

## تماس و پشتیبانی
برای گزارش باگ یا درخواست فیچر جدید، با تیم توسعه عرشلاین تماس بگیرید.

---

**آخرین بروزرسانی:** ۲۵ سپتامبر ۲۰۲۵  
**نسخه ابزارها:** 1.0.0  
**سازگاری:** Python 3.6+, PowerShell 5.0+