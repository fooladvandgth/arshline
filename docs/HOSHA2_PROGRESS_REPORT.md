# گزارش پیشرفت جامع Hosha2 (تا تاریخ ۱۴ مهر ۱۴۰۴ / 6 Oct 2025)

## 1. مقدمه
Hosha2 نسل دوم موتور «کمک‌هوشمند» (هوشا) برای تولید و تکامل فرم‌ها در افزونه عرش‌لاین است. نسخه جدید با تمرکز بر: شفافیت (Observability)، قابلیت حسابرسی (Auditability)، کنترل خطا، قابلیت لغو (Cancellation)، مدیریت نرخ (Rate Limiting)، و ایجاد نسخه‌های قابل ردیابی (Version Snapshots) طراحی شده است.

این گزارش، وضعیت فعلی، معماری، فازهای انجام شده، جزئیات فنی پیاده‌سازی، تصمیم‌های طراحی و کارهای باقی‌مانده را مستند می‌کند.

---
## 2. اهداف کلیدی پروژه
| حوزه | هدف | وضعیت |
|------|------|-------|
| پایداری | جداسازی لایه‌های توانمندی (Capabilities) از اورکستریشن | انجام شد |
| ردیابی تغییرات | محاسبه و ذخیره `diff_sha` برای هر خروجی | انجام شد |
| قابلیت لغو | لغو غیرمخرب در چندین Checkpoint (قبل Capabilities، قبل OpenAI، بعد OpenAI) | انجام شد |
| محدودسازی مصرف | Rate Limiter لغزنده (Sliding Window) | انجام شد (مقدار پیش‌فرض 10/60s) |
| مدیریت خطا | نگاشت خطاهای OpenAI / HTTP به taxonomy داخلی | پیاده‌سازی هسته + نیازمند تست تکمیلی |
| نسخه‌سازی | Repository موقتی برای Snapshot نسخه فرم + متادیتا | انجام شد (Transient Stub) |
| REST API | Endpoint عمومی تولید فرم | در حال تکمیل (Phase 1+2 انجام) |
| اعتبارسنجی | تمایز «نبودن Prompt» از «Prompt خالی» | انجام شد |
| شفافیت | لاگ‌های ساخت‌یافته + Summary Notes شامل `diff_sha` | انجام شد |
| مستندسازی | مستند نهایی Specification | در انتظار بروزرسانی نهایی |

---
## 3. نمای معماری (High-Level)
```
[REST Controller]
      |  (ورودی کاربر + اعتبارسنجی اولیه)
      v
[Hosha2GenerateService]
  ├─ RateLimiter (قبل از کار سنگین)
  ├─ Cancellation Checkpoint #1 (before capabilities)
  ├─ CapabilitiesBuilder
  ├─ Cancellation Checkpoint #2 (before OpenAI)
  ├─ EnvelopeFactory (ساخت محتوای مدل)
  ├─ OpenAI Client (HTTP, error taxonomy)
  ├─ diff_sha (sha1 روی ساختار diff خام)
  ├─ Cancellation Checkpoint #3 (after OpenAI, before validate)
  ├─ DiffValidator (نرمال‌سازی + صحت ساختار diff)
  ├─ VersionRepository (Snapshot + متادیتا)
  ├─ Logger (progress + summary)
  └─ خروجی ساخت‌یافته (final_form + token_usage + progress + version_id + diff_sha)
```

### کامپوننت‌های کلیدی
| کامپوننت | نقش | نکات ویژه |
|----------|-----|-----------|
| `Hosha2GenerateService` | اورکستراتور اصلی Pipeline | سه Checkpoint لغو + تزریق وابستگی ها |
| `Hosha2CapabilitiesBuilder` | آماده‌سازی قابلیت‌های لازم برای مدل | فعلاً پایه‌ای |
| `Hosha2OpenAIEnvelopeFactory` | ساخت Envelope استاندارد مدل | جدا از لایه سرویس |
| `Hosha2OpenAIHttpClient` / Stub | انتزاع ارتباط با OpenAI | نگاشت خطا + لاگ diff_sha |
| `Hosha2DiffValidator` | اعتبارسنجی ساختار diff | در صورت نامعتبر → استثنا |
| `Hosha2RateLimiter` | پنجره لغزنده درخواست‌ها | لاگ `rate_limit_check` و `rate_limit_exceeded` |
| `Hosha2VersionRepository` | ذخیره Snapshot نسخه | ذخیره diff_sha در متا |
| `Hosha2ProgressTracker` | ثبت مراحل | در Summary خروجی |
| `Hosha2GenerateController` | REST ورودی/خروجی | مدیریت کدهای HTTP و نقشه خطا |

