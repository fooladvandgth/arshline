<?php
echo "تحلیل امتیاز matching برای فهیمه کرم الهی\n";

// Persian normalization function (same as in API)
$faNorm = function($s){
    $s = (string)$s;
    $s = str_replace(["\xE2\x80\x8C", "\xC2\xA0"], ['', ' '], $s); // ZWNJ, NBSP
    $s = str_replace(["ي","ك","ة"],["ی","ک","ه"], $s); // Arabic->Persian
    $s = preg_replace('/\p{Mn}+/u', '', $s); // remove diacritics
    $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s); // punct to space
    $s = preg_replace('/\s+/u',' ', $s);
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    // Enhanced Persian normalization for better matching
    $s = str_replace(['آ', 'أ', 'إ'], 'ا', $s); // Alef variations
    return $s;
};

$stripTitles = function(array $tokens){
    $titles = ['آقا','آقای','خانم','دکتر','مهندس','استاد','جناب'];
    return array_filter($tokens, function($t) use ($titles){ return !in_array($t, $titles); });
};

$tokenize = function($s) use ($faNorm,$stripTitles){
    $n = $faNorm($s);
    $t = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
    return $stripTitles($t);
};

// Test data
$nameHint = "نیما";
$fullName = "فهیمه کرم الهی";

echo "Query hint: '$nameHint'\n";
echo "Target name: '$fullName'\n\n";

// Tokenization
$hintTokens = $tokenize($nameHint);
$nameTokens = $tokenize($fullName);

echo "Hint tokens: " . json_encode($hintTokens, JSON_UNESCAPED_UNICODE) . "\n";
echo "Name tokens: " . json_encode($nameTokens, JSON_UNESCAPED_UNICODE) . "\n\n";

// Token similarity (Jaccard + bonuses)
$tokSim = function(array $A, array $B){
    if (empty($A) || empty($B)) return 0.0;
    $A = array_values(array_unique($A));
    $B = array_values(array_unique($B));
    $Ai = []; foreach ($A as $a){ $Ai[$a]=true; }
    $Bi = []; foreach ($B as $b){ $Bi[$b]=true; }
    $inter = array_values(array_intersect(array_keys($Ai), array_keys($Bi)));
    $unionCount = count(array_unique(array_merge(array_keys($Ai), array_keys($Bi))));
    $j = $unionCount>0 ? (count($inter)/$unionCount) : 0.0;
    $exact = count($inter);
    $partial = 0;
    foreach ($A as $ta){
        foreach ($B as $tb){
            if ($ta !== $tb && (mb_strpos($ta,$tb,0,'UTF-8')!==false || mb_strpos($tb,$ta,0,'UTF-8')!==false)){ $partial++; break; }
        }
    }
    return min(1.0, $j + 0.1*$exact + 0.05*$partial);
};

$scTok = $tokSim($hintTokens, $nameTokens);
echo "Token similarity: $scTok\n";

// String similarity
$rowFull = $faNorm($fullName);
$hintFull = $faNorm($nameHint);

echo "Normalized hint: '$hintFull'\n";
echo "Normalized name: '$rowFull'\n";

$simLev = function(string $a, string $b) {
    $aN = $a; $bN = $b;
    // try ASCII transliteration for better granularity; fallback to raw
    if (function_exists('iconv')){
        $aT = @iconv('UTF-8', 'ASCII//TRANSLIT', $aN);
        $bT = @iconv('UTF-8', 'ASCII//TRANSLIT', $bN);
        if (is_string($aT) && $aT !== '') $aN = $aT;
        if (is_string($bT) && $bT !== '') $bN = $bT;
    }
    $pct = 0.0; similar_text($aN, $bN, $pct);
    return max(0.0, min(1.0, $pct / 100.0));
};

$scLev = $simLev($hintFull, $rowFull);
echo "Levenshtein similarity: $scLev\n";

// Partial bonus
$partialBonus = 0.0;
foreach ($hintTokens as $ht){
    foreach ($nameTokens as $rt){
        if (mb_strpos($rt, $ht, 0, 'UTF-8') !== false || mb_strpos($ht, $rt, 0, 'UTF-8') !== false){
            $partialBonus = max($partialBonus, 0.25);
            echo "Partial match found: '$ht' <-> '$rt'\n";
        }
    }
}
echo "Partial bonus: $partialBonus\n";

// First-name bonus
$bonus = 0.0;
$bExact = 0.20;
$bPartial = 0.15;
$firstHint = isset($hintTokens[0]) ? (string)$hintTokens[0] : '';
if ($firstHint !== ''){
    foreach ($nameTokens as $rt){
        if ($rt === $firstHint){ 
            $bonus = $bExact; 
            echo "Exact first name match: '$firstHint' = '$rt'\n";
            break; 
        }
        if ((mb_strlen($rt,'UTF-8')>=3 || mb_strlen($firstHint,'UTF-8')>=3) && (mb_strpos($rt,$firstHint,0,'UTF-8')===0 || mb_strpos($firstHint,$rt,0,'UTF-8')===0)){ 
            $bonus = max($bonus, $bPartial);
            echo "Partial first name match: '$firstHint' ~ '$rt'\n";
        }
    }
}
echo "First name bonus: $bonus\n";

// Final calculation
$wTok = 0.65;
$wLev = 0.35;
$sc = ($wTok*$scTok) + ($wLev*$scLev) + $bonus + $partialBonus;
if ($sc > 1.0) $sc = 1.0;

echo "\nFinal calculation:\n";
echo "Score = (0.65 * $scTok) + (0.35 * $scLev) + $bonus + $partialBonus\n";
echo "Score = " . ($wTok*$scTok) . " + " . ($wLev*$scLev) . " + $bonus + $partialBonus\n";
echo "Score = $sc\n";
echo "Threshold: 0.30\n";
echo "Match: " . ($sc >= 0.30 ? "YES" : "NO") . "\n";