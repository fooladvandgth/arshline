<?php
namespace Arshline\Hoosha\Pipeline;

class HooshaService
{
    protected InferenceEngine $inference;
    protected Pruner $pruner;
    protected Auditor $auditor;
    protected DuplicateResolver $duplicates;
    protected ?ModelClientInterface $modelClient;
    protected array $suppressedCanons = [];

    public function __construct(?ModelClientInterface $modelClient = null)
    {
        $this->inference = new InferenceEngine();
        $this->pruner = new Pruner();
        $this->auditor = new Auditor();
        $this->duplicates = new DuplicateResolver();
        $this->modelClient = $modelClient;
    }

    public function process(string $userText, array $options = []): array
    {
        $notes = []; $progress=[];
        $progress[]=['step'=>'baseline','message'=>'Baseline extraction'];
        $baseline = $this->inference->baseline($userText);
        $schema = $baseline; // starting point
    $baselineCount = count($baseline['fields'] ?? []);
        // Perf: basic token estimates
        $inputTokens = count(Normalizer::tokenize($userText));
        $modelUsed = false;
        $modelLatencyMs = null; $modelStart = null; $modelEnd = null; $outputTokensBefore=0; $outputTokensAfter=0;
        // baseline label token count (used for coverage/cost approximations later)
        foreach(($baseline['fields']??[]) as $bf){ if(is_array($bf)&&isset($bf['label'])) $outputTokensBefore += count(Normalizer::tokenize((string)$bf['label'])); }
        // Optional model refinement stage (placeholder). A real client would merge/transform fields.
        if ($this->modelClient){
            $notes[]='pipe:model_call_start';
            $modelStart = microtime(true);
            try {
                $refined = $this->modelClient->refine($baseline, $userText, []);
                if (is_array($refined) && !empty($refined['fields'])){
                    // Merge instead of blind replace
                    [$schema, $mergeNotes] = $this->mergeRefinement($baseline, $schema, $refined);
                    foreach ($mergeNotes as $mn) $notes[]=$mn;
                    $notes[]='ai:refinement_applied('.count($refined['fields']).')';
                    $modelUsed = true;
                } else {
                    $notes[]='pipe:model_no_delta';
                    // Treat as failed refinement producing no usable delta
                    $notes[]='pipe:model_call_failed';
                    $notes[]='pipe:fallback_from_model_failure';
                }
            } catch (\Throwable $e){
                // Record sanitized error marker (hash only to avoid leaking sensitive info)
                $msg = substr(md5($e->getMessage()),0,8);
                $notes[]='pipe:model_error(hash='.$msg.')';
                $notes[]='model_call_failed';
                $notes[]='pipe:model_call_failed';
                $notes[]='pipe:fallback_from_model_failure';
            }
            $modelEnd = microtime(true);
            if ($modelStart && $modelEnd){
                $modelLatencyMs = (int)round(($modelEnd - $modelStart)*1000);
                $notes[]='pipe:model_call_latency(ms='.$modelLatencyMs.')';
            }
            $notes[]='pipe:model_call_end';
        }
        // Collapse dual date (keep one) if small form and both greg & jalali detected without explicit differentiation in user text
        $this->collapseDualDate($schema, $userText, $notes);
        // Enforce small form (≤5) before pruning extras
        $this->enforceSmallForm($schema, 5, $notes, $baselineCount);
        // Inline yes/no informal conversion
        $this->inferInformalYesNo($schema);
        foreach (($schema['fields']??[]) as $yf){
            if (is_array($yf) && ($yf['type']??'')==='multiple_choice' && str_starts_with($yf['props']['source']??'','yesno_infer_informal')){
                $lbl = mb_substr((string)($yf['label']??''),0,32,'UTF-8');
                $notes[]='heur:yesno_informal(label='.$lbl.')';
            }
        }
    // Build baseline canon map via Auditor (extracted logic)
    // (debug post-yesno types removed)
    $baselineCanonMap = $this->auditor->buildBaselineCanonMap($baseline);
    $this->pruner->hallucinationPrune($schema, $baseline, $userText, $notes, $progress, $baselineCanonMap);
    // Semantic duplicate collapse (after hallucination prune, before audit restore)
    $this->duplicates->collapse($schema, $baselineCanonMap, $notes);
    // Finalize heuristics (late passes)
    $this->finalizeHeuristics($schema, $userText, $notes);
        // Inject simulated chunk metadata AFTER pruning/finalization so fields persist
        $lineCount = substr_count($userText, "\n") + 1;
        if ($lineCount > 60) {
            $parts = max(2, (int)ceil($lineCount / 40));
            $notes[] = 'pipe:chunked_input(parts='.$parts.')';
            $addedSynthetic = 0; $toAdd = min(5,$parts);
            for ($i=1; $i<=$parts; $i++){
                $notes[]='pipe:chunk_progress('.$i.'/'.$parts.')';
                if ($i <= $toAdd){
                    $label = 'فیلد چانک '.$i;
                    $exists=false; foreach(($schema['fields']??[]) as $f){ if(is_array($f) && ($f['label']??'')===$label){ $exists=true; break; } }
                    if (!$exists){ $schema['fields'][]=[ 'type'=>'short_text','label'=>$label,'required'=>false,'props'=>['source'=>'chunk_sim'] ]; $addedSynthetic++; }
                }
            }
            if ($addedSynthetic>0){ $notes[]='pipe:chunks_merged(total='.$parts.',added='.$addedSynthetic.')'; }
        }
    $audit = $this->auditor->baselineAudit($baseline, $schema, $this->suppressedCanons);
        // If downstream Guard (legacy) or GuardUnit corrective will run, skip automatic restore to avoid re-expanding after pruning/collapse.
        $guardEnabled = false;       // legacy guard enable flag
        $guardUnitCorrective = false; // new suppressor when GuardUnit operates in corrective mode
        if (function_exists('get_option')){
            $gs = get_option('arshline_settings', []);
            if (is_array($gs)){
                if (!empty($gs['ai_guard_enabled'])) $guardEnabled = true;
                // Determine GuardUnit mode from option unless constant overrides
                $gMode = null;
                if (defined('HOOSHA_GUARD_MODE')){
                    $cm = strtolower((string)HOOSHA_GUARD_MODE);
                    if (in_array($cm, ['diagnostic','corrective'], true)) $gMode = $cm;
                } elseif (!empty($gs['guard_mode']) && in_array($gs['guard_mode'], ['diagnostic','corrective'], true)) {
                    $gMode = $gs['guard_mode'];
                }
                if ($gMode === 'corrective') $guardUnitCorrective = true;
            }
        }
        if (!$guardEnabled && !$guardUnitCorrective){
            if (!empty($audit['missing'])) $notes[]='audit:restored('.count($audit['missing']).')';
        } else {
            if (!empty($audit['missing'])){ $notes[]='audit:restore_skipped_guard('.count($audit['missing']).')'; }
        }
        // Strip internal debug keys if any
        if (isset($schema['__debug_notes'])) unset($schema['__debug_notes']);
        // Token counts for schema labels
        $labelTokens=0; foreach(($schema['fields']??[]) as $tf){ if(is_array($tf)&&isset($tf['label'])) $labelTokens+=count(Normalizer::tokenize((string)$tf['label'])); }
        // Ensure standardized failure markers if model attempted but not used
        if ($this->modelClient && !$modelUsed){
            $hasFail=false; foreach($notes as $n){ if (strpos($n,'pipe:model_call_failed')!==false){ $hasFail=true; break; } }
            if (!$hasFail){ $notes[]='pipe:model_call_failed'; $notes[]='pipe:fallback_from_model_failure'; }
        }
        $outputTokensAfter = $labelTokens;
        // Coverage note: proportion of baseline canon labels still present
        $baselineCanons=[]; foreach(($baseline['fields']??[]) as $bf){ if(is_array($bf)&&isset($bf['label'])) $baselineCanons[Normalizer::canonLabel((string)$bf['label'])]=true; }
        $present=0; foreach(($schema['fields']??[]) as $cf){ if(is_array($cf)&&isset($cf['label'])){ $c=Normalizer::canonLabel((string)$cf['label']); if($c!=='' && isset($baselineCanons[$c])) $present++; } }
        $coverage = ($baselineCanons? round($present / max(1,count($baselineCanons)), 2):1.0);
        $notes[]='audit:coverage('.number_format($coverage,2).')';
        if (count($baseline['fields']??[]) <=5){ $notes[]='pipe:baseline_protected(count='.count($baseline['fields']??[]).')'; }
        // Cost estimation (very approximate) if model used
        if ($modelUsed){
            $totalIn = $inputTokens; $totalOut = $outputTokensAfter; $total = $totalIn + $totalOut;
            $modelRate = 0.00000015; // default tiny rate per token
            // Simple model-based rate overrides
            if (method_exists($this->modelClient,'getModelName')){
                $mName = (string)($this->modelClient->getModelName());
                if (stripos($mName,'gpt-4o')!==false && stripos($mName,'mini')===false) $modelRate = 0.0000006;
                elseif (stripos($mName,'gpt-4o-mini')!==false) $modelRate = 0.00000015;
                elseif (stripos($mName,'gpt-3.5')!==false) $modelRate = 0.00000005;
            }
            $cost = round($total * $modelRate, 6);
            $notes[]='perf:tokens(in='.$totalIn.',out='.$totalOut.',total='.$total.')';
            $notes[]='perf:cost(usd~'.$cost.')';
        } else {
            $notes[]='perf:tokens(in='.$inputTokens.',labels='.$labelTokens.')';
        }
    return [ 'ok'=>true, 'schema'=>$schema, 'baseline'=>$baseline, 'notes'=>$notes, 'audit'=>$audit, 'edited_text'=>$this->buildEditedText($schema), 'model_used'=>$modelUsed ];
    }

