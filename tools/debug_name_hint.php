<?php
/**
 * Debug name hint extraction for Persian analytics
 */
echo "Debug script started...\n";

$question = 'حال نیما چطوره؟';

// Test name hint extraction - simpler approach
$nhint = '';

// Direct pattern for "حال X چطوره؟"
if (preg_match('/حال\s+([^؟\?]+?)\s+چطور/ui', $question, $mm)) {
    $nhint = trim($mm[1]);
    echo "Pattern 1 matched: '{$nhint}'\n";
} elseif (preg_match('/^حال\s+([^؟\?]+)/ui', $question, $mm)) {
    $nhint = trim($mm[1]);
    $nhint = preg_replace('/\s*چطور.*$/ui', '', $nhint); // remove trailing چطوره
    echo "Pattern 2 matched: '{$nhint}'\n";
}

echo "Question: {$question}\n";
echo "Extracted name hint: '{$nhint}'\n";

// Test normalization
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

$normalized = $faNorm($nhint);
echo "Normalized name hint: '{$normalized}'\n";

// Test against our sample names
$sampleNames = ['نیما سیدعزیزی', 'فهیمه کرم الهی'];
foreach ($sampleNames as $name) {
    $normalizedName = $faNorm($name);
    echo "Name: '{$name}' -> Normalized: '{$normalizedName}'\n";
    
    // Test if hint tokens are in name tokens
    $hintTokens = array_filter(explode(' ', $normalized), function($t){ return trim($t) !== ''; });
    $nameTokens = array_filter(explode(' ', $normalizedName), function($t){ return trim($t) !== ''; });
    
    echo "  Hint tokens: " . json_encode($hintTokens, JSON_UNESCAPED_UNICODE) . "\n";
    echo "  Name tokens: " . json_encode($nameTokens, JSON_UNESCAPED_UNICODE) . "\n";
    
    // Check partial matching
    $partialMatch = false;
    foreach ($hintTokens as $ht) {
        foreach ($nameTokens as $nt) {
            if (mb_strpos($nt, $ht, 0, 'UTF-8') !== false || mb_strpos($ht, $nt, 0, 'UTF-8') !== false) {
                $partialMatch = true;
                echo "  PARTIAL MATCH: '{$ht}' <-> '{$nt}'\n";
                break 2;
            }
        }
    }
    
    if (!$partialMatch) {
        echo "  NO PARTIAL MATCH\n";
    }
    echo "\n";
}