---
## 4. فازها و وضعیت اجرایی
### فازهای زیرساخت اولیه (F0–F3)
- اسکلت‌بندی Capabilities / Envelope / Client Interface / Progress / Diff Validation
- Stub Logger و تزریق وابستگی‌ها
- آماده‌سازی تست‌های پایه (F3) برای اطمینان از مونتاژ صحیح قطعات

### فاز 4 (F4) – نگاشت تسک‌ها
| تسک | توضیح | وضعیت |
|------|-------|-------|
| HTTP Client واقعی | پیاده‌سازی اتصال (الگوی قابل تزریق) + نقشه خطا | انجام شد |
| Error Taxonomy | TIMEOUT, RATE_LIMIT, OPENAI_UNAVAILABLE, MODEL_LIMIT, SCHEMA_INVALID | پیاده‌سازی (تست اختصاصی باقیست) |
| Cancellation | سه Checkpoint + transient `hosha2_cancel_{req_id}` | انجام شد |
| Rate Limiting | Sliding Window (پیش‌فرض 10 در 60 ثانیه) | انجام شد + تست |
| Version Snapshot | ذخیره فرم + diff_sha + متادیتا | انجام شد |
| diff_sha | `sha1(json_encode(diff))` روی diff خام قبل از اعتبارسنجی نهایی | انجام شد + 7 سناریو تست |
| REST Endpoint | Phase 1 (Validation) + Phase 2 (RateLimit & Cancellation) | در حال تکمیل |
| مستند نهایی | به‌روزرسانی Spec و README تکمیلی | باقی‌مانده |

### Task 7 – Endpoint Phases
| فاز | محتوا | وضعیت |
|-----|--------|--------|
| Phase 1 | اعتبارسنجی: `invalid_form_id`, `missing_prompt`, `empty_prompt`, `invalid_options_type` | انجام شد (4 تست، 13 assertion) |
| Phase 2 | سناریوهای Rate Limit (429) + Cancellation (499) در حالات مختلف | انجام شد (3 تست، 12 assertion) |
| Phase 3 | Contract Response: ساختار خروجی (version_id, diff_sha, progress sequence) | باقی‌مانده |
| Phase 4 | سناریوهای خطاهای سرویسی (OpenAI failure mapping, invalid diff, repo failure) | باقی‌مانده |
| Phase 5 | مستندات Endpoint + جدول خطا+ مثال JSON | باقی‌مانده |

---
## 5. جزئیات پیاده‌سازی‌های مهم
### 5.1 نرخ دهی (Rate Limiter)
- الگوریتم: Sliding Window ساده با ذخیره آرایه رویدادها در transient.
- متدها:
  - `isAllowed(req_id)`: پاکسازی رویدادهای قدیمی + بررسی ظرفیت.
  - `recordRequest(req_id)`: ثبت درخواست جدید.
- TTL ذخیره: `window + 60s` برای حاشیه.
- لاگ: رویدادهای `rate_limit_check` و در صورت تجاوز `rate_limit_exceeded`.

### 5.2 لغو (Cancellation)
- مکانیسم: `get_transient('hosha2_cancel_{req_id}')` → اگر true، پرتاب `RuntimeException` با پیام «Request cancelled by user» یا بازگشت ساختار `cancelled=true`.
- Checkpoints:
  1. قبل از ساخت Capabilities
  2. قبل از فراخوانی OpenAI
  3. بعد از پاسخ OpenAI و قبل از Diff Validation
- کد HTTP خروجی: 499 (Client Closed Request) — تصمیم طراحی برای تفکیک معنایی از خطاهای 4xx استاندارد.

### 5.3 diff_sha
- محاسبه فقط اگر `diff` آرایه غیرخالی و معتبر باشد.
- فرمول: `sha1(json_encode($diff))`
- ذخیره در:
  - Summary Log (کلید `diff_sha` + یک Note در آرایه notes)
  - Version Snapshot Metadata
  - خروجی REST (فیلد `diff_sha`)

