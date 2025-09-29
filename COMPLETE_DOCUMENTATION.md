# 📋 مستندات کامل افزونه عرشلاین (ARSHLINE) - نسخه ۲.۱.۰ (AI Stable)

## 🎯 خلاصه کلی افزونه

افزونه عرشلاین یک سازنده فرم پیشرفته و هوشمند برای وردپرس است که امکان ایجاد، مدیریت و پردازش فرم‌های پیچیده را با رابط کاربری مدرن و ویژگی‌های هوش مصنوعی فراهم می‌کند.

### 🔧 ویژگی‌های کلیدی
- **داشبورد تمام‌صفحه** با طراحی مدرن و کاربری بی‌نظیر
- **سازنده فرم بصری** با قابلیت کشیدن و رها کردن
- **سیستم هوش مصنوعی** با ترمینال فارسی و اتصال به OpenAI
- **مدیریت کامل فرم‌ها** شامل ایجاد، ویرایش، پیش‌نمایش و گزارش‌گیری
- **REST API کامل** برای تعامل برنامه‌نویسی
- **سیستم تم دو حالته** (روشن/تاریک)
- **پشتیبانی کامل RTL** و فارسی

---

## 🏗️ معماری و ساختار کلی

### 📁 ساختار فایل‌ها
```
arshline/
├── arshline.php                    # فایل اصلی پلاگین
├── assets/
│   ├── css/dashboard.css          # استایل‌های داشبورد
│   └── js/dashboard.js            # جاوااسکریپت‌های داشبورد
├── src/
│   ├── Core/
│   │   ├── Api.php               # مدیریت REST API
│   │   ├── FeatureFlags.php      # مدیریت فیچرهای تجربی
│   │   └── ServiceContainer.php  # Dependency Injection
│   ├── Dashboard/
│   │   ├── Dashboard.php         # کنترلر اصلی داشبورد
│   │   ├── DashboardPageInstaller.php # نصب‌کننده صفحه داشبورد
│   │   ├── dashboard-template.php # قالب HTML داشبورد
│   │   └── submission-view.php   # نمایش جزئیات ارسال‌ها
│   ├── Database/
│   │   └── Migrations.php        # مایگریشن‌های دیتابیس
│   ├── Frontend/
│   │   └── form-template.php     # قالب نمایش عمومی فرم‌ها
│   ├── Modules/
│   │   └── Forms/
│   │       ├── Form.php          # مدل فرم
│   │       ├── FormRepository.php # Repository فرم‌ها
│   │       ├── FormValidator.php  # اعتبارسنجی فرم‌ها
│   │       ├── FieldRepository.php # Repository فیلدها
│   │       ├── Submission.php     # مدل ارسال‌ها
│   │       ├── SubmissionRepository.php # Repository ارسال‌ها
│   │       └── SubmissionValueRepository.php # مقادیر ارسال‌ها
│   │   └── FormsModule.php       # ماژول اصلی فرم‌ها
│   └── Support/
│       └── Helpers.php           # توابع کمکی
└── tools/                        # ابزارهای توسعه
```

---

## 🛠️ تحلیل کلاس‌ها و ماژول‌ها

### 📦 Core Classes

#### `Api.php` - مدیریت REST API
```php
namespace Arshline\Core;

class Api {
    public static function boot()              # راه‌اندازی API
    public static function register_routes()   # ثبت route های REST
    public static function user_can_manage_forms() # بررسی مجوز
    
    // Form Management Endpoints
    public static function get_forms()         # GET /forms - لیست فرم‌ها
    public static function create_form()       # POST /forms - ایجاد فرم جدید
    public static function get_form()          # GET /forms/{id} - جزئیات فرم
    public static function update_fields()     # PUT /forms/{id}/fields - به‌روزرسانی فیلدها
    public static function delete_form()       # DELETE /forms/{id} - حذف فرم
    
    // Submission Management
    public static function get_submissions()   # GET /forms/{id}/submissions - لیست ارسال‌ها
    public static function create_submission() # POST /forms/{id}/submissions - ارسال جدید
    
    // Utility Endpoints
    public static function upload_image()      # POST /upload - آپلود فایل
    public static function generate_token()    # POST /forms/{id}/token - تولید توکن عمومی
    
    // AI Integration
    public static function get_ai_config()     # GET /ai/config - تنظیمات هوش مصنوعی
    public static function update_ai_config()  # PUT /ai/config - به‌روزرسانی تنظیمات AI
    public static function test_ai_connection() # POST /ai/test - تست اتصال AI
    public static function ai_agent()          # POST /ai/agent - ترمینال هوش مصنوعی
    public static function get_ai_capabilities() # GET /ai/capabilities - قابلیت‌های AI
}
```

#### `Dashboard.php` - کنترلر داشبورد
```php
namespace Arshline\Dashboard;

class Dashboard {
    const VERSION = '2.1.0';
    
    public static function boot()              # راه‌اندازی داشبورد
    public static function enqueue_scripts()   # بارگذاری اسکریپت‌ها
    public static function localize_data()     # ارسال داده‌ها به جاوااسکریپت
}
```

#### `DashboardPageInstaller.php` - نصب‌کننده صفحه داشبورد
```php
namespace Arshline\Dashboard;

class DashboardPageInstaller {
    public static function install_dashboard_page()  # ایجاد صفحه داشبورد
    public static function ensure_front_page()       # تنظیم صفحه اصلی
    public static function register_shortcode()      # ثبت شورت‌کد
}
```

