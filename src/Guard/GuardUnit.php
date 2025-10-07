<?php
namespace Arshline\Guard;

/**
 * GuardUnit (Phase 1 Skeleton)
 * Self-aware validation & correction orchestrator (diagnostic + corrective modes).
 * Examples are symptomatic only, not the targets of the fix.
 */
class GuardUnit
{
    protected array $settings;
    protected array $capabilities;
    protected string $mode; // diagnostic|corrective
    protected ?string $requestId;

    public function __construct(array $settings = [], array $capabilities = [], string $mode = 'diagnostic', ?string $requestId = null)
    {
        $this->settings = $settings;
        if ($capabilities) {
            $this->capabilities = $capabilities;
        } else {
            if (class_exists('Arshline\\Guard\\CapabilityScanner')) {
                $this->capabilities = CapabilityScanner::get();
            } else {
                $this->capabilities = $this->loadCapabilities();
            }
        }
        $this->mode = in_array($mode,['diagnostic','corrective'],true) ? $mode : 'diagnostic';
        $this->requestId = $requestId;
    }

    /**
     * Main entry.
     * @param string[] $userQuestions
     * @param array $modelSchema  Expected: ['fields'=>[...],'meta'=>?]
     * @param array $context Optional extra: baseline_schema, etc.
     * @return array result contract (status, schema, issues, notes, metrics, debug?)
     */
    public function run(array $userQuestions, array $modelSchema, array $context = []): array
    {
        $notes = [];
        $issues = [];
        $metrics = [
            'input_count'=>count($userQuestions),
            'output_count'=>count($modelSchema['fields']??[]),
            'hallucinations_removed'=>0,
            'hallucination_count'=>0,
            'semantic_merges'=>0,
            'duplicate_semantic_count'=>0,
            'type_corrections'=>0,
            'format_corrections'=>0,
            'similarity_avg'=>0.0,
            'coverage_rate'=>0.0,
            'ai_intents'=>0,
            'ai_actions_applied'=>0,
            'ai_validation_confidence'=>null,
        ];
        $debug = [];
        $fields = is_array($modelSchema['fields'] ?? null) ? $modelSchema['fields'] : [];
        $baseline = $context['baseline_schema']['fields'] ?? [];

        if (class_exists('Arshline\\Guard\\GuardLogger')) {
            GuardLogger::phase('start', ['mode'=>$this->mode,'request_id'=>$this->requestId]);
        }

        // Phase: Meta Preflight
        if (!empty($modelSchema['meta']) && is_array($modelSchema['meta'])){
            $m = $modelSchema['meta'];
            if ((isset($m['input_count']) && isset($m['output_count'])) && $m['input_count'] !== $m['output_count']){
                $issues[]='meta_mismatch(count)';
                $notes[]='guard:meta_violation(input='.$m['input_count'].',output='.$m['output_count'].')';
                if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::phase('meta_violation', ['input'=>$m['input_count'],'output'=>$m['output_count']]); }
            }
            if (isset($m['added']) && $m['added']>0){ $issues[]='meta_added_positive'; $notes[]='guard:meta_added('.$m['added'].')'; if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::phase('meta_added', ['added'=>$m['added']]); } }
        }

        // Phase: Normalize & semantic scaffold
        $questionNorm = [];
        foreach ($userQuestions as $qi=>$q){ $questionNorm[$qi] = $this->normalize($q); }
        $fieldInternal = [];
        foreach ($fields as $fi=>$f){
            if (!is_array($f)) continue;
            $rawLabel = (string)($f['label'] ?? '');
            $norm = $this->normalize($rawLabel);
            $fieldInternal[] = [
                'idx'=>$fi,
                'raw_label'=>$rawLabel,
                'norm_label'=>$norm,
                'orig_type'=>$f['type'] ?? null,
                'working_type'=>$f['type'] ?? null,
                'props'=> $f['props'] ?? [],
                'matched_question'=>null,
                'similarity'=>0.0,
                'status'=>'original'
            ];
            if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fi,'action'=>'normalize','label'=>$rawLabel,'norm'=>$norm]); }
        }

        // Optional Phase: AI Intent Analysis (pre-semantic) if assist enabled
        $aiAssist = $this->isAiAssistEnabled(); $aiConfidenceThr = $this->getAiConfidenceThreshold();
        $intentMap = [];
        // Lightweight static intent detection (non-injective) using IntentRules
        if (class_exists('Arshline\\Guard\\IntentRules')){
            $joinedQuestions = implode("\n", $userQuestions);
            $staticIntents = \Arshline\Guard\IntentRules::detect($joinedQuestions);
            foreach ($staticIntents as $ir){
                $notes[] = 'intent_detected:'.$ir['id'];
                if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::phase('intent_detected', ['id'=>$ir['id'],'auto_inject'=>$ir['auto_inject']?'1':'0']); }
            }
        }
        if ($aiAssist && class_exists('Arshline\\Guard\\GuardAIClient')){
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_analysis_start', ['request_id'=>$this->requestId]); }
            $client = $this->buildAiClient();
            if ($client){
                $questionsForAi = $userQuestions; // feed original questions
                $cap = $this->capabilities;
                $res = null;
                try { $res = $client->analyzeIntents($questionsForAi, $cap); } catch(\Throwable $e){ $notes[]='guard:ai_intent_fail('.$e->getMessage().')'; }
                if (is_array($res) && !empty($res['ok']) && !empty($res['items'])){
                    foreach ($res['items'] as $it){
                        $intentMap[$this->normalize($it['label'])] = $it;
                        if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_analysis', ['label'=>$it['label'],'intent'=>$it['intent'],'confidence'=>$it['confidence']]); }
                    }
                    $metrics['ai_intents'] = count($res['items']);
                }
            }
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_analysis_end', ['intent_count'=>$metrics['ai_intents']]); }
        }

        // Phase: Semantic Matching (simple Jaccard via SemanticTools if available) — may later blend AI intents
        $simSum = 0.0; $simCount=0; $threshold = $this->getSimilarityThreshold();
        $matchedQuestionsSet = [];
        foreach ($fieldInternal as &$fiRef){
            $bestScore = 0.0; $bestQ = null;
            foreach ($questionNorm as $qi=>$qn){
                $score = $this->similarity($fiRef['norm_label'], $qn);
                if ($score > $bestScore){ $bestScore=$score; $bestQ=$qi; }
            }
            $fiRef['matched_question'] = $bestQ;
            $fiRef['similarity'] = $bestScore;
            if ($bestQ !== null){ $simSum += $bestScore; $simCount++; $matchedQuestionsSet[$bestQ]=true; }
            if ($bestScore >= $threshold){
                $notes[]='guard:semantic_link(field_idx='.$fiRef['idx'].',q='.$bestQ.',score='.round($bestScore,3).')';
                if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fiRef['idx'],'action'=>'semantic_link','question_idx'=>$bestQ,'score'=>round($bestScore,4)]); }
            } else {
                $notes[]='guard:low_similarity(field_idx='.$fiRef['idx'].',score='.round($bestScore,3).')';
                if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fiRef['idx'],'action'=>'low_similarity','score'=>round($bestScore,4)]); }
            }
        }
        unset($fiRef);
        if ($simCount>0){ $metrics['similarity_avg'] = round($simSum/$simCount,4); }
        // Coverage: distinct matched questions / total questions (avoid div by zero)
        if ($metrics['input_count']>0){ $metrics['coverage_rate'] = round(count($matchedQuestionsSet)/$metrics['input_count'],4); }

        // Phase: Canonical Merge (high-similarity duplicates mapping to same question)
        if ($simCount>0){
            $mergedIdx = [];
            // Group by matched_question
            $byQ = [];
            foreach ($fieldInternal as $k=>$fi){ if ($fi['matched_question']!==null){ $byQ[$fi['matched_question']][]=$k; } }
            foreach ($byQ as $qIdx=>$list){ if (count($list)<=1) continue; // multiple candidates for same question
                // Track duplicate semantic occurrence
                $dupCount = count($list)-1; // number of extras beyond canonical
                $metrics['duplicate_semantic_count'] += $dupCount;
                if ($this->mode==='corrective'){
                    // Choose canonical: highest similarity; tie -> shortest normalized label (more concise)
                    $best = null; $bestScore=-1; $bestLen=PHP_INT_MAX;
                    foreach ($list as $idx){ $fi=$fieldInternal[$idx]; $score=$fi['similarity']; $len=mb_strlen($fi['norm_label'],'UTF-8');
                        if ($score>$bestScore || ($score===$bestScore && $len<$bestLen)){ $best=$idx; $bestScore=$score; $bestLen=$len; }
                    }
                    $removedForQ = 0;
                    foreach ($list as $idx){ if ($idx===$best) continue; $fieldInternal[$idx]['status']='merged'; $mergedIdx[]=$idx; $removedForQ++; }
                    if ($removedForQ>0){ $metrics['semantic_merges'] += $removedForQ; $notes[]='guard:semantic_merge(q='.$qIdx.',removed='.$removedForQ.')'; if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::phase('semantic_merge', ['question_idx'=>$qIdx,'removed'=>$removedForQ]); } }
                } else {
                    // Diagnostic: record issue but do not merge
                    $issues[]='present_both(q='.$qIdx.')';
                    if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::phase('semantic_duplicate', ['question_idx'=>$qIdx,'candidates'=>count($list)]); }
                }
            }
            if ($this->mode==='corrective' && $mergedIdx){
                // Remove merged entries only in corrective
                $fieldInternal = array_values(array_filter($fieldInternal, fn($f)=>$f['status']!=='merged'));
            }
        }

        // Phase: Type / Format correction (heuristic + AI intent suggestion) before hallucination pruning
        if ($this->mode==='corrective'){
            foreach ($fieldInternal as &$fiRef){
                $raw = mb_strtolower($fiRef['raw_label'],'UTF-8');
                $changed = false; $changeNotes = [];
                // AI intent suggestion (if available & high confidence)
                $intentKey = $this->normalize($fiRef['raw_label']);
                if ($aiAssist && isset($intentMap[$intentKey])){
                    $ii = $intentMap[$intentKey]; $conf = (float)($ii['confidence'] ?? 0);
                    if ($conf >= $aiConfidenceThr){
                        if (!empty($ii['suggested_type']) && $fiRef['working_type'] !== $ii['suggested_type']){ $fiRef['working_type']=$ii['suggested_type']; $changed=true; $changeNotes[]='ai_type='.$ii['suggested_type']; }
                        if (!empty($ii['suggested_format']) && (($fiRef['props']['format'] ?? '') !== $ii['suggested_format'])){ $fiRef['props']['format']=$ii['suggested_format']; $changed=true; $changeNotes[]='ai_format='.$ii['suggested_format']; }
                        if (!empty($ii['props']) && is_array($ii['props'])){ foreach($ii['props'] as $k=>$v){ if(!isset($fiRef['props'][$k])){ $fiRef['props'][$k]=$v; $changed=true; $changeNotes[]='ai_prop='.$k; } } }
                    }
                }
                // Date pattern
                if (preg_match('/تاریخ|date/u',$raw)){
                    if (!in_array($fiRef['working_type'], ['short_text','date'], true)) { $fiRef['working_type']='short_text'; $changed=true; $changeNotes[]='type=date->short_text'; }
                    $fmt = $fiRef['props']['format'] ?? '';
                    if ($fmt==='') { $fiRef['props']['format']='date_greg'; $changed=true; $changeNotes[]='format=date_greg'; }
                }
                // National ID
                if (preg_match('/کد\s*ملی|شماره\s*ملی/u',$raw)){
                    if ($fiRef['working_type']!=='short_text'){ $fiRef['working_type']='short_text'; $changed=true; $changeNotes[]='type=short_text'; }
                    if (($fiRef['props']['format'] ?? '')!=='national_id_ir'){ $fiRef['props']['format']='national_id_ir'; $changed=true; $changeNotes[]='format=national_id_ir'; }
                }
                // Mobile / phone
                if (preg_match('/(شماره).*(موبایل|تلفن)|موبایل|تلفن/u',$raw)){
                    if ($fiRef['working_type']!=='short_text'){ $fiRef['working_type']='short_text'; $changed=true; $changeNotes[]='type=short_text'; }
                    if (($fiRef['props']['format'] ?? '')!=='mobile_ir'){ $fiRef['props']['format']='mobile_ir'; $changed=true; $changeNotes[]='format=mobile_ir'; }
                }
                // Age / number
                if (preg_match('/سن|عمر|age/u',$raw)){
                    if ($fiRef['working_type']!=='number'){ $fiRef['working_type']='number'; $changed=true; $changeNotes[]='type=number'; }
                }
                // Yes/No intent (binary) -> multiple_choice with 2 options (defer creation - just tag?)
                if (preg_match('/(آیا|یا\s+نه|می(?:خوای|خواهی)|میخواید)/u',$raw)){
                    if ($fiRef['working_type']!=='multiple_choice'){ $fiRef['working_type']='multiple_choice'; $changed=true; $changeNotes[]='type=multiple_choice'; }
                    if (empty($fiRef['props']['options']) || count($fiRef['props']['options'])<2){
                        $fiRef['props']['options']=['بله','خیر']; $changed=true; $changeNotes[]='options=yesno';
                    }
                }
                if ($changed){
                    $metrics['type_corrections']++;
                    $notes[]='guard:type_correct_phase(field_idx='.$fiRef['idx'].':'.implode('|',$changeNotes).')';
                    if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fiRef['idx'],'action'=>'type_format_correct','details'=>$changeNotes]); }
                }
            }
            unset($fiRef);
        }

        // Optional Phase: AI Reasoning (conflict resolution) BEFORE hallucination pruning modifies list
        if ($aiAssist && class_exists('Arshline\\Guard\\GuardAIClient')){
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_reasoning_start', []); }
            $client = $this->buildAiClient();
            if ($client){
                $baselineLabels = array_map(fn($q)=>$q, $userQuestions); // treat questions as canonical baseline for conflicts
                $currentFieldsLabels = array_map(fn($f)=>['label'=>$f['raw_label']], $fieldInternal);
                try {
                    $rr = $client->reasonConflicts($baselineLabels, $currentFieldsLabels);
                    if (!empty($rr['ok']) && !empty($rr['items'])){
                        foreach ($rr['items'] as $act){
                            $conf = (float)($act['confidence'] ?? 0);
                            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_reasoning', ['action'=>$act['action'],'target'=>$act['target_label'],'confidence'=>$conf,'reason'=>$act['reason']]); }
                            if ($conf >= $aiConfidenceThr){
                                // Apply simple actions: replace->mark duplicate, merge->no-op now but note, drop->mark hallucination, keep->ignore
                                foreach ($fieldInternal as &$fiRef){ if($fiRef['raw_label']===$act['target_label']){
                                    if ($act['action']==='drop'){ $fiRef['status']='hallucination'; $metrics['hallucinations_removed']++; $metrics['ai_actions_applied']++; }
                                    elseif ($act['action']==='replace'){ $fiRef['status']='hallucination'; $metrics['hallucinations_removed']++; $metrics['ai_actions_applied']++; }
                                    elseif ($act['action']==='merge'){ /* future: merge semantics */ $metrics['ai_actions_applied']++; }
                                } }
                                unset($fiRef);
                            }
                        }
                    }
                } catch(\Throwable $e){ $notes[]='guard:ai_reason_fail('.$e->getMessage().')'; }
            }
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_reasoning_end', ['actions_applied'=>$metrics['ai_actions_applied']]); }
        }

        // Phase: Hallucination detection
        foreach ($fieldInternal as &$fiRef){
            if ($fiRef['similarity'] < $threshold){
                $fiRef['status']='hallucination';
                $metrics['hallucination_count']++;
                if ($this->mode==='corrective'){
                    $metrics['hallucinations_removed']++;
                    if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fiRef['idx'],'action'=>'prune','reason'=>'low_similarity','score'=>round($fiRef['similarity'],4)]); }
                } else {
                    $notes[]='guard:would_prune(field_idx='.$fiRef['idx'].')';
                    if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::fieldDecision(['field_idx'=>$fiRef['idx'],'action'=>'flag_hallucination','score'=>round($fiRef['similarity'],4)]); }
                }
            }
        }
        unset($fiRef);

        // Apply corrective pruning if enabled
        if ($this->mode==='corrective'){
            $fieldInternal = array_values(array_filter($fieldInternal, fn($f)=> $f['status']!=='hallucination'));
        }

        // Update output_count metric (post pruning / merges)
        $metrics['output_count'] = count(array_filter($fieldInternal, fn($f)=>$f['status']!=='merged'));

        // Strict 1:1 enforcement: reject if mismatch cannot be auto-healed (no placeholder strategy implemented)
        if ($metrics['output_count'] !== $metrics['input_count']){
            $issues[]='E_COUNT_MISMATCH(fields='.$metrics['output_count'].',questions='.$metrics['input_count'].')';
            $notes[]='guard:count_enforce_fail';
            if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::phase('count_mismatch', ['fields'=>$metrics['output_count'],'questions'=>$metrics['input_count'],'code'=>'E_COUNT_MISMATCH']); }
        }

        // Rebuild output schema fields if corrective
        if ($this->mode==='corrective'){
            $newFields=[]; foreach ($fieldInternal as $fo){
                if ($fo['status']==='merged') continue; // merged entries removed
                $orig = $fields[$fo['idx']] ?? null; if(!$orig) continue; $newFields[]=$orig; }
            $fields = $newFields;
        }

        // Optional Phase: AI Validation after building schema
        if ($aiAssist && class_exists('Arshline\\Guard\\GuardAIClient')){
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_validation_start', []); }
            $client = $this->buildAiClient();
            if ($client){
                try {
                    $fieldsOut = array_map(fn($f)=>['label'=>$f['raw_label'],'type'=>$f['working_type'],'props'=>$f['props']], $fieldInternal);
                    $validation = $client->validateSchema(['fields'=>$fieldsOut], $userQuestions);
                    if (!empty($validation['ok'])){
                        $metrics['ai_validation_confidence'] = $validation['confidence'] ?? null;
                        if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_validation', ['approved'=>$validation['approved'],'confidence'=>$validation['confidence']??null,'issues'=>$validation['issues']??[]]); }
                        if (!empty($validation['issues'])){ foreach($validation['issues'] as $iv){ $issues[]='ai_val:'.$iv; } }
                        if (isset($validation['approved']) && !$validation['approved']){ $notes[]='guard:ai_validation_reject'; }
                    }
                } catch(\Throwable $e){ $notes[]='guard:ai_validation_fail('.$e->getMessage().')'; }
            }
            if (class_exists('Arshline\\Guard\\GuardLogger')){ GuardLogger::ai('ai_validation_end', ['confidence'=>$metrics['ai_validation_confidence']]); }
        }

        $status = 'approved';
        if ($issues){ $status='rejected'; }

        $result = [
            'status'=>$status,
            'schema'=>['fields'=>$fields],
            'issues'=>$issues,
            'notes'=>$notes,
            'metrics'=>$metrics
        ];
        if ($this->isDebug()){ $result['debug'] = [ 'internal'=>$fieldInternal ]; }
        if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::summary($metrics, $issues, $notes, $this->requestId); }
        if (class_exists('Arshline\\Guard\\GuardLogger')) { GuardLogger::phase('end', ['status'=>$status]); }
        return $result;
    }

    protected function isAiAssistEnabled(): bool
    {
        if (defined('HOOSHA_GUARD_AI_ASSIST') && HOOSHA_GUARD_AI_ASSIST) return true;
        if (function_exists('get_option')){ $gs = get_option('arshline_settings', []); return !empty($gs['guard_ai_assist']); }
        return false;
    }

    protected function getAiConfidenceThreshold(): float
    {
        $thr = 0.9; if (function_exists('get_option')){ $gs = get_option('arshline_settings', []); if(isset($gs['guard_ai_confidence_threshold']) && is_numeric($gs['guard_ai_confidence_threshold'])){ $v=(float)$gs['guard_ai_confidence_threshold']; if($v>=0.5 && $v<=0.99) $thr=$v; } }
        return $thr;
    }

    protected function buildAiClient(): ?\Arshline\Guard\GuardAIClient
    {
        // Reuse Api-level environment (Api likely holds key/base URL retrieval)
        if (!class_exists('Arshline\\Hoosha\\Pipeline\\OpenAIModelClient')) return null;
        if (!class_exists('Arshline\\Guard\\GuardAIClient')) return null;
        $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY')?:'');
        if ($apiKey==='') return null;
        $base = getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com';
        $model = getenv('HOOSHA_GUARD_AI_MODEL') ?: 'gpt-4o-mini';
        try {
            $client = new \Arshline\Hoosha\Pipeline\OpenAIModelClient($apiKey, $model, $base);
            return new \Arshline\Guard\GuardAIClient($client);
        } catch(\Throwable $e){ return null; }
    }

    protected function loadCapabilities(): array
    {
        // Placeholder: later scan actual plugin structures or cached dictionary.
        return [ 'types'=>['short_text','multiple_choice','number','date'], 'formats'=>['national_id_ir','mobile_ir','date_greg','date_jalali'], 'rules'=>[] ];
    }

    protected function getSimilarityThreshold(): float
    {
        $thr = 0.8;
        if (function_exists('get_option')){
            $gs = get_option('arshline_settings', []);
            if (is_array($gs) && isset($gs['guard_semantic_similarity_min']) && is_numeric($gs['guard_semantic_similarity_min'])){
                $v=(float)$gs['guard_semantic_similarity_min']; if ($v>=0.5 && $v<=0.95) $thr=$v; }
        }
        return $thr;
    }

    protected function normalize(string $label): string
    {
        if (class_exists('Arshline\\Guard\\SemanticTools')){
            return SemanticTools::normalize_label($label);
        }
        $l = mb_strtolower($label,'UTF-8');
        $l = preg_replace('/\s+/u',' ',trim($l));
        return $l;
    }

    protected function similarity(string $a, string $b): float
    {
        if (class_exists('Arshline\\Guard\\SemanticTools')){
            return SemanticTools::similarity($a,$b);
        }
        if ($a===''||$b==='') return 0.0;
        if ($a===$b) return 1.0;
        $ta = preg_split('/\s+/u',$a,-1,PREG_SPLIT_NO_EMPTY);
        $tb = preg_split('/\s+/u',$b,-1,PREG_SPLIT_NO_EMPTY);
        $inter = array_intersect($ta,$tb); $union = array_unique(array_merge($ta,$tb));
        return count($union)? count($inter)/count($union):0.0;
    }

    protected function isDebug(): bool
    {
        if (defined('HOOSHA_GUARD_DEBUG') && HOOSHA_GUARD_DEBUG) return true;
        if (function_exists('get_option')){ $gs = get_option('arshline_settings', []); return !empty($gs['guard_debug']); }
        return false;
    }
}
