# مثال‌های انسانی (فارسی → JSON Plan)

مثال 1: «یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسم فرم را چکاپ سه بگذار»

خروجی:
{
  "version": 1,
  "steps": [
    { "action": "create_form", "params": { "title": "چکاپ سه" } },
    { "action": "add_field", "params": { "type": "short_text" } },
    { "action": "add_field", "params": { "type": "short_text" } }
  ]
}

---

مثال 2: «یک فرم با عنوان دریافت بازخورد بساز و یک سوال امتیازدهی اضافه کن»

خروجی:
{
  "version": 1,
  "steps": [
    { "action": "create_form", "params": { "title": "دریافت بازخورد" } },
    { "action": "add_field", "params": { "type": "rating" } }
  ]
}

---

مثال 3: «عنوان فرم 3 را به فرم مشتریان تغییر بده»

خروجی:
{
  "version": 1,
  "steps": [
    { "action": "update_form_title", "params": { "id": 3, "title": "فرم مشتریان" } }
  ]
}

---

مثال ۴: «سه سوال بلند به همین فرم اضافه کن» (فرض: بلافاصله بعد از create_form)

خروجی:
{
  "version": 1,
  "steps": [
    { "action": "add_field", "params": { "type": "long_text" } },
    { "action": "add_field", "params": { "type": "long_text" } },
    { "action": "add_field", "params": { "type": "long_text" } }
  ]
}

---

مثال ۵: «نتایج فرم 5 را باز کن» (تک‌مرحله ناوبری — پلن چندمرحله‌ای نیست)

خروجی (LLM):
{"none": true}