### 📋 Form Management Classes

#### `Form.php` - مدل فرم
```php
namespace Arshline\Modules\Forms;

class Form {
    public int $id;                    # شناسه یکتا
    public string $schema_version;     # نسخه اسکیما
    public int $owner_id;             # شناسه مالک
    public string $status;            # وضعیت (draft/published/archived)
    public ?string $public_token;     # توکن عمومی
    public array $meta;               # اطلاعات متا (عنوان، توضیحات، تنظیمات)
    public string $created_at;        # زمان ایجاد
    public string $updated_at;        # زمان آخرین به‌روزرسانی
    
    public function __construct(array $data)
}
```

#### `FormRepository.php` - Repository فرم‌ها
```php
namespace Arshline\Modules\Forms;

class FormRepository {
    public static function save(Form $form): int          # ذخیره فرم
    public static function find(int $id): ?Form          # یافتن فرم با شناسه
    public static function findByToken(string $token): ?Form # یافتن با توکن
    public static function delete(int $id): bool         # حذف فرم
    public static function list(array $filters = []): array # لیست فرم‌ها با فیلتر
    public static function exists(int $id): bool         # بررسی وجود فرم
    public static function updateStatus(int $id, string $status): bool # به‌روزرسانی وضعیت
    public static function generateUniqueToken(): string # تولید توکن یکتا
    public static function setPublicToken(int $id, string $token): bool # تنظیم توکن عمومی
}
```

#### `FormValidator.php` - اعتبارسنجی فرم‌ها
```php
namespace Arshline\Modules\Forms;

class FormValidator {
    public static function validate(Form $form): array    # اعتبارسنجی کامل فرم
    protected static function validateMeta(array $meta): array # اعتبارسنجی متا
    protected static function validateFields(array $fields): array # اعتبارسنجی فیلدها
    protected static function validateFieldProps(array $props, string $type): array # اعتبارسنجی خصوصیات فیلد
}
```

#### `FieldRepository.php` - Repository فیلدها
```php
namespace Arshline\Modules\Forms;

class FieldRepository {
    public static function listByForm(int $formId): array # لیست فیلدهای فرم
    public static function save(int $formId, array $fields): bool # ذخیره فیلدها
    public static function deleteByForm(int $formId): bool # حذف تمام فیلدهای فرم
    public static function reorder(int $formId, array $fieldIds): bool # مرتب‌سازی فیلدها
}
```

### 📨 Submission Management Classes

#### `Submission.php` - مدل ارسال‌ها
```php
namespace Arshline\Modules\Forms;

class Submission {
    public int $id;                   # شناسه یکتا
    public int $form_id;             # شناسه فرم
    public int $user_id;             # شناسه کاربر (0 برای ناشناس)
    public string $ip;               # آدرس IP ارسال‌کننده
    public string $status;           # وضعیت (pending/approved/spam/trash)
    public array $meta;              # اطلاعات متا
    public array $values;            # مقادیر فیلدها
    public string $created_at;       # زمان ایجاد
    
    public function __construct(array $data)
}
```

#### `SubmissionRepository.php` - Repository ارسال‌ها
```php
namespace Arshline\Modules\Forms;

class SubmissionRepository {
    public static function save(Submission $submission): int # ذخیره ارسال
    public static function find(int $id): ?Submission    # یافتن ارسال
    public static function listByForm(int $formId, array $options = []): array # لیست ارسال‌های فرم
    public static function delete(int $id): bool         # حذف ارسال
    public static function updateStatus(int $id, string $status): bool # به‌روزرسانی وضعیت
    public static function getStats(int $formId): array  # آمار ارسال‌ها
    public static function export(int $formId, string $format = 'array'): mixed # صادرات داده‌ها
}
```

#### `SubmissionValueRepository.php` - Repository مقادیر ارسال‌ها
```php
namespace Arshline\Modules\Forms;

class SubmissionValueRepository {
    public static function save(int $submissionId, int $fieldId, string $value, int $idx = 0): bool
    public static function getBySubmission(int $submissionId): array
    public static function deleteBySubmission(int $submissionId): bool
    public static function bulkInsert(int $submissionId, array $values): bool
}
```

### 🛠️ Support Classes

#### `Helpers.php` - توابع کمکی
```php
namespace Arshline\Support;

class Helpers {
    public static function tableName(string $table): string # نام کامل جدول
    public static function sanitizeFieldType(string $type): string # پاکسازی نوع فیلد
    public static function validateEmail(string $email): bool # اعتبارسنجی ایمیل
    public static function formatDate(string $date, string $format = 'Y-m-d H:i:s'): string # فرمت تاریخ
    public static function generateRandomToken(int $length = 16): string # تولید توکن تصادفی
    public static function parseUserAgent(): array          # تجزیه User Agent
    public static function getClientIP(): string           # دریافت IP کلاینت
    public static function isValidIPAddress(string $ip): bool # اعتبارسنجی IP
    public static function escapeHtml(string $text): string # Escape HTML
    public static function truncateText(string $text, int $length = 100): string # کوتاه‌سازی متن
}
```

---

## 🌐 REST API مستندات کامل

### 🎯 Base URL
```
/wp-json/arshline/v1/
```

