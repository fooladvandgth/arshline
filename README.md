# افزونه عرشلاین (ARSHLINE) v7.2.0 🚀

**فرم‌ساز پیشرفته فارسی با هوش مصنوعی برای وردپرس**

داشبورد تمام‌صفحه، فرم‌ساز مدرن RTL، هوش مصنوعی هوشیار، و API نسخه‌دار برای ساخت فرم‌های ساده تا پیشرفته در وردپرس.

[![نسخه](https://img.shields.io/badge/version-7.2.0-blue.svg)](https://github.com/fooladvandgth/arshline)
[![لایسنس](https://img.shields.io/badge/license-GPL2-green.svg)](LICENSE)
[![وردپرس](https://img.shields.io/badge/wordpress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/php-8.1%2B-purple.svg)](https://php.net)

## امکانات کلیدی
- داشبورد اختصاصی با طراحی شیشه‌ای (Glass) و تم روشن/تاریک
- فرم‌ساز مستقل با چیدمان «تنظیمات چپ / پیش‌نمایش راست»
- فیلد «پاسخ کوتاه» با فرمت‌ها: free_text, email, numeric, date_jalali, date_greg, time, mobile_ir, mobile_intl, tel, fa_letters, en_letters, ip, national_id_ir, postal_code_ir
- Placeholder هوشمند؛ پیش‌فرض free_text: «پاسخ را وارد کنید»
- سؤال بالای ورودی + شماره‌گذاری اختیاری (Toggle)
- تاگل «توضیحات» با استایل VC Switch (Yes/No)
- پیش‌نمایش تمام‌صفحه با ماسک ورودی و تقویم شمسی فقط برای تاریخ شمسی
- REST API نسخه‌دار: CRUD فرم‌ها، فیلدها و ارسال پاسخ‌ها
	- ابزارهای ثابت: «پیام خوش‌آمد» (ابتدا) و «پیام تشکر» (انتها) + امکان حذف
	- ادیتور پیام: عنوان/متن/تصویر + آپلود از داخل ادیتور
	- دکمه‌های «پیش‌نمایش» در بیلدر و ادیتور
	- حذف تکی سؤال + انتخاب چندتایی و حذف گروهی با انیمیشن نرم
	- درگ‌انداپ پایدار (SortableJS، Placeholder ملایم، تمیزکاری گوست/Placeholder)
	- مسیریابی مبتنی بر Hash (Back مرورگر) و هشدار خروج بدون ذخیره در ادیتور
	- نوار کناری آیکونی با جمع/بازشدن خودکار در بیلدر/ادیتور/پیش‌نمایش

## شروع سریع
1. نصب در مسیر `wp-content/plugins/arshline`
2. فعال‌سازی افزونه از پیشخوان وردپرس
3. از منوی «عرشلاین» وارد داشبورد اختصاصی شوید
4. در تب «فرم‌ها»، فرم جدید بسازید یا ویرایش کنید

## توسعه
- PHP 8.1+، وردپرس 6.x
- ساختار ماژولار در `src/`
- تم‌ها و UI در `src/Dashboard`
- تست‌ها در `tests/`

## تغییرات اخیر
نسخه پایدار فعلی: 1.4.1 (2025-09-23)

راهنمای تغییرات داشبورد در `CHANGELOG_DASHBOARD.md` و پیشرفت‌ها در `PROGRESS_LOG.md` ثبت می‌شود.

### Backend Event Streaming (Logging Integration)

The `hoosha/prepare` endpoint now returns an `events` array in addition to `progress` and `notes`:

```
events: [
	{ seq: 0, type: 'progress', step: 'model_request', message: 'ارسال درخواست به مدل' },
	{ seq: 1, type: 'note', note: 'pipe:chunk_progress(1/3)' },
	{ seq: 2, type: 'note', note: 'perf:total_ms=1234' }
]
```

Frontend (`dashboard-controller.js`) iterates these and emits console lines with prefix `[ARSH-EVENT]`, which are captured by `console-capture.js`. This provides granular visibility into pipeline stages (chunking, refine pass, final review, performance metrics).

If you build a custom UI, read `events` and stream them live for a real-time progress console.

### Field Coverage & Soft Prune

To mitigate over-aggressive reduction on large heterogeneous inputs, the prepare pipeline now includes:

- Dynamic coverage threshold (`coverage_threshold` request body param, default 0.55). If final model+heuristic field count / baseline field count < threshold, missing baseline fields are injected with `props.source=coverage_injected`.
- Optional second refine pass for only injected fields via `coverage_refine=true` which may relabel / infer formats; refined ones tagged `coverage_injected_refined`.
- Fallback file injection: if no `type=file` fields remain but text clearly references files (PDF, تصویر, log, MP4), synthetic file fields are appended with `source=file_injected` and reasonable accept/multiple props.
- Soft prune mode: duplicates are no longer removed, only tagged with `duplicate_of` and notes include `heur:prune_soft_mode` plus `heur:duplicates_found(N)`.

Progress steps that may appear:
 - `coverage_enforced`
 - `coverage_refine`
 - `file_injected`

Notes emitted:
 - `heur:coverage_injected(N)`
 - `pipe:coverage_refine_start(N)` / `pipe:coverage_refine_applied(M)`
 - `heur:file_fallback_injected(N)`
 - `heur:prune_soft_mode`
 - `heur:duplicates_tagged(N)`

### Canonical Label Dedupe
Numbering (Persian/Arabic/Latin digits + punctuation) is stripped when deciding if a baseline field already exists, preventing duplicate re-injection like "۱. نام و نام خانوادگی" vs "نام و نام خانوادگی".

### Stricter File Injection
File fallback only triggers if explicit keywords (فایل|رزومه|بارگذاری|آپلود|تصویر|رسید|jpg|jpeg|png|گزارش|log|ویدیو|mp4) are present; otherwise a skip note is added: `heur:file_injection_skipped(no_keyword)` or `heur:file_injection_skipped(no_pattern_match_detail)`.

These help auditors understand why overall field count increased post-model.

### Preserve Order & Summary Metrics
If you pass `{"preserve_order": true}` in the `hoosha/prepare` POST body, the final `schema.fields` list is re-sorted to follow the original baseline heuristic extraction order (after canonical label normalization). Fields not present in the baseline (pure model additions, injected coverage or file fields) are appended afterward.

The response now contains a `summary` object for fast auditing:

```
summary: {
	baseline_count: <int>,        // number of baseline heuristic fields
	final_count: <int>,           // number of fields returned in final schema
	coverage_ratio: <number|null>,// final_count / baseline_count (3 decimals) or null if baseline_count=0
	sources: {                    // counts per field source tag
		model: <int>,
		heuristic_or_unchanged: <int>,
		reconciled_from_baseline: <int>,
		coverage_injected: <int>,
		coverage_injected_refined: <int>,
		file_injected: <int>
	},
	preserve_order: <bool>
}
```

### Duplicate (DUP) Badge
Soft prune mode retains duplicates and marks them with `props.duplicate_of` referencing the zero-based index of the original field. The dashboard preview now displays a red `DUP` badge with a tooltip like: «Duplicate of original field #3». This enables reviewers to quickly see that the field was detected as a semantic duplicate without being removed.

### فرم نیم (form_name) خودکار
در فراخوانی `POST /hoosha/prepare` می‌توانید فیلد اختیاری `form_name` را ارسال کنید:

```
{ "user_text": "...", "form_name": "فرم استخدام توسعه‌دهنده" }
```

اگر `form_name` خالی باشد، سیستم به‌صورت هوشمند یک نام کوتاه تولید می‌کند:
1. تلاش برای استفاده از اولین برچسب (Label) فیلد استخراج‌شده پایه
2. در صورت نبود، استخراج ۲ تا ۵ واژه معنادار نخست متن (حذف کلمات توقف مانند «از، به، و، در، برای ...»)
3. کوتاه‌سازی حداکثر تا ۶۰ کاراکتر
4. در صورت نیاز پس از نهایی شدن اسکیمای فیلدها یک تلاش ثانویه از اولین فیلد نهایی انجام می‌شود

نوت‌هایی که ممکن است اضافه شوند:
- `heur:form_name_provided` زمانی که کاربر نام را خودش داده
- `heur:form_name_heuristic` زمانی که نام با روش واژگان معنادار یا برچسب اولیه تولید شده
- `heur:form_name_from_schema` وقتی پس از نهایی شدن اسکیمای فیلدها استخراج شده است

خروجی نهایی شامل کلید `form_name` خواهد بود.

### ویرایش طبیعی (Natural Language Editing)
یک باکس «متن طبیعی ویرایش» زیر پیش‌نمایش افزوده شده است. شما می‌توانید تغییرات را به زبان عامیانه وارد کنید:

نمونه‌ها:
```
سوال اول رو رسمی کن و الزامی بشه
برای سوال شغل سه تا گزینه جدید اضافه کن: کارمند، آزاد، فریلنس
سوال ایمیل رو بیار بالا قبل از شماره موبایل
گزینه های سوال جنسیت رو فقط زن و مرد کن
سوال سوم رو حذف کن
```

دکمه «تبدیل متن طبیعی به دستورات» متن را به Endpoint جدید `/hoosha/interpret_nl` می‌فرستد. پاسخ شامل آرایه `commands` است که به طور خودکار در فیلد دستورات قرار می‌گیرد و عملیات Apply اجرا می‌شود.

### پیش‌نمایش و تایید تغییرات (preview_edit)
جریان جدید امن‌تر:
1. متن طبیعی تغییرات را در باکس وارد کنید.
2. دکمه «پیش‌نمایش تغییرات» → فراخوانی `POST /hoosha/preview_edit` با بدنه:
```
{ "schema": { ... }, "natural_prompt": "..." }
```
3. سرور: تفسیر (`interpret_nl`) → تبدیل به `commands` → اعمال موقتی توسط مدل → تولید `preview_schema` + `deltas`.
4. فرانت‌اند اختلاف‌های ساده (Labels/Types/Count) را نشان می‌دهد.
5. دکمه «تایید و اعمال» → جایگزینی اسکیمای جاری با پیش‌نمایش؛ بدون درخواست دوم به مدل.
6. «انصراف» → رد تغییرات.

Endpoint `preview_edit` خروجی نمونه:
```
{
	"ok": true,
	"commands": ["سوال اول رسمی شود"],
	"preview_schema": { "fields": [...] },
	"deltas": [{"op":"update_label","field_index":0}],
	"notes": ["pipe:preview_edit_start","ai:preview_success(1)"]
}
```

مزایا: بدون اعمال فوری، امکان بازبینی و جلوگیری از تغییر ناخواسته.

### Diff پیشرفته، بازگشت (Undo) و نسخه‌ها
هنگام پیش‌نمایش، اگر مدل یا سیستم محلی `deltas` تولید کند، هر آیتم با رنگ کد می‌شود:
- سبز (add_field)
- قرمز (remove_field)
- آبی (update_label / update_type / update_required)

با تایید، نسخه قبلی در یک پشته (Stack) ذخیره می‌شود و دکمه «بازگشت» فعال می‌گردد. با هر بازگشت اگر پشته خالی شود دکمه مخفی می‌شود. شمار نسخه‌های ذخیره شده در ناحیه کوچک زیر پیش‌نمایش نمایش داده می‌شود.

الگوریتم Diff محلی در نبود deltas مدل تغییرات زیر را تولید می‌کند:
- افزودن فیلد جدید (add_field)
- حذف فیلد (remove_field)
- تغییر Label (update_label)
- تغییر نوع (update_type)
- تغییر required (update_required)

نوت‌های تفسیری ممکن:
- `ai:interpret_start`
- `ai:interpret_success(N)`
- در حالت غیرفعال بودن AI: `ai:interpret_ai_disabled`

در صورت خطا، تقسیم‌بندی اولیه‌ی محلی (Heuristic Splitting) بازگردانده می‌شود.
## توسعه و تست

ماتریس سناریوهای Hoosha: `docs/HOOSHA_SCENARIOS.md`

برای اجرای تست‌ها:

```bash
composer install
composer test
```

## نکات امنیتی

- مسیرهای GET مربوط به فرم‌ها تنها برای کاربرانی که حداقل توانایی `edit_posts` دارند در دسترس است.
- هوک‌های دیباگ نیازمند nonce و دسترسی مدیریتی هستند.
- دارایی‌های رابط کاربری از مسیر `assets/` بارگذاری و از طریق `wp_enqueue_script` و `wp_enqueue_style` در صفحه داشبورد ثبت می‌شوند.

## Hoosha CLI Simulation (Offline Testing)
برای اجرای سناریوهای فرم‌ساز بدون فراخوانی HTTP و مشاهده خروجی کامل (schema، notes، guard):

مثال اجرای سناریوی از پیش تعریف‌شده:
```powershell
php tools/simulate_hoosha.php --file=tools/hoosha_scenarios.json --id=duplicate_explosion
```

مثال ورودی مستقیم بدون مدل (فقط baseline + guard):
```powershell
php tools/simulate_hoosha.php --text="نام و کد ملی و شماره موبایل خود را وارد کنید" --no-model --json-only
```

سوئیچ‌ها:
- `--file=PATH` + `--id=ID` انتخاب سناریو.
- `--text=...` ورودی مستقیم (اولویت بالاتر از id).
- `--no-model` غیرفعال کردن لایه مدل (مفید برای تست خلوص baseline و Guard).
- `--guard=0|1` اجبار فعال/غیرفعال بودن Guard (در نبود تنظیم → پیش‌فرض فعال).
- `--json-only` خروجی فقط JSON خام.

خروجی خلاصه شامل:
- field_count، duplicates_estimated، guard_approved
- guard_issues و issues_detail (در صورت وجود)
- jalali_present و yn_contamination جهت تشخیص آلودگی

فایل سناریو نمونه: `tools/hoosha_scenarios.json` – می‌توانید سناریوهای شخصی را با `{ "id":"...","text":"..." }` اضافه کنید.

نکته: برای تست Guard بدون تماس واقعی با مدل، base_url جعلی و api_key تست ست می‌شود و مسیر مدل معمولاً خطا می‌دهد؛ Guard همچنان اجرا و prune را اعمال می‌کند.
