<?php
namespace Arshline\Hoosha\Pipeline;

/**
 * NaturalEditProcessor: lightweight parser for simple natural language edit commands.
 * Currently supports (Persian):
 *   "سوال دو رو اجباری کن" / "سوال 2 را اجباری کن" / "سوال شماره ۲ اجباری شود"
 * Multiple commands can be separated by newlines.
 * Adds notes like: edit:required_set(q=2,label=...)
 */
class NaturalEditProcessor
{
    protected static array $persianNumberWords = [
        'یک'=>1,'۱'=>1,'دو'=>2,'۲'=>2,'سه'=>3,'۳'=>3,'چهار'=>4,'۴'=>4,'پنج'=>5,'۵'=>5,
        'شش'=>6,'۶'=>6,'هفت'=>7,'۷'=>7,'هشت'=>8,'۸'=>8,'نه'=>9,'۹'=>9,'نهه'=>9,'ده'=>10,'۱۰'=>10
    ];

    /** Apply commands to schema in-place */
    public static function apply(array &$schema, string $commands, array &$notes): void
    {
        if (!is_array($schema['fields'] ?? null) || trim($commands)==='') return;
        $lines = preg_split('/\r?\n/u',$commands,-1,PREG_SPLIT_NO_EMPTY);
        foreach ($lines as $line){ self::applyLine($schema,$line,$notes); }
    }

    protected static function applyLine(array &$schema, string $line, array &$notes): void
    {
        $raw = trim($line);
        if ($raw==='') return;
        // Normalization: convert Persian digits to ASCII for easier parse
        $digitMap = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
        $norm = strtr($raw,$digitMap);
        // Pattern: سوال (شماره)? (N|word) (را|رو)? (اجباری|الزامی) (کن|شود|بشه)
        if (preg_match('/سوال\s*(?:شماره\s*)?([0-9]+|'.self::numberWordsAlternation().')\s*(?:را|رو)?\s*(?:کاملا\s*)?(?:اجباری|الزامی)\s*(?:کن|شود|بشه)?/u',$norm,$m)){
            $idxToken = $m[1];
            $qIndex = self::resolveIndex($idxToken);
            if ($qIndex===null){ $notes[]='edit:error(number_unresolved='.mb_substr($idxToken,0,12,'UTF-8').')'; return; }
            $zeroBased = $qIndex-1;
            $fields =& $schema['fields'];
            if ($zeroBased < 0 || $zeroBased >= count($fields)){
                $notes[]='edit:error(out_of_range='.$qIndex.')';
                return;
            }
            if (!is_array($fields[$zeroBased])) return;
            $already = !empty($fields[$zeroBased]['required']);
            $fields[$zeroBased]['required'] = true;
            $labelShort = mb_substr((string)($fields[$zeroBased]['label']??''),0,32,'UTF-8');
            $notes[] = 'edit:required_'.($already?'kept':'set').'(q='.$qIndex.',label='.$labelShort.')';
            return;
        }
        // Future: extend with patterns (گزینه اضافه کن ... , نوع سوال دو را تاریخ کن , ...)
        $notes[]='edit:ignored(raw='.mb_substr($raw,0,30,'UTF-8').')';
    }

    protected static function numberWordsAlternation(): string
    {
        $words = array_keys(self::$persianNumberWords);
        // Escape potential regex metachars (not expected here but safe)
        $escaped = array_map(function($w){ return preg_quote($w,'/'); }, $words);
        return '(?:'.implode('|',$escaped).')';
    }

    protected static function resolveIndex(string $token): ?int
    {
        $t = trim($token);
        if ($t==='') return null;
        if (ctype_digit($t)){
            $i = intval($t,10); return $i>0? $i : null;
        }
        return self::$persianNumberWords[$t] ?? null;
    }
}