### 🔐 Authentication
تمام endpoint های مدیریتی نیاز به یکی از این مجوزها دارند:
- `manage_options` (مدیر سایت)
- `edit_posts` (ویرایشگر)

### 📋 Form Management Endpoints

#### `GET /forms` - لیست فرم‌ها
```http
GET /wp-json/arshline/v1/forms
Authorization: WordPress Nonce
```
**پاسخ:**
```json
[
    {
        "id": 1,
        "title": "فرم تماس",
        "status": "published",
        "created_at": "2024-01-01 10:00:00"
    }
]
```

#### `POST /forms` - ایجاد فرم جدید
```http
POST /wp-json/arshline/v1/forms
Content-Type: application/json
Authorization: WordPress Nonce

{
    "title": "فرم جدید"
}
```
**پاسخ:**
```json
{
    "id": 2,
    "title": "فرم جدید",
    "status": "draft"
}
```

#### `GET /forms/{id}` - جزئیات فرم
```http
GET /wp-json/arshline/v1/forms/1
Authorization: WordPress Nonce
```
**پاسخ:**
```json
{
    "id": 1,
    "status": "published",
    "meta": {
        "title": "فرم تماس",
        "description": "توضیحات فرم"
    },
    "fields": [
        {
            "id": 1,
            "type": "short_text",
            "label": "نام",
            "required": true,
            "sort": 0
        }
    ]
}
```

#### `PUT /forms/{id}/fields` - به‌روزرسانی فیلدها
```http
PUT /wp-json/arshline/v1/forms/1/fields
Content-Type: application/json
Authorization: WordPress Nonce

{
    "fields": [
        {
            "type": "short_text",
            "label": "نام کامل",
            "required": true,
            "props": {
                "placeholder": "نام خود را وارد کنید"
            }
        }
    ]
}
```

#### `DELETE /forms/{id}` - حذف فرم
```http
DELETE /wp-json/arshline/v1/forms/1
Authorization: WordPress Nonce
```

### 📨 Submission Endpoints

#### `GET /forms/{id}/submissions` - لیست ارسال‌ها
```http
GET /wp-json/arshline/v1/forms/1/submissions?page=1&per_page=20&include=values,fields
Authorization: WordPress Nonce
```

**پارامترهای Query:**
- `page`: شماره صفحه (پیش‌فرض: 1)
- `per_page`: تعداد در هر صفحه (پیش‌فرض: 20، حداکثر: 100)
- `include`: شامل کردن داده‌های اضافی (`values`, `fields`, `user`)
- `status`: فیلتر بر اساس وضعیت
- `from_date`: فیلتر از تاریخ مشخص
- `to_date`: فیلتر تا تاریخ مشخص
- `field_id`: فیلتر بر اساس فیلد مشخص
- `field_value`: مقدار فیلد برای فیلتر
- `field_operator`: عملگر فیلتر (`like`, `equals`, `not_equals`)
- `format`: فرمت خروجی (`json`, `csv`, `excel`)

#### `POST /forms/{id}/submissions` - ارسال جدید (عمومی)
```http
POST /wp-json/arshline/v1/forms/1/submissions
Content-Type: application/json

{
    "values": [
        {
            "field_id": 1,
            "value": "علی احمدی"
        }
    ]
}
```

### 🖼️ Upload Endpoint

#### `POST /upload` - آپلود فایل
```http
POST /wp-json/arshline/v1/upload
Authorization: WordPress Nonce
Content-Type: multipart/form-data

file: [فایل]
```

### 🤖 AI Integration Endpoints

#### `GET /ai/config` - تنظیمات هوش مصنوعی
```http
GET /wp-json/arshline/v1/ai/config
Authorization: WordPress Nonce
```

#### `PUT /ai/config` - به‌روزرسانی تنظیمات AI
```http
PUT /wp-json/arshline/v1/ai/config
Content-Type: application/json
Authorization: WordPress Nonce

{
    "base_url": "https://api.openai.com/v1",
    "api_key": "sk-...",
    "model": "gpt-4o-mini"
}
```

#### `POST /ai/test` - تست اتصال AI
```http
POST /wp-json/arshline/v1/ai/test
Authorization: WordPress Nonce
```

#### `POST /ai/agent` - ترمینال هوش مصنوعی
```http
POST /wp-json/arshline/v1/ai/agent
Content-Type: application/json
Authorization: WordPress Nonce

{
    "command": "لیست فرم ها"
}
```

#### `GET /ai/capabilities` - قابلیت‌های AI
```http
GET /wp-json/arshline/v1/ai/capabilities
Authorization: WordPress Nonce
```

---

## 🎨 سیستم داشبورد - تحلیل کامل

### 🖥️ ساختار کلی رابط کاربری

#### Layout اصلی
```html
<div class="arshline-dashboard-root">
    <aside class="arshline-sidebar">        <!-- سایدبار منو -->
    <main class="arshline-main">            <!-- محتوای اصلی -->
        <div class="arshline-header">       <!-- هدر بالایی -->
        <div id="arshlineDashboardContent"> <!-- محتوای تب‌ها -->
</div>
```

### 📱 منوی سایدبار

#### 🎯 تب‌های اصلی:

1. **داشبورد** (`dashboard`)
   - نمای کلی و آمار
   - کارت‌های ویژگی
   - خوش‌آمدگویی و راهنمایی سریع

