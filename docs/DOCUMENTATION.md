# مستندات جامع افزونه عرشلاین (ARSHLINE)

## فهرست مطالب
1. [معرفی پروژه](#معرفی-پروژه)
2. [نصب و راه‌اندازی](#نصب-و-راه‌اندازی)
3. [معماری سیستم](#معماری-سیستم)
4. [راهنمای توسعه‌دهنده](#راهنمای-توسعه‌دهنده)
5. [API Reference](#api-reference)
6. [امنیت](#امنیت)
7. [تست](#تست)
8. [عیب‌یابی](#عیب‌یابی)
9. [مشارکت](#مشارکت)

---

## معرفی پروژه

### چشم‌انداز
عرشلاین یک افزونه نسل جدید وردپرس برای ساخت فرم‌های پیشرفته، آزمون‌ها و نظرسنجی‌ها است که با معماری مدرن، داشبورد اختصاصی و قابلیت‌های هوشمند طراحی شده است.

### ویژگی‌های کلیدی
- 🎨 **داشبورد اختصاصی** با طراحی Glassmorphism
- 🔧 **فرم‌ساز درگ و دراپ** با پیش‌نمایش زنده
- 🌐 **پشتیبانی کامل RTL** و تقویم شمسی
- 🔒 **امنیت پیشرفته** با اعتبارسنجی چندلایه
- 📊 **REST API نسخه‌دار** برای یکپارچه‌سازی
- 🎯 **تجربه کاربری بهینه** با انیمیشن‌های نرم

### نسخه فعلی
- **نسخه:** v1.5.1
- **تاریخ انتشار:** 2025-09-25
- **وضعیت:** پایدار (Stable)

---

## نصب و راه‌اندازی

### پیش‌نیازها
- PHP 8.1 یا بالاتر
- WordPress 6.0 یا بالاتر
- MySQL 5.7 یا MariaDB 10.3
- حداقل 128MB حافظه PHP

### نصب
1. فایل ZIP افزونه را در پوشه `/wp-content/plugins/` استخراج کنید
2. از پنل مدیریت وردپرس، افزونه را فعال کنید
3. به منوی "عرشلاین" در پنل مدیریت بروید

### تنظیمات اولیه
```php
// تنظیمات پیش‌فرض در wp-config.php
define('ARSHLINE_DEBUG', false);
define('ARSHLINE_MAX_UPLOAD_SIZE', 300 * 1024); // 300KB
```

---

## معماری سیستم

### ساختار کلی
```
Arshline\
├── Core\              # هسته سیستم
│   ├── Api            # REST API endpoints
│   ├── FeatureFlags   # مدیریت ویژگی‌ها
│   └── ServiceContainer # Dependency Injection
├── Dashboard\         # داشبورد اختصاصی
├── Database\          # مایگریشن‌ها
├── Modules\           # ماژول‌های اصلی
│   └── Forms\         # مدیریت فرم‌ها
└── Support\           # کلاس‌های کمکی
```

### جداول دیتابیس
```sql
-- فرم‌ها
CREATE TABLE wp_x_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schema_version VARCHAR(20) NOT NULL,
    owner_id BIGINT UNSIGNED,
    status VARCHAR(20) DEFAULT 'draft',
    meta JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- فیلدهای فرم
CREATE TABLE wp_x_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    sort INT UNSIGNED DEFAULT 0,
    props JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES wp_x_forms(id) ON DELETE CASCADE
);

-- ارسال‌ها
CREATE TABLE wp_x_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    status VARCHAR(20) DEFAULT 'pending',
    meta JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES wp_x_forms(id) ON DELETE CASCADE
);

-- مقادیر ارسال‌شده
CREATE TABLE wp_x_submission_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    value TEXT,
    idx INT UNSIGNED DEFAULT 0,
    FOREIGN KEY (submission_id) REFERENCES wp_x_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES wp_x_fields(id) ON DELETE CASCADE
);
```

### الگوهای طراحی
- **Repository Pattern** برای دسترسی به داده
- **Service Container** برای Dependency Injection
- **Factory Pattern** برای ساخت اشیاء
- **Observer Pattern** برای رویدادها

---

## راهنمای توسعه‌دهنده

### محیط توسعه
```bash
# نصب dependencies
composer install

# اجرای تست‌ها
composer test

# بررسی کیفیت کد
composer phpcs
```

### ساخت ماژول جدید
```php
<?php
namespace Arshline\Modules\MyModule;

class MyModule
{
    public static function boot()
    {
        add_action('init', [self::class, 'init']);
    }
    
    public static function init()
    {
        // منطق ماژول
    }
}
```

### افزودن فیلد جدید
```php
// در فایل field-types.js
const newFieldType = {
    type: 'my_field',
    label: 'فیلد من',
    icon: 'icon-name',
    defaultProps: {
        required: false,
        placeholder: ''
    },
    render: function(props) {
        // رندر فیلد
    }
};
```

### Hook های موجود
```php
// قبل از ذخیره فرم
do_action('arshline_before_save_form', $form);

// بعد از ذخیره فرم
do_action('arshline_after_save_form', $form_id, $form);

// قبل از ارسال
do_action('arshline_before_submission', $submission);

// بعد از ارسال
do_action('arshline_after_submission', $submission_id, $submission);
```

---

## API Reference

### Authentication
تمام endpoint های مدیریتی نیاز به یکی از مجوزهای زیر دارند:
- `manage_options`
- `edit_posts`

### Base URL
```
/wp-json/arshline/v1/
```

### Endpoints

#### فرم‌ها
```http
GET    /forms                    # لیست فرم‌ها
POST   /forms                    # ایجاد فرم جدید
GET    /forms/{id}               # جزئیات فرم
DELETE /forms/{id}               # حذف فرم
PUT    /forms/{id}/fields        # به‌روزرسانی فیلدها
```

#### ارسال‌ها
```http
GET    /forms/{id}/submissions   # لیست ارسال‌ها
POST   /forms/{id}/submissions   # ارسال جدید (عمومی)
```

#### آپلود
```http
POST   /upload                   # آپلود فایل
```

### نمونه درخواست
```javascript
// ایجاد فرم جدید
fetch('/wp-json/arshline/v1/forms', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({
        title: 'فرم تماس'
    })
});
```

### نمونه پاسخ
```json
{
    "id": 123,
    "title": "فرم تماس",
    "status": "draft",
    "created_at": "2025-01-01 12:00:00"
}
```

---

## امنیت

### اقدامات امنیتی پیاده‌سازی شده
- ✅ **Input Sanitization**: تمیزسازی ورودی‌ها
- ✅ **Output Escaping**: escape کردن خروجی‌ها
- ✅ **Nonce Verification**: تأیید nonce برای درخواست‌ها
- ✅ **Capability Checks**: بررسی مجوزهای کاربر
- ✅ **File Upload Security**: محدودیت نوع و حجم فایل

### بهترین شیوه‌ها
```php
// همیشه ورودی را sanitize کنید
$title = sanitize_text_field($_POST['title']);

// خروجی را escape کنید
echo esc_html($title);

// مجوزها را بررسی کنید
if (!current_user_can('edit_posts')) {
    wp_die('دسترسی مجاز نیست');
}

// nonce را تأیید کنید
if (!wp_verify_nonce($_POST['nonce'], 'action_name')) {
    wp_die('درخواست نامعتبر');
}
```

### گزارش مشکلات امنیتی
مشکلات امنیتی را به صورت خصوصی گزارش دهید:
- ایمیل: security@arshline.com
- PGP Key: [لینک کلید عمومی]

---

## تست

### اجرای تست‌ها
```bash
# تمام تست‌ها
composer test

# تست‌های واحد
./vendor/bin/phpunit tests/Unit/

# تست‌های یکپارچه
./vendor/bin/phpunit tests/Integration/

# تست با پوشش کد
./vendor/bin/phpunit --coverage-html coverage/
```

### نوشتن تست جدید
```php
<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Modules\Forms\Form;

class FormTest extends TestCase
{
    public function testFormCreation()
    {
        $data = [
            'schema_version' => '1.0.0',
            'owner_id' => 1,
            'status' => 'draft',
            'meta' => ['title' => 'تست']
        ];
        
        $form = new Form($data);
        
        $this->assertEquals('تست', $form->meta['title']);
        $this->assertEquals('draft', $form->status);
    }
}
```

---

## عیب‌یابی

### فعال‌سازی حالت دیباگ
```php
// در wp-config.php
define('ARSHLINE_DEBUG', true);

// در JavaScript Console
localStorage.setItem('arshDebug', '1');
```

### لاگ‌های مفید
```php
// لاگ سفارشی
error_log('[ARSHLINE] پیام دیباگ: ' . print_r($data, true));

// استفاده از WP_DEBUG
if (WP_DEBUG) {
    error_log('ARSHLINE DEBUG: ' . $message);
}
```

### مشکلات رایج

#### فرم ذخیره نمی‌شود
- بررسی مجوزهای کاربر
- بررسی nonce
- بررسی لاگ‌های خطا

#### داشبورد بارگذاری نمی‌شود
- بررسی JavaScript errors در Console
- بررسی تداخل با افزونه‌های دیگر
- بررسی تنظیمات cache

#### آپلود فایل کار نمی‌کند
- بررسی مجوزهای پوشه uploads
- بررسی محدودیت‌های PHP (upload_max_filesize)
- بررسی نوع فایل مجاز

---

## مشارکت

### راهنمای مشارکت
1. Fork کردن پروژه
2. ایجاد branch جدید (`git checkout -b feature/amazing-feature`)
3. Commit تغییرات (`git commit -m 'Add amazing feature'`)
4. Push به branch (`git push origin feature/amazing-feature`)
5. ایجاد Pull Request

### استانداردهای کد
- PSR-4 برای autoloading
- PSR-12 برای code style
- DocBlocks کامل برای تمام methods
- تست‌های واحد برای کدهای جدید

### گزارش باگ
برای گزارش باگ، موارد زیر را ارائه دهید:
- نسخه افزونه
- نسخه وردپرس و PHP
- مراحل بازتولید مشکل
- پیام‌های خطا
- اسکرین‌شات (در صورت نیاز)

---

## لایسنس
این پروژه تحت لایسنس GPL v2 منتشر شده است.

## تماس
- وب‌سایت: https://arshline.com
- ایمیل: info@arshline.com
- GitHub: https://github.com/drkerami/arshline

---

*آخرین به‌روزرسانی: 2025-01-01*
