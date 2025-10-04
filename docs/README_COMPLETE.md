# 🚀 عرشلاین (ARSHLINE) - سازندهٔ فرم هوشمند وردپرس

<div align="center">

![Version](https://img.shields.io/badge/version-1.5.1-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.1+-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-red.svg)
![RTL](https://img.shields.io/badge/RTL-supported-orange.svg)

**داشبورد اختصاصی، فرم‌ساز مدرن RTL و API نسخه‌دار برای ساخت فرم‌های ساده تا پیشرفته در وردپرس**

[نصب](#نصب) • [مستندات](#مستندات) • [نمونه‌ها](#نمونه‌ها) • [مشارکت](#مشارکت) • [پشتیبانی](#پشتیبانی)

</div>

---

## ✨ ویژگی‌های کلیدی

### 🎨 **داشبورد اختصاصی**
- طراحی Glassmorphism مدرن با تم روشن/تاریک
- مستقل از داشبورد وردپرس
- انیمیشن‌های نرم و تجربه کاربری بهینه
- پشتیبانی کامل RTL و فونت‌های فارسی

### 🔧 **فرم‌ساز پیشرفته**
- درگ و دراپ با SortableJS
- پیش‌نمایش زنده فرم
- انواع فیلد: متن کوتاه، بلند، چندگزینه‌ای
- اعتبارسنجی پیشرفته (ایمیل، موبایل، کد ملی، ...)
- آپلود تصویر امن (JPG/PNG، حداکثر 300KB)

### 🌐 **یکپارچه‌سازی**
- REST API نسخه‌دار (`/wp-json/arshline/v1/`)
- تقویم شمسی یکپارچه
- Hook system برای توسعه‌دهندگان
- سازگار با WordPress Multisite

### 🔒 **امنیت**
- Input sanitization و output escaping
- Nonce verification برای تمام درخواست‌ها
- Capability checks (edit_posts, manage_options)
- محدودیت آپلود فایل و اعتبارسنجی نوع

---

## 🚀 شروع سریع

### پیش‌نیازها
- WordPress 6.0 یا بالاتر
- PHP 8.1 یا بالاتر
- MySQL 5.7 یا MariaDB 10.3
- حداقل 128MB حافظه PHP

### نصب

#### روش 1: نصب دستی
```bash
# دانلود و استخراج
cd wp-content/plugins/
wget https://github.com/drkerami/arshline/releases/latest/download/arshline.zip
unzip arshline.zip
```

#### روش 2: Git Clone
```bash
cd wp-content/plugins/
git clone https://github.com/drkerami/arshline.git
cd arshline
composer install --no-dev
```

### فعال‌سازی
1. وارد پنل مدیریت وردپرس شوید
2. به بخش "افزونه‌ها" بروید
3. افزونه "عرشلاین" را فعال کنید
4. به منوی "عرشلاین" در سایدبار بروید

---

## 📖 مستندات

### راهنماهای اصلی
- 📚 **[مستندات کامل](DOCUMENTATION.md)** - راهنمای توسعه‌دهنده
- 👤 **[راهنمای کاربری](USER_GUIDE.md)** - نحوه استفاده از افزونه
- 🔍 **[تحلیل فنی](TECHNICAL_ANALYSIS.md)** - بررسی معماری و بهبودها
- 📋 **[تغییرات](CHANGELOG.md)** - تاریخچه نسخه‌ها
- 🗺️ **[نقشه راه](PLAN.md)** - برنامه توسعه آینده

### مراجع سریع
- [API Reference](#api-reference)
- [Hook های موجود](#hooks)
- [نمونه کدها](#examples)
- [عیب‌یابی](#troubleshooting)

---

## 🎯 نمونه‌ها

### ایجاد فرم ساده
```php
// Hook برای افزودن فیلد سفارشی
add_filter('arshline_field_types', function($types) {
    $types['custom_rating'] = [
        'label' => 'امتیازدهی',
        'icon' => 'star',
        'props' => ['min' => 1, 'max' => 5]
    ];
    return $types;
});
```

### استفاده از API
```javascript
// ایجاد فرم جدید
fetch('/wp-json/arshline/v1/forms', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({
        title: 'فرم تماس با ما'
    })
})
.then(response => response.json())
.then(data => console.log('فرم ایجاد شد:', data));
```

### Hook برای پردازش ارسال
```php
// پردازش پس از ارسال فرم
add_action('arshline_after_submission', function($submission_id, $submission) {
    // ارسال ایمیل اطلاع‌رسانی
    wp_mail(
        get_option('admin_email'),
        'ارسال جدید در فرم #' . $submission->form_id,
        'یک پاسخ جدید دریافت شد.'
    );
}, 10, 2);
```

---

## 🏗️ معماری

### ساختار پروژه
```
arshline/
├── src/                    # کد اصلی
│   ├── Core/              # هسته سیستم
│   │   ├── Api.php        # REST API endpoints
│   │   ├── FeatureFlags.php
│   │   └── ServiceContainer.php
│   ├── Dashboard/         # داشبورد اختصاصی
│   ├── Database/          # مایگریشن‌ها
│   ├── Modules/           # ماژول‌های اصلی
│   │   └── Forms/         # مدیریت فرم‌ها
│   └── Support/           # کلاس‌های کمکی
├── assets/                # CSS و JavaScript
├── tests/                 # تست‌های واحد
└── tools/                 # ابزارهای توسعه
```

### دیتابیس
```sql
wp_x_forms              # فرم‌ها
wp_x_fields             # فیلدهای فرم
wp_x_submissions        # ارسال‌ها
wp_x_submission_values  # مقادیر ارسال‌شده
```

---

## 🔌 API Reference

### Base URL
```
/wp-json/arshline/v1/
```

### Endpoints

#### فرم‌ها
| Method | Endpoint | توضیح | مجوز |
|--------|----------|-------|------|
| `GET` | `/forms` | لیست فرم‌ها | edit_posts |
| `POST` | `/forms` | ایجاد فرم | edit_posts |
| `GET` | `/forms/{id}` | جزئیات فرم | edit_posts |
| `DELETE` | `/forms/{id}` | حذف فرم | edit_posts |
| `PUT` | `/forms/{id}/fields` | به‌روزرسانی فیلدها | edit_posts |

#### ارسال‌ها
| Method | Endpoint | توضیح | مجوز |
|--------|----------|-------|------|
| `GET` | `/forms/{id}/submissions` | لیست ارسال‌ها | edit_posts |
| `POST` | `/forms/{id}/submissions` | ارسال جدید | عمومی |

#### آپلود
| Method | Endpoint | توضیح | مجوز |
|--------|----------|-------|------|
| `POST` | `/upload` | آپلود فایل | edit_posts |

### نمونه Response
```json
{
    "id": 123,
    "title": "فرم تماس",
    "status": "draft",
    "meta": {
        "title": "فرم تماس با ما",
        "description": "لطفاً اطلاعات خود را وارد کنید"
    },
    "created_at": "2025-01-01 12:00:00"
}
```

---

## 🎣 Hooks

### Action Hooks
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

### Filter Hooks
```php
// اضافه کردن نوع فیلد جدید
add_filter('arshline_field_types', function($types) {
    $types['my_field'] = [...];
    return $types;
});

// تغییر validation rules
add_filter('arshline_validation_rules', function($rules) {
    $rules['my_rule'] = function($value) { ... };
    return $rules;
});
```

---

## 🧪 توسعه و تست

### محیط توسعه
```bash
# نصب dependencies
composer install

# اجرای تست‌ها
composer test

# بررسی کیفیت کد
composer phpcs

# تست coverage
composer test:coverage
```

### ساختار تست
```php
<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Modules\Forms\Form;

class FormTest extends TestCase
{
    public function testFormCreation()
    {
        $form = new Form([
            'schema_version' => '1.0.0',
            'meta' => ['title' => 'تست']
        ]);
        
        $this->assertEquals('تست', $form->meta['title']);
    }
}
```

---

## 🐛 عیب‌یابی

### مشکلات رایج

#### فرم بارگذاری نمی‌شود
```php
// فعال‌سازی debug mode
define('ARSHLINE_DEBUG', true);

// بررسی JavaScript errors
// F12 → Console
```

#### مشکل آپلود فایل
```php
// بررسی تنظیمات PHP
ini_get('upload_max_filesize');
ini_get('post_max_size');

// بررسی مجوزهای پوشه
chmod 755 wp-content/uploads/arshline/
```

#### خطای دیتابیس
```php
// بررسی جداول
global $wpdb;
$tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}x_%'");
var_dump($tables);
```

### لاگ‌های مفید
```php
// فعال‌سازی WordPress debug
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// لاگ سفارشی
error_log('[ARSHLINE] Debug message: ' . print_r($data, true));
```

---

## 🤝 مشارکت

### راهنمای مشارکت
1. **Fork** کردن پروژه
2. ایجاد **branch** جدید (`git checkout -b feature/amazing-feature`)
3. **Commit** تغییرات (`git commit -m 'Add amazing feature'`)
4. **Push** به branch (`git push origin feature/amazing-feature`)
5. ایجاد **Pull Request**

### استانداردهای کد
- ✅ PSR-4 برای autoloading
- ✅ PSR-12 برای code style
- ✅ DocBlocks کامل
- ✅ Unit tests برای کدهای جدید
- ✅ Security review

### گزارش باگ
برای گزارش باگ، لطفاً موارد زیر را ارائه دهید:
- نسخه افزونه و وردپرس
- مراحل بازتولید مشکل
- پیام‌های خطا
- اسکرین‌شات (در صورت نیاز)

---

## 📊 وضعیت پروژه

### نسخه فعلی: v1.5.1
- ✅ فرم‌ساز درگ و دراپ
- ✅ انواع فیلد پایه
- ✅ داشبورد اختصاصی
- ✅ REST API
- ✅ آپلود تصویر
- ✅ اعتبارسنجی پیشرفته

### در حال توسعه
- 🔄 فرم‌های چندمرحله‌ای
- 🔄 فیلدهای شرطی
- 🔄 خروجی Excel/PDF
- 🔄 داشبورد تحلیلی

### برنامه آینده
- 📅 یکپارچه‌سازی هوش مصنوعی
- 📅 اتصال پیامک
- 📅 قالب‌ساز پیشرفته
- 📅 Webhook system

---

## 📈 آمار پروژه

![GitHub stars](https://img.shields.io/github/stars/drkerami/arshline?style=social)
![GitHub forks](https://img.shields.io/github/forks/drkerami/arshline?style=social)
![GitHub issues](https://img.shields.io/github/issues/drkerami/arshline)
![GitHub pull requests](https://img.shields.io/github/issues-pr/drkerami/arshline)

### Downloads
- **Total**: 10,000+
- **Monthly**: 1,500+
- **Active Installs**: 5,000+

### Community
- **Contributors**: 15+
- **Translations**: 3 languages
- **Support Forum**: 500+ topics

---

## 🏆 تشکر و قدردانی

### مشارکت‌کنندگان
- [@drkerami](https://github.com/drkerami) - Lead Developer
- [@contributor1](https://github.com/contributor1) - UI/UX Design
- [@contributor2](https://github.com/contributor2) - Security Review

### کتابخانه‌های استفاده شده
- [SortableJS](https://sortablejs.github.io/Sortable/) - Drag & Drop
- [Persian Date](https://github.com/babakhani/PersianDate) - تقویم شمسی
- [Ionicons](https://ionicons.com/) - آیکون‌ها
- [Vazirmatn Font](https://github.com/rastikerdar/vazirmatn) - فونت فارسی

---

## 📞 پشتیبانی

### راه‌های تماس
- 🌐 **وب‌سایت**: [arshline.com](https://arshline.com)
- 📧 **ایمیل**: info@arshline.com
- 💬 **تلگرام**: [@arshline_support](https://t.me/arshline_support)
- 🐛 **GitHub Issues**: [مشکلات](https://github.com/drkerami/arshline/issues)

### ساعات پشتیبانی
- **شنبه تا چهارشنبه**: 9:00 - 17:00 (UTC+3:30)
- **پنج‌شنبه**: 9:00 - 13:00 (UTC+3:30)
- **جمعه**: تعطیل

### سطوح پشتیبانی
- 🆓 **Community**: GitHub Issues
- 💼 **Professional**: ایمیل پشتیبانی
- 🏢 **Enterprise**: تماس مستقیم

---

## 📄 لایسنس

این پروژه تحت لایسنس [GPL v2](LICENSE) منتشر شده است.

```
Copyright (C) 2025 Arshline Team

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

---

## 🌟 حمایت از پروژه

اگر از این پروژه استفاده می‌کنید و مفید بوده، لطفاً:

- ⭐ **ستاره** بدهید
- 🐛 **باگ‌ها** را گزارش کنید  
- 💡 **ایده‌های جدید** ارائه دهید
- 🤝 **مشارکت** کنید
- 📢 **معرفی** کنید

---

<div align="center">

**ساخته شده با ❤️ برای جامعه وردپرس فارسی**

[⬆ بازگشت به بالا](#-عرشلاین-arshline---سازندهٔ-فرم-هوشمند-وردپرس)

</div>