2. **فرم‌ها** (`forms`)
   - لیست تمام فرم‌ها
   - ایجاد فرم جدید (اینلاین)
   - عملیات: ساخت، ویرایش، پیش‌نمایش، گزارش، حذف
   - فیلترهای جستجو و تاریخ

3. **گزارشات** (`reports`)
   - نمایش ارسال‌ها
   - فیلترها و صادرات
   - جزئیات کامل هر ارسال

4. **کاربران** (`users`)
   - مدیریت کاربران
   - آمار فعالیت

5. **تنظیمات** (`settings`)
   - پیکربندی عمومی افزونه
   - تنظیمات هوش مصنوعی
   - تنظیمات امنیتی و عملکرد

#### ✨ ویژگی‌های سایدبار:
- **قابلیت جمع/باز شدن** با دکمه toggle
- **نمایش/مخفی کردن برچسب‌ها** در حالت جمع شده
- **ذخیره وضعیت** در localStorage
- **پشتیبانی کامل کیبورد** (Tab, Enter, Space)
- **نمایش آیکون‌های SVG** برای هر بخش

### 🎛️ سیستم تب‌ها و Routing

#### Hash-based Routing:
```javascript
// مسیرهای موجود:
#dashboard           // صفحه اصلی
#forms              // لیست فرم‌ها
#builder/123        // سازنده فرم با ID=123
#editor/123         // ویرایشگر فرم
#preview/123        // پیش‌نمایش فرم
#results/123        // نتایج فرم
#reports            // گزارشات عمومی
#users              // مدیریت کاربران
#settings           // تنظیمات
```

#### State Management:
- **localStorage**: ذخیره آخرین تب بازدید شده
- **sessionStorage**: حفظ وضعیت ترمینال AI
- **URL Hash**: پشتیبانی مرورگر (back/forward)

### 🎨 سیستم تم و ظاهر

#### تم دوتایی (Light/Dark):
```css
:root {
    /* Light Mode */
    --primary: #1e40af;
    --bg-surface: #f5f7fb;
    --surface: #ffffff;
    --text: #0b1220;
}

body.dark {
    /* Dark Mode */
    --bg-surface: #0c111f;
    --surface: #0d1321;
    --text: #e5e7eb;
}
```

#### ویژگی‌های بصری:
- **Glassmorphism**: شیشه‌ای بودن المان‌ها
- **انیمیشن‌های Smooth**: تبدیل نرم بین حالت‌ها
- **Responsive Design**: سازگار با تمام سایزها
- **RTL Support**: پشتیبانی کامل راست به چپ
- **فونت Vazirmatn**: طراحی زیبای فارسی

### 🔧 سازنده فرم (Form Builder)

#### ساختار کلی:
```html
<div id="arBuilder">
    <div class="ar-tabs">              <!-- تب‌های سازنده -->
    <div class="ar-sections">
        <div id="arFormFieldsList">    <!-- لیست فیلدها -->
        <div id="arDesignPanel">       <!-- پنل طراحی -->
        <div id="arSettingsPanel">     <!-- تنظیمات فرم -->
        <div id="arSharePanel">        <!-- اشتراک‌گذاری -->
        <div id="arReportsPanel">      <!-- گزارش‌ها -->
```

#### تب‌های سازنده:

1. **سازنده** (`builder`)
   - لیست فیلدهای فعلی
   - افزودن فیلد جدید
   - ویرایش inline فیلدها
   - تغییر ترتیب (drag & drop)

2. **طراحی** (`design`)
   - انتخاب قالب
   - تنظیمات رنگ و فونت
   - CSS سفارشی

3. **تنظیمات** (`settings`)
   - تنظیمات عمومی فرم
   - پیام‌های خوش‌آمد و تشکر
   - تنظیمات امنیتی (Captcha, Honeypot)

4. **اشتراک** (`share`)
   - لینک عمومی فرم
   - کد Embed
   - QR Code

5. **گزارش‌ها** (`reports`)
   - آمار کلی ارسال‌ها
   - لیست ارسال‌ها
   - صادرات داده‌ها

#### انواع فیلدهای پشتیبانی شده:

1. **متنی**:
   - `short_text`: متن کوتاه
   - `long_text`: متن طولانی (textarea)
   - `email`: ایمیل
   - `tel`: تلفن
   - `url`: آدرس وب

2. **انتخابی**:
   - `select`: لیست کشویی
   - `radio`: دکمه رادیویی
   - `checkbox`: چک‌باکس
   - `multi_select`: انتخاب چندگانه

3. **عددی و تاریخ**:
   - `number`: عدد
   - `range`: محدوده
   - `date`: تاریخ
   - `time`: زمان

4. **فایل و رسانه**:
   - `file`: آپلود فایل
   - `image`: آپلود تصویر

5. **ویژه**:
   - `rating`: امتیازدهی
   - `signature`: امضای دیجیتال
   - `section_break`: جداکننده بخش
   - `page_break`: جداکننده صفحه

### 📊 سیستم گزارشات (Reports)

#### نمایش ارسال‌ها:
- **جدول پاژینیت شده**: نمایش داده‌ها به صورت صفحه‌ای
- **فیلترهای پیشرفته**: بر اساس تاریخ، فیلد، وضعیت
- **جستجو در محتوا**: جستجو در تمام فیلدها
- **مرتب‌سازی**: بر اساس هر ستون

