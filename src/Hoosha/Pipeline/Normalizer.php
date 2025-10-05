<?php
namespace Arshline\Hoosha\Pipeline;

/**
 * Normalizer: canonical label + lightweight tokenizer utilities extracted from monolithic Api.
 * Pure stateless helpers to enable reuse across Pruner, Auditor, DuplicateResolver without reflection.
 */
class Normalizer
{
    /** Canonicalize a label: remove leading numbering & punctuation & normalize spaces (lowercase). */
    public static function canonLabel(string $label): string
    {
        $l = trim($label);
        // Remove Persian/Arabic/Latin digits + dot/parenthesis at start (e.g., "1.", "۲.", "13)")
        $l = preg_replace('/^[\p{N}۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩]+[\.)\-_:]+\s*/u','',$l);
        // Collapse internal whitespace
        $l = preg_replace('/\s+/u',' ', $l);
        return mb_strtolower($l,'UTF-8');
    }

    /** Lightweight tokenizer for similarity scoring (Persian oriented stop words & punctuation stripping). */
    public static function tokenize(string $s): array
    {
        $s = mb_strtolower((string)$s,'UTF-8');
        $s = preg_replace('/[\p{P}\p{S}]+/u',' ',$s);
        $parts = preg_split('/\s+/u', trim($s)) ?: [];
        if (!$parts) return [];
        static $stop = ['از','در','به','و','یا','را','با','برای','که','این','یک','آن','تا','های','می','یا','چه','رو'];
        $out=[];
        foreach ($parts as $p){
            if ($p===''|| mb_strlen($p,'UTF-8')<2) continue;
            if (in_array($p,$stop,true)) continue;
            $out[]=$p;
        }
        return $out ? array_values(array_unique($out)) : [];
    }
}
