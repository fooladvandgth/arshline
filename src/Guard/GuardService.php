<?php
namespace Arshline\Guard;

use Arshline\Core\Api;

/**
 * GuardService: Final validation / approval layer.
 * Responsibilities:
 *  - Accept baseline fields, refined schema, original user text.
 *  - Ask model (if available) to validate: duplicates, missing types, risky additions, consistency.
 *  - Return structured result: [approved => bool, issues => [], corrections => schema?]
 *  - If model unavailable or disabled -> conservative approve=true with heuristic checks.
 *  - Logging each request/response pair to guard.log (line-delimited JSON).
 */
class GuardService
{
    protected ?object $modelClient;
    protected string $logPath;

    public function __construct(?object $modelClient = null, ?string $logPath = null)
    {
        $this->modelClient = $modelClient;
        $upl = wp_upload_dir();
        $base = is_array($upl) && !empty($upl['basedir']) ? $upl['basedir'] : dirname(__DIR__,2).'/logs';
        if (!is_dir($base)) @mkdir($base,0775,true);
        $this->logPath = $logPath ?: rtrim($base,'/').'/guard.log';
    }

    public function evaluate(array $baseline, array $schema, string $userText, array $notes = []): array
    {
        $ts = date('c');
        $issues = [];
        $issuesDetail = [];
        $approved = true;
        $corrections = [];
        $diagnostics = [
            'ai_added'=>0,
            'ai_removed'=>0,
            'type_fixed'=>0,
            'duplicates_collapsed'=>0,
            'options_corrected'=>0
        ];
        // Option flags
        $allowAdd = false;
        $whitelist = [];
        if (function_exists('get_option')){
            $gs = get_option('arshline_settings', []);
            if (is_array($gs) && !empty($gs['allow_ai_additions'])) $allowAdd = true;
            if (is_array($gs) && !empty($gs['allow_ai_additions_whitelist']) && is_array($gs['allow_ai_additions_whitelist'])){
                // Expect list of canonical labels user explicitly allows
                foreach ($gs['allow_ai_additions_whitelist'] as $wl){ if (is_string($wl) && $wl!==''){ $whitelist[ self::canon($wl) ] = true; } }
            }
        }
        $classify = function(string $code) use (&$issuesDetail){
            // severity logic
            $sev = 'warning';
            if (preg_match('/empty_|removed_ai_added|count_enforced|duplicate_label|type_demoted|format_corrected|type_corrected/',$code)) $sev='error';
            if (preg_match('/soft_fail_allowed|type_unified|duplicates_collapsed|choice_expanded/',$code)) $sev='info';
            $issuesDetail[] = ['code'=>$code,'severity'=>$sev];
        };
        // Heuristic pre-flight: ensure no empty label or missing type
        foreach (($schema['fields'] ?? []) as $idx=>$f){
            if (!is_array($f)) { $issues[] = 'field_non_array('.$idx.')'; $approved=false; continue; }
            if (empty($f['label'])){ $issues[]='empty_label('.$idx.')'; $approved=false; }
            if (empty($f['type'])){ $issues[]='empty_type('.$idx.')'; $approved=false; }
        }
        // Build baseline canonical map for origin linking
        $baseCanon = []; $baseLabels = [];
        foreach (($baseline['fields'] ?? []) as $i=>$bf){
            if (!is_array($bf)) continue; $lbl = $bf['label'] ?? ''; if ($lbl==='') continue;
            $c = self::canon($lbl); $baseCanon[$c] = $i; $baseLabels[$i]=$lbl;
        }
        // Link each field to origin or mark AI-added. Collect hallucinations for pruning if disallowed.
        $hallucinatedIdx = [];
        foreach (($schema['fields'] ?? []) as $i=>$f){
            if (!is_array($f)) continue; $lbl = $f['label'] ?? ''; $c = self::canon($lbl);
            if (isset($baseCanon[$c])){ $schema['fields'][$i]['props']['guard_origin_index'] = $baseCanon[$c]; }
            else {
                $schema['fields'][$i]['props']['guard_origin_index'] = null; // AI-added
                $schema['fields'][$i]['props']['guard_ai_added'] = true; $diagnostics['ai_added']++;
                if (!$allowAdd){
                    // If whitelist defined and matches canon -> allow despite global block
                    if (!isset($whitelist[$c])){ $hallucinatedIdx[] = $i; } else { $notes[]='guard:ai_allowed_whitelist('.$lbl.')'; }
                }
            }
        }
        // Hallucination pruning (strict)
        if (!$allowAdd && $hallucinatedIdx){
            // remove in reverse order
            rsort($hallucinatedIdx); $removed=0;
            foreach ($hallucinatedIdx as $ri){ unset($schema['fields'][$ri]); $removed++; }
            if ($removed>0){ $schema['fields'] = array_values(array_filter($schema['fields'])); $diagnostics['ai_removed']=$removed; $issues[]='removed_ai_added('.$removed.')'; $notes[]='guard:ai_removed('.$removed.')'; $approved=false; }
        }
        // Semantic type validation & correction
        foreach (($schema['fields'] ?? []) as &$f){
            if (!is_array($f)) continue; $lbl = $f['label'] ?? ''; if ($lbl==='') continue;
            $low = mb_strtolower($lbl,'UTF-8');
            $origType = $f['type'] ?? '';
            // Year / birth detection
            if (preg_match('/سال\s+تولد/u',$low) && $origType!=='short_text' && $origType!=='date_greg'){
                $f['type']='short_text'; $f['props']['format']='numeric'; $diagnostics['type_fixed']++; $issues[]='type_corrected(year_of_birth)';
            }
            // National ID vs credit card confusion
            if (preg_match('/(کد|شماره)\s*(ملی)/u',$low) && ($f['props']['format'] ?? '')==='credit_card_ir'){
                $f['props']['format']='national_id_ir'; $diagnostics['type_fixed']++; $issues[]='format_corrected(national_id_ir)';
            }
            // Rating detection from 1..10 pattern
            if (preg_match('/۱\s*تا\s*۱۰|1\s*تا\s*10/u',$low) && $origType!=='rating'){
                $f['type']='rating'; $f['props']['rating']=['min'=>1,'max'=>10,'icon'=>'like']; $diagnostics['type_fixed']++; $issues[]='type_corrected(rating_range_1_10)';
            }
            // Mis-labeled numeric where no digits keyword present
            if ($origType==='numeric' || $origType==='number'){
                if (!preg_match('/عدد|چند|تعداد|\d+/u',$low)){
                    $f['type']='short_text'; $diagnostics['type_fixed']++; $issues[]='type_demoted(non_numeric_context)';
                }
            }
            // Multiple list of names -> enforce multiple_choice
            if (preg_match('/\b(علی|رضا|نگار|سارا|محمد|حسین)\b.*\b(علی|رضا|نگار|سارا|محمد|حسین)\b/u',$low) && $origType!=='multiple_choice'){
                $names = [];
                preg_match_all('/(علی|رضا|نگار|سارا|محمد|حسین)/u',$lbl,$mN);
                if (!empty($mN[1])){ $names = array_values(array_unique($mN[1])); }
                if (count($names)>=2){
                    $f['type']='multiple_choice'; $f['props']['options']=$names; $diagnostics['options_corrected']++; $issues[]='choice_expanded(person_names)';
                }
            }
            // Force multiple_choice if at least 3 comma/، separated tokens present and not already rating/date/file
            if (!in_array($f['type'],['multiple_choice','dropdown','rating','file'])){
                $parts = preg_split('/[،,]/u',$lbl); $tokCount=0; $cands=[];
                if (is_array($parts)){
                    foreach ($parts as $p){ $pp=trim($p); if ($pp==='') continue; if (mb_strlen($pp,'UTF-8')<2) continue; $cands[]=$pp; }
                    $tokCount=count($cands);
                }
                if ($tokCount>=3){
                    $f['type']='multiple_choice'; $f['props']['options']=$cands; $diagnostics['options_corrected']++; $issues[]='choice_expanded(tokenized_list)';
                }
            }
        }
        unset($f);
        // Duplicate collapse (semantic) using simple Jaccard over tokens
        $tokenize = function(string $s){ $s=mb_strtolower($s,'UTF-8'); $s=preg_replace('/[؟?.,،!؛:\\-]+/u',' ',$s); $t=preg_split('/\s+/u',$s,-1,PREG_SPLIT_NO_EMPTY); return array_values(array_unique($t)); };
        $fields = $schema['fields'] ?? [];
        $keep = []; $removedDup=0;
        for ($i=0;$i<count($fields);$i++){
            $fi = $fields[$i]; if(!is_array($fi)||empty($fi['label'])) continue; $ti=$tokenize($fi['label']); if(!$ti) { $keep[]=$fi; continue; }
            $isDup=false;
            foreach ($keep as $kIndex=>$kf){ if(!is_array($kf)||empty($kf['label'])) continue; $tk=$tokenize($kf['label']); if(!$tk) continue; $inter=array_intersect($ti,$tk); $union=array_unique(array_merge($ti,$tk)); $j=(count($union)>0? count($inter)/count($union):0); if($j>=0.8){ $isDup=true; break; } }
            if ($isDup){ $removedDup++; continue; }
            $keep[]=$fi;
        }
    if ($removedDup>0){ $schema['fields']=$keep; $diagnostics['duplicates_collapsed']=$removedDup; $issues[]='duplicates_collapsed('.$removedDup.')'; $notes[]='guard:duplicate_collapsed('.$removedDup.')'; }
        // Unify type per origin: choose strongest encountered type across fields sharing same canon label
        $priority = ['multiple_choice'=>6,'dropdown'=>5,'file'=>4,'rating'=>3,'long_text'=>2,'short_text'=>1,'text'=>1,'numeric'=>1];
        $bestType = [];
        foreach (($schema['fields']??[]) as $idx=>$f){ if(!is_array($f)||empty($f['label'])) continue; $c=self::canon($f['label']); $t=$f['type']??''; $score=$priority[$t]??0; if(!isset($bestType[$c])||$score>$bestType[$c]['score']) $bestType[$c]=['type'=>$t,'score'=>$score,'idx'=>$idx]; }
        $typeUnified=0;
        foreach (($schema['fields']??[]) as &$f){ if(!is_array($f)||empty($f['label'])) continue; $c=self::canon($f['label']); if(isset($bestType[$c])){ $bt=$bestType[$c]['type']; if(($f['type']??'')!==$bt){ $f['type']=$bt; $typeUnified++; } } }
        unset($f);
        if ($typeUnified>0){ $issues[]='type_unified('.$typeUnified.')'; $notes[]='guard:type_unified('.$typeUnified.')'; $diagnostics['type_fixed']+=$typeUnified; }
        // Cleanup improper yes/no contamination: remove {بله|خیر} from fields whose label is not a yes/no question and that have unrelated semantic (email,date,national id,numeric measure)
        $ynRemoved=0;
        foreach (($schema['fields']??[]) as &$f){
            if (!is_array($f)) continue; $opts = $f['props']['options'] ?? null; if (!$opts || !is_array($opts)) continue;
            $canonOpts = array_map(function($o){ return preg_replace('/\s+/u','', mb_strtolower($o,'UTF-8')); }, $opts);
            $hasYN = in_array('بله',$opts,true) || in_array('خیر',$opts,true) || in_array('بلهخیر',$canonOpts,true);
            if (!$hasYN) continue;
            $lbl = mb_strtolower($f['label'] ?? '','UTF-8');
            $isBinaryIntent = (mb_strpos($lbl,'آیا')!==false) || (preg_match('/\b(است|هست)\?$/u',$lbl));
            $isSensitiveFormat = in_array(($f['props']['format']??''),['email','date_greg','date_jalali','national_id_ir','mobile_ir','numeric']);
            if ($isSensitiveFormat && !$isBinaryIntent){
                // Purge yes/no leaving field as text or numeric measure
                unset($f['props']['options']); $ynRemoved++; $issues[]='options_purged_yes_no('.($f['label']??'').')';
            }
        }
        unset($f);
        if ($ynRemoved>0){ $notes[]='guard:option_cleanup('.$ynRemoved.')'; $diagnostics['options_corrected'] += $ynRemoved; }
        // Enforce count limit: do not exceed baseline count unless additions allowed
        if(!$allowAdd){
            $baseCount = count($baseline['fields']??[]);
            $finalCount = count($schema['fields']??[]);
            if ($baseCount>0 && $finalCount>$baseCount){
                // Trim excess from end (lowest priority semantics assumption)
                $schema['fields'] = array_slice($schema['fields'],0,$baseCount);
                $issues[]='count_enforced(trimmed='.($finalCount-$baseCount).')';
                $notes[]='guard:enforced_count_limit('.$baseCount.')';
                $approved=false;
            }
        }
        // Detect near duplicate canonical labels
        $canonSeen = [];
        foreach (($schema['fields'] ?? []) as $f){
            if (!is_array($f) || empty($f['label'])) continue;
            $c = self::canon((string)$f['label']);
            if (isset($canonSeen[$c])){ $issues[]='duplicate_label('.$c.')'; $approved=false; }
            else $canonSeen[$c]=1;
        }
        // If no model client or already failing, skip model call if failing? We still can ask model for auto-corrections.
        $modelPayload = [
            'baseline_labels' => array_map(fn($f)=> is_array($f)? ($f['label']??''):'', $baseline['fields']??[]),
            'final_schema' => $schema,
            'user_text' => $userText,
            'notes' => $notes,
            'instructions' => 'Check Persian form schema. Respond ONLY JSON: {"approved":bool,"issues":string[],"suggested_schema"?:{"fields":[...]}}. Rules: flag hallucinated fields (unrelated to user_text), flag duplicate semantics, ensure national id has format, infer rating if scale 1..N present.'
        ];
        $modelResult = null; $modelOk=false; $latMs=null; $errMsg=null;
        if ($this->modelClient){
            $t0=microtime(true);
            try {
                // Reuse model client interface assuming ->complete() returning ['ok'=>bool,'text'=>string]
                $resp = $this->modelClient->complete([
                    ['role'=>'system','content'=>'You are a strict JSON validator for form schemas.'],
                    ['role'=>'user','content'=>json_encode($modelPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
                ], ['temperature'=>0]);
                $latMs = (int)round((microtime(true)-$t0)*1000);
                if (!empty($resp['ok']) && !empty($resp['text'])){
                    $txt = trim($resp['text']);
                    $try = json_decode($txt,true);
                    if (!is_array($try)){
                        $txt2 = preg_replace('/```json|```/u','',$txt);
                        $try = json_decode(trim($txt2),true);
                    }
                    if (is_array($try)) { $modelResult=$try; $modelOk=true; }
                }
            } catch (\Throwable $e){ $errMsg=$e->getMessage(); }
        }
        if ($modelOk && is_array($modelResult)){
            if (isset($modelResult['issues']) && is_array($modelResult['issues'])){
                foreach ($modelResult['issues'] as $is){ if (is_string($is) && $is!=='') $issues[]=$is; }
            }
            if (isset($modelResult['approved'])){ $approved = (bool)$modelResult['approved'] && $approved; }
            if (isset($modelResult['suggested_schema']['fields']) && is_array($modelResult['suggested_schema']['fields'])){
                $corrections = $modelResult['suggested_schema'];
            }
        }
        // Deduplicate issues
    $issues = array_values(array_unique($issues));
    foreach ($issues as $ic){ $classify($ic); }
        // Auto adopt corrections only if baseline small and corrections not bigger by >2 fields
        $adopted=false;
        if ($corrections && isset($corrections['fields']) && is_array($corrections['fields'])){
            $origCount = count($schema['fields']??[]);
            $newCount = count($corrections['fields']);
            if ($newCount>0 && $newCount <= $origCount+2){
                $schema = $corrections; $adopted=true; $notes[]='guard:corrections_adopted('.$newCount.')';
            } else {
                $notes[]='guard:corrections_ignored(size)';
            }
        }
        // Final decision: if still failing but no critical (only stylistic) allow preview
        if (!$approved && $issues){
            $onlyStyle = !array_filter($issues, fn($i)=> !preg_match('/empty_|duplicate_label/', $i));
            if ($onlyStyle){ $notes[]='guard:soft_fail_allowed'; $approved=true; }
        }
        $record = [
            'ts'=>$ts,
            'approved'=>$approved,
            'issues'=>$issues,
            'adopted'=>$adopted,
            'lat_ms'=>$latMs,
            'model_ok'=>$modelOk,
            'error'=>$errMsg,
            'payload_summary'=>[
                'baseline_count'=>count($baseline['fields']??[]),
                'final_count'=>count($schema['fields']??[])
            ],
            'diagnostics'=>$diagnostics
        ];
        $this->log($record, $modelPayload, $modelResult);
        return [ 'approved'=>$approved, 'issues'=>$issues, 'issues_detail'=>$issuesDetail, 'schema'=>$schema, 'notes'=>$notes, 'adopted'=>$adopted, 'lat_ms'=>$latMs, 'diagnostics'=>$diagnostics ];
    }

    protected function log(array $record, array $request, $response): void
    {
        $line = json_encode([
            'record'=>$record,
            'request'=>$request,
            'response'=>$response
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->logPath, $line."\n", FILE_APPEND);
    }

    public static function canon(string $label): string
    {
        $l = mb_strtolower($label,'UTF-8');
        $l = preg_replace('/[\s[:punct:]]+/u','', $l);
        return $l;
    }
}
