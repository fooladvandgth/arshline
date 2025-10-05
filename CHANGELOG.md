# Changelog

## 7.3.0 - Enforcement & Test Hardening (Unreleased)

Highlights:
- Strict small-form enforcement: حداکثر ۵ فیلد اولیه بدون تزریق پوشش اضافی؛ حذف فیزیکی فیلدهای زائد و جلوگیری از «ترجیح روش تماس» در فرم کوچک.
- Semantic duplicate collapse (Jaccard) برای فرم‌های کوچک؛ نگه‌داشتن نسخه ساختاریافته و تگ‌گذاری duplicate_of / confirm_for.
- Hallucination pruning: حذف فیلدهای بی‌ربط با هم‌پوشانی توکنی <30% با ورودی.
- File inference & fallback تقویت‌شده (رسید، رزومه، تصویر، گزارش).
- Rebuild edited_text پس از هر مرحله پاکسازی برای شماره‌گذاری پایدار.
- NEW: Configurable AI base URL via constant `ARSHLINE_AI_BASE_URL` or option `arshline_ai_base_url` (defaults to https://api.openai.com).
- NEW: Introduced dedicated DuplicateResolver (token Jaccard threshold 0.6 default) integrated after hallucination pruning & before baseline audit. Emits notes: `heur:semantic_duplicate(<dropped>-><kept>)` and summary `heur:semantic_duplicate_collapsed(N)`.
- NEW: AI refinement merge (preserve baseline order, modify in-place, append new). Emits `ai:modified(label=…)`, `ai:added(label=…)`, `ai:removed(count=N)`.

New Tests:
- HooshaPrepareSmallFormTest: تضمین عدم حضور فیلد ترجیح تماس در فرم کوچک و الزامی بودن فرمت‌های ملی/موبایل.
- HooshaPrepareLargeFormTest: اجازه حضور ترجیح تماس در فرم بزرگ و بررسی نوع انتخابی.
- HooshaPrepareFileInferenceTest: تبدیل «آپلود تصویر/رسید» به فیلد فایل با accept صحیح.
- HooshaPrepareConfirmEmailTest: لینک confirm_for برای ایمیل دوم.
- HooshaPrepareNationalIdDuplicateTest: تگ‌گذاری confirm_for یا duplicate_of برای کد ملی تکراری.
- HooshaPreparePerformanceTest: بودجه زمانی (محلی) < 8s و حداقل تعداد فیلد.
- HooshaPrepareChunkModeTest: فعال‌سازی مسیر chunk برای ورودی بلند، ادغام چند چانک و یادداشت‌های pipe:chunk_progress و pipe:chunks_merged.
- HooshaPrepareEmptyInputTest: بازگشت خطای 400 برای ورودی خالی یا فقط فاصله.
- HooshaPrepareModelFailureFallbackTest: شبیه‌سازی شکست مدل و بازگشت به baseline با یادداشت‌های pipe:model_call_failed و pipe:fallback_from_model_failure.

CI:
- افزوده شدن GitHub Actions workflow (phpunit.yml) برای اجرای خودکار تست‌ها روی PHP 8.1 و 8.2.

Telemetry Notes:
- افزودن رویدادهای progress + notes در خروجی prepare برای مانیتورینگ مرحله‌ای (start, model_request, chunk_X, post_finalize, complete).

Pending / Next:
- Chunk-mode stress test with extremely large paragraphs & timeout path.
- Negative path for malformed JSON body.
- Expanded multi-file inference & size/extension policy enforcement.

---

## 7.2.0 - هوشا / هوشیار / هوشنگ Unified Upgrade

ویژگی‌های شاخص (Highlights):
- جابجایی ساختاری پیش‌نمایش «هوشا» به پایین صفحه + مرتب‌سازی کارت ویرایش طبیعی زیر آن.
- اسکرول هوشمند فقط (بدون فوکوس اجباری) به اولین فیلد تغییر یافته + انتقال دکمه‌های تایید/انصراف کنار همان فیلد.
- انصراف: بازگردانی دکمه‌ها به کارت ویرایش و اسکرول برگشتی به باکس متن طبیعی.
- تایید: محو تدریجی (fade) اکشن‌ها و اعمال نسخه‌ی جدید اسکیمای فرم درجا (بدون درخواست اضافی).
- اکشن Observed Anchor برای جلوگیری از به‌هم‌ریختگی جایگاه پیش‌نمایش در رندرهای بعدی.
- بهبود هایلایت فیلد با requestAnimationFrame و بازاعمال سریع در صورت رندر مجدد.

هوشیار (هوش سطح پشتیبانی / تفسیر):
- ارتقاء پایپ‌لاین تفسیر متن طبیعی برای تحمل تأخیر و fallback محلی در تبدیل short_text → long_text.
- ساختاردهی بهتر لاگ دیباگ (Capture) و فیلتر هوشمند لاگ‌های AI.

هوشنگ (تحلیل / Analytics Base):
- آماده‌سازی زیرساخت تفکیک حالت «چت ساده» و «تحلیل پیشرفته» با غیرفعالسازی موقت چت ساده برای تمرکز روی تحلیل.

Refactors & Dev:
- تثبیت MutationObserver برای reposition preview.
- آرایش اکشن‌های NL به context محل تغییر برای کاهش حرکت چشم کاربر.

Future (در صف بعدی):
- مسیریابی بین چند تغییر (Next/Prev Delta).
- Diff سطح گزینه‌ها (Option-level granular diff).
- توابع fallback گسترده‌تر (افزودن/حذف گزینه، required toggle).

---

## 7.1.0 - Hoosha Smart Form Builder (هوشا، فرم‌ساز باهوش)

Enhancements:
- Advanced format detection: sheba_ir, credit_card_ir, national_id_company_ir, alphanumeric, alphanumeric_no_space, captcha_alphanumeric, file_upload.
- Enhanced enumeration parsing for numbered, parenthetical, comma, slash, and hyphen separated option lists.
- Dynamic rating range detection (e.g. "از 1 تا 7").
- Multi-select intent detection for plural constructs (e.g. روزهای هفته).
- Option cross-contamination resolver with semantic splitting (drinks vs contact methods).
- Confirm national ID field modeling via confirm_for metadata.
- Phrase preservation improvements (e.g. "روزی که گذشت").
- Early spelling normalization pipeline (نوشیدنی‌آ -> نوشیدنی‌ها, دوس -> دوست...).

Fixes:
- Eliminated residual mixed option sets and concatenated tokens.
- Improved sanitation and rebuilt edited_text for consistent schema alignment.

Upcoming (not yet in this release):
- Validator & telemetry instrumentation.
- Composite field splitting (mobile & email, height & weight).
- Expanded semantic noun preservation and debug UI enhancements.

## 7.0.0
Initial baseline for new multipass parser foundation before advanced formats.
