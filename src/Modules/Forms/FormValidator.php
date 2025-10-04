<?php
namespace Arshline\Modules\Forms;

class FormValidator
{
    // Luhn check for credit card numbers (digits only)
    protected static function luhnValid(string $num): bool
    {
        if ($num === '' || preg_match('/[^0-9]/', $num)) return false;
        $sum = 0; $alt = false; for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $n = intval($num[$i]); if ($alt) { $n *= 2; if ($n > 9) $n -= 9; } $sum += $n; $alt = !$alt; }
        return $sum % 10 === 0;
    }

    // IBAN (basic) mod97 for IR (assumes already normalized, e.g., IRxxxxxxxxxxxxxxxxxxxxxxxx)
    protected static function ibanMod97(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (strlen($iban) < 4) return false;
        // Move first 4 chars to end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        // Replace letters
        $expanded = '';
        foreach (str_split($rearranged) as $ch){
            if (ctype_alpha($ch)) { $expanded .= (string)(ord($ch) - 55); } else { $expanded .= $ch; }
        }
        // Large number mod 97
        $mod = 0; $len = strlen($expanded);
        for ($i=0; $i<$len; $i++) { $mod = ($mod*10 + intval($expanded[$i])) % 97; }
        return $mod === 1;
    }
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
        // Map schema by field_id (not by array index) to align with incoming payload
        $map = [];
        foreach ($fields as $f) {
            $fid = isset($f['id']) ? (int)$f['id'] : 0;
            $props = isset($f['props']) ? $f['props'] : $f;
            if ($fid > 0) { $map[$fid] = $props; }
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
        // Track provided field_ids to later detect missing required fields
        $provided = [];
        foreach ($values as $idx => $entry) {
            $fid = (int)($entry['field_id'] ?? 0);
            if ($fid > 0) { $provided[$fid] = true; }
            $props = $map[$fid] ?? null;
            if (!$props) continue;
            $val = (string)($entry['value'] ?? '');
            $val = $normalizeDigits(trim($val));
            $required = !empty($props['required']);
            if ($required && $val === '') { $errors[] = ($props['label'] ?? $props['question'] ?? 'فیلد').' الزامی است.'; continue; }
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
                    case 'national_id_company_ir':
                        if (!preg_match('/^\d{11}$/', $val)) { $errors[]='شناسه ملی شرکت نامعتبر است.'; break; }
                        if (preg_match('/^(\d)\1{10}$/', $val)) { $errors[]='شناسه ملی شرکت نامعتبر است.'; break; }
                        break;
                    case 'sheba_ir':
                        $canon = strtoupper(preg_replace('/\s+/', '', $val));
                        if (!preg_match('/^IR\d{24}$/', $canon)) { $errors[]='شماره شبا نامعتبر است.'; break; }
                        if (!self::ibanMod97($canon)) { $errors[]='شماره شبا نامعتبر است.'; }
                        break;
                    case 'credit_card_ir':
                        $digits = preg_replace('/[^0-9]/', '', $val);
                        if (!preg_match('/^\d{16}$/', $digits) || !self::luhnValid($digits)) { $errors[]='شماره کارت نامعتبر است.'; }
                        break;
                    case 'captcha_alphanumeric':
                        if (!preg_match('/^[A-Za-z0-9]{4,12}$/', $val)) $errors[]='کپچا نامعتبر است.';
                        break;
                    case 'alphanumeric_no_space':
                        if (!preg_match('/^[A-Za-z0-9\p{Arabic}]+$/u', $val)) $errors[]='فقط حروف و اعداد بدون فاصله مجاز است.';
                        break;
                    case 'alphanumeric_extended':
                        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{2,63}$/', $val)) $errors[]='شناسه آلفانامریک توسعه‌یافته نامعتبر است.';
                        break;
                    case 'alphanumeric':
                        if (!preg_match('/^[A-Za-z0-9\p{Arabic}\s]+$/u', $val)) $errors[]='فقط حروف و اعداد مجاز است.';
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
        // Also mark required fields missing entirely from payload as errors (questions only)
        $supported = ['short_text'=>1, 'long_text'=>1, 'multiple_choice'=>1, 'dropdown'=>1, 'rating'=>1];
        foreach ($map as $fid => $props) {
            if (!empty($props['required'])) {
                $type = $props['type'] ?? '';
                if (isset($supported[$type]) && empty($provided[$fid])) {
                    $errors[] = ($props['label'] ?? $props['question'] ?? 'فیلد').' الزامی است.';
                }
            }
        }
        // confirm_for validation: ensure confirmation matches original for national_id_ir & email
        // Build value map by fid
        $valueMap = [];
        foreach ($values as $entry){ $fid = (int)($entry['field_id'] ?? 0); if($fid>0){ $valueMap[$fid] = isset($entry['value']) ? (string)$entry['value'] : ''; }}
        foreach ($fields as $f){
            if (!is_array($f)) continue; $fid = isset($f['id'])?(int)$f['id']:0; if($fid<=0) continue;
            $props = isset($f['props'])?$f['props']:$f; if (empty($props['confirm_for'])) continue;
            $refIndex = (int)$props['confirm_for'];
            // confirm_for stored as index; we need to locate that field's id
            if (!isset($fields[$refIndex])) continue; $refField = $fields[$refIndex];
            $refId = isset($refField['id'])?(int)$refField['id']:0; if($refId<=0) continue;
            $fmt = $props['format'] ?? '';
            if (in_array($fmt, ['national_id_ir','email'], true)){
                $v1 = $valueMap[$refId] ?? '';
                $v2 = $valueMap[$fid] ?? '';
                if ($v1 !== '' && $v2 !== '' && $v1 !== $v2){
                    $errors[] = ($props['label'] ?? 'فیلد تایید').' با مقدار اصلی مطابقت ندارد.';
                }
            }
        }
        return $errors;
    }
}
