<?php
namespace Arshline\Hoosha\Pipeline;

/**
 * DuplicateResolver: collapse semantically duplicate fields using Jaccard similarity
 * over Normalizer::tokenize output. Preference order: baseline fields > earlier order.
 * Appends notes of form:
 *   heur:semantic_duplicate(labelB->labelA)
 * and a summary note:
 *   heur:semantic_duplicate_collapsed(N)
 */
class DuplicateResolver
{
    public function collapse(array &$schema, array $baselineCanonMap, array &$notes, float $threshold = 0.6): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $fields = $schema['fields'];
        $n = count($fields);
        if ($n < 2) return;
        $tokens = [];
        $canon  = [];
        for ($i=0;$i<$n;$i++){
            $f = $fields[$i];
            if (!is_array($f)) { $tokens[$i]=[]; $canon[$i]=''; continue; }
            $lbl = (string)($f['label'] ?? '');
            $canon[$i] = Normalizer::canonLabel($lbl);
            $tokens[$i] = Normalizer::tokenize($lbl);
        }
        $removed = [];
        $collapsed = 0;
        for ($i=0;$i<$n;$i++){
            if (isset($removed[$i])) continue;
            for ($j=$i+1;$j<$n;$j++){
                if (isset($removed[$j])) continue;
                $a = $tokens[$i]; $b = $tokens[$j];
                if (!$a || !$b) continue; // skip empty token sets
                // Jaccard similarity
                $inter = array_intersect($a,$b); $union = array_unique(array_merge($a,$b));
                if (empty($union)) continue;
                $jaccard = count($inter)/count($union);
                if ($jaccard >= $threshold){
                    $keep = $i; $drop = $j; // default: keep earlier
                    $inBaselineI = isset($baselineCanonMap[$canon[$i]]);
                    $inBaselineJ = isset($baselineCanonMap[$canon[$j]]);
                    if ($inBaselineI && !$inBaselineJ){ $keep=$i; $drop=$j; }
                    elseif ($inBaselineJ && !$inBaselineI){ $keep=$j; $drop=$i; }
                    // else earlier order stands (already set)
                    if (!isset($removed[$drop])){
                        $removed[$drop]=true; $collapsed++;
                        $labelDrop = mb_substr((string)($fields[$drop]['label']??''),0,24,'UTF-8');
                        $labelKeep = mb_substr((string)($fields[$keep]['label']??''),0,24,'UTF-8');
                        $notes[] = 'heur:semantic_duplicate('.$labelDrop.'->'.$labelKeep.')';
                    }
                }
            }
        }
        if ($collapsed>0){
            // rebuild fields
            $schema['fields'] = array_values(array_filter($fields, function($f,$idx) use ($removed){ return !isset($removed[$idx]); }, ARRAY_FILTER_USE_BOTH));
            $notes[]='heur:semantic_duplicate_collapsed('.$collapsed.')';
        }
    }
}
