# Changelog

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