#### صادرات داده‌ها:
- **فرمت CSV**: برای Excel و Google Sheets
- **فرمت Excel**: فایل .xlsx
- **Print View**: نمایش قابل چاپ

#### آمار و تجزیه‌تحلیل:
- **تعداد کل ارسال‌ها**
- **آمار بر اساس تاریخ**
- **نرخ تبدیل**
- **آمار وضعیت‌ها** (تایید شده، در انتظار، اسپم)

---

## 🤖 سیستم هوش مصنوعی - مستندات کامل

### 🎯 ترمینال شناور AI

#### طراحی و قابلیت‌ها:
- **دکمه شناور (FAB)**: "هوش مصنوعی ▷" در گوشه صفحه
- **پنل کامل**: textarea برای دستورات + خروجی
- **کیبورد shortcut**: Ctrl+Enter برای اجرا
- **حفظ تاریخچه**: تا 20 دستور آخر در sessionStorage
- **پشتیبانی فارسی کامل**

#### دستورات پشتیبانی شده:

1. **مدیریت فرم‌ها**:
   ```
   لیست فرم ها
   ایجاد فرم با عنوان فرم جدید
   حذف فرم 5
   نمایش فرم 3
   ```

2. **ناوبری**:
   ```
   برو به تب فرم ها
   باز کردن سازنده فرم 2
   نمایش تنظیمات
   ```

3. **کمک و راهنمایی**:
   ```
   کمک
   قابلیت ها
   راهنما
   ```

4. **کنترل رابط**:
   ```
   تغییر تم
   حالت تاریک
   ```

#### جریان‌های تعامل:

1. **جریان تأیید**:
   ```json
   {
       "action": "confirm",
       "message": "آیا مطمئنید که می‌خواهید فرم را حذف کنید؟",
       "confirm_action": {
           "action": "delete_form",
           "params": {"id": 5}
       }
   }
   ```

2. **جریان توضیح**:
   ```json
   {
       "action": "clarify",
       "kind": "options",
       "message": "کدام فرم را می‌خواهید ویرایش کنید؟",
       "options": [
           {"label": "فرم تماس", "value": "1"},
           {"label": "فرم ثبت‌نام", "value": "2"}
       ]
   }
   ```

3. **جریان کمک**:
   ```json
   {
       "action": "help",
       "capabilities": [
           "مدیریت فرم‌ها",
           "ناوبری در داشبورد",
           "تغییر تنظیمات"
       ]
   }
   ```

### ⚙️ تنظیمات هوش مصنوعی

#### پیکربندی اتصال:
- **Base URL**: آدرس API (پیش‌فرض: OpenAI)
- **API Key**: کلید احراز هویت
- **Model**: انتخاب مدل (`gpt-4o-mini`, `gpt-4`)
- **دکمه تست اتصال**: بررسی فوری عملکرد

#### امنیت:
- **عدم نمایش API Key**: برای امنیت کلید نمایش داده نمی‌شود
- **Nonce Protection**: محافظت در برابر CSRF
- **Permission Check**: فقط مدیران دسترسی دارند

---

## 🗃️ ساختار دیتابیس

### 📋 جداول اصلی

#### `wp_x_forms` - جدول فرم‌ها
```sql
CREATE TABLE wp_x_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schema_version VARCHAR(20) NOT NULL,           -- نسخه اسکیما
    owner_id BIGINT UNSIGNED,                      -- مالک فرم
    status VARCHAR(20) DEFAULT 'draft',            -- وضعیت فرم
    public_token VARCHAR(24) NULL,                 -- توکن عمومی
    meta JSON NULL,                                -- متادیتا (عنوان، توضیحات، تنظیمات)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY public_token_unique (public_token)
) ENGINE=InnoDB;
```

#### `wp_x_fields` - جدول فیلدها
```sql
CREATE TABLE wp_x_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,              -- شناسه فرم
    sort INT UNSIGNED DEFAULT 0,                   -- ترتیب نمایش
    props JSON NOT NULL,                           -- خصوصیات فیلد (نوع، برچسب، اجباری بودن، ...)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES wp_x_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

#### `wp_x_submissions` - جدول ارسال‌ها
```sql
CREATE TABLE wp_x_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,              -- شناسه فرم
    user_id BIGINT UNSIGNED NULL,                  -- شناسه کاربر (NULL برای ناشناس)
    ip VARCHAR(45) NULL,                          -- آدرس IP
    status VARCHAR(20) DEFAULT 'pending',          -- وضعیت ارسال
    meta JSON NULL,                               -- اطلاعات اضافی
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES wp_x_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

#### `wp_x_submission_values` - جدول مقادیر ارسال‌ها
```sql
CREATE TABLE wp_x_submission_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,        -- شناسه ارسال
    field_id BIGINT UNSIGNED NOT NULL,             -- شناسه فیلد
    value TEXT,                                   -- مقدار ارسال شده
    idx INT UNSIGNED DEFAULT 0,                   -- ترتیب (برای فیلدهای چندگانه)
    FOREIGN KEY (submission_id) REFERENCES wp_x_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES wp_x_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 🔗 روابط دیتابیسی

```
wp_x_forms (1) -----> (*) wp_x_fields
    |
    v
wp_x_submissions (1) -----> (*) wp_x_submission_values
    |                              |
    v                              v  
