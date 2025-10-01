<?php
// CLI tool to tune Persian person-name matching thresholds and weights
// Usage: php tools/name_match_tuner.php

mb_internal_encoding('UTF-8');

function faNorm($s){
    $s = (string)$s;
    $s = str_replace(["\xE2\x80\x8C", "\xC2\xA0"], ['', ' '], $s); // ZWNJ, NBSP
    $s = str_replace(["ي","ك","ة"],["ی","ک","ه"], $s); // Arabic->Persian
    $s = preg_replace('/\p{Mn}+/u', '', $s); // diacritics
    $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s); // punct to space
    $s = preg_replace('/\s+/u',' ', $s);
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    return $s;
}

function tokenize($s){
    $titles = [ 'آقای', 'آقا', 'خانم', 'دکتر', 'مهندس', 'استاد' ];
    $n = faNorm($s);
    $t = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $set = [];
    foreach ($titles as $w){ $set[$w]=true; $set[mb_strtolower($w,'UTF-8')]=true; }
    foreach ($t as $x){ if ($x!=='' && !isset($set[$x])) $out[]=$x; }
    return $out;
}

function tokSim(array $A, array $B){
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
            if ($ta===$tb) continue;
            if (mb_strlen($ta,'UTF-8')>=3 && mb_strlen($tb,'UTF-8')>=3){
                if (mb_strpos($ta,$tb,0,'UTF-8')!==false || mb_strpos($tb,$ta,0,'UTF-8')!==false){ $partial++; break; }
            }
        }
    }
    if ($exact>=2) return 1.0;
    $score = 0.6*$j + 0.2*($exact>0?1:0) + 0.2*($partial>0?1:0);
    return ($score>1.0)?1.0:$score;
}

function simLev($a, $b){
    $aN = $a; $bN = $b;
    if (function_exists('iconv')){
        $aT = @iconv('UTF-8', 'ASCII//TRANSLIT', $aN);
        $bT = @iconv('UTF-8', 'ASCII//TRANSLIT', $bN);
        if (is_string($aT) && $aT !== '') $aN = $aT;
        if (is_string($bT) && $bT !== '') $bN = $bT;
    }
    $pct = 0.0; similar_text($aN, $bN, $pct);
    return max(0.0, min(1.0, $pct / 100.0));
}

function combinedScore(array $hintTokens, array $rowTokens, $wTok=0.65, $wLev=0.35, $bonusExact=0.20, $bonusPartial=0.15){
    $scTok = tokSim($hintTokens, $rowTokens);
    $hintFull = faNorm(implode(' ', $hintTokens));
    $rowFull  = faNorm(implode(' ', $rowTokens));
    $scLev = simLev($hintFull, $rowFull);
    $bonus = 0.0; $first = $hintTokens[0] ?? '';
    if ($first !== ''){
        foreach ($rowTokens as $rt){
            if ($rt === $first){ $bonus = $bonusExact; break; }
            if ((mb_strlen($rt,'UTF-8')>=3 || mb_strlen($first,'UTF-8')>=3) && (mb_strpos($rt,$first,0,'UTF-8')===0 || mb_strpos($first,$rt,0,'UTF-8')===0)){
                $bonus = max($bonus, $bonusPartial);
            }
        }
    }
    $sc = $wTok*$scTok + $wLev*$scLev + $bonus;
    return $sc>1.0?1.0:$sc;
}

$tests = [
    // [hint, rowName, expectedMatch]
    ['نیما', 'نیما عزیزی', 1],
    ['نیما سیدعزیزی', 'نیما سید عزیزی', 1],
    ['نیما', 'محمد نیما', 1],
    ['نیما', 'مینا عزیزی', 0],
    ['نیما', 'نیماا', 1],
    ['سیدعزیزی', 'نیما سید عزیزی', 1],
    ['آقای نیما عزیزی', 'نیما عزیزی', 1],
    ['نیما', 'آقای نیما', 1],
    ['کیان', 'كیان', 1], // Arabic kaf
    ['نیما علی', 'نیما علی‌اکبر', 1],
    ['نسترن', 'نیما عزیزی', 0],
    ['حسین', 'حسین‌', 1],
    ['مهدی', 'مهدی محمدی', 1],
    ['محمد', 'حمید', 0],
];