### 5.4 Version Snapshot Repository (Stub)
- هدف: جداسازی مسیر ذخیره نسخه فرم تولید شده از pipeline.
- داده‌های ابرداده (metadata):
  - `user_prompt`
  - `tokens_used`
  - `created_by`
  - `diff_applied` (فعلاً false)
  - `diff_sha`
- برگرداندن `version_id` برای قابلیت ردگیری.

### 5.5 ساختار خروجی سرویس (GenerateService)
نمونه (موفق):
```json
{
  "request_id": "abc12def",
  "final_form": {"version": "arshline_form@v1","fields":[],"layout":[],"meta":[]},
  "diff": [],
  "token_usage": {"prompt":0,"completion":0,"total":0},
  "progress": ["analyze_input","build_capabilities","openai_send","validate_response","persist_form","render_preview"],
  "version_id": 1,
  "diff_sha": null
}
```
لغو (Cancelled):
```json
{
  "request_id": "abc12def",
  "cancelled": true,
  "message": "Request cancelled by user"
}
```

### 5.6 کنترل خطا (Controller Layer)
| سناریو | کد HTTP | `error.code` | توضیح |
|--------|---------|-------------|-------|
| فرم نامعتبر | 400 | invalid_form_id | form_id <= 0 |
| عدم وجود prompt | 400 | missing_prompt | پارامتر ارسال نشده |
| prompt خالی | 400 | empty_prompt | Trim == '' |
| options نوع نادرست | 400 | invalid_options_type | باید آبجکت/آرایه باشد |
| نرخ محدودیت | 429 | rate_limited | `Rate limit exceeded` |
| لغو کاربر | 499 | request_cancelled | transient فعال |
| خطای اجرایی دیگر | 500 | runtime_error | پیام داخلی RuntimeException |
| خطای پیش‌بینی‌نشده | 500 | fatal | Throwable غیرمنتظره |

---
## 6. تست‌ها و پوشش فعلی
| گروه تست | تعداد سناریو | وضعیت |
|-----------|--------------|--------|
| Validation Endpoint | 4 | سبز |
| RateLimit + Cancellation Endpoint | 3 | سبز |
| diff_sha | 7 | سبز |
| RateLimiter داخلی | پوشش پایه | سبز |
| Version Repository | پایه | سبز |
| سایر هسته Hoosha قبلی | موجود | سبز (مطابق خروجی قبلی) |

تست‌های در انتظار توسعه:
- Error Mapping HTTP Client (Timeout / 429 / 5xx / مدل محدودیت / اسکیما نامعتبر)
- Response Contract کامل (Phase 3)
- سناریوهای ممانعت ذخیره نسخه یا diff نامعتبر (Phase 4)

---
## 7. تصمیم‌های طراحی مهم و توجیه
| تصمیم | دلیل |
|--------|------|
| استفاده از 499 برای لغو | تفکیک معنایی از 409/400؛ الگو در Nginx و برخی CDNs پذیرفته شده |
| جداسازی Envelope Factory | کاهش کوپل سرویس با Prompt Engineering و امکان تست مستقل |
| ذخیره diff_sha پیش از اعتبارسنجی بعدی | حفظ تطابق با خروجی خام مدل برای Audit |
| Sliding Window ساده بدون DB | سرعت توسعه + هزینه پایین؛ آماده مهاجرت به persistent store در آینده |
| Snapshot موقتی (Transient) | تسریع فاز توسعه؛ امکان ارتقا به جدول سفارشی یا CPT |

---
## 8. کارهای باقی‌مانده (Backlog فعال)
| دسته | آیتم | توضیح |
|------|------|-------|
| Endpoint Phase 3 | Response Contract Test | اعتبار ساختار خروجی (کلیدها، نوع‌ها، ترتیب منطقی progress) |
| Endpoint Phase 4 | Service Error Scenarios | شبیه‌سازی: OpenAI Timeout / Model Limit / Schema Invalid / Repo Failure |
| Endpoint Phase 5 | Documentation Update | جدول API کامل + مثال curl + JSON Schema پیشنهادی |
| Testing | HTTP Error Mapping Tests | تولید Transport Stub با کدهای مختلف |
| Docs | Spec Update (HOSHA2_SPEC.md) | افزودن 499 + taxonomy خطا + diff_sha |
| کیفیت | Logging Enhancement | اضافه کردن correlation ID در همه لاگ‌ها (در صورت نیاز) |
| بهبود | Persistent Rate Limiting | مهاجرت به استاتیک Table یا Redis (در آینده) |
| امنیت | Auth & Capability Gate | محدودسازی endpoint در سطح WordPress capability |

