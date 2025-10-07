# GUARD_AI_ASSIST (Phase 2.5)

هدف: ارتقاء GuardUnit از اعتبارسنجی صرف قاعده‌ای به «استدلال کمکی توسط مدل زبانی».

## فعال‌سازی

```
define('HOOSHA_GUARD_AI_ASSIST', true);
// یا در گزینهٔ وردپرس
// arshline_settings['guard_ai_assist'] = true;
// آستانه اعتماد
// arshline_settings['guard_ai_confidence_threshold'] = 0.9;
```

## فازهای اضافه‌شده
1. AI Intent Analysis (قبل از Semantic Matching)
2. AI Reasoning (Conflict Resolution) قبل از Prune
3. AI Validation (بازبینی نهایی) پس از ساخت خروجی

هر فاز در لاگ با رویدادهای: `ai_analysis` / `ai_reasoning` / `ai_validation` ثبت می‌شود.

## ساختار خروجی متریک‌های جدید
```
metrics: {
  ai_intents: <int>,
  ai_actions_applied: <int>,
  ai_validation_confidence: <float|null>
}
```

## منطق به‌کارگیری
- Intent های با confidence ≥ threshold → پیشنهاد type/format و props اعمال می‌شود (در حالت corrective).
- Reasoning با اقدام‌های high confidence: drop/replace → علامت hallucination (یا حذف)؛ merge فعلاً فقط ثبت می‌شود.
- Validation اگر approved=false و issues داشت → به issues با پیشوند `ai_val:` افزوده می‌شود.

## مدل و کلید
- متغیر محیطی یا constant: `OPENAI_API_KEY` / `OPENAI_BASE_URL` / `HOOSHA_GUARD_AI_MODEL`.
- پیش‌فرض مدل: `gpt-4o-mini`.

## لاگ نمونه
```
{"ev":"ai_analysis","label":"کد ملیت چیه؟","intent":"national_id","confidence":0.97}
{"ev":"ai_reasoning","action":"drop","target":"کد ملی ت رو بگو","confidence":0.93,"reason":"Duplicate"}
{"ev":"ai_validation","approved":true,"confidence":0.95}
```

## محدودیت‌ها / مرحله بعد
- Merge واقعی (ادغام props) هنوز پیاده‌سازی نشده.
- تشخیص adaptive threshold هوشمند هنوز وارد نشده.
- Logging drift metrics (رصد زمانی) در فاز بعد.

## ریسک‌ها
| ریسک | توضیح | کاهش ریسک |
|------|-------|-----------|
| اتکای بیش از حد به AI | خطای مدل می‌تواند اشتباه ساختاری ایجاد کند | آستانه اعتماد بالا + حالت diagnostic برای تست |
| هزینه و تأخیر | افزایش latency | لایه اختیاری، قابل غیرفعال | 
| JSON نامعتبر | شکست پردازش | پاکسازی fence + کنترل خطا + بازگشت graceful |

## تست پیشنهادی
- سناریو baseline ناقص + user_text کامل → intent analysis باید type مناسب بدهد.
- Reasoning: دو برچسب معنی واحد → اقدام replace/drop ثبت شود.
- Validation: حذف عمدی یک سوال → AI باید issue برگشت دهد.

---
این سند بخشی از تحول Guard به حالت self-aware + AI-assisted است و به موازات بهبودهای semantic در فاز 3 توسعه خواهد یافت.