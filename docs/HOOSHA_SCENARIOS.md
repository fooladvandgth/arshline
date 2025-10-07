# Hoosha Scenario Matrix

این سند سناریوهای تست end-to-end (یا شبه end-to-end) هوشا را توضیح می‌دهد.

## سناریوهای مثبت (Happy Path)
1. Baseline Simple
   - Input:
     ```
     نام شما چیست؟
     ایمیل
     کد ملی
     تاریخ تولد
     شماره موبایل
     ```
   - Expected:
     - حداقل یک فیلد تاریخ (date_greg یا date_jalali)
     - فرمت کد ملی: national_id_ir
     - ایمیل: format=email

2. Informal Yes/No
   - Input:
     ```
     اسمت چیه
     امروز میای شرکت ؟
     ```
   - Expected: سوال دوم multiple_choice با گزینه های [بله, خیر]

3. Enumerated Checklist
   - Input: خطوط شماره‌دار 1) 2) 3) ...
   - Expected: حذف پیشوندهای عددی؛ عدم ایجاد فیلدهای تکراری ناشی از اعداد

4. Rating Detection
   - Input: «میزان رضایت خود را از 1 تا 10 وارد کنید»
   - Expected: type=rating با محدوده 1..10

5. Dual Date (Birthdate)
   - Input شامل «تاریخ تولد» و «تاریخ تولد (جلالی)» یا اشاره میلادی/جلالی
   - Expected: یکی از فرمت‌ها حفظ؛ عدم ایجاد دو فیلد بی‌جهت هم‌پوشان.

6. Noise Pruning
   - Input شامل خطوط معتبر + خط نویزی «*** تورنادو کوانتومی بنفش ***»
   - Expected: حذف خط نویزی در خروجی نهایی.

7. Semantic Duplicate Collapse
   - Input شامل «کد ملی» و «شماره ملی»
   - Expected: ادغام/حفظ یکی همراه با audit یادداشت.

8. Mixed Option + Text
   - Input: «نوع نوشیدنی (چای / قهوه / آب) توضیح علت انتخاب»
   - Expected: تفکیک یک فیلد multiple_choice و احتمالاً فیلد توضیح متن جدا (در نسخه آتی کامل می‌شود).

## سناریوهای منفی (Error / Edge)
1. Empty Input
   - Expected: پاسخ با error (user_text required)
2. Only Noise / Symbols
   - Expected: 0 fields + note عدم پوشش
3. Excessive Hallucination-Like Lines (10+ غیرمرتبط)
   - Expected: pruning محدود (حداکثر 3 حذف soft) + بازگردانی baseline اگر لازم.
4. Ambiguous Questions («خوبی؟» به تنهایی)
   - Expected: یا فیلد ساده short_text یا note کاهش اطمینان.

## موک مدل (Model Mock)
در تست‌ها از DummyModelClient استفاده می‌شود که خروجی پایدار JSON برمی‌گرداند تا وابستگی شبکه حذف شود.

## توسعه
- برای افزودن سناریوی جدید، به تست `HooshaScenarioTest` یا تست REST جدید اضافه کنید.
- برای پوشش کامل REST، باید stub های WP_REST_* یا Brain Monkey افزوده شوند.

## TODO آینده
- ماژول مستقل Normalizer
- ماژول DuplicateResolver
- استخراج کامل pruning و audit از Api
- تست مقایسه performance (ms) روی سناریوهای بزرگ