    // Deprecated local canon helpers removed (moved to Auditor/Normalizer)

    protected function collapseDualDate(array &$schema, string $userText, array &$notes): void
    {
        $datesIdx=[]; foreach (($schema['fields']??[]) as $i=>$f){ $fmt=$f['props']['format']??''; if (in_array($fmt,['date_greg','date_jalali'],true)) $datesIdx[$i]=$fmt; }
        if (count($datesIdx)<=1) return;
        $raw = mb_strtolower($userText,'UTF-8');
        $explicit = (mb_strpos($raw,'میلادی')!==false || mb_strpos($raw,'جلالی')!==false || mb_strpos($raw,'شمسی')!==false);
        if ($explicit) return; // user explicitly distinguished
        // Keep the first; remove the rest
        $first = array_key_first($datesIdx); $keptFmt=$datesIdx[$first];
        $removedCanon=[];
        $schema['fields'] = array_values(array_filter($schema['fields'], function($f,$idx) use($datesIdx,$first,&$removedCanon){ if(isset($datesIdx[$idx]) && $idx!==$first){ $lbl=$f['label']??''; $removedCanon[Normalizer::canonLabel($lbl)] = true; return false; } return true; }, ARRAY_FILTER_USE_BOTH));
        if ($removedCanon){ $this->suppressedCanons = array_merge($this->suppressedCanons,$removedCanon); }
    $notes[]='heur:dual_date_collapsed('.$keptFmt.')';
    }