wp_users                       wp_x_fields
```

### 📊 نمونه داده‌ها

#### فرم نمونه:
```json
{
    "id": 1,
    "schema_version": "1.0.0",
    "owner_id": 1,
    "status": "published",
    "public_token": "ABC123XYZ789",
    "meta": {
        "title": "فرم تماس با ما",
        "description": "از طریق این فرم با ما در ارتباط باشید",
        "welcome_message": "خوش آمدید",
        "thankyou_message": "از شما متشکریم",
        "anti_spam_honeypot": true,
        "captcha_enabled": false,
        "email_notifications": true,
        "admin_email": "admin@example.com"
    }
}
```

#### فیلد نمونه:
```json
{
    "id": 1,
    "form_id": 1,
    "sort": 0,
    "props": {
        "type": "short_text",
        "label": "نام کامل",
        "placeholder": "نام خود را وارد کنید",
        "required": true,
        "validation": "fa_letters",
        "help_text": "نام و نام خانوادگی خود را به فارسی وارد کنید"
    }
}
```

#### ارسال نمونه:
```json
{
    "id": 1,
    "form_id": 1,
    "user_id": 0,
    "ip": "192.168.1.100",
    "status": "pending",
    "meta": {
        "user_agent": "Mozilla/5.0...",
        "referrer": "https://example.com/contact",
        "submission_time": "2024-01-01 10:30:00"
    },
    "values": [
        {
            "field_id": 1,
            "value": "علی احمدی",
            "idx": 0
        }
    ]
}
```

---

## 🎯 سیستم فرم‌ها - عملکرد کامل

### 🔧 مراحل ایجاد فرم

1. **ایجاد فرم خام**:
   ```javascript
   // از تب فرم‌ها - دکمه "ایجاد فرم جدید"
   fetch('/wp-json/arshline/v1/forms', {
       method: 'POST',
       body: JSON.stringify({ title: 'عنوان فرم' })
   })
   ```

2. **ورود به سازنده**:
   ```
   #builder/{form_id}
   ```

3. **افزودن فیلدها**:
   - انتخاب نوع فیلد از منوی کشویی
   - تنظیم برچسب، placeholder، اجباری بودن
   - ذخیره فیلدها با PUT /forms/{id}/fields

4. **تنظیمات فرم**:
   - پیام خوش‌آمد و تشکر
   - تنظیمات امنیتی
   - اعلان‌های ایمیل

5. **انتشار فرم**:
   - تولید توکن عمومی
   - دریافت لینک اشتراک‌گذاری

### 🎨 نمایش عمومی فرم

#### URL Patterns:
```
?arshline_form=123          # با شناسه فرم
?arshline=ABC123XYZ789      # با توکن عمومی
```

#### رندر کردن فیلدها:
```javascript
// انواع فیلدها و نحوه نمایش
function renderField(field) {
    switch(field.type) {
        case 'short_text':
            return `<input type="text" name="field_${field.id}" 
                           placeholder="${field.placeholder}" 
                           ${field.required ? 'required' : ''}>`
        
        case 'long_text':
            return `<textarea name="field_${field.id}" 
                             placeholder="${field.placeholder}"
                             ${field.required ? 'required' : ''}></textarea>`
        
        case 'select':
            return `<select name="field_${field.id}">
                        ${field.options.map(opt => 
                            `<option value="${opt.value}">${opt.label}</option>`
                        ).join('')}
                    </select>`
        
        // ... سایر انواع فیلد
    }
}
```

#### اعتبارسنجی کلاینت:
```javascript
// ماسک‌های ورودی
function applyInputMask(input, format) {
    switch(format) {
        case 'mobile_ir':
            // فقط اعداد، شروع با 09
            input.value = input.value.replace(/[^\d]/g, '')
            if (input.value.startsWith('9')) input.value = '0' + input.value
            break
            
        case 'email':
            // اعتبارسنجی ایمیل
            if (!/^\S+@\S+\.\S+$/.test(input.value)) {
                input.setCustomValidity('ایمیل نامعتبر است')
            }
            break
            
        case 'national_id_ir':
            // اعتبارسنجی کد ملی ایرانی
            input.value = input.value.replace(/\D/g, '').slice(0, 10)
            break
    }
}
```

### 📨 پردازش ارسال‌ها

#### مراحل ارسال:
1. **جمع‌آوری داده‌ها** از فرم
2. **اعتبارسنجی کلاینت** (JavaScript)
3. **ارسال به API** (POST /forms/{id}/submissions)
4. **اعتبارسنجی سرور** (PHP)
5. **ذخیره در دیتابیس**
6. **ارسال اعلان‌ها** (اختیاری)

#### کد ارسال:
```javascript
// جمع‌آوری مقادیر فرم
const values = [];
form.querySelectorAll('[data-field-id]').forEach(input => {
    values.push({
        field_id: parseInt(input.dataset.fieldId),
        value: input.value
    });
});

