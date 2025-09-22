<?php
namespace Arshline\Modules\Forms;

class FormValidator
{
    public static function validate(Form $form): array
    {
        $errors = [];
        if (empty($form->fields) || !is_array($form->fields)) {
            $errors[] = 'فرم باید حداقل یک فیلد داشته باشد.';
        }
        foreach ($form->fields as $field) {
            if (empty($field['type']) || empty($field['label'])) {
                $errors[] = 'هر فیلد باید نوع و برچسب داشته باشد.';
            }
        }
        return $errors;
    }

    public static function validateSubmission(array $fields, array $values): array
    {
        $errors = [];
        $map = [];
        foreach ($fields as $idx => $f) {
            $props = isset($f['props']) ? $f['props'] : $f;
            $map[$idx] = $props;
        }
        $normalizeDigits = function(string $s): string {
            $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            $out = '';
            $len = mb_strlen($s, 'UTF-8');
            for ($i=0; $i<$len; $i++) {
                $ch = mb_substr($s, $i, 1, 'UTF-8');
                $pos = array_search($ch, $fa, true);
                if ($pos !== false) { $out .= (string)$pos; continue; }
                $pos = array_search($ch, $ar, true);
                if ($pos !== false) { $out .= (string)$pos; continue; }
                $out .= $ch;
            }
            return $out;
        };
        foreach ($values as $idx => $entry) {
            $props = $map[$idx] ?? null;
            if (!$props) continue;
            $val = (string)($entry['value'] ?? '');
            $val = $normalizeDigits(trim($val));
            $required = !empty($props['required']);
            if ($required && $val === '') { $errors[] = ($props['label'] ?? 'فیلد').' الزامی است.'; continue; }
            if ($val === '') continue;
            $type = $props['type'] ?? 'short_text';
            $format = $props['format'] ?? 'free_text';
            if ($type === 'short_text') {
                switch ($format) {
                    case 'email':
                        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) $errors[] = 'ایمیل نامعتبر است.';
                        break;
                    case 'mobile_ir':
                        if (!preg_match('/^(\+98|0)?9\d{9}$/', $val)) $errors[] = 'شماره موبایل ایران نامعتبر است.';
                        break;
                    case 'mobile_intl':
                        if (!preg_match('/^\+?[1-9]\d{7,14}$/', $val)) $errors[] = 'شماره موبایل بین‌المللی نامعتبر است.';
                        break;
                    case 'tel':
                        if (!preg_match('/^[0-9\-\+\s\(\)]{5,20}$/', $val)) $errors[] = 'شماره تلفن نامعتبر است.';
                        break;
                    case 'numeric':
                        if (!preg_match('/^\d+$/', $val)) $errors[] = 'فقط اعداد مجاز است.';
                        break;
                    case 'national_id_ir':
                        if (!preg_match('/^\d{10}$/', $val)) { $errors[] = 'کد ملی نامعتبر است.'; break; }
                        if (preg_match('/^(\d)\1{9}$/', $val)) { $errors[] = 'کد ملی نامعتبر است.'; break; }
                        $sum = 0; for ($i=0; $i<9; $i++) { $sum += intval($val[$i]) * (10 - $i); }
                        $r = $sum % 11; $c = intval($val[9]);
                        if (!(($r < 2 && $c === $r) || ($r >= 2 && $c === (11 - $r)))) $errors[] = 'کد ملی نامعتبر است.';
                        break;
                    case 'postal_code_ir':
                        if (!preg_match('/^\d{10}$/', $val)) { $errors[] = 'کد پستی نامعتبر است.'; break; }
                        if (preg_match('/^(\d)\1{9}$/', $val)) { $errors[] = 'کد پستی نامعتبر است.'; break; }
                        break;
                    case 'fa_letters':
                        if (!preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $val)) $errors[] = 'فقط حروف فارسی مجاز است.';
                        break;
                    case 'en_letters':
                        if (!preg_match('/^[A-Za-z\s]+$/', $val)) $errors[] = 'فقط حروف انگلیسی مجاز است.';
                        break;
                    case 'ip':
                        if (!(filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) $errors[] = 'آی‌پی نامعتبر است.';
                        break;
                    case 'time':
                        if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?:\:[0-5]\d)?$/', $val)) $errors[] = 'زمان نامعتبر است.';
                        break;
                    case 'date_jalali':
                        if (!preg_match('/^\d{4}\/(0[1-6]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $val)) $errors[] = 'تاریخ شمسی نامعتبر است.';
                        break;
                    case 'date_greg':
                        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])\-(0[1-9]|[12]\d|3[01])$/', $val)) $errors[] = 'تاریخ میلادی نامعتبر است.';
                        break;
                    case 'regex':
                        $pattern = (string)($props['regex'] ?? '');
                        if ($pattern === '' || @preg_match($pattern, '') === false) { $errors[] = 'الگوی دلخواه نامعتبر است.'; break; }
                        if (!preg_match($pattern, $val)) $errors[] = 'مقدار با الگوی دلخواه تطابق ندارد.';
                        break;
                    case 'free_text':
                    default:
                        // no-op
                        break;
                }
            }
        }
        return $errors;
    }
}
