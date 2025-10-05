<?php
namespace Arshline\Hoosha\Pipeline;

class Pruner
{
    /**
     * Prune or tag hallucinated / unrelated fields and collapse repeated consent/acceptance style fields.
     * Ported from monolithic Api::hoosha_prune_hallucinated_fields; now standalone & testable.
     */
    public function hallucinationPrune(array &$schema, array $baseline, string $userText, array &$notes, array &$progress, array $baselineCanonMap): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $raw = mb_strtolower($userText,'UTF-8');
        // Build baseline token bag
        $baselineTokens=[]; if (!empty($baseline['fields'])){
            foreach ($baseline['fields'] as $bf){ if(!is_array($bf)) continue; $l=$bf['label']??''; $baselineTokens=array_merge($baselineTokens, Normalizer::tokenize($l)); }
        }
        $userTokens = Normalizer::tokenize($raw);
        $sig = array_unique(array_merge($baselineTokens,$userTokens));
        $sigMap = array_fill_keys($sig,true);
        $kept=[]; $dropped=0; $consentIndex=null; $dupConsent=0; $fixedTypes=0;
        foreach ($schema['fields'] as $idx=>$f){
            if(!is_array($f)){ continue; }
            $lbl = (string)($f['label']??''); $canonTokens = Normalizer::tokenize($lbl);
            $canonLabel = Normalizer::canonLabel($lbl);
            $low = mb_strtolower($lbl,'UTF-8');
            $isConsent = (mb_strpos($low,'موافقت')!==false || mb_strpos($low,'شرایط')!==false || mb_strpos($low,'قوانین')!==false || mb_strpos($low,'privacy')!==false || mb_strpos($low,'پذیرش')!==false);
            if ($isConsent){
                if ($consentIndex===null){ $consentIndex = count($kept); }
                else {
                    if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[]; $f['props']['duplicate_of']=$consentIndex; $dupConsent++;
                }
            }
            if (($f['type']??'')==='long_text' && preg_match('/تصویر|عکس|آپلود|بارگذاری/u',$low)){
                $f['type']='file'; if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[]; $f['props']['format']='file_upload'; $fixedTypes++;
            }
            $src = $f['props']['source'] ?? '';
            if (in_array($src,['coverage_injected','coverage_injected_refined','file_injected','recovered_scan','reconciled_from_baseline','heuristic_or_unchanged','refined_split'],true)){
                $kept[]=$f; continue; }
            $overlap=0; foreach($canonTokens as $tk){ if(isset($sigMap[$tk])) $overlap++; }
            $score = (count($canonTokens)>0)? ($overlap / count($canonTokens)) : 0.0;
            if (isset($baselineCanonMap[$canonLabel])){ $kept[]=$f; continue; }
            $isNameField = (preg_match('/^(نام|اسم)(\s|$)/u', $low) || preg_match('/(نام|اسم)\s*(?:و)?\s*(نام)?\s*خانوادگی/u',$low));
            if ($isNameField){ $kept[]=$f; continue; }
            if ($score < 0.3 && $dropped < 3){
                $dropped++;
                $notes[]='heur:pruned_hallucinated(label='.mb_substr($lbl,0,24,'UTF-8').')';
                continue;
            }
            $kept[] = $f;
        }
        if ($dropped>0){ $schema['fields']=$kept; $notes[]='heur:hallucination_prune_count('.$dropped.')'; $progress[]=['step'=>'hallucination_pruned','message'=>'حذف فیلدهای بی‌ربط ('.$dropped.')']; }
        if ($dupConsent>0){ $notes[]='heur:duplicate_consent_tagged('.$dupConsent.')'; }
        if ($fixedTypes>0){ $notes[]='heur:mis_typed_file_corrected('.$fixedTypes.')'; }
    }
}