// ارسال به API
fetch(`/wp-json/arshline/v1/forms/${formId}/submissions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ values })
})
.then(response => response.json())
.then(data => {
    // نمایش پیام تشکر
    showThankYouMessage();
});
```

---

## 🎨 طراحی و Theme System

### 🌓 سیستم دو تمه

#### متغیرهای CSS:
```css
:root {
    /* Cyberpunk Light */
    --primary: #1e40af;
    --secondary: #0e7490;
    --hot: #a21caf;
    --accent: #047857;
    --text: #0b1220;
    --bg-surface: #f5f7fb;
    --surface: #ffffff;
    --shadow-primary: 0 16px 36px rgba(30,64,175,.28);
}

body.dark {
    /* Cyberpunk Dark */
    --text: #e5e7eb;
    --bg-surface: #0c111f;
    --surface: #0d1321;
    --shadow-primary: 0 18px 42px rgba(30,64,175,.35);
}
```

#### Toggle تم:
```javascript
function toggleTheme() {
    const isDark = document.body.classList.toggle('dark');
    localStorage.setItem('arshline-theme', isDark ? 'dark' : 'light');
}

// بازیابی تم ذخیره شده
const savedTheme = localStorage.getItem('arshline-theme');
if (savedTheme === 'dark') {
    document.body.classList.add('dark');
}
```

### 🎭 انیمیشن‌ها و Transitions

#### ورودی صفحات:
```css
.view {
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.3s ease;
}

.view.active {
    opacity: 1;
    transform: translateY(0);
}
```

#### Hover Effects:
```css
.card {
    transition: all 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-primary);
}
```

### 📱 Responsive Design

#### Breakpoints:
```css
/* Mobile First */
.arshline-dashboard-root {
    flex-direction: column;
}

@media (min-width: 768px) {
    .arshline-dashboard-root {
        flex-direction: row;
    }
    
    .arshline-sidebar {
        width: 280px;
    }
}

@media (max-width: 767px) {
    .arshline-sidebar {
        width: 100%;
        height: auto;
    }
}
```

---

## 🔒 امنیت و Performance

### 🛡️ امنیت

#### احراز هویت:
- **WordPress Nonce**: محافظت CSRF
- **Capability Check**: `manage_options` یا `edit_posts`
- **Sanitization**: پاکسازی تمام ورودی‌ها

#### محافظت در برابر اسپم:
- **Honeypot Field**: فیلد مخفی برای ربات‌ها
- **Rate Limiting**: محدودیت تعداد ارسال
- **IP Tracking**: ردیابی آدرس IP
- **Captcha**: پشتیبانی reCAPTCHA v2/v3

#### کد امنیتی نمونه:
```php
// اعتبارسنجی مجوز
if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
    return new WP_REST_Response(['error' => 'forbidden'], 403);
}

// بررسی nonce
if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'wp_rest')) {
    return new WP_REST_Response(['error' => 'invalid_nonce'], 401);
}

// پاکسازی ورودی
$title = sanitize_text_field($request->get_param('title'));
```

### ⚡ بهینه‌سازی عملکرد

#### Frontend:
- **Code Splitting**: بارگذاری تنها کد مورد نیاز
- **Lazy Loading**: بارگذاری تصاویر به صورت تأخیری
- **CSS Minification**: فشرده‌سازی استایل‌ها
- **CDN Integration**: استفاده از CDN برای منابع خارجی

#### Backend:
- **Query Optimization**: بهینه‌سازی کوئری‌های دیتابیس
- **Caching**: کش کردن نتایج پرهزینه
- **Pagination**: صفحه‌بندی داده‌های زیاد
- **Lazy Loading**: بارگذاری تنها داده‌های مورد نیاز

#### کد بهینه‌سازی نمونه:
```php
// Pagination برای ارسال‌ها
$per_page = min(100, max(1, (int)($request->get_param('per_page') ?: 20)));
$page = max(1, (int)($request->get_param('page') ?: 1));
$offset = ($page - 1) * $per_page;

$query = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE form_id = %d LIMIT %d OFFSET %d",
    $form_id, $per_page, $offset
);
```

---

## 🚀 دستورالعمل استقرار و پیکربندی

### 📦 نصب افزونه

1. **آپلود فایل‌ها** به `/wp-content/plugins/arshline/`
2. **فعال‌سازی افزونه** از پنل مدیریت وردپرس
3. **اجرای Migration** (خودکار در فعال‌سازی)
4. **ایجاد صفحه داشبورد** (خودکار)

### ⚙️ پیکربندی اولیه

#### تنظیمات پایه:
```php
// در wp-config.php (اختیاری)
define('ARSHLINE_DEBUG', true);                    // فعال‌سازی دیباگ
define('ARSHLINE_UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // حداکثر سایز آپلود (10MB)
define('ARSHLINE_API_RATE_LIMIT', 60);            // محدودیت API (درخواست در دقیقه)
```

#### تنظیمات هوش مصنوعی:
1. **رفتن به تنظیمات** در داشبورد
2. **وارد کردن Base URL**: `https://api.openai.com/v1`
3. **وارد کردن API Key**: کلید OpenAI
4. **انتخاب Model**: `gpt-4o-mini` یا `gpt-4`
5. **تست اتصال** برای اطمینان

### 🔧 سفارشی‌سازی

#### اضافه کردن فیلد جدید:
```javascript
// در dashboard.js
const customFieldTypes = {
    'custom_field': {
        label: 'فیلد سفارشی',
        render: function(field) {
            return `<div class="custom-field">
                <label>${field.label}</label>
                <input type="text" name="field_${field.id}" />
            </div>`;
        }
    }
};
```

#### CSS سفارشی:
```css
/* در dashboard.css یا فایل جداگانه */
.arshline-dashboard-root.custom-theme {
    --primary: #your-color;
    --secondary: #your-secondary;
}

.custom-card {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
}
```

### 🐛 عیب‌یابی

#### مشکلات رایج:

1. **داشبورد لود نمی‌شود**:
   - بررسی مجوزهای کاربری
   - چک کردن console browser
   - فعال‌سازی WP_DEBUG

2. **API کار نمی‌کند**:
   - بررسی Permalink structure
   - تست REST API endpoints دستی
   - چک کردن .htaccess

3. **هوش مصنوعی پاسخ نمی‌دهد**:
   - بررسی Base URL و API Key
   - تست اتصال از تنظیمات
   - چک کردن Network logs

#### لاگ‌های دیباگ:
```javascript
// فعال‌سازی دیباگ در browser console
window.ARSHDBG = 1;

// مشاهده لاگ‌های داخلی
console.log('Dashboard debug enabled');
```

---

## 📋 چک‌لیست بازسازی کامل

### ✅ بک‌اند (PHP)

- [ ] **فایل اصلی**: `arshline.php` با تمام hook ها و autoloader
- [ ] **API Controller**: `src/Core/Api.php` با 15+ endpoint
- [ ] **Form Management**: کلاس‌های Form, FormRepository, FormValidator
- [ ] **Submission System**: کلاس‌های Submission و Repository مربوطه
- [ ] **Database Migrations**: ساخت 4 جدول اصلی
- [ ] **Dashboard Controller**: مدیریت enqueue و localization
- [ ] **Template System**: dashboard-template.php و form-template.php
- [ ] **Helper Classes**: Helpers.php برای توابع عمومی

### ✅ فرانت‌اند (JavaScript/CSS)

- [ ] **Dashboard Core**: `assets/js/dashboard.js` با 2800+ خط کد
- [ ] **CSS Framework**: `assets/css/dashboard.css` با theme system
- [ ] **Tab System**: routing با hash navigation
- [ ] **Form Builder**: سازنده بصری با drag & drop
- [ ] **AI Terminal**: ترمینال شناور با دستورات فارسی
- [ ] **Theme Toggle**: سیستم تم دوتایی light/dark
- [ ] **Responsive Design**: سازگاری با تمام دستگاه‌ها
- [ ] **RTL Support**: پشتیبانی کامل راست به چپ

### ✅ ویژگی‌های پیشرفته

- [ ] **Real-time Validation**: اعتبارسنجی لحظه‌ای فیلدها
- [ ] **Input Masks**: ماسک‌های ورودی برای انواع داده
- [ ] **File Upload**: سیستم آپلود با پیش‌نمایش
- [ ] **Export System**: صادرات CSV/Excel
- [ ] **Print Views**: نمایش قابل چاپ ارسال‌ها
- [ ] **Toast Notifications**: اعلان‌های زیبا و responsive
- [ ] **Keyboard Navigation**: پشتیبانی کامل کیبورد
- [ ] **Accessibility**: ARIA labels و semantic HTML

### ✅ امنیت و عملکرد

- [ ] **CSRF Protection**: WordPress nonce در تمام endpoints
- [ ] **Permission Checks**: بررسی مجوز در هر عملیات
- [ ] **Input Sanitization**: پاکسازی تمام ورودی‌ها
- [ ] **SQL Injection Prevention**: استفاده از Prepared Statements
- [ ] **Rate Limiting**: محدودیت درخواست‌های API
- [ ] **Spam Protection**: Honeypot و Captcha
- [ ] **Performance Optimization**: Pagination و lazy loading
- [ ] **Error Handling**: مدیریت خطا در تمام سطوح

### ✅ تست و کیفیت

- [ ] **Unit Tests**: تست‌های خودکار برای کلاس‌های کلیدی
- [ ] **Integration Tests**: تست‌های API endpoints
- [ ] **Browser Compatibility**: تست در مرورگرهای مختلف
- [ ] **Mobile Testing**: تست روی دستگاه‌های موبایل
- [ ] **Performance Testing**: اندازه‌گیری سرعت و حافظه
- [ ] **Security Testing**: بررسی آسیب‌پذیری‌ها
- [ ] **User Experience Testing**: تست قابلیت استفاده
- [ ] **Documentation**: مستندسازی کامل تمام قسمت‌ها

---

## 🎉 نتیجه‌گیری

این مستند تمام جنبه‌های افزونه عرشلاین نسخه ۲.۱.۰ (AI Stable) را پوشش می‌دهد. با استفاده از این مستندات، می‌توانید:

1. **بازسازی کامل افزونه** را از صفر انجام دهید
2. **تمام ویژگی‌ها و قابلیت‌ها** را پیاده‌سازی کنید
3. **سیستم‌های پیچیده** مانند AI و Form Builder را بسازید
4. **امنیت و عملکرد** را در سطح تولید تضمین کنید

### 🚀 مراحل بعدی

برای بازسازی، این مراحل را دنبال کنید:

1. **شروع با ساختار پایه**: فایل‌های PHP اصلی
2. **پیاده‌سازی API**: REST endpoints
3. **ساخت رابط کاربری**: HTML/CSS/JS
4. **افزودن ویژگی‌های پیشرفته**: AI و Form Builder
5. **تست و بهینه‌سازی**: کیفیت و عملکرد
6. **مستندسازی**: راهنمای کاربر و توسعه‌دهنده

**تبریک! شما اکنون تمام اطلاعات لازم برای بازسازی کامل افزونه عرشلاین را در اختیار دارید.** 🎊