---
## 9. ریسک‌ها و پیشنهادهای کاهش
| ریسک | توضیح | کاهش |
|------|-------|-------|
| Transient Volatility | از بین رفتن داده Snapshot پس از TTL | مهاجرت به storage پایدار در فاز بعد |
| عدم تست کامل Error Mapping | احتمال رفتار غیرمنتظره در شرایط مرزی | افزودن تست‌های پوشش taxonomy (Phase آتی) |
| Parallel Requests | عدم قفل optimistic روی Snapshot | افزودن version_conflict handling بعد از واقعی شدن persistence |
| تغییر API آینده | مصرف‌کنندگان خارجی ممکن است وابسته شوند | انتشار نسخه با tag و Semantic Versioning |

---
## 10. چشم‌انداز فنی (Next Evolution)
1. افزوده شدن «Partial Apply Mode» برای diff های بزرگ.
2. ارتقای Logger به ساختار JSON Lines + امکان Export.
3. افزودن Trace ID سراسری (همان req_id توسعه یافته).
4. Migration به دیتابیس برای Version Snapshots (ساخت جدول: `arshline_form_versions`).
5. معرفی Feature Flags برای فعال/غیرفعال سازی مسیر Hosha2 در محیط‌های staging.

---
## 11. دستورالعمل استفاده Endpoint (وضعیت فعلی)
- URL: `POST /hosha2/v1/forms/{form_id}/generate`
- Body JSON نمونه:
```json
{
  "prompt": "یک فرم ثبت نام ساده بساز",
  "options": {"lang":"fa"}
}
```
- پاسخ موفق:
```json
{
  "success": true,
  "request_id": "d13fa0b2",
  "version_id": 1,
  "diff_sha": null,
  "data": {
    "final_form": {"version":"arshline_form@v1","fields":[],"layout":[],"meta":[]},
    "diff": [],
    "token_usage": {"prompt":0,"completion":0,"total":0},
    "progress": ["analyze_input","build_capabilities","openai_send","validate_response","persist_form","render_preview"]
  }
}
```
- لغو:
```json
{
  "success": false,
  "cancelled": true,
  "error": {"code":"request_cancelled","message":"Request cancelled by user"},
  "request_id": "d13fa0b2"
}
```

---
## 12. جمع‌بندی وضعیت کنونی
پایه معماری Hosha2 تثبیت شده، مسیر تولید فرم انتزاعی و ماژولار است، قابلیت‌های کلیدی (Rate Limiting، Cancellation، Version Snapshot، diff_sha) فعال هستند و تست‌های فاز ۱ و ۲ Endpoint موفق‌اند. تمرکز آتی روی تکمیل پوشش خطا، تست Contract خروجی و مستندسازی نهایی خواهد بود. ریسک اصلی فعلی «موقتی بودن ذخیره‌سازی نسخه‌ها» و «عدم تست کامل نقشه خطا» است که در Backlog برنامه‌ریزی شده است.

---
## 13. فهرست کوتاه اقدامات فوری پیشنهادی
1. نوشتن تست Contract (Phase 3) – تضمین ثبات API.
2. تکمیل تست‌های Error Mapping (Transport Stub).
3. بروزرسانی Spec و README (افزودن جدول Status Codes + نمونه‌ها).
4. افزودن Capability Check (مثلاً `current_user_can('manage_options')`).
5. تصمیم‌گیری درباره persistence نسخه‌ها برای عرضه اولیه.

---
## 14. پیوست – واژگان
| اصطلاح | معنا |
|--------|------|
| diff | مجموعه تغییرات پیشنهادی مدل برای فرم | 
| diff_sha | هش یکتای diff برای Audit / Deduplication |
| Snapshot | ذخیره حالت فرم + متادیتا قبل از اعمال diff |
| Checkpoint | نقطه‌ای که لغو کاربر بررسی می‌شود |
| Envelope | ساختار استاندارد درخواست به مدل |

---
اگر نیاز به تفکیک عمیق‌تر هر لایه یا دیاگرام Sequence تفصیلی باشد، آماده افزودن در نسخه بعدی سند هست.