function evaluate($wTok, $wLev, $thr1, $thr2, $bonusExact=0.20, $bonusPartial=0.15){
    global $tests;
    $tp=$tn=$fp=$fn=0; $details=[];
    foreach ($tests as $t){
        [$hint, $row, $exp] = $t;
        $hTok = tokenize($hint);
        $rTok = tokenize($row);
        $sc = combinedScore($hTok, $rTok, $wTok, $wLev, $bonusExact, $bonusPartial);
        $tokCount = count($hTok);
        $thr = ($tokCount<=1) ? $thr1 : $thr2;
        $pred = ($sc >= $thr) ? 1 : 0;
        if ($pred && $exp) $tp++; elseif (!$pred && !$exp) $tn++; elseif ($pred && !$exp) $fp++; else $fn++;
        $details[] = [ 'hint'=>$hint, 'row'=>$row, 'score'=>round($sc,3), 'threshold'=>$thr, 'pred'=>$pred, 'exp'=>$exp ];
    }
    $acc = ($tp+$tn)/max(1,($tp+$tn+$fp+$fn));
    $prec = $tp/max(1,($tp+$fp));
    $rec  = $tp/max(1,($tp+$fn));
    $f1 = ($prec+$rec>0)? (2*$prec*$rec/($prec+$rec)) : 0;
    return [ 'acc'=>$acc, 'prec'=>$prec, 'rec'=>$rec, 'f1'=>$f1, 'tp'=>$tp, 'tn'=>$tn, 'fp'=>$fp, 'fn'=>$fn, 'details'=>$details ];
}

// Sweep a small grid around current defaults
$best = null; $bestCfg = null;
$weights = [ [0.6,0.4], [0.65,0.35], [0.7,0.3] ];
$thr1s = [0.45,0.5,0.55,0.6]; // single-token
$thr2s = [0.6,0.65,0.7,0.75]; // two-or-more tokens
$bonuses = [ [0.2,0.15], [0.25,0.2], [0.15,0.1] ];

foreach ($weights as $w){
    foreach ($thr1s as $t1){
        foreach ($thr2s as $t2){
            foreach ($bonuses as $b){
                [$wTok,$wLev] = $w; [$bE,$bP] = $b;
                $res = evaluate($wTok,$wLev,$t1,$t2,$bE,$bP);
                $score = $res['f1'];
                if ($best===null || $score > $best){ $best=$score; $bestCfg=['wTok'=>$wTok,'wLev'=>$wLev,'thr1'=>$t1,'thr2'=>$t2,'bExact'=>$bE,'bPart'=>$bP,'metrics'=>$res]; }
            }
        }
    }
}

$cur = evaluate(0.65,0.35,0.50,0.65,0.20,0.15);

function fmtPct($x){ return sprintf('%.1f%%', 100.0*$x); }

echo "Current config => acc: ".fmtPct($cur['acc'])." prec: ".fmtPct($cur['prec'])." rec: ".fmtPct($cur['rec'])." f1: ".fmtPct($cur['f1'])."\n";
echo "Best config    => wTok={$bestCfg['wTok']} wLev={$bestCfg['wLev']} thr1={$bestCfg['thr1']} thr2={$bestCfg['thr2']} bExact={$bestCfg['bExact']} bPart={$bestCfg['bPart']}\n";
echo "                 acc: ".fmtPct($bestCfg['metrics']['acc'])." prec: ".fmtPct($bestCfg['metrics']['prec'])." rec: ".fmtPct($bestCfg['metrics']['rec'])." f1: ".fmtPct($bestCfg['metrics']['f1'])."\n\n";

// Print a small table of test cases for the best config
$details = $bestCfg['metrics']['details'];
foreach ($details as $d){
    $ok = ($d['pred']===$d['exp']) ? 'OK' : '!!';
    echo "[{$ok}] hint='{$d['hint']}' vs row='{$d['row']}' => score={$d['score']} thr={$d['threshold']} pred={$d['pred']} exp={$d['exp']}\n";
}
