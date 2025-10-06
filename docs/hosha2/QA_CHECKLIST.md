# Hosha2 QA Checklist (Draft)

## 1. Logger
- [ ] hooshyar2-log.txt ایجاد می‌شود.
- [ ] rotation بعد از >5MB عمل می‌کند (شبیه‌سازی با نوشتن خطوط زیاد).
- [ ] سطوح DEBUG/INFO/WARN/ERROR تفکیک شده.
- [ ] redact ایمیل و تلفن درست.

## 2. Generate Flow
- [ ] درخواست بدون nonce => 403.
- [ ] درخواست معتبر => phase_start و phase_end لاگ شده.
- [ ] envelope ارسالی در لاگ (بدون api_key).
- [ ] پاسخ مدل ثبت (tokens_in/out).
- [ ] secondary validation فعال => دو رویداد openai_response.

## 3. Edit Flow
- [ ] diff ثبت با diff_sha.
- [ ] خطای DIFF_APPLY_FAIL به درستی مدیریت.
- [ ] نسخه جدید در VersionRepository ثبت.

## 4. Validation & Errors
- [ ] SCHEMA_INVALID: نمایش پیام + لاگ ERROR.
- [ ] UNSAFE_OUTPUT: حذف HTML ناامن + هشدار.
- [ ] RATE_LIMIT: بیش از 10req/60s => خطا.
- [ ] TIMEOUT: ثبت latency_ms و error_code.

## 5. Progress Bar
- [ ] مراحل به ترتیب فعال.
- [ ] درصد نهایی = 100.
- [ ] لغو قبل از persist_form => status=cancelled.
- [ ] ETA تقریبی غیر منفی.

## 6. Versioning
- [ ] version=1 در generate.
- [ ] version افزایش بعد edit.
- [ ] rollback نسخه قدیمی موفق.

## 7. Security
- [ ] فقط کاربران دارای manage_options دسترسی دارند.
- [ ] Base URL اگر http ناامن => بلاک.
- [ ] XSS فیلد html کنترل شده.

## 8. Rate Limiting
- [ ] شمارنده transients ریست بعد 60s.
- [ ] پیام فارسی مناسب.

## 9. No Local Fallback
- [ ] خطای OpenAI => هیچ فرم ذخیره نمی‌شود.
- [ ] پیام “سرویس هوشمند در دسترس نیست”.

## 10. Documentation
- [ ] README_HOSHA2 شامل JSON نمونه.
- [ ] Spec به روز با تغییرات.

## 11. Accessibility / RTL
- [ ] Progress Bar با aria-label.
- [ ] پیش‌نمایش RTL درست.

## 12. Performance
- [ ] Capabilities map cache hit < 5ms.
- [ ] میانگین generate end-to-end < 7s (با مدل واقعی *تقریبی*).

## Sign-off
- QA Lead:
- Date:
