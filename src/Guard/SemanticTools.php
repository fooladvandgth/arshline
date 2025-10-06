<?php
namespace Arshline\Guard;

/**
 * SemanticTools: lightweight Persian-oriented normalization & similarity helpers.
 * Examples are symptomatic only, not the targets of the fix.
 * For illustration only — do not hardcode fixes for specific labels.
 */
class SemanticTools
{
    /** Optional override threshold for tests (null = disabled) */
    protected static ?float $overrideThreshold = null;

    /** Set a temporary global override for clustering similarity threshold (testing) */
    public static function setOverrideThreshold(?float $value): void
    {
        if ($value === null) { self::$overrideThreshold = null; return; }
        $v = (float)$value; if ($v < 0.1) $v = 0.1; if ($v > 0.99) $v = 0.99; self::$overrideThreshold = $v;
    }

    /** Normalize label (Persian-focused):
     *  - lowercase
     *  - unify digits (Persian & Arabic to ASCII)
     *  - unify Arabic variants (ك->ک, ي->ی, ة->ه,ۀ->ه, ؤ->و, ئ->ی)
     *  - strip tatweel (ـ)
     *  - remove diacritics (harakat)
     *  - normalize ellipsis (…) -> '...'
     *  - collapse/replace punctuation (؟ ? ، , ؛ ; . ! - :)
     *  - collapse ZWNJ / half-space to normal space (‌)
     *  - basic typo map + fuzzy short-token correction
     *  - remove polite/stop tokens
     */
    public static function normalize_label(string $label): string
    {
        $l = mb_strtolower($label,'UTF-8');
        // Standard digit normalization (Persian/Arabic digits to ASCII)
        $map = [ '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
        $letterMap = [ 'ك'=>'ک','ي'=>'ی','ة'=>'ه','ۀ'=>'ه','ؤ'=>'و','ئ'=>'ی','أ'=>'ا','إ'=>'ا','آ'=>'ا' ];
        $l = strtr($l,$map + $letterMap);
        // Remove tatweel
        $l = str_replace('ـ','',$l);
        // Replace ellipsis char with three dots
        $l = str_replace('…','...', $l);
        // Remove Arabic diacritics (harakat)
        $l = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u','',$l);
        // Normalize half-space / ZWNJ to space
        $l = preg_replace("/\x{200C}+|\x{200B}+/u"," ",$l); // ZWNJ & zero-width space
        // Punctuation to space
        $l = preg_replace('/[؟?.,،!؛:\-]+/u',' ',$l);
        // Collapse whitespace
        $l = preg_replace('/\s+/u',' ',$l);
        $l = trim($l);
        // Basic typo replacements (common informal mistakes) BEFORE stop-word pruning
        // Keep list short & generic to avoid overfitting sample labels.
        $typoMap = [
            'تچیه' => 'چیه',
            'چطوره' => 'چطوره', // placeholder (example structure)
            'ك' => 'ک', // Arabic keheh to Persian
            'ي' => 'ی',
        ];
        if ($l !== ''){
            $partsRaw = preg_split('/\s+/u',$l,-1,PREG_SPLIT_NO_EMPTY);
            foreach ($partsRaw as &$pr){ if (isset($typoMap[$pr])) $pr = $typoMap[$pr]; }
            unset($pr);
            // Optional lightweight fuzzy correction for very short tokens (length 3–6)
            // We only attempt to correct if token edit distance 1 from a frequent target inside same string.
            // Collect frequency counts
            $freq = []; foreach ($partsRaw as $pr){ $freq[$pr] = ($freq[$pr]??0)+1; }
            $canonTargets = array_keys($freq);
            foreach ($partsRaw as &$pr){
                $len = mb_strlen($pr,'UTF-8'); if ($len<3 || $len>6) continue;
                // Skip if already appears more than once (assume canonical)
                if (($freq[$pr]??0) > 1) continue;
                foreach ($canonTargets as $ct){
                    if ($ct === $pr) continue; $cl = mb_strlen($ct,'UTF-8'); if ($cl<3 || $cl>6) continue;
                    if (abs($cl - $len) > 1) continue;
                    if (self::levenshtein_utf8($pr,$ct) === 1){ $pr = $ct; break; }
                }
            }
            unset($pr);
            $l = implode(' ',$partsRaw);
        }
        // Remove filler / polite tokens (extensible) AFTER typo/fuzzy normalization
        $stop = ['لطفا','لطفاً','خواهشمندیم','خود','شما','را','لطف'];
        $parts = preg_split('/\s+/u',$l,-1,PREG_SPLIT_NO_EMPTY);
        $filtered = [];
        foreach ($parts as $p){ if (in_array($p,$stop,true)) continue; $filtered[]=$p; }
        $l = implode(' ',$filtered);
        return $l;
    }

    /** Token set for Jaccard-like operations */
    public static function token_set(string $label): array
    {
        $n = self::normalize_label($label);
        $t = preg_split('/\s+/u',$n,-1,PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($t));
    }

    /** Jaccard similarity over normalized tokens */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::token_set($a); $tb = self::token_set($b);
        if (!$ta || !$tb) return 0.0;
        $inter = array_intersect($ta,$tb);
        $union = array_unique(array_merge($ta,$tb));
        if (count($union)===0) return 0.0;
        return count($inter)/count($union);
    }

    /** Cluster labels with similarity >= threshold (default 0.8) */
    public static function cluster_labels(array $labels, float $threshold = 0.8): array
    {
        if (self::$overrideThreshold !== null) { $threshold = self::$overrideThreshold; }
        $clusters = [];
        $used = [];
        $count = count($labels);
        for ($i=0;$i<$count;$i++){
            if (isset($used[$i])) continue;
            $base = $labels[$i];
            $cluster = [$i];
            for ($j=$i+1;$j<$count;$j++){
                if (isset($used[$j])) continue;
                $sim = self::similarity($base,$labels[$j]);
                if ($sim >= $threshold){ $cluster[]=$j; $used[$j]=true; }
            }
            foreach ($cluster as $ci){ $used[$ci]=true; }
            $clusters[]=$cluster;
        }
        return $clusters;
    }
    public static function levenshtein_utf8(string $a, string $b): int
    {
        if (preg_match('/^[\x00-\x7F]+$/',$a.$b)) return levenshtein($a,$b);
        $aa = preg_split('//u',$a,-1,PREG_SPLIT_NO_EMPTY); $bb = preg_split('//u',$b,-1,PREG_SPLIT_NO_EMPTY);
        $la = count($aa); $lb = count($bb);
        if ($la===0) return $lb; if ($lb===0) return $la;
        $dp = [];
        for ($i=0;$i<=$la;$i++){ $dp[$i]=[$i]; }
        for ($j=0;$j<=$lb;$j++){ $dp[0][$j]=$j; }
        for ($i=1;$i<=$la;$i++){
            for ($j=1;$j<=$lb;$j++){
                $cost = ($aa[$i-1] === $bb[$j-1]) ? 0 : 1;
                $dp[$i][$j] = min(
                    $dp[$i-1][$j] + 1,
                    $dp[$i][$j-1] + 1,
                    $dp[$i-1][$j-1] + $cost
                );
            }
        }
        return (int)$dp[$la][$lb];
    }
}