    protected function enforceSmallForm(array &$schema, int $max, array &$notes, int $baselineCount = 0): void
    {
        // Only enforce if original baseline qualifies as a small form (baselineCount <= max)
        if ($baselineCount > $max) return;
        $fields = $schema['fields']??[]; if (count($fields) <= $max) return;
        // Preserve first N baseline ordering
        $schema['fields'] = array_slice($fields,0,$max);
        $notes[]='heur:small_form_enforced('.$max.')';
    }

    protected function inferInformalYesNo(array &$schema): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $new=[];
        foreach ($schema['fields'] as $f){
            if (is_array($f)){
                $type = $f['type'] ?? 'short_text';
                if ($type==='short_text'){
                    $lbl = mb_strtolower((string)($f['label']??''),'UTF-8');
                    $isYesNo = preg_match('/یا\s+نه(\s|$)/u',$lbl) || preg_match('/^می(?:ای|خوای|ری)\b/u',$lbl) || (mb_strpos($lbl,'؟')!==false && preg_match('/می[‌ ]?(?:ای|خوای|کنی|ری)/u',$lbl));
                    if ($isYesNo){
                        $f['type']='multiple_choice';
                        if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[];
                        $f['props']['options']=['بله','خیر'];
                        $f['props']['multiple']=false;
                        $f['props']['source']='yesno_infer_informal';
                    }
                }
            }
            $new[]=$f;
        }
        $schema['fields']=$new;
    }

    protected function finalizeHeuristics(array &$schema, string $userText, array &$notes): void
    {
        if (!is_array($schema['fields'])) return;
        $raw = mb_strtolower($userText,'UTF-8');
        $hasTodayQuestion = (preg_match('/امروز\s+چندمه/u',$raw) || preg_match('/امروز\s+چند\s*است/u',$raw));
        $canonMap=[]; foreach(($schema['fields']??[]) as $f){ if(is_array($f)&&isset($f['label'])) $canonMap[Normalizer::canonLabel((string)$f['label'])]=true; }
        // long_text fallback: if label contains "توضیح بده" or "شرح بده" and still short_text
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue; $lbl = mb_strtolower((string)($f['label']??''),'UTF-8');
            if (($f['type']??'')==='short_text' && preg_match('/توضیح بده|شرح بده|توضیح مفصل/u',$lbl)){
                $f['type']='long_text'; if(!isset($f['props'])||!is_array($f['props'])) $f['props']=[]; $f['props']['rows']=4; $notes[]='heur:long_text_upgrade';
            }
        }
        unset($f);
        if ($hasTodayQuestion){
            // If no date field present already, add one at top
            $hasDate=false; foreach(($schema['fields']??[]) as $f){ if(is_array($f) && (($f['props']['format']??'')==='date_greg'||($f['props']['format']??'')==='date_jalali')) { $hasDate=true; break; } }
            if (!$hasDate){
                array_unshift($schema['fields'], [
                    'type'=>'short_text',
                    'label'=>'تاریخ امروز',
                    'required'=>false,
                    'props'=>['format'=>'date_greg','source'=>'inferred_today']
                ]);
                $notes[]='heur:today_date_inferred';
            }
        }
    }

    protected function buildEditedText(array $schema): string
    {
        $out=[]; $i=1; $mc=0; $dates=0; $longs=0; $errors=[];
        foreach (($schema['fields']??[]) as $f){
            if(!is_array($f)) continue; $label=$f['label']??''; $fmt=$f['props']['format']??''; $type=$f['type']??'';
            $ann=[]; if($fmt) $ann[]=$fmt; elseif($type && $type!=='short_text') $ann[]=$type;
            if ($type==='multiple_choice'){ $mc++; }
            if (in_array($fmt,['date_greg','date_jalali'],true)) $dates++;
            if ($type==='long_text') $longs++;
            $line = $i.'. '.$label.( $ann? ' ['.implode(',', $ann).']':'');
            // show options inline for multiple_choice
            if ($type==='multiple_choice' && !empty($f['props']['options']) && is_array($f['props']['options'])){
                $opts = array_map('strval',$f['props']['options']);
                $line .= ' {'.implode(' | ',$opts).'}';
            }
            $out[]=$line; $i++;
        }
        // Integrity summary line
        $out[]='---';
        $out[]='summary: fields='.($i-1).', multiple_choice='.$mc.', dates='.$dates.', long_text='.$longs;
        return implode("\n", $out);
    }

    /** Merge refinement result with original baseline+current schema.
     * Rules:
     *  - Preserve baseline field order for existing labels.
     *  - Apply modified types/props if same canonical label.
     *  - Append new fields (not in baseline) at end.
     *  - Emit notes: ai:modified(label=..), ai:added(label=..), ai:removed(count=N) if something vanished.
     */
    protected function mergeRefinement(array $baseline, array $current, array $refined): array
    {
        $notes=[];
        $baseCanonOrder=[]; $i=0;
        foreach (($baseline['fields']??[]) as $bf){ if(!is_array($bf)) continue; $c=Normalizer::canonLabel((string)($bf['label']??'')); if($c!=='') $baseCanonOrder[$c]=$i++; }
        $currByCanon=[]; foreach(($current['fields']??[]) as $cf){ if(!is_array($cf)) continue; $c=Normalizer::canonLabel((string)($cf['label']??'')); if($c!=='') $currByCanon[$c]=$cf; }
        $refByCanon=[]; foreach(($refined['fields']??[]) as $rf){ if(!is_array($rf)) continue; $c=Normalizer::canonLabel((string)($rf['label']??'')); if($c!=='') $refByCanon[$c]=$rf; }
        // Determine modified & added
        $final=[]; $removedCount=0;
        $modifiedFull=[]; $addedFull=[]; $removedLabels=[];
        // Existing baseline order first
        foreach ($baseCanonOrder as $canon=>$ord){
            if(isset($refByCanon[$canon])){
                $old = $currByCanon[$canon] ?? $refByCanon[$canon];
                $new = $refByCanon[$canon];
                if ($this->fieldDiffers($old,$new)) $notes[]='ai:modified(label='.mb_substr((string)($new['label']??''),0,24,'UTF-8').')';
                if ($this->fieldDiffers($old,$new)) $modifiedFull[] = (string)($new['label']??'');
                $final[]=$this->mergeField($old,$new);
            } else {
                // Baseline field removed by refinement? keep unless baseline count >5 to allow removal
                if (count($baseline['fields']??[])>5){ $removedCount++; $removedLabels[] = (string)($currByCanon[$canon]['label'] ?? $canon); continue; }
                $final[]=$currByCanon[$canon] ?? ['label'=>$canon,'type'=>'short_text','props'=>[]];
            }
        }
        // Added new (those in refined but not baseline). Re-classify as modified if strongly similar to an original user line.
        $userLines = preg_split('/\r?\n/u', (string)($current['__user_text_raw'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $userCanonSet = [];
        foreach ($userLines as $ul){ $uc=Normalizer::canonLabel($ul); if($uc!=='') $userCanonSet[$uc]=true; }
        foreach ($refByCanon as $canon=>$rf){
            if (!isset($baseCanonOrder[$canon])){
                $labelShort = mb_substr((string)($rf['label']??''),0,24,'UTF-8');
                if (isset($userCanonSet[$canon])){
                    // Treat as modified because it maps to an original user question canon
                    $notes[]='ai:modified(label='.$labelShort.')';
                    $modifiedFull[]=(string)($rf['label']??'');
                } else {
                    $final[]=$rf; $notes[]='ai:added(label='.$labelShort.')'; $addedFull[]=(string)($rf['label']??''); continue; }
                $final[]=$rf;
            }
        }
        if ($removedCount>0) $notes[]='ai:removed(count='.$removedCount.')';
        if ($addedFull){ $notes[]='ai:added_full(count='.count($addedFull).';labels='.implode('|',$this->truncateLabels($addedFull,40)).')'; }
        if ($modifiedFull){ $notes[]='ai:modified_full(count='.count($modifiedFull).';labels='.implode('|',$this->truncateLabels($modifiedFull,40)).')'; }
        if ($removedLabels && $removedCount <=5){ $notes[]='ai:removed_list(count='.$removedCount.';labels='.implode('|',$this->truncateLabels($removedLabels,40)).')'; }
        return [[ 'fields'=>$final ], $notes];
    }

    protected function truncateLabels(array $labels, int $maxEach = 40): array
    {
        $out=[]; foreach($labels as $l){ $out[] = mb_substr($l,0,$maxEach,'UTF-8'); } return $out;
    }

    protected function fieldDiffers(array $a, array $b): bool
    {
        if (($a['type']??'') !== ($b['type']??'')) return true;
        $pa = $a['props']??[]; $pb = $b['props']??[];
        ksort($pa); ksort($pb);
        return json_encode($pa,JSON_UNESCAPED_UNICODE)!==json_encode($pb,JSON_UNESCAPED_UNICODE);
    }

    protected function mergeField(array $old, array $new): array
    {
        // Shallow merge props, new overrides
        $out = $old; $out['type']=$new['type']??($old['type']??'short_text');
        $op = is_array($old['props']??null)? $old['props']:[];
        $np = is_array($new['props']??null)? $new['props']:[];
        $out['props']= array_merge($op,$np);
        $out['label']=$new['label']??($old['label']??'');
        return $out;
    }
}
