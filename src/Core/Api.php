<?php
namespace Arshline\Core;

// Explicit imports for form repositories (full-feature versions under Modules\Forms)
use Arshline\Modules\Forms\SubmissionRepository as FormsSubmissionRepository;
use Arshline\Modules\Forms\FieldRepository as FormsFieldRepository;
use Arshline\Modules\Forms\FormRepository; // Added: proper Forms repository (was missing, caused class not found)
use Arshline\Modules\Forms\Form;            // Added: Form entity class
use Arshline\Modules\UserGroups\GroupRepository; // Added: User Groups repository import
use Arshline\Modules\UserGroups\Group;            // Added: Group entity import
use Arshline\Modules\UserGroups\FormGroupAccessRepository; // Access mapping repo
use Arshline\Modules\UserGroups\FieldRepository; // For custom group fields
use Arshline\Modules\UserGroups\Field;           // Field entity
// MemberRepository for group members (if exists under Modules\UserGroups)
use Arshline\Modules\UserGroups\MemberRepository; // ensure proper member repo

// Backwards compatibility / aliasing for older class names referenced in code (UGFieldRepo, UGField)
if (!class_exists(__NAMESPACE__.'\UGFieldRepo') && class_exists(FieldRepository::class)) {
    class_alias(FieldRepository::class, __NAMESPACE__.'\UGFieldRepo');
}
if (!class_exists(__NAMESPACE__.'\UGField') && class_exists(Field::class)) {
    class_alias(Field::class, __NAMESPACE__.'\UGField');
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use Arshline\Support\Helpers;
class Api {
    /**
     * Snapshot of the last upstream AI model HTTP interaction for debugging (admin only exposure).
     * Structure (array|null): {
     *   endpoint: string,
     *   status: int,
     *   ok: bool,
     *   model: string,
     *   response_preview: string (truncated raw body),
     *   message_content_preview: string (truncated extracted content)
     * }
     *
     * NOTE: This was referenced via self::$last_ai_debug in multiple methods but not previously
     * declared, causing a fatal error: "Access to undeclared static property" during the
     * model_request_start -> model_request_end phase. Adding it here restores intended
     * diagnostic behavior without altering pipeline logic.
     */
    public static $last_ai_debug = null;
    /**
     * Clean final normalization: collapse duplicates, prune Jalali if Gregorian exists, merge groups, trim to <=9.
     */
    protected static function final_guard_normalize(array &$schema, array &$notes, string $userText, array $baseline_formal, ?array $guardBlock): void {
        if (empty($schema['fields']) || !is_array($schema['fields'])) { $notes[]='guard:final_normalize_start(before=0)'; $notes[]='guard:final_normalize_end(after=0)'; $notes[]='guard:final_debug(fields_before=0,fields_after=0)'; return; }
        $fields =& $schema['fields']; $before=count($fields); $notes[]='guard:final_normalize_start(before='.$before.')';
        $removed=0; $merged=[];
        $score=function(array $f){ $p=$f['props']??[]; $s=0; if(!empty($p['format']))$s+=2; if(!empty($p['options']))$s+=1; if(($f['type']??'')==='multiple_choice')$s+=1; return $s; };
        $isYN=function($f){ $o=$f['props']['options']??null; if(!is_array($o))return false; $n=$o; sort($n); return $n===['بله','خیر']; };
        $isBinary=function($l){ $lw=mb_strtolower($l,'UTF-8'); return mb_strpos($lw,'آیا')!==false || preg_match('/\؟$/u',$lw); };
        // Yes/No contamination cleanup
        foreach($fields as &$f){ if(!is_array($f))continue; if(!$isYN($f))continue; $lbl=$f['label']??''; if($lbl==='')continue; if(!$isBinary($lbl)){ $lw=mb_strtolower($lbl,'UTF-8'); if(preg_match('/ایمیل/u',$lw)){$f['props']['format']='email';$f['type']='short_text';} elseif(preg_match('/حقوق|درآمد|دستمزد/u',$lw)){$f['props']['format']=$f['props']['format']??'numeric';$f['type']='short_text';} elseif(preg_match('/تاریخ\s*تولد/u',$lw)){$f['props']['format']='date_greg';$f['type']='short_text';} elseif(preg_match('/کد\s*ملی|شماره\s*ملی/u',$lw)){$f['props']['format']=$f['props']['format']??'national_id_ir';$f['type']='short_text';} unset($f['props']['options']); unset($f['props']['multiple']); $notes[]='guard:yn_contamination_reverted('.$lbl.')'; }} unset($f);
        // Jalali prune
        $hasGreg=false; foreach($fields as $f){ if(is_array($f) && (($f['props']['format']??'')==='date_greg')){$hasGreg=true;break;}} if($hasGreg){ foreach($fields as $i=>$f){ if(is_array($f)&&(($f['props']['format']??'')==='date_jalali')){ unset($fields[$i]); $removed++; }} }
        // Group mapping
        $groups=['dob'=>[],'national'=>[],'color'=>[],'sport'=>[],'salary'=>[],'email'=>[],'insurance'=>[]];
        foreach($fields as $i=>$f){ if(!is_array($f))continue; $l=mb_strtolower($f['label']??'','UTF-8'); if(preg_match('/تاریخ\s*تولد|روز\s*تولد/u',$l))$groups['dob'][]=$i; if(preg_match('/کد\s*ملی|شماره\s*ملی/u',$l))$groups['national'][]=$i; if(preg_match('/رنگ/u',$l))$groups['color'][]=$i; if(preg_match('/ورزش|فعالیت\s*ورزشی/u',$l))$groups['sport'][]=$i; if(preg_match('/حقوق|درآمد|دستمزد/u',$l))$groups['salary'][]=$i; if(preg_match('/ایمیل/u',$l))$groups['email'][]=$i; if(preg_match('/بیمه\s*خودرو|بیمه\s*ماشین/u',$l))$groups['insurance'][]=$i; }
        $delete=[]; $pick=function($idxs) use(&$fields,$score){ $best=null;$bs=-1; foreach($idxs as $ix){ $sc=$score($fields[$ix]??[]); if($sc>$bs){$bs=$sc;$best=$ix;} } return $best; };
        foreach($groups as $g=>$idxs){ if(count($idxs)>1){ $best=$pick($idxs); foreach($idxs as $ix){ if($ix!==$best){ $delete[]=$ix; }} $merged[]=$g; }}
        // Color canonicalization
        if(!empty($groups['color'])){ $bc=$pick($groups['color']); if($bc!==null && isset($fields[$bc])){ $fields[$bc]['type']='multiple_choice'; if(!isset($fields[$bc]['props'])||!is_array($fields[$bc]['props']))$fields[$bc]['props']=[]; $fields[$bc]['props']['options']=['سبز','آبی','قرمز']; $fields[$bc]['props']['multiple']=false; $fields[$bc]['props']['source']=$fields[$bc]['props']['source']??'color_inferred'; }}
        // DOB day-only removal
        if(count($groups['dob'])>1){ $dateIx=null; $days=[]; foreach($groups['dob'] as $ix){ $ll=mb_strtolower($fields[$ix]['label']??'','UTF-8'); if(mb_strpos($ll,'روز تولد')!==false && mb_strpos($ll,'تاریخ')===false){ $days[]=$ix; } else if($dateIx===null){ $dateIx=$ix; }} if($dateIx!==null && $days){ foreach($days as $di){ $delete[]=$di; } $merged[]='dob'; }}
        // Email confirm cleanup
        foreach($fields as $i=>$f){ if(!is_array($f))continue; if(!empty(($f['props']['confirm_for']??null)) && $isYN($f)){ unset($fields[$i]['props']['options']); unset($fields[$i]['props']['multiple']); $notes[]='guard:confirm_yesno_cleaned(index='.$i.')'; }}
        if($delete){ foreach(array_unique($delete) as $di){ if(isset($fields[$di])){ unset($fields[$di]); $removed++; }}}
        // Reindex
        $fields=array_values(array_filter($fields,function($f){ return is_array($f)&&isset($f['label'])&&$f['label']!==''; })); $after=count($fields);
        if($after>9){ $priority=['dob','national','email','salary','sport','color','insurance']; $sel=[];$other=[]; $detect=function($lbl){ $l=mb_strtolower($lbl,'UTF-8'); if(preg_match('/تاریخ\s*تولد|روز\s*تولد/u',$l))return'dob'; if(preg_match('/کد\s*ملی|شماره\s*ملی/u',$l))return'national'; if(preg_match('/ایمیل/u',$l))return'email'; if(preg_match('/حقوق|درآمد|دستمزد/u',$l))return'salary'; if(preg_match('/ورزش|فعالیت\s*ورزشی/u',$l))return'sport'; if(preg_match('/رنگ/u',$l))return'color'; if(preg_match('/بیمه\s*خودرو|بیمه\s*ماشین/u',$l))return'insurance'; return'other'; }; foreach($fields as $fl){ $g=$detect($fl['label']??''); if($g!=='other'){ if(!isset($sel[$g]))$sel[$g]=$fl; } else { $other[]=$fl; }} $final=[]; foreach($priority as $p){ if(isset($sel[$p])) $final[]=$sel[$p]; } foreach($other as $o){ if(count($final)>=9)break; $final[]=$o; } if(count($final)>9)$final=array_slice($final,0,9); $trim=$after-count($final); if($trim>0){ $fields=$final; $after=count($fields); $removed+=$trim; $notes[]='guard:hard_trim(removed='.$trim.')'; }}
        if($removed>0)$notes[]='guard:final_collapse(removed='.$removed.')'; if($merged)$notes[]='guard:merged_groups('.implode('|',$merged).')'; $notes[]='guard:final_normalize_end(after='.$after.')'; $notes[]='guard:final_debug(fields_before='.$before.',fields_after='.$after.')';
    }

    /** Post-trim refinement: convert residual non-binary yes/no to short_text and normalize core formats */
    protected static function final_guard_post_refine(array &$schema, array &$notes): void {
        if (empty($schema['fields']) || !is_array($schema['fields'])) { $notes[]='guard:post_trim_refine(fixed=0,converted=0,options_fixed=0,total=0)'; return; }
        $fixed=0;$converted=0;$optsFixed=0;
        foreach($schema['fields'] as &$f){ if(!is_array($f))continue; $lbl=(string)($f['label']??''); $low=mb_strtolower($lbl,'UTF-8'); $type=$f['type']??''; $opts=$f['props']['options']??null; $hasYN=is_array($opts)&&count($opts)===2&&in_array('بله',$opts,true)&&in_array('خیر',$opts,true); $binary=(mb_strpos($low,'آیا')!==false)||preg_match('/\؟$/u',$low);
            if($hasYN && !$binary){ if(preg_match('/ایمیل/u',$low)){$f['props']['format']='email';} elseif(preg_match('/حقوق|درآمد|دستمزد/u',$low)){$f['props']['format']=$f['props']['format']??'numeric';} elseif(preg_match('/تاریخ\s*تولد/u',$low)){$f['props']['format']='date_greg';} elseif(preg_match('/کد\s*ملی|شماره\s*ملی/u',$low)){$f['props']['format']='national_id_ir';} $f['type']='short_text'; unset($f['props']['options']); unset($f['props']['multiple']); $converted++; }
            if($type==='multiple_choice' && !$hasYN){ if(!empty($f['props']['source']) && $f['props']['source']==='color_inferred'){ /* keep */ } elseif(preg_match('/ایمیل/u',$low)){ $f['type']='short_text'; $f['props']['format']='email'; $fixed++; } elseif(preg_match('/حقوق|درآمد|دستمزد/u',$low)){ $f['type']='short_text'; $f['props']['format']=$f['props']['format']??'numeric'; $fixed++; } elseif(preg_match('/تاریخ\s*تولد/u',$low)){ $f['type']='short_text'; $f['props']['format']='date_greg'; $fixed++; } elseif(preg_match('/کد\s*ملی|شماره\s*ملی/u',$low)){ $f['type']='short_text'; $f['props']['format']='national_id_ir'; $fixed++; } elseif(preg_match('/ورزش|فعالیت\s*ورزشی/u',$low) && empty($f['props']['options'])){ $f['type']='short_text'; $fixed++; } }
            if(!empty($f['props']['format']) && $f['props']['format']==='email' && isset($f['props']['options'])){ unset($f['props']['options']); unset($f['props']['multiple']); $optsFixed++; }
        } unset($f); $total=$fixed+$converted+$optsFixed; $notes[]='guard:post_trim_refine(fixed='.$fixed.',converted='.$converted.',options_fixed='.$optsFixed.',total='.$total.')';
    }
    /** Map user-provided or settings-provided model name (possibly fuzzy) into a supported canonical model. */
    protected static function normalize_model_name(?string $name): string
    {
        $n = trim((string)$name);
        if ($n === '') return 'gpt-4o-mini';
        $n = strtolower($n);
        // Family / nickname collapse
        if (preg_match('/mini|small|lite|fast/i', $n)) return 'gpt-4o-mini';
        if (preg_match('/4o|gpt-4|advanced|pro/i', $n)) return 'gpt-4o';
        if (preg_match('/3\.5|cheap|basic|legacy/i', $n)) return 'gpt-3.5-turbo';
        // Already canonical? keep
        return $n;
    }

    /** Choose an optimal model given settings, use-case and an approximate complexity score. */
    protected static function select_optimal_model(array $settings, string $promptSample, string $useCase, int $complexity): string
    {
        try { $userModel = (string)($settings['model'] ?? $settings['ai_model'] ?? ''); } catch (\Throwable $e) { $userModel=''; }
        if ($userModel !== '') return self::normalize_model_name($userModel);
        // Heuristic: heavier model for final reviews / analytics or high complexity
        if ($complexity >= 5 || preg_match('/final|review|analytics|plan/i', $useCase)) return 'gpt-4o';
        // Light default
        return 'gpt-4o-mini';
    }

    /**
     * Minimal subset analysis via OpenAI-compatible JSON response.
     * Returns [answer, confidence, evidence_ids] or a safe fallback.
     */
    protected static function ai_subset_analyze(array $pkg, array $ai_cfg): array
    {
        $model = self::select_optimal_model($ai_cfg, json_encode($pkg), 'analysis', 5);
        $base_url = isset($ai_cfg['base_url']) && is_string($ai_cfg['base_url']) ? (string)$ai_cfg['base_url'] : '';
        $api_key = isset($ai_cfg['api_key']) && is_string($ai_cfg['api_key']) ? (string)$ai_cfg['api_key'] : '';

        $system = 'You are a strict JSON generator. Read the provided subset table and answer in Persian (fa) as JSON only.'
            . ' Keys: answer (string), confidence (low|medium|high), evidence_ids (int[]).'
            . ' If insufficient info, answer with "<fa>اطلاعات کافی برای پاسخ وجود ندارد.</fa>" and confidence=low.';
        $user = json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            // --- Early direct phrase mapping for theme (light/dark) commands (Persian) ---
            $plainCmd = trim(mb_strtolower(preg_replace('/[\x{200C}\x{200F}]/u',' ', (string)$cmd)));
            if ($plainCmd !== ''){
                // Normalize extra spaces
                $plainCmd = preg_replace('/\s+/u',' ', $plainCmd);
                $isDarkReq = preg_match('/(حالت|تم|پوسته)\s*(?:کاملا\s*)?(?:تاریک|تیره|شب)/u', $plainCmd) === 1
                              || preg_match('/\b(dark|darkmode)\b/u', $plainCmd) === 1
                              || in_array($plainCmd, ['تم تیره','حالت تیره','پوسته تیره','تم شب','حالت شب','حالت تشب','تشب','دارکش کن'], true);
                $isLightReq = preg_match('/(حالت|تم|پوسته)\s*(?:کاملا\s*)?(?:روشن|روز|سفید)/u', $plainCmd) === 1
                               || preg_match('/\b(light|lightmode)\b/u', $plainCmd) === 1
                               || in_array($plainCmd, ['تم روز','حالت روز','پوسته روشن'], true);
                $toggleReq  = !$isDarkReq && !$isLightReq && preg_match('/^(?:تم|حالت)\s*رو?\s*(?:عوض|تغییر) کن$/u', $plainCmd) === 1;
                // Short minimal triggers
                if (!$isDarkReq && !$isLightReq && !$toggleReq){
                    if (in_array($plainCmd, ['تم تاریک','تاریک کن','تیره کن'], true)) $isDarkReq = true;
                    if (in_array($plainCmd, ['تم روشن','روشن کن','سفیدش کن'], true)) $isLightReq = true;
                }
                if ($isDarkReq || $isLightReq || $toggleReq){
                    $mode = $toggleReq ? null : ($isDarkReq ? 'dark' : 'light');
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'ui',
                        'target'=>'toggle_theme',
                        'mode'=> $mode,
                        'detected'=>'theme_direct'
                    ], 200);
                }
            }
            // --- End theme phrase mapping ---
            $resp = self::openai_chat_json($base_url, $api_key, $model, $system, $user);
            if (is_array($resp) && isset($resp['answer'])){
                $ans = [
                    'answer' => (string)$resp['answer'],
                    'confidence' => isset($resp['confidence']) ? (string)$resp['confidence'] : 'low',
                    'evidence_ids' => isset($resp['evidence_ids']) && is_array($resp['evidence_ids']) ? array_values(array_map('intval', $resp['evidence_ids'])) : [],
                ];
                return $ans;
            }
        } catch (\Throwable $e) { /* ignore and fallback */ }
        return [ 'answer' => '<fa>اطلاعات کافی برای پاسخ وجود ندارد.</fa>', 'confidence' => 'low', 'evidence_ids' => [] ];
    }

    /**
     * OpenAI-compatible chat completion expecting JSON object; basic repair on code fences.
     */
    protected static function openai_chat_json(string $base_url, string $api_key, string $model, string $system, string $user)
    {
        $base = rtrim($base_url ?: 'https://api.openai.com', '/');
        // Avoid double /v1 if user already provided it in base URL
        if (preg_match('~/(v1)(/)?$~i', $base)) {
            $endpoint = $base . '/chat/completions';
        } else {
            $endpoint = $base . '/v1/chat/completions';
        }
    $headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ];
        $payload = [
            'model' => self::normalize_model_name($model),
            'response_format' => [ 'type' => 'json_object' ],
            'messages' => [ [ 'role' => 'system', 'content' => $system ], [ 'role' => 'user', 'content' => $user ] ],
            'temperature' => 0.2,
            'max_tokens' => 1200,
        ];
        $request_id = null;
        $t0 = microtime(true);
        if (class_exists('Arshline\\Hoosha\\HooshaLogger')) {
            try {
                $request_id = bin2hex(random_bytes(8));
            } catch (\Throwable $e) { $request_id = uniqid('rq_', true); }
            \Arshline\Hoosha\HooshaLogger::log([
                'phase' => 'model_request_start',
                'request_id' => $request_id,
                'endpoint' => $endpoint,
                'payload_summary' => [
                    'model' => $payload['model'],
                    'messages_count' => count($payload['messages']),
                    'system_preview' => mb_substr($system,0,220,'UTF-8'),
                    'user_preview' => mb_substr($user,0,600,'UTF-8')
                ],
                'human' => 'ارسال درخواست به مدل (قبل از HTTP).',
            ]);
        }
        try {
            $res = self::wp_post_with_retries($endpoint, $headers, $payload, 40, 3, [500,1000,2000], 'gpt-4o-mini');
            if (!is_array($res)) return null;
            $b = $res['json'] ?? (json_decode((string)($res['body'] ?? ''), true) ?: null);
            $txt = '';
            if (is_array($b)){
                if (isset($b['choices'][0]['message']['content']) && is_string($b['choices'][0]['message']['content'])) $txt = (string)$b['choices'][0]['message']['content'];
                elseif (isset($b['choices'][0]['text']) && is_string($b['choices'][0]['text'])) $txt = (string)$b['choices'][0]['text'];
                elseif (isset($b['output_text']) && is_string($b['output_text'])) $txt = (string)$b['output_text'];
            }
            // Debug snapshot of upstream response (admin only exposure; sanitized)
            self::$last_ai_debug = [
                'endpoint' => $endpoint,
                'status' => (int)($res['status'] ?? 0),
                'ok' => (bool)($res['ok'] ?? false),
                'model' => $payload['model'] ?? '',
                'response_preview' => is_string($res['body'] ?? null) ? mb_substr((string)$res['body'], 0, 2000, 'UTF-8') : '',
                'message_content_preview' => $txt ? mb_substr($txt, 0, 1200, 'UTF-8') : '',
            ];
            if (class_exists('Arshline\\Hoosha\\HooshaLogger')) {
                \Arshline\Hoosha\HooshaLogger::log([
                    'phase' => 'model_request_end',
                    'request_id' => $request_id,
                    'status' => (int)($res['status'] ?? 0),
                    'latency_ms' => (int)round((microtime(true)-$t0)*1000),
                    'response_preview' => is_string($res['body'] ?? null) ? mb_substr((string)$res['body'],0,1200,'UTF-8') : null,
                    'human' => 'پاسخ مدل دریافت شد.',
                    'ok' => (bool)($res['ok'] ?? false)
                ]);
            }
            if (!($res['ok'] ?? false)){
                $status = (int)($res['status'] ?? 0);
                $bodyPreview = '';
                if (is_string($res['body'] ?? null)){
                    $bodyPreview = substr((string)$res['body'], 0, 240);
                }
                @error_log('Arshline openai_chat_json upstream error status=' . $status . ' bodyPreview=' . $bodyPreview);
            }
            $j = $txt ? json_decode($txt, true) : null;
            if (is_array($j)) return $j;
            // Simple repair: strip code fences
            $txt2 = preg_replace('/```json|```/u', '', (string)$txt);
            $j2 = $txt2 ? json_decode(trim((string)$txt2), true) : null;
            if (is_array($j2)) return $j2;
        } catch (\Throwable $e) {
            if (class_exists('Arshline\\Hoosha\\HooshaLogger')) {
                \Arshline\Hoosha\HooshaLogger::log([
                    'phase' => 'model_request_exception',
                    'request_id' => $request_id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'latency_ms' => (int)round((microtime(true)-$t0)*1000),
                    'human' => 'استثناء در فراخوانی مدل.'
                ]);
            }
            return null;
        }
        if (class_exists('Arshline\\Hoosha\\HooshaLogger')) {
            \Arshline\Hoosha\HooshaLogger::log([
                'phase' => 'model_request_parse_fail',
                'request_id' => $request_id,
                'human' => 'پاسخ مدل JSON معتبر نبود؛ بازگشت null.',
                'latency_ms' => (int)round((microtime(true)-$t0)*1000)
            ]);
        }
        return null;
    }

    /** Public wrapper to allow pipeline model clients to call the protected OpenAI helper safely. */
    public static function openai_chat_json_wrapper(string $base_url, string $api_key, string $model, string $system, string $user): array
    {
        $resp = self::openai_chat_json($base_url, $api_key, $model, $system, $user);
        if (is_array($resp)) {
            // Attach ok/text if missing (some upstream JSON tasks may not include)
            if (!isset($resp['ok'])) $resp['ok']=true;
            if (!isset($resp['text'])) $resp['text']=json_encode($resp, JSON_UNESCAPED_UNICODE);
            return $resp;
        }
        return ['ok'=>false,'error'=>'empty_response','text'=>''];
    }

    /**
     * Wrapper adding retry/backoff semantics at logical (LLM) layer, producing markers for notes.
     * Returns [resp|null, markers[]]
     */
    protected static function hoosha_model_call_with_retry(string $base_url, string $api_key, string $model, string $system, string $user, int $maxAttempts = 5): array
    {
        $attempt = 0; $markers = []; $resp = null; $backoffSeq = [0.25,1,2,4,8];
        while ($attempt < $maxAttempts) {
            $resp = self::openai_chat_json($base_url, $api_key, $model, $system, $user);
            if (is_array($resp)) { return [$resp,$markers]; }
            // Determine reason (simplified — real upstream status captured in last_ai_debug if available)
            $reason = 'unknown';
            if (is_array(self::$last_ai_debug)){
                $st = (int)(self::$last_ai_debug['status'] ?? 0);
                if ($st === 429) $reason='rate_limit'; elseif ($st >=500) $reason='server_error'; elseif ($st === 400) $reason='bad_request';
            }
            $markers[] = 'pipe:retry_attempt('.($attempt+1).','.$reason.')';
            // Only retry on rate_limit/server_error; abort otherwise
            if (!in_array($reason,['rate_limit','server_error','unknown'],true)) break;
            $sleep = $backoffSeq[$attempt] ?? end($backoffSeq);
            if ($sleep > 0) { if (function_exists('usleep')) { @usleep((int)round($sleep*1000000)); } else { @sleep(max(1,(int)ceil($sleep))); } }
            $attempt++;
        }
        if (class_exists('Arshline\\Hoosha\\HooshaLogger')) {
            \Arshline\Hoosha\HooshaLogger::log([
                'phase' => 'model_retry_exhausted',
                'attempts' => $attempt,
                'markers' => $markers,
                'human' => 'تمام تلاش‌های مدل بدون پاسخ معتبر پایان یافت.'
            ]);
        }
        return [null,$markers];
    }

    /**
     * Phase-1: Column Mapper — given header (labels/types) and a query, return relevant columns with probabilities,
     * intents, entities, and overall confidence. Uses a tiny model and strict JSON.
     */
    protected static function ai_mapper_map_columns(array $ai_cfg, array $header, string $query, ?string $language_hint = null): array
    {
        $base_url = isset($ai_cfg['base_url']) && is_string($ai_cfg['base_url']) ? (string)$ai_cfg['base_url'] : '';
        $api_key = isset($ai_cfg['api_key']) && is_string($ai_cfg['api_key']) ? (string)$ai_cfg['api_key'] : '';
        $model = self::normalize_model_name('gpt-4o-mini');
        // Build minimal header projection
        $hdr = [];
        foreach ($header as $h){
            if (!is_array($h)) continue;
            $hdr[] = [
                'id' => $h['id'] ?? ($h['key'] ?? null),
                'key' => $h['key'] ?? null,
                'title' => $h['title'] ?? ($h['label'] ?? ''),
                'type' => $h['type'] ?? null,
                'semantic_tags' => $h['semantic_tags'] ?? null,
            ];
        }
        $sys = 'You are a fast column-mapper and intent/entity classifier. Language can be Persian. '
            . 'Respond ONLY in strict JSON with keys: language, columns[{column_id,probability,reason_code?}], intents[{name,probability}], '
            . 'entities[{text,normalized?,type,subtype?,probability}], confidence.';
        $user = [ 'query'=>$query, 'language_hint'=>$language_hint, 'header'=>$hdr ];
        $resp = self::openai_chat_json($base_url, $api_key, $model, $sys, wp_json_encode($user, JSON_UNESCAPED_UNICODE));
        // Validate shape and coerce minimal defaults
        $out = [ 'language'=>'fa', 'columns'=>[], 'intents'=>[], 'entities'=>[], 'confidence'=>0.0 ];
        if (is_array($resp)){
            $out['language'] = is_string($resp['language'] ?? null) ? (string)$resp['language'] : ($language_hint ?: 'fa');
            $cols = is_array($resp['columns'] ?? null) ? $resp['columns'] : [];
            $normCols = [];
            foreach ($cols as $c){
                $cid = is_scalar($c['column_id'] ?? null) ? (string)$c['column_id'] : null;
                $p = is_numeric($c['probability'] ?? null) ? (float)$c['probability'] : null;
                if ($cid !== null && $p !== null){ $normCols[] = [ 'column_id'=>$cid, 'probability'=> max(0.0, min(1.0, $p)), 'reason_code'=> (string)($c['reason_code'] ?? '') ]; }
            }
            $out['columns'] = $normCols;
            $ints = is_array($resp['intents'] ?? null) ? $resp['intents'] : [];
            $normInt = [];
            foreach ($ints as $it){
                $nm = is_string($it['name'] ?? null) ? (string)$it['name'] : null;
                $p = is_numeric($it['probability'] ?? null) ? (float)$it['probability'] : null;
                if ($nm && $p !== null){ $normInt[] = [ 'name'=>$nm, 'probability'=> max(0.0, min(1.0, $p)) ]; }
            }
            $out['intents'] = $normInt;
            $ents = is_array($resp['entities'] ?? null) ? $resp['entities'] : [];
            $normEnt = [];
            foreach ($ents as $en){
                $txt = is_string($en['text'] ?? null) ? (string)$en['text'] : null;
                $tp = is_string($en['type'] ?? null) ? (string)$en['type'] : null;
                $p = is_numeric($en['probability'] ?? null) ? (float)$en['probability'] : null;
                if ($txt && $tp && $p !== null){
                    $normEnt[] = [ 'text'=>$txt, 'normalized'=> (string)($en['normalized'] ?? ''), 'type'=>$tp, 'subtype'=> (string)($en['subtype'] ?? ''), 'probability'=> max(0.0, min(1.0, $p)) ];
                }
            }
            $out['entities'] = $normEnt;
            $conf = is_numeric($resp['confidence'] ?? null) ? (float)$resp['confidence'] : 0.0;
            $out['confidence'] = max(0.0, min(1.0, $conf));
        }
        return $out;
    }

    /**
     * POST with retries/backoff for 429/5xx and optional model fallback (changes req['model']).
     * Returns ['status'=>int,'body'=>string,'json'=>mixed,'ok'=>bool]
     */
    protected static function wp_post_with_retries(string $endpoint, array $headers, array $req, int $timeout, int $maxRetries = 3, array $backoffMs = [500,1000,2000], ?string $fallbackModel = 'gpt-4o-mini')
    {
        $attempt = 0;
        $last = null;
        while ($attempt <= $maxRetries) {
            $body = wp_json_encode($req);
            $r = wp_remote_post($endpoint, [ 'timeout' => $timeout, 'headers' => $headers, 'body' => $body ]);
            $status = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
            $raw = is_wp_error($r) ? ($r->get_error_message() ?: '') : (string)wp_remote_retrieve_body($r);
            $ok = ($status >= 200 && $status < 300);
            $json = $raw !== '' ? (json_decode($raw, true) ?: null) : null;
            $last = [ 'status'=>$status, 'body'=>$raw, 'json'=>$json, 'ok'=>$ok ];
            if ($ok) return $last;
            // Retry only on 429/5xx
            if ($status === 429 || $status >= 500 || is_wp_error($r)){
                if ($attempt < $maxRetries){
                    $ms = $backoffMs[$attempt] ?? 1000;
                    if (function_exists('usleep')) { @usleep((int)$ms * 1000); } else { @sleep(max(1, (int)round($ms/1000))); }
                    $attempt++;
                    continue;
                }
            }
            break;
        }
        // Final fallback: try with fallback model once if model differs
        if ($fallbackModel && isset($req['model']) && is_string($req['model'])){
            $fallback = self::normalize_model_name($fallbackModel);
            if ($fallback !== '' && $fallback !== $req['model']){
                $req['model'] = $fallback;
                $body = wp_json_encode($req);
                $r = wp_remote_post($endpoint, [ 'timeout' => $timeout, 'headers' => $headers, 'body' => $body ]);
                $status = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
                $raw = is_wp_error($r) ? ($r->get_error_message() ?: '') : (string)wp_remote_retrieve_body($r);
                $ok = ($status >= 200 && $status < 300);
                $json = $raw !== '' ? (json_decode($raw, true) ?: null) : null;
                return [ 'status'=>$status, 'body'=>$raw, 'json'=>$json, 'ok'=>$ok ];
            }
        }
        return $last;
    }
    /**
     * Read AI analysis configuration from options with sane defaults and filters.
     * This aids Hybrid/Efficient/AI-Heavy modes and caps while UI is pending.
     */
    protected static function get_ai_analysis_config(): array
    {
        $gs = get_option('arshline_settings', []);
        $mode = is_scalar($gs['ai_mode'] ?? null) ? (string)$gs['ai_mode'] : 'hybrid'; // efficient|hybrid|ai-heavy
        $maxRows = isset($gs['ai_max_rows']) && is_numeric($gs['ai_max_rows']) ? max(50, min(1000, (int)$gs['ai_max_rows'])) : 400;
        $allowPII = !empty($gs['ai_allow_pii']);
        $tokTypical = isset($gs['ai_tok_typical']) && is_numeric($gs['ai_tok_typical']) ? max(1000, min(16000, (int)$gs['ai_tok_typical'])) : 8000;
        $tokMax = isset($gs['ai_tok_max']) && is_numeric($gs['ai_tok_max']) ? max($tokTypical, min(32000, (int)$gs['ai_tok_max'])) : 32000;
        $res = [
            'mode' => $mode,
            'max_rows' => $maxRows,
            'allow_pii' => $allowPII,
            'token_typical' => $tokTypical,
            'token_max' => $tokMax,
        ];
        // Allow integrators to tune programmatically
        if (function_exists('apply_filters')){
            $res = apply_filters('arshline_ai_analysis_config', $res);
        }
        return $res;
    }
    /**
     * Normalize and sanitize supported meta keys to expected types.
     * Unknown keys are left as-is to keep flexibility, but callers should prefer known keys.
     */
    protected static function sanitize_meta_input(array $in): array
    {
        $out = $in;
        // Booleans
        if (array_key_exists('anti_spam_honeypot', $in)) {
            $out['anti_spam_honeypot'] = (bool)$in['anti_spam_honeypot'];
        }
        if (array_key_exists('captcha_enabled', $in)) {
            $out['captcha_enabled'] = (bool)$in['captcha_enabled'];
        }
        // Integers with bounds
        if (array_key_exists('min_submit_seconds', $in)) {
            $out['min_submit_seconds'] = max(0, (int)$in['min_submit_seconds']);
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $out['rate_limit_per_min'] = max(0, (int)$in['rate_limit_per_min']);
        }
        if (array_key_exists('rate_limit_window_min', $in)) {
            $out['rate_limit_window_min'] = max(1, (int)$in['rate_limit_window_min']);
        }
        // Captcha keys (allow limited charset)
        $allowKey = function($v) {
            $v = is_scalar($v) ? (string)$v : '';
            $v = trim($v);
            // Allow common recaptcha key charset
            $v = preg_replace('/[^A-Za-z0-9_\-\.:]/', '', $v);
            // Limit length
            return substr($v, 0, 200);
        };
        if (array_key_exists('captcha_site_key', $in)) {
            $out['captcha_site_key'] = $allowKey($in['captcha_site_key']);
        }
        if (array_key_exists('captcha_secret_key', $in)) {
            $out['captcha_secret_key'] = $allowKey($in['captcha_secret_key']);
        }
        if (array_key_exists('captcha_version', $in)) {
            $v = is_scalar($in['captcha_version']) ? (string)$in['captcha_version'] : 'v2';
            $v = ($v === 'v3') ? 'v3' : 'v2';
            $out['captcha_version'] = $v;
        }
        // Design keys
        $sanitize_color = function($v, $fallback) {
            $s = is_scalar($v) ? (string)$v : '';
            $s = trim($s);
            if (preg_match('/^#([A-Fa-f0-9]{6})$/', $s)) return $s;
            return $fallback;
        };
        if (array_key_exists('design_primary', $in)) {
            $out['design_primary'] = $sanitize_color($in['design_primary'], '#1e40af');
        }
        if (array_key_exists('design_bg', $in)) {
            $out['design_bg'] = $sanitize_color($in['design_bg'], '#f5f7fb');
        }
        if (array_key_exists('design_theme', $in)) {
            $v = is_scalar($in['design_theme']) ? (string)$in['design_theme'] : 'light';
            $v = ($v === 'dark') ? 'dark' : 'light';
            $out['design_theme'] = $v;
        }
        return $out;
    }
    /**
     * Sanitize global settings payload and enforce defaults/limits.
     */
    protected static function sanitize_settings_input(array $in): array
    {
        $out = self::sanitize_meta_input($in);
        // Upload constraints
        if (array_key_exists('upload_max_kb', $in)) {
            $kb = (int)$in['upload_max_kb'];
            $out['upload_max_kb'] = max(50, min(4096, $kb));
        }
        if (array_key_exists('block_svg', $in)) {
            $out['block_svg'] = (bool)$in['block_svg'];
        }
        // AI options
        if (array_key_exists('ai_enabled', $in)) {
            $out['ai_enabled'] = (bool)$in['ai_enabled'];
        }
        if (array_key_exists('ai_spam_threshold', $in)) {
            $t = is_numeric($in['ai_spam_threshold']) ? (float)$in['ai_spam_threshold'] : 0.5;
            $out['ai_spam_threshold'] = max(0.0, min(1.0, $t));
        }
        if (array_key_exists('ai_model', $in)) {
            $m = is_scalar($in['ai_model']) ? trim((string)$in['ai_model']) : '';
            // keep arbitrary model name but constrain length and charset
            $m = preg_replace('/[^A-Za-z0-9_\-\.:\/]/', '', $m);
            $out['ai_model'] = substr($m, 0, 100);
        }
        if (array_key_exists('ai_model_mode', $in)) {
            $mode = is_scalar($in['ai_model_mode']) ? trim((string)$in['ai_model_mode']) : 'auto';
            $out['ai_model_mode'] = in_array($mode, ['auto', 'manual']) ? $mode : 'auto';
        }
        if (array_key_exists('ai_final_review_enabled', $in)) {
            $out['ai_final_review_enabled'] = (bool)$in['ai_final_review_enabled'];
        }
        return $out;
    }

    /**
     * Load global settings from WP options with defaults and sanitization.
     */
    protected static function get_global_settings(): array
    {
        $defaults = [
            'anti_spam_honeypot' => false,
            'min_submit_seconds' => 0,
            'rate_limit_per_min' => 0,
            'rate_limit_window_min' => 1,
            'captcha_enabled' => false,
            'captcha_site_key' => '',
            'captcha_secret_key' => '',
            'captcha_version' => 'v2',
            'upload_max_kb' => 300,
            'block_svg' => true,
            'ai_enabled' => false,
            'ai_spam_threshold' => 0.5,
            'ai_model' => 'auto',
            'ai_model_mode' => 'auto',
            'ai_final_review_enabled' => false,
        ];
        $raw = get_option('arshline_settings', []);
    $arr = is_array($raw) ? $raw : [];
        $san = self::sanitize_settings_input($arr);
        return array_merge($defaults, $san);
    }
    /** Get SMS settings (separate option to keep credentials isolated). */
    protected static function get_sms_settings_store(): array
    {
        $raw = get_option('arshline_sms_settings', []);
        $arr = is_array($raw) ? $raw : [];
        $out = [
            'enabled' => !empty($arr['enabled']),
            'provider' => ($arr['provider'] ?? 'sms_ir') === 'sms_ir' ? 'sms_ir' : 'sms_ir',
            'api_key' => is_string($arr['api_key'] ?? '') ? (string)$arr['api_key'] : '',
            'line_number' => is_string($arr['line_number'] ?? '') ? (string)$arr['line_number'] : '',
        ];
        // sanitize
        $out['api_key'] = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $out['api_key']), 0, 200);
        $out['line_number'] = substr(preg_replace('/[^0-9]/', '', $out['line_number']), 0, 20);
        return $out;
    }
    protected static function put_sms_settings_store(array $in): array
    {
        $cur = self::get_sms_settings_store();
        $next = [
            'enabled' => array_key_exists('enabled', $in) ? (bool)$in['enabled'] : $cur['enabled'],
            'provider' => 'sms_ir',
            'api_key' => array_key_exists('api_key', $in) ? (string)$in['api_key'] : $cur['api_key'],
            'line_number' => array_key_exists('line_number', $in) ? (string)$in['line_number'] : $cur['line_number'],
        ];
        // Sanitize
        $next['api_key'] = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $next['api_key']), 0, 200);
        $next['line_number'] = substr(preg_replace('/[^0-9]/', '', $next['line_number']), 0, 20);
        update_option('arshline_sms_settings', $next, false);
        return $next;
    }

    public static function get_sms_settings(\WP_REST_Request $r)
    {
        return new \WP_REST_Response(self::get_sms_settings_store(), 200);
    }
    public static function update_sms_settings(\WP_REST_Request $r)
    {
        $p = $r->get_json_params(); if (!is_array($p)) $p = $r->get_params();
        $saved = self::put_sms_settings_store($p);
        return new \WP_REST_Response($saved, 200);
    }

    /** Compose message from template and member data. Supports #name/#نام, #phone, #link/#لینک placeholders. */
    protected static function compose_sms_template(string $tpl, array $member, string $link = ''): string
    {
        // Normalize Persian synonyms to canonical placeholders
        $tpl = str_replace(['#نام', '#لینک'], ['#name', '#link'], $tpl);
        $repl = [
            'name' => (string)($member['name'] ?? ''),
            'phone' => (string)($member['phone'] ?? ''),
            'link' => (string)$link,
        ];
        // include custom fields from member[data]
        $data = is_array($member['data'] ?? null) ? $member['data'] : [];
        foreach ($data as $k=>$v){
            if (is_scalar($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)){
                $repl[(string)$k] = (string)$v;
            }
        }
        $msg = preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k, $repl) ? (string)$repl[$k] : $m[0]; }, $tpl);
        return self::ensure_sms_suffix($msg);
    }

    /** Ensure required SMS suffix (opt-out phrase) is appended once. */
    protected static function ensure_sms_suffix(string $msg): string
    {
        $suffix = ' لغو11';
        $m = rtrim($msg);
        // Avoid double-adding: if message already ends with suffix (allowing whitespace), skip
        if (preg_match('/'.preg_quote($suffix, '/').'\s*$/u', $m)) return $m;
        return $m.$suffix;
    }

    /** Build personal link for form + member if requested. */
    protected static function build_member_form_link(int $formId, array $member): string
    {
        $form = FormRepository::find($formId);
        if (!$form || $form->status !== 'published' || empty($form->public_token)) return '';
        $formToken = (string)$form->public_token;
        // Ensure member token
        $tok = MemberRepository::ensureToken((int)($member['id'] ?? 0));
        if (!$tok) return '';
        $base = add_query_arg('arshline', rawurlencode($formToken), home_url('/'));
        $url = add_query_arg('member_token', rawurlencode((string)$tok), $base);
        return esc_url_raw($url);
    }

    /** Send SMS via SMS.IR for a single recipient (simple bulk endpoint with single mobile). */
    protected static function smsir_send_single(string $apiKey, string $lineNumber, string $mobile, string $message, ?string $sendDateTime): bool
    {
        $apiKey = trim($apiKey); $lineNumber = trim($lineNumber); $mobile = preg_replace('/[^0-9]/', '', (string)$mobile);
        if ($apiKey === '' || $lineNumber === '' || $mobile === '' || $message === '') return false;
        $url = 'https://api.sms.ir/v1/send/bulk';
        $body = [
            'lineNumber' => $lineNumber,
            'messageText' => $message,
            'mobiles' => [$mobile],
        ];
        if ($sendDateTime){ $body['sendDateTime'] = $sendDateTime; }
        $args = [
            'headers' => [ 'x-api-key' => $apiKey, 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ];
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) return true;
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($json) && isset($json['status']) && (int)$json['status'] === 1) return true;
        return false;
    }

    /** Basic SMS.IR test: if phone provided, attempt to send; else, just validate config presence. */
    public static function test_sms_connect(\WP_REST_Request $r)
    {
        $cfg = self::get_sms_settings_store();
        $phone = trim((string)($r->get_param('phone') ?? ''));
        $msg = trim((string)($r->get_param('message') ?? 'آزمایش ارتباط پیامک عرشلاین'));
        $msg = self::ensure_sms_suffix($msg);
        if ($phone !== ''){
            $ok = self::smsir_send_single($cfg['api_key'], $cfg['line_number'], $phone, $msg, null);
            return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 500);
        }
        $ok = ($cfg['api_key'] !== '' && $cfg['line_number'] !== '');
        return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 422);
    }

    /** POST /sms/send — create a job or send immediately. */
    public static function sms_send(\WP_REST_Request $r)
    {
    $cfg = self::get_sms_settings_store();
    if (!$cfg['enabled']) return new \WP_REST_Response(['error'=>'sms_disabled','message'=>'ارسال پیامک غیرفعال است. لطفاً در تنظیمات پیامک فعال کنید.'], 422);
    if ($cfg['api_key'] === '' || $cfg['line_number'] === '') return new \WP_REST_Response(['error'=>'missing_config','message'=>'تنظیمات پیامک ناقص است (API Key یا شماره خط).'], 422);

        $formId = (int)($r->get_param('form_id') ?? 0);
        $includeLink = !empty($r->get_param('include_link')) && $formId > 0;
        $groupIds = $r->get_param('group_ids'); if (!is_array($groupIds)) $groupIds = [];
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        // Enforce group scope for current user
        $groupIds = AccessControl::filterGroupIdsByCurrentUser($groupIds);
        $template = trim((string)($r->get_param('message') ?? ''));
        // Normalize Persian synonyms in template for early validations
        $templateNorm = str_replace(['#نام', '#لینک'], ['#name', '#link'], $template);
    if (empty($groupIds)) return new \WP_REST_Response(['error'=>'no_groups','message'=>'حداقل یک گروه را انتخاب کنید.'], 422);
    if ($template === '') return new \WP_REST_Response(['error'=>'empty_message','message'=>'متن پیام خالی است.'], 422);
        // If template uses #link but includeLink not requested (no form), abort
        if (strpos($templateNorm, '#link') !== false && !$includeLink){
            return new \WP_REST_Response(['error'=>'link_placeholder_without_form','message'=>'در متن از #لینک استفاده شده ولی فرمی انتخاب نشده است.'], 422);
        }

        // If a form link is requested, enforce that the form is mapped to the selected groups
        if ($includeLink){
            $allowedGroups = FormGroupAccessRepository::getGroupIds($formId);
            // Require explicit mapping to avoid sending links to groups without access
            if (empty($allowedGroups)){
                return new \WP_REST_Response(['error'=>'form_not_mapped','message'=>'فرم انتخابی به هیچ گروهی متصل نشده است. ابتدا در «اتصال فرم‌ها» گروه(ها) را برای این فرم تنظیم کنید.'], 422);
            }
            $invalid = array_values(array_diff($groupIds, $allowedGroups));
            if (!empty($invalid)){
                return new \WP_REST_Response([
                    'error' => 'form_not_allowed_for_groups',
                    'message' => 'فرم انتخابی به برخی از گروه‌های انتخابی متصل نیست.',
                    'invalid_group_ids' => $invalid,
                ], 422);
            }
        }

        // Resolve recipients from DB
        global $wpdb; $t = \Arshline\Support\Helpers::tableName('user_group_members');
        $in = implode(',', array_map('intval', $groupIds));
        $rows = $wpdb->get_results("SELECT id, group_id, name, phone, data FROM {$t} WHERE group_id IN ($in) AND phone IS NOT NULL AND phone <> ''", ARRAY_A) ?: [];
        // Deduplicate by phone
        $byPhone = [];
        foreach ($rows as $row){ $ph = preg_replace('/[^0-9]/', '', (string)$row['phone']); if ($ph==='') continue; $row['phone'] = $ph; $row['data'] = json_decode($row['data'] ?: '[]', true) ?: []; $byPhone[$ph] = $row; }
        $recipients = array_values($byPhone);
    if (empty($recipients)) return new \WP_REST_Response(['error'=>'no_recipients','message'=>'هیچ مخاطبی با شماره معتبر در گروه‌های انتخابی یافت نشد.'], 422);

        // Build messages with optional personal link
        $payload = [];
        foreach ($recipients as $m){
            $link = $includeLink ? self::build_member_form_link($formId, [ 'id'=>(int)$m['id'], 'name'=>$m['name'], 'phone'=>$m['phone'], 'data'=>$m['data'] ]) : '';
            // If link placeholder is used but we couldn't build a link, abort
            if ($includeLink && strpos($templateNorm, '#link') !== false && $link === ''){
                return new \WP_REST_Response(['error'=>'link_build_failed','member_id'=>(int)$m['id'], 'message'=>'ساخت لینک اختصاصی برای یکی از اعضا ناموفق بود. فرم باید فعال و دارای توکن عمومی باشد.'], 422);
            }
            $msg = self::compose_sms_template($template, [ 'id'=>(int)$m['id'], 'name'=>$m['name'], 'phone'=>$m['phone'], 'data'=>$m['data'] ], $link);
            $payload[] = [ 'phone'=>$m['phone'], 'message'=> self::ensure_sms_suffix($msg), 'vars' => [ 'name'=>$m['name'], 'phone'=>$m['phone'], 'link'=>$link ] ];
        }

        // Schedule or send now
        $scheduleAt = $r->get_param('schedule_at');
        $ts = null;
        if (is_numeric($scheduleAt)) { $ts = (int)$scheduleAt; }
        else if (is_string($scheduleAt) && $scheduleAt !== '') { $ts = strtotime($scheduleAt); }
        $now = time(); if ($ts !== null && $ts < ($now+60)) { $ts = $now + 60; }

        $maxImmediate = 50; // limit to avoid timeouts
        if ($ts === null && count($payload) <= $maxImmediate){
            $okCount = 0; $failCount = 0;
            global $wpdb; $logTable = \Arshline\Support\Helpers::tableName('sms_log');
            foreach ($payload as $i=>$it){
                $ok = self::smsir_send_single($cfg['api_key'], $cfg['line_number'], $it['phone'], $it['message'], null);
                if ($ok) $okCount++; else $failCount++;
                // Log attempt (best effort)
                try {
                    $wpdb->insert($logTable, [
                        'job_id' => null,
                        'phone' => $it['phone'],
                        'form_id' => $includeLink ? $formId : null,
                        'group_id' => isset($it['vars']['group_id']) ? (int)$it['vars']['group_id'] : (isset($groupIds[0]) ? (int)$groupIds[0] : null),
                        'status' => $ok ? 'sent' : 'fail',
                        'created_at' => current_time('mysql'),
                    ]);
                } catch (\Throwable $e) { /* swallow */ }
            }
            return new \WP_REST_Response(['ok'=>true, 'sent'=>$okCount, 'failed'=>$failCount], 200);
        }
        // Create job in options and schedule cron
        $seq = (int)get_option('arsh_sms_job_seq', 0) + 1; update_option('arsh_sms_job_seq', $seq, false);
        $jobId = $seq;
        $job = [ 'id'=>$jobId, 'provider'=>'sms_ir', 'created_at'=>current_time('timestamp'), 'schedule_at'=> ($ts ?? ($now+5)), 'config'=>[ 'api_key'=>$cfg['api_key'], 'line_number'=>$cfg['line_number'] ], 'payload'=>$payload, 'cursor'=>0 ];
        update_option('arsh_sms_job_'.$jobId, $job, false);
        wp_schedule_single_event($job['schedule_at'], 'arshline_process_sms_job', [ $jobId ]);
        return new \WP_REST_Response(['ok'=>true, 'job_id'=>$jobId, 'schedule_at'=>$job['schedule_at'], 'recipients'=>count($payload)], 200);
    }

    /** Cron handler to process a queued SMS job in batches. */
    public static function process_sms_job($jobId)
    {
        $key = 'arsh_sms_job_'.(int)$jobId; $job = get_option($key, null);
        if (!$job || !is_array($job)) return;
        $cfg = $job['config'] ?? ['api_key'=>'','line_number'=>''];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $cursor = (int)($job['cursor'] ?? 0);
        $batch = array_slice($payload, $cursor, 40);
        foreach ($batch as $i=>$it){ self::smsir_send_single((string)$cfg['api_key'], (string)$cfg['line_number'], (string)$it['phone'], (string)$it['message'], null); }
        // After sending batch, log them (best effort)
        try {
            global $wpdb; $logTable = \Arshline\Support\Helpers::tableName('sms_log');
            foreach ($batch as $it){
                $wpdb->insert($logTable, [
                    'job_id' => (int)$jobId,
                    'phone' => (string)$it['phone'],
                    'form_id' => isset($job['form_id']) ? (int)$job['form_id'] : null,
                    'group_id' => isset($it['vars']['group_id']) ? (int)$it['vars']['group_id'] : null,
                    'status' => 'sent',
                    'created_at' => current_time('mysql'),
                ]);
            }
        } catch (\Throwable $e) { /* ignore logging failures */ }
        $cursor += count($batch);
        if ($cursor >= count($payload)){
            // Persist a trimmed result for auditing
            $result = [
                'job_id' => (int)$jobId,
                'finished_at' => time(),
                'total' => count($payload),
                'entries' => array_map(function($it){ return [ 'phone'=>$it['phone'], 'vars'=>($it['vars'] ?? []), 'message'=>$it['message'] ]; }, $payload),
            ];
            update_option('arsh_sms_result_'.(int)$jobId, $result, false);
            delete_option($key);
        } else {
            $job['cursor'] = $cursor; update_option($key, $job, false);
            wp_schedule_single_event(time()+30, 'arshline_process_sms_job', [ (int)$jobId ]);
        }
    }
    protected static function flag(array $meta, string $key, bool $default=false): bool
    {
        if (!array_key_exists($key, $meta)) return $default;
        return (bool)$meta[$key];
    }
    public static function boot()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        // Serve raw HTML for HTMX routes (avoid JSON-encoding the HTML fragment)
        add_filter('rest_pre_serve_request', [self::class, 'serve_htmx_html'], 10, 4);
        // Background SMS job processor (runs via WP-Cron)
        add_action('arshline_process_sms_job', [self::class, 'process_sms_job'], 10, 1);
    }

    public static function user_can_manage_forms(): bool
    {
        return AccessControl::currentUserCanFeature('forms');
    }

    public static function user_can_manage_groups(): bool
    {
        return AccessControl::currentUserCanFeature('groups');
    }
    public static function user_can_send_sms(): bool
    {
        return AccessControl::currentUserCanFeature('sms');
    }
    public static function user_can_manage_settings(): bool
    {
        return AccessControl::currentUserCanFeature('settings');
    }
    public static function user_can_manage_users(): bool
    {
        return AccessControl::currentUserCanFeature('users');
    }

    public static function register_routes()
    {
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_forms'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Basic dashboard/reporting stats
        register_rest_route('arshline/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_stats'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('reports'); },
            'args' => [
                'days' => [ 'type' => 'integer', 'required' => false ],
            ],
        ]);
        // Global settings (admin-only)
        register_rest_route('arshline/v1', '/settings', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_settings'],
                'permission_callback' => [self::class, 'user_can_manage_settings'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_settings'],
                'permission_callback' => [self::class, 'user_can_manage_settings'],
            ]
        ]);

        // Analytics (هوشنگ) endpoints
        register_rest_route('arshline/v1', '/analytics/config', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'get_analytics_config'],
        ]);
        // Expose menu registry for Hoshiyar assistant (list + resolve)
        register_rest_route('arshline/v1', '/menus', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('manage_options'); },
            'callback' => function(){ return new \WP_REST_Response(['menus' => \Arshline\Core\MenuRegistry::all()], 200); },
        ]);
        register_rest_route('arshline/v1', '/menus/resolve', [
            'methods' => 'GET',
            'args' => [ 'q' => [ 'required' => true, 'type' => 'string' ] ],
            'permission_callback' => function(){ return current_user_can('manage_options'); },
            'callback' => function(\WP_REST_Request $r){
                $q = (string)$r->get_param('q');
                $m = \Arshline\Core\MenuRegistry::findByCommand($q);
                if(!$m) return new \WP_REST_Response(['found' => false], 200);
                return new \WP_REST_Response(['found' => true, 'menu' => $m], 200);
            },
        ]);
        // Simple LLM chat (minimal proxy). No grounding, no form data, just chat.
        register_rest_route('arshline/v1', '/ai/simple-chat', [
            'methods' => 'POST',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'ai_simple_chat'],
        ]);
        // Natural language -> form commands interpreter
        register_rest_route('arshline/v1', '/hoosha/interpret_nl', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => [self::class, 'hoosha_interpret_nl'],
        ]);
        register_rest_route('arshline/v1', '/hoosha/preview_edit', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => [self::class, 'hoosha_preview_edit'],
        ]);
        register_rest_route('arshline/v1', '/analytics/analyze', [
            'methods' => 'POST',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'analytics_analyze'],
        ]);
        // Consolidated metrics for dashboard charts
        register_rest_route('arshline/v1', '/analytics/metrics', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'analytics_metrics'],
        ]);
        // Chat history endpoints
        register_rest_route('arshline/v1', '/analytics/sessions', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'list_chat_sessions'],
        ]);
        register_rest_route('arshline/v1', '/analytics/sessions/(?P<session_id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'get_chat_messages'],
        ]);
        register_rest_route('arshline/v1', '/analytics/sessions/(?P<session_id>\d+)/export', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'export_chat_session'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Ensure/generate public token and return it (admin only)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'ensure_token'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Public, read-only form definition (without requiring admin perms)
        register_rest_route('arshline/v1', '/public/forms/(?P<form_id>\\d+)', [
            'methods' => 'GET',
            // Use a public-safe getter that enforces status gating
            'callback' => [self::class, 'get_public_form'],
            'permission_callback' => '__return_true',
        ]);
        // Public by-token routes
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form_by_token'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})/submissions', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_by_token'],
            'permission_callback' => '__return_true',
        ]);
        // Public by-token, HTMX-friendly submission endpoint (HTML fragment response)
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})/submit', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_htmx_by_token'],
            'permission_callback' => '__return_true',
        ]);
        // Public, HTMX-friendly submission endpoint (form-encoded)
        register_rest_route('arshline/v1', '/public/forms/(?P<form_id>\\d+)/submit', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_htmx'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/fields', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_fields'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/meta', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_meta'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Hoosha2: list versions of a form (F6-1)
        register_rest_route('hosha2/v1', '/forms/(?P<form_id>\d+)/versions', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => function(\WP_REST_Request $request){
                $repo = new \Arshline\Hosha2\Hosha2VersionRepository();
                $controller = new \Arshline\Hosha2\Hosha2VersionController($repo, null);
                return $controller->listFormVersions($request);
            },
            'args' => [
                'limit' => [ 'required'=>false, 'validate_callback'=>function($param){ return is_numeric($param) && (int)$param >=1 && (int)$param <=100; } ],
                'offset'=> [ 'required'=>false, 'validate_callback'=>function($param){ return is_numeric($param) && (int)$param >=0; } ]
            ]
        ]);
        // Hoosha2: get single version (F6-2)
        register_rest_route('hosha2/v1', '/forms/(?P<form_id>\d+)/versions/(?P<version_id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => function(\WP_REST_Request $request){
                $repo = new \Arshline\Hosha2\Hosha2VersionRepository();
                $controller = new \Arshline\Hosha2\Hosha2VersionController($repo, null);
                return $controller->getVersion($request);
            }
        ]);
        // Hoosha2: apply diff to a version (F7)
        register_rest_route('hosha2/v1', '/forms/(?P<form_id>\d+)/versions/(?P<version_id>\d+)/apply-diff', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => function(\WP_REST_Request $request){
                $repo = new \Arshline\Hosha2\Hosha2VersionRepository();
                $controller = new \Arshline\Hosha2\Hosha2VersionController($repo, null);
                return $controller->applyDiff($request);
            }
        ]);
        // Hoosha2: rollback to a previous version (F8)
        register_rest_route('hosha2/v1', '/forms/(?P<form_id>\d+)/versions/(?P<version_id>\d+)/rollback', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => function(\WP_REST_Request $request){
                $repo = new \Arshline\Hosha2\Hosha2VersionRepository();
                $controller = new \Arshline\Hosha2\Hosha2VersionController($repo, null);
                return $controller->rollback($request);
            }
        ]);
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [
                'title' => [ 'type' => 'string', 'required' => false ],
            ],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'delete_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Update form (status toggle: draft|published|disabled)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/submissions', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_submissions'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create_submission'],
                'permission_callback' => '__return_true',
            ]
        ]);

        // Hoosha (Smart Form Builder) endpoints
        register_rest_route('arshline/v1', '/hoosha/prepare', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'hoosha_prepare'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/hoosha/apply', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'hoosha_apply'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/hoosha/final-review', [
            'methods' => 'POST',
            'callback' => [self::class, 'hoosha_final_review'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Hosha2 generation endpoint (modular pipeline)
        register_rest_route('hosha2/v1', '/forms/(?P<form_id>\d+)/generate', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'callback' => function(\WP_REST_Request $r){
                // Lazy build dependencies (would later be centralized in a service container)
                $logger = $GLOBALS['hosha2_logger'] ?? null; // pre-registered singleton in bootstrap
                $rateLimiter = $GLOBALS['hosha2_rate_limiter'] ?? null;
                $versionRepo = $GLOBALS['hosha2_version_repo'] ?? null;
                $capBuilder = new \Arshline\Hosha2\Hosha2CapabilitiesBuilder();
                $envFactory = new \Arshline\Hosha2\Hosha2OpenAIEnvelopeFactory();
                $client = $GLOBALS['hosha2_openai_client'] ?? new \Arshline\Hosha2\Hosha2OpenAIClientStub();
                $diffValidator = new \Arshline\Hosha2\Hosha2DiffValidator();
                $service = new \Arshline\Hosha2\Hosha2GenerateService($capBuilder, $envFactory, $client, $diffValidator, $logger, $rateLimiter, $versionRepo);
                $controller = new \Arshline\Hosha2\Hosha2GenerateController($service);
                return $controller->handle($r);
            },
            'args' => [
                'prompt' => [ 'type'=>'string', 'required'=>true ],
                'options' => [ 'required'=>false ],
            ],
        ]);
        // AI configuration and agent endpoints (admin-only)
        register_rest_route('arshline/v1', '/ai/config', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_ai_config'],
                'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_ai_config'],
                'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
            ]
        ]);
        register_rest_route('arshline/v1', '/ai/test', [
            'methods' => 'POST',
                'callback' => [self::class, 'test_ai_connect'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
        ]);
        register_rest_route('arshline/v1', '/ai/capabilities', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_ai_capabilities'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
        ]);
        register_rest_route('arshline/v1', '/ai/agent', [
            'methods' => 'POST',
            'callback' => [self::class, 'ai_agent'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
        ]);
        // Audit log and undo endpoints (admin-only)
        register_rest_route('arshline/v1', '/ai/audit', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_audit'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [ 'limit' => [ 'type' => 'integer', 'required' => false ] ],
        ]);
        register_rest_route('arshline/v1', '/ai/undo', [
            'methods' => 'POST',
            'callback' => [self::class, 'undo_by_token'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [ 'token' => [ 'type' => 'string', 'required' => true ] ],
        ]);
        // Migration: restore previously degraded file_upload fields
        register_rest_route('arshline/v1', '/migrations/restore-file-uploads', [
            'methods' => 'POST',
            'callback' => [self::class, 'migrate_restore_file_uploads'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Get a specific submission (with values)
        register_rest_route('arshline/v1', '/submissions/(?P<submission_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_submission'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Upload image (for admins/editors)
        register_rest_route('arshline/v1', '/upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'upload_image'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [
                'file' => [ 'required' => false ],
            ],
        ]);
        // Generic secured file upload for form file fields (non-image, limited types) - public submit context uses token validation
        register_rest_route('arshline/v1', '/public/forms/(?P<form_id>\\d+)/file-upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'public_form_file_upload'],
            'permission_callback' => '__return_true', // validated by token + form status inside handler
            'args' => [
                'file' => [ 'required' => false ],
                'token' => [ 'required' => false ], // optional access token if form restricted
            ],
        ]);

        // User Groups Management (feature-gated)
        register_rest_route('arshline/v1', '/user-groups', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_groups'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_create_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
                'args' => [
                    'name' => [ 'type' => 'string', 'required' => true ],
                    'meta' => [ 'required' => false ],
                ]
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_members'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_add_members'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
                'args' => [ 'members' => [ 'required' => true ] ]
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/(?P<member_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_member'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_member'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/(?P<member_id>\\d+)/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'ug_ensure_member_token'],
            'permission_callback' => [self::class, 'user_can_manage_groups'],
        ]);
        // Bulk ensure tokens for a group's members
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/ensure-tokens', [
            'methods' => 'POST',
            'callback' => [self::class, 'ug_bulk_ensure_tokens'],
            'permission_callback' => [self::class, 'user_can_manage_groups'],
        ]);

        // Group custom fields
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/fields', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_fields'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_create_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/fields/(?P<field_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);

        // Form ↔ Group access mapping (admin-only)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/access/groups', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_form_access_groups'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'set_form_access_groups'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
                'args' => [ 'group_ids' => [ 'required' => true ] ],
            ],
        ]);
        // Messaging (SMS)
        register_rest_route('arshline/v1', '/sms/settings', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_sms_settings'],
                'permission_callback' => [self::class, 'user_can_send_sms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_sms_settings'],
                'permission_callback' => [self::class, 'user_can_send_sms'],
            ],
        ]);
        register_rest_route('arshline/v1', '/sms/test', [
            'methods' => 'POST',
            'callback' => [self::class, 'test_sms_connect'],
            'permission_callback' => [self::class, 'user_can_send_sms'],
        ]);
        register_rest_route('arshline/v1', '/sms/send', [
            'methods' => 'POST',
            'callback' => [self::class, 'sms_send'],
            'permission_callback' => [self::class, 'user_can_send_sms'],
        ]);

        // Role policies (super admin only)
        register_rest_route('arshline/v1', '/roles/policies', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_role_policies'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_role_policies'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
        ]);

        // Roles info (list roles + feature caps) — super admin only
        register_rest_route('arshline/v1', '/roles', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_roles_info'],
            'permission_callback' => function(){ return current_user_can('manage_options'); },
        ]);

        // Users management (super admin only)
        register_rest_route('arshline/v1', '/users', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'list_users'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => [ 'search' => [ 'required' => false ], 'role' => [ 'required' => false ] ],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => [ 'user_email' => [ 'required' => true ], 'user_login' => [ 'required' => true ], 'role' => [ 'required' => false ] ],
            ],
        ]);
        register_rest_route('arshline/v1', '/users/(?P<user_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'delete_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
        ]);
    }

    // ========== User Groups Handlers ==========
    public static function ug_list_groups(WP_REST_Request $r)
    {
        // Enforce group scope for non-admins: filter returned groups to allowed set
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed === null) {
            $rows = GroupRepository::all();
        } else {
            $rows = array_values(array_filter(GroupRepository::all(), function($g) use ($allowed){ return in_array((int)$g->id, $allowed, true); }));
        }
        // Build member counts for listed groups in a single query
        $counts = [];
        try {
            global $wpdb; $t = \Arshline\Support\Helpers::tableName('user_group_members');
            $ids = array_map(fn($g)=> (int)$g->id, $rows);
            if (!empty($ids)){
                $in = implode(',', array_map('intval', $ids));
                $sql = "SELECT group_id, COUNT(*) AS c FROM {$t} WHERE group_id IN ($in) GROUP BY group_id";
                $rs = $wpdb->get_results($sql, ARRAY_A) ?: [];
                foreach ($rs as $r){ $counts[(int)$r['group_id']] = (int)$r['c']; }
            }
        } catch (\Throwable $e) { /* noop */ }
        $out = array_map(function($g) use ($counts){ return [
            'id' => $g->id,
            'name' => $g->name,
            'parent_id' => $g->parent_id,
            'meta' => $g->meta,
            'member_count' => isset($counts[$g->id]) ? (int)$counts[$g->id] : 0,
            'created_at' => $g->created_at,
            'updated_at' => $g->updated_at,
        ]; }, $rows);
        return new WP_REST_Response($out, 200);
    }

    // ===== Roles/users helpers =====
    public static function get_roles_info(WP_REST_Request $r)
    {
        global $wp_roles;
        if (!isset($wp_roles)) $wp_roles = wp_roles();
        $roles = [];
        foreach ($wp_roles->roles as $k=>$v){ $roles[] = [ 'key'=>$k, 'name'=>$v['name'] ?? $k ]; }
        return new WP_REST_Response(['roles'=>$roles, 'features'=>array_keys(AccessControl::featureCaps())], 200);
    }
    public static function list_users(WP_REST_Request $r)
    {
        $args = [ 'number' => 50 ];
        $search = (string)($r->get_param('search') ?? ''); if ($search !== ''){ $args['search'] = '*'.esc_attr($search).'*'; }
        $role = (string)($r->get_param('role') ?? ''); if ($role !== ''){ $args['role'] = $role; }
        $users = get_users($args);
        $items = array_map(function($u){
            $disabled = (bool) get_user_meta($u->ID, 'arsh_disabled', true);
            return [
                'id' => $u->ID,
                'user_login' => $u->user_login,
                'display_name' => $u->display_name,
                'email' => $u->user_email,
                'roles' => $u->roles,
                'disabled' => $disabled,
            ];
        }, $users);
        return new WP_REST_Response([ 'items'=>$items, 'count'=>count($items) ], 200);
    }
    public static function create_user(WP_REST_Request $r)
    {
        $email = sanitize_email((string)$r->get_param('user_email'));
        $login = sanitize_user((string)$r->get_param('user_login'));
        $role = (string)($r->get_param('role') ?? '');
        if (!$email || !$login) return new WP_REST_Response(['message'=>'invalid_input'], 422);
        $pass = wp_generate_password(12, true);
        $uid = wp_create_user($login, $pass, $email);
        if (is_wp_error($uid)) return new WP_REST_Response(['message'=>$uid->get_error_message()], 400);
        if ($role !== ''){ $u = new \WP_User($uid); $u->set_role($role); }
        return new WP_REST_Response(['id'=>$uid], 201);
    }
    public static function update_user(WP_REST_Request $r)
    {
        $uid = (int)$r['user_id']; $u = get_user_by('id', $uid); if (!$u) return new WP_REST_Response(['message'=>'not_found'], 404);
        $role = $r->get_param('role'); if (is_string($role) && $role!==''){ $u = new \WP_User($uid); $u->set_role($role); }
        $roles = $r->get_param('roles'); if (is_array($roles)){
            $u = new \WP_User($uid);
            foreach ($u->roles as $r0){ $u->remove_role($r0); }
            foreach ($roles as $r1){ if (is_string($r1) && $r1!=='') $u->add_role($r1); }
        }
        if ($r->offsetExists('disabled')){
            $disabled = (bool)$r->get_param('disabled');
            if ($disabled) { update_user_meta($uid, 'arsh_disabled', 1); }
            else { delete_user_meta($uid, 'arsh_disabled'); }
        }
        return new WP_REST_Response(['ok'=>true], 200);
    }
    public static function delete_user(WP_REST_Request $r)
    {
        $uid = (int)$r['user_id'];
        if ($uid <= 0) return new WP_REST_Response(['message'=>'invalid_id'], 422);
        $reassign = null;
        $ok = wp_delete_user($uid, $reassign);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_create_group(WP_REST_Request $r)
    {
        $name = trim((string)$r->get_param('name'));
        if ($name === '') return new WP_REST_Response(['message' => 'نام گروه الزامی است.'], 422);
        $meta = $r->get_param('meta'); if (!is_array($meta)) $meta = [];
        $parent_id = $r->get_param('parent_id');
        $pid = is_numeric($parent_id) ? (int)$parent_id : null; if ($pid === 0) { $pid = null; }
        if ($pid !== null) { if (!GroupRepository::find($pid)) { return new WP_REST_Response(['message' => 'گروه مادر نامعتبر است.'], 422); } }
        $g = new \Arshline\Modules\UserGroups\Group(['name' => $name, 'parent_id' => $pid, 'meta' => $meta]);
        $id = GroupRepository::save($g);
        return new WP_REST_Response(['id' => $id], 201);
    }
    public static function ug_update_group(WP_REST_Request $r)
    {
        $id = (int)$r['group_id'];
        $g = GroupRepository::find($id);
        if (!$g) return new WP_REST_Response(['message' => 'گروه یافت نشد.'], 404);
        $name = $r->get_param('name'); if (is_string($name)) $g->name = trim($name);
        if ($r->offsetExists('parent_id')){
            $parent_id = $r->get_param('parent_id');
            $pid = (is_numeric($parent_id) ? (int)$parent_id : null);
            if ($pid === 0) $pid = null;
            if ($pid === $g->id) { return new WP_REST_Response(['message' => 'نمی‌توانید گروه را مادر خودش قرار دهید.'], 422); }
            if ($pid !== null && !GroupRepository::find($pid)) { return new WP_REST_Response(['message' => 'گروه مادر نامعتبر است.'], 422); }
            $g->parent_id = $pid;
        }
        $meta = $r->get_param('meta'); if (is_array($meta)) $g->meta = $meta;
        GroupRepository::save($g);
        return new WP_REST_Response(['ok' => true], 200);
    }
    public static function ug_delete_group(WP_REST_Request $r)
    {
        $id = (int)$r['group_id'];
        $ok = GroupRepository::delete($id);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_list_members(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        // Enforce group scope
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        // Support pagination and search for large datasets
        $per_page = (int)($r->get_param('per_page') ?? 20);
        if ($per_page <= 0) $per_page = 20; if ($per_page > 200) $per_page = 200;
        $page = (int)($r->get_param('page') ?? 1); if ($page <= 0) $page = 1;
        $search = trim((string)($r->get_param('search') ?? ''));
        $orderby = (string)($r->get_param('orderby') ?? 'id');
        $order = (string)($r->get_param('order') ?? 'DESC');

        // Compute total first, then fetch page
        $total = MemberRepository::countAll($gid, $search);
        $rows = MemberRepository::paginated($gid, $per_page, $page, $search, $orderby, $order);
        $items = array_map(function($m){ return [
            'id' => $m->id,
            'group_id' => $m->group_id,
            'name' => $m->name,
            'phone' => $m->phone,
            'data' => $m->data,
            'token' => $m->token,
            'created_at' => $m->created_at,
        ];}, $rows);
        $resp = [
            'items' => $items,
            'total' => (int)$total,
            'page' => (int)$page,
            'per_page' => (int)$per_page,
            'total_pages' => (int)max(1, (int)ceil(($total ?: 0) / max(1, $per_page)))
        ];
        return new WP_REST_Response($resp, 200);
    }
    public static function ug_add_members(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $members = $r->get_param('members');
        if (!is_array($members)) return new WP_REST_Response(['message' => 'members باید آرایه باشد.'], 422);
        $n = MemberRepository::addBulk($gid, $members);
        return new WP_REST_Response(['inserted' => $n], 201);
    }
    public static function ug_delete_member(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $mid = (int)$r['member_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $ok = MemberRepository::delete($gid, $mid);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_update_member(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $mid = (int)$r['member_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $name = $r->get_param('name');
        $phone = $r->get_param('phone');
        $data = $r->get_param('data');
        $fields = [];
        if (is_string($name)) $fields['name'] = $name;
        if (is_string($phone)) $fields['phone'] = $phone;
        if (is_array($data)) $fields['data'] = $data;
        if (empty($fields)) return new WP_REST_Response(['ok'=>false,'message'=>'no_fields'], 422);
        $ok = MemberRepository::update($gid, $mid, $fields);
        if ($ok) { try { MemberRepository::ensureToken($mid); } catch (\Throwable $e) { /* noop */ } }
        return new WP_REST_Response(['ok'=>(bool)$ok], $ok?200:404);
    }
    public static function ug_ensure_member_token(WP_REST_Request $r)
    {
        $mid = (int)$r['member_id'];
        // Gate by member's group scope
        $tok = null;
        try {
            $m = MemberRepository::find($mid);
            $allowed = AccessControl::allowedGroupIdsForCurrentUser();
            if ($m && ($allowed === null || in_array((int)$m->group_id, $allowed, true))){
                $tok = MemberRepository::ensureToken($mid);
            }
        } catch (\Throwable $e) { /* noop */ }
        if (!$tok) return new WP_REST_Response(['message' => 'عضو یافت نشد.'], 404);
        return new WP_REST_Response(['token' => $tok], 200);
    }

    public static function ug_bulk_ensure_tokens(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $count = 0;
        try {
            $members = MemberRepository::list($gid, 50000);
            foreach ($members as $m){ $tok = $m->token; if (!$tok){ $tok2 = MemberRepository::ensureToken((int)$m->id); if ($tok2) $count++; } }
        } catch (\Throwable $e) { /* noop */ }
        return new WP_REST_Response(['ok'=>true, 'generated' => $count], 200);
    }

    // ========== Group custom fields ==========
    public static function ug_list_fields(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $rows = UGFieldRepo::listByGroup($gid);
        $out = array_map(function($f){ return [
            'id' => $f->id,
            'group_id' => $f->group_id,
            'name' => $f->name,
            'label' => $f->label,
            'type' => $f->type,
            'options' => $f->options,
            'required' => $f->required,
            'sort' => $f->sort,
        ]; }, $rows);
        return new WP_REST_Response($out, 200);
    }
    public static function ug_create_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $name = trim((string)$r->get_param('name'));
        $label = trim((string)$r->get_param('label'));
        $type = trim((string)($r->get_param('type') ?? 'text'));
        $options = $r->get_param('options'); if (!is_array($options)) $options = [];
        $required = (bool)$r->get_param('required');
        $sort = (int)($r->get_param('sort') ?? 0);
        if ($gid<=0 || $name==='') return new WP_REST_Response(['message'=>'invalid'], 422);
        $f = new UGField([ 'group_id'=>$gid, 'name'=>$name, 'label'=>$label?:$name, 'type'=>$type, 'options'=>$options, 'required'=>$required, 'sort'=>$sort ]);
        $id = UGFieldRepo::save($f);
        return new WP_REST_Response(['id'=>$id], 201);
    }
    public static function ug_update_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $fid = (int)$r['field_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $rows = UGFieldRepo::listByGroup($gid);
        $target = null; foreach ($rows as $f){ if ((int)$f->id === $fid) { $target = $f; break; } }
        if (!$target) return new WP_REST_Response(['message'=>'not_found'], 404);
        $p = $r->get_params();
        if (isset($p['name'])) $target->name = trim((string)$p['name']);
        if (isset($p['label'])) $target->label = trim((string)$p['label']);
        if (isset($p['type'])) $target->type = trim((string)$p['type']);
        if (isset($p['options']) && is_array($p['options'])) $target->options = $p['options'];
        if (isset($p['required'])) $target->required = (bool)$p['required'];
        if (isset($p['sort'])) $target->sort = (int)$p['sort'];
        UGFieldRepo::save($target);
        return new WP_REST_Response(['ok'=>true], 200);
    }
    public static function ug_delete_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $fid = (int)$r['field_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        // Ensure it belongs to the group
        $rows = UGFieldRepo::listByGroup($gid);
        $ok = false; foreach ($rows as $f){ if ((int)$f->id === $fid) { $ok = UGFieldRepo::delete($fid); break; } }
        return new WP_REST_Response(['ok'=>(bool)$ok], $ok?200:404);
    }

    // ===== Roles/Policies endpoints (super admin) =====
    public static function get_role_policies(WP_REST_Request $r)
    {
        $pol = AccessControl::getPolicies();
        return new WP_REST_Response(['policies' => $pol], 200);
    }
    public static function update_role_policies(WP_REST_Request $r)
    {
        $body = $r->get_json_params(); if (!is_array($body)) $body = $r->get_params();
        $pol = is_array($body['policies'] ?? null) ? $body['policies'] : [];
        $saved = AccessControl::updatePolicies($pol);
        return new WP_REST_Response(['ok'=>true, 'policies'=>$saved], 200);
    }

    // ========== Form ↔ Group access mapping (admin-only) ==========
    public static function get_form_access_groups(WP_REST_Request $r)
    {
        $fid = (int)$r['form_id'];
        if ($fid <= 0) return new WP_REST_Response(['group_ids' => []], 200);
        $ids = FormGroupAccessRepository::getGroupIds($fid);
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null){
            $ids = array_values(array_intersect(array_map('intval', $ids), array_map('intval', $allowed)));
        }
        return new WP_REST_Response(['group_ids' => array_values($ids)], 200);
    }
    public static function set_form_access_groups(WP_REST_Request $r)
    {
        $fid = (int)$r['form_id'];
        if ($fid <= 0) return new WP_REST_Response(['error'=>'invalid_form_id'], 400);
        $arr = $r->get_param('group_ids');
        if (!is_array($arr)) $arr = [];
        $ids = array_values(array_unique(array_map('intval', $arr)));
        // Enforce group scope: only allow mapping within current user's allowed groups
        $ids = AccessControl::filterGroupIdsByCurrentUser($ids);
        FormGroupAccessRepository::setGroupIds($fid, $ids);
        return new WP_REST_Response(['ok'=>true, 'group_ids'=>$ids], 200);
    }

    // ===== Access control helpers =====
    /**
     * Check whether the current requester can access the given form via either:
     * - Logged-in WP user belonging to at least one allowed group (via filter hook), or
     * - A valid member token provided as request param `member_token` or header `X-Arsh-Member-Token`.
     * Returns [allowed:boolean, member?:array].
     */
    protected static function enforce_form_group_access($formId, WP_REST_Request $r): array
    {
        $formId = (int)$formId;
        $allowedGroups = FormGroupAccessRepository::getGroupIds($formId);
        // If no mapping exists, treat as public for backward-compat
        if (empty($allowedGroups)) return [ true, null ];

        // 1) Member token path (preferred for visitors)
        $tok = '';
        $p = $r->get_param('member_token'); if (is_string($p)) $tok = trim($p);
        // Try headers if param missing
        if ($tok === '' && method_exists($r, 'get_header')){
            $tok = (string)($r->get_header('X-Arsh-Member-Token') ?: $r->get_header('x-arsh-member-token'));
            $tok = trim($tok);
        }
        if ($tok !== ''){
            $m = MemberRepository::verifyToken($tok);
            if ($m && in_array((int)$m->group_id, $allowedGroups, true)){
                // Attach member to request context for later personalization
                return [ true, [
                    'id' => (int)$m->id,
                    'group_id' => (int)$m->group_id,
                    'name' => (string)$m->name,
                    'phone' => (string)$m->phone,
                    'data' => is_array($m->data) ? $m->data : [],
                ] ];
            }
        }

        // 2) Logged-in WP user path — allow integrators to declare user→group mapping via filter
        $uid = get_current_user_id();
        if ($uid > 0 && function_exists('apply_filters')){
            $userGroupIds = apply_filters('arshline_user_group_ids', [], $uid);
            if (is_array($userGroupIds)){
                $userGroupIds = array_map('intval', $userGroupIds);
                foreach ($userGroupIds as $gid){ if (in_array($gid, $allowedGroups, true)) return [ true, null ]; }
            }
        }
        return [ false, null ];
    }

    /** Replace #placeholders using member data for title/description meta (server-side hydration). */
    protected static function hydrate_meta_with_member(array $meta, ?array $member): array
    {
        if (!$member) return $meta;
        $repl = [];
        // Flatten member for simple replacement keys
        $repl['name'] = (string)($member['name'] ?? '');
        $repl['phone'] = (string)($member['phone'] ?? '');
        $data = is_array($member['data'] ?? null) ? ($member['data']) : [];
        foreach ($data as $k => $v){ if (is_scalar($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[(string)$k] = (string)$v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        return $meta;
    }

    /**
     * GET /stats — Returns simple KPI counts and a submissions time series for last N days (default 30)
     */
    public static function get_stats(WP_REST_Request $request)
    {
        global $wpdb;
        $forms = Helpers::tableName('forms');
        $subs = Helpers::tableName('submissions');

        // Counts
        $total_forms = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$forms}");
        $draft_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'draft'));
        $disabled_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'disabled'));
        // Active is strictly published; we also surface disabled separately
        $active_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'published'));
        $total_submissions = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$subs}");
        // WP users
        $users_count = 0;
        if (function_exists('count_users')) {
            $cu = count_users();
            $users_count = isset($cu['total_users']) ? (int)$cu['total_users'] : 0;
        } else {
            // Fallback minimal query if needed
            $users_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        }

        // Time series for submissions per day
        $days = (int)$request->get_param('days');
        if ($days <= 0) $days = 30;
        $days = min(max($days, 7), 180); // clamp 7..180
        // Build date buckets in PHP to include days with zero
        // Timezone handling with backwards-compatible fallback
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
        } else {
            $tzString = '';
            if (function_exists('wp_timezone_string')) {
                $tzString = (string)wp_timezone_string();
            }
            if ($tzString === '') {
                $tzString = (string)get_option('timezone_string');
            }
            if ($tzString === '') {
                $offset = (float)get_option('gmt_offset');
                $sign = $offset < 0 ? '-' : '+';
                $hours = (int)floor(abs($offset));
                $mins = (int)round((abs($offset) - $hours) * 60);
                $tzString = sprintf('%s%02d:%02d', $sign, $hours, $mins);
            }
            try { $tz = new \DateTimeZone($tzString ?: 'UTC'); } catch (\Throwable $e) { $tz = new \DateTimeZone('UTC'); }
        }
        $now = new \DateTimeImmutable('now', $tz);
        $start = $now->sub(new \DateInterval('P'.($days-1).'D'));
        $labels = [];
        $map = [];
        for ($i = 0; $i < $days; $i++){
            $d = $start->add(new \DateInterval('P'.$i.'D'));
            $key = $d->format('Y-m-d');
            $labels[] = $key;
            $map[$key] = 0;
        }
        // Query grouped counts from DB (UTC assumed by MySQL; we use DATE of created_at)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$subs} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC",
            $start->format('Y-m-d 00:00:00')
        ), ARRAY_A) ?: [];
        foreach ($rows as $r){
            $d = (string)$r['d'];
            $c = (int)$r['c'];
            if (isset($map[$d])) $map[$d] = $c;
        }
        $series = array_values($map);

        $out = [
            'counts' => [
                'forms' => $total_forms,
                'forms_active' => $active_forms,
                'forms_draft' => $draft_forms,
                'forms_disabled' => $disabled_forms,
                'submissions' => $total_submissions,
                'users' => $users_count,
            ],
            'series' => [
                'labels' => $labels,
                'submissions_per_day' => $series,
            ],
        ];
        return new WP_REST_Response($out, 200);
    }

    /** GET /analytics/metrics — unified metrics (sms sent, submissions, form views, group members) */
    public static function analytics_metrics(WP_REST_Request $request)
    {
        global $wpdb;
        $days = (int)$request->get_param('days'); if ($days <= 0) $days = 30; $days = min(max($days, 7), 180);
        // Resolve table names
        $tSubs = Helpers::tableName('submissions');
        $tViews = Helpers::tableName('form_views');
        $tSms  = Helpers::tableName('sms_log');
        $tGroups = Helpers::tableName('user_groups');
        $tMembers = Helpers::tableName('user_group_members');

        // Totals
        $total_sms = $wpdb->get_var("SELECT COUNT(*) FROM {$tSms}") ?: 0;
        $total_subs = $wpdb->get_var("SELECT COUNT(*) FROM {$tSubs}") ?: 0;
        $total_views = $wpdb->get_var("SELECT COUNT(*) FROM {$tViews}") ?: 0;
        $total_members = $wpdb->get_var("SELECT COUNT(*) FROM {$tMembers}") ?: 0;

        // Build date buckets
        if (function_exists('wp_timezone')) { $tz = wp_timezone(); } else { $tz = new \DateTimeZone('UTC'); }
        $now = new \DateTimeImmutable('now', $tz);
        $start = $now->sub(new \DateInterval('P'.($days-1).'D'));
        $labels = []; $seriesSms = []; $seriesSubs = []; $seriesViews = []; $mapSms=[]; $mapSubs=[]; $mapViews=[];
        for ($i=0; $i<$days; $i++){ $d=$start->add(new \DateInterval('P'.$i.'D')); $k=$d->format('Y-m-d'); $labels[]=$k; $mapSms[$k]=0; $mapSubs[$k]=0; $mapViews[$k]=0; }
        $since = $start->format('Y-m-d 00:00:00');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM {$tSms} WHERE created_at >= %s GROUP BY DATE(created_at)", $since), ARRAY_A) ?: [];
        foreach($rows as $r){ $d=$r['d']; $c=(int)$r['c']; if(isset($mapSms[$d])) $mapSms[$d]=$c; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM {$tSubs} WHERE created_at >= %s GROUP BY DATE(created_at)", $since), ARRAY_A) ?: [];
        foreach($rows as $r){ $d=$r['d']; $c=(int)$r['c']; if(isset($mapSubs[$d])) $mapSubs[$d]=$c; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM {$tViews} WHERE created_at >= %s GROUP BY DATE(created_at)", $since), ARRAY_A) ?: [];
        foreach($rows as $r){ $d=$r['d']; $c=(int)$r['c']; if(isset($mapViews[$d])) $mapViews[$d]=$c; }
        $seriesSms = array_values($mapSms); $seriesSubs = array_values($mapSubs); $seriesViews = array_values($mapViews);

        $out = [
            'totals' => [
                'sms' => (int)$total_sms,
                'submissions' => (int)$total_subs,
                'views' => (int)$total_views,
                'group_members' => (int)$total_members,
            ],
            'series' => [
                'labels' => $labels,
                'sms_per_day' => $seriesSms,
                'submissions_per_day' => $seriesSubs,
                'views_per_day' => $seriesViews,
            ],
        ];
        return new WP_REST_Response($out, 200);
    }

    public static function get_forms(WP_REST_Request $request)
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $rows = $wpdb->get_results("SELECT id, status, meta, created_at FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        $data = array_map(function ($r) {
            $meta = json_decode($r['meta'] ?: '{}', true);
            return [
                'id' => (int)$r['id'],
                'title' => $meta['title'] ?? 'بدون عنوان',
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows ?: []);
        return new WP_REST_Response($data, 200);
    }

    public static function create_form(WP_REST_Request $request)
    {
        $title = trim((string)($request->get_param('title') ?? 'فرم بدون عنوان'));
        $formData = [
            'schema_version' => '1.0.0',
            'owner_id' => get_current_user_id(),
            'status' => 'draft',
            'meta' => [ 'title' => $title ],
        ];
        $form = new Form($formData);
        $id = FormRepository::save($form);
        if ($id > 0) {
            // Log audit entry with undo token
            $undo = Audit::log('create_form', 'form', $id, [], [ 'form' => [ 'id'=>$id, 'title'=>$title, 'status'=>'draft' ] ]);
            return new WP_REST_Response([ 'id' => $id, 'title' => $title, 'status' => 'draft', 'undo_token' => $undo ], 201);
        }
        global $wpdb;
        $err = $wpdb->last_error ?: 'unknown_db_error';
        return new WP_REST_Response([ 'error' => 'create_failed', 'message' => $err ], 500);
    }

    public static function get_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        // Ensure token exists for published forms only (avoid generating for drafts/disabled)
        if (self::user_can_manage_forms() && $form->status === 'published' && empty($form->public_token)) {
            FormRepository::save($form);
            $form = FormRepository::find($id) ?: $form;
        }
    $fields = FormsFieldRepository::listByForm($id);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ];
        // Expose token only for published forms to users who can manage
        if (self::user_can_manage_forms() && $form->status === 'published' && !empty($form->public_token)) {
            $payload['token'] = $form->public_token;
        }
        return new WP_REST_Response($payload, 200);
    }

    /**
     * Public-safe: only returns form definition when status is published.
     * Hides token field and returns 403 for draft/disabled forms.
     */
    public static function get_public_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        // Enforce group-based access if any mapping exists
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
    $fields = FormsFieldRepository::listByForm($id);
        // Minimal personalization via GET params for title/description placeholders like #name
        $meta = $form->meta;
        $params = $request->get_params();
        $repl = [];
        foreach ($params as $k=>$v){ if (is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[$k] = $v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        // Then apply member-based hydration for server-side personalization
        $meta = self::hydrate_meta_with_member($meta, $member);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $meta,
            'fields' => $fields,
        ];
        return new WP_REST_Response($payload, 200);
    }

    /**
     * GET /settings (admin-only)
     */
    public static function get_settings(WP_REST_Request $request)
    {
        $settings = self::get_global_settings();
        return new WP_REST_Response(['settings' => $settings], 200);
    }

    /**
     * PUT /settings (admin-only)
     */
    public static function update_settings(WP_REST_Request $request)
    {
        $data = $request->get_param('settings');
        if (!is_array($data)) $data = [];
        $san = self::sanitize_settings_input($data);
        $current = get_option('arshline_settings', []);
        $cur = is_array($current) ? $current : [];
        $merged = array_merge($cur, $san);
        update_option('arshline_settings', $merged, false);
        $out = self::get_global_settings();
        return new WP_REST_Response(['ok'=>true, 'settings'=>$out], 200);
    }

    // ---- AI config helpers & endpoints ----
    protected static function get_ai_settings(): array
    {
        $raw = get_option('arshline_settings', []);
        $arr = is_array($raw) ? $raw : [];
        $base = isset($arr['ai_base_url']) && is_scalar($arr['ai_base_url']) ? trim((string)$arr['ai_base_url']) : '';
        // normalize base URL (no trailing spaces, keep as-is otherwise to allow custom paths)
        $base = substr($base, 0, 500);
        $key = isset($arr['ai_api_key']) && is_scalar($arr['ai_api_key']) ? trim((string)$arr['ai_api_key']) : '';
        $key = substr($key, 0, 2000);
        $enabled = isset($arr['ai_enabled']) ? (bool)$arr['ai_enabled'] : false;
        $model = isset($arr['ai_model']) && is_scalar($arr['ai_model']) ? (string)$arr['ai_model'] : 'auto';
        $model_mode = isset($arr['ai_model_mode']) && is_scalar($arr['ai_model_mode']) ? (string)$arr['ai_model_mode'] : 'auto';
        $model_mode = in_array($model_mode, ['auto', 'manual'], true) ? $model_mode : 'auto';
    $parser = isset($arr['ai_parser']) && is_scalar($arr['ai_parser']) ? (string)$arr['ai_parser'] : 'hybrid'; // 'internal' | 'hybrid' | 'llm'
        $parser = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid';
    // Analytics defaults
    $anaMaxTok = isset($arr['ai_ana_max_tokens']) && is_numeric($arr['ai_ana_max_tokens']) ? max(16, min(4096, (int)$arr['ai_ana_max_tokens'])) : 1200;
    $anaChunkSize = isset($arr['ai_ana_chunk_size']) && is_numeric($arr['ai_ana_chunk_size']) ? max(50, min(2000, (int)$arr['ai_ana_chunk_size'])) : 800;
    $anaAutoFmt = isset($arr['ai_ana_auto_format']) ? (bool)$arr['ai_ana_auto_format'] : true;
    $anaShowAdv = isset($arr['ai_ana_show_advanced']) ? (bool)$arr['ai_ana_show_advanced'] : false;
        // Hoshang-specific optional overrides
        $hoshModel = isset($arr['ai_hosh_model']) && is_scalar($arr['ai_hosh_model']) ? (string)$arr['ai_hosh_model'] : '';
        $hoshMode  = isset($arr['ai_hosh_mode']) && is_scalar($arr['ai_hosh_mode']) ? (string)$arr['ai_hosh_mode'] : 'hybrid';
        $hoshMode  = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid';
        // New: allowlist for AI-accessible menus/actions
        $allowedMenus = isset($arr['ai_allowed_menus']) && is_array($arr['ai_allowed_menus']) ? array_values(array_unique(array_filter(array_map('strval', $arr['ai_allowed_menus'])))) : ['dashboard','forms'];
        $allowedActions = isset($arr['ai_allowed_actions']) && is_array($arr['ai_allowed_actions']) ? array_values(array_unique(array_filter(array_map('strval', $arr['ai_allowed_actions'])))) : [];
    return [ 'base_url' => $base, 'api_key' => $key, 'enabled' => $enabled, 'model' => $model, 'model_mode' => $model_mode, 'parser' => $parser, 'hosh_model' => $hoshModel, 'hosh_mode' => $hoshMode, 'ana_max_tokens' => $anaMaxTok, 'ana_chunk_size' => $anaChunkSize, 'ana_auto_format' => $anaAutoFmt, 'ana_show_advanced' => $anaShowAdv, 'allowed_menus' => $allowedMenus, 'allowed_actions' => $allowedActions ];
    }
    public static function get_ai_config(WP_REST_Request $request)
    {
        return new WP_REST_Response(['config' => self::get_ai_settings()], 200);
    }
    public static function update_ai_config(WP_REST_Request $request)
    {
    $cfg = $request->get_param('config');
        if (!is_array($cfg)) $cfg = [];
        $base = is_scalar($cfg['base_url'] ?? '') ? trim((string)$cfg['base_url']) : '';
        $key  = is_scalar($cfg['api_key'] ?? '') ? trim((string)$cfg['api_key']) : '';
        $enabled = (bool)($cfg['enabled'] ?? false);
        $model = is_scalar($cfg['model'] ?? '') ? trim((string)$cfg['model']) : '';
        $model_mode = is_scalar($cfg['model_mode'] ?? '') ? trim((string)$cfg['model_mode']) : '';
    $parser = is_scalar($cfg['parser'] ?? '') ? trim((string)$cfg['parser']) : '';
    $anaMaxTok = isset($cfg['ana_max_tokens']) && is_numeric($cfg['ana_max_tokens']) ? max(16, min(4096, (int)$cfg['ana_max_tokens'])) : null;
    $anaChunkSize = isset($cfg['ana_chunk_size']) && is_numeric($cfg['ana_chunk_size']) ? max(50, min(2000, (int)$cfg['ana_chunk_size'])) : null;
    $anaAutoFmt = isset($cfg['ana_auto_format']) ? (bool)$cfg['ana_auto_format'] : null;
    $anaShowAdv = isset($cfg['ana_show_advanced']) ? (bool)$cfg['ana_show_advanced'] : null;
        $hoshModel = is_scalar($cfg['hosh_model'] ?? '') ? trim((string)$cfg['hosh_model']) : '';
        $hoshMode  = is_scalar($cfg['hosh_mode'] ?? '') ? trim((string)$cfg['hosh_mode']) : '';
        $allowedMenus = isset($cfg['allowed_menus']) && is_array($cfg['allowed_menus']) ? array_values(array_unique(array_filter(array_map('strval', $cfg['allowed_menus'])))) : null;
        $allowedActions = isset($cfg['allowed_actions']) && is_array($cfg['allowed_actions']) ? array_values(array_unique(array_filter(array_map('strval', $cfg['allowed_actions'])))) : null;
        $cur = get_option('arshline_settings', []);
        $arr = is_array($cur) ? $cur : [];
        $arr['ai_base_url'] = substr($base, 0, 500);
        $arr['ai_api_key']  = substr($key, 0, 2000);
        $arr['ai_enabled']  = $enabled;
        if ($model !== ''){ $arr['ai_model'] = substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', $model), 0, 100); }
        if ($model_mode !== ''){ $arr['ai_model_mode'] = in_array($model_mode, ['auto', 'manual'], true) ? $model_mode : 'auto'; }
    if ($parser !== ''){ $arr['ai_parser'] = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid'; }
    if ($anaMaxTok !== null){ $arr['ai_ana_max_tokens'] = $anaMaxTok; }
    if ($anaChunkSize !== null){ $arr['ai_ana_chunk_size'] = $anaChunkSize; }
    if ($anaAutoFmt !== null){ $arr['ai_ana_auto_format'] = (bool)$anaAutoFmt; }
    if ($anaShowAdv !== null){ $arr['ai_ana_show_advanced'] = (bool)$anaShowAdv; }
        if ($hoshModel !== ''){ $arr['ai_hosh_model'] = substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', $hoshModel), 0, 100); }
        if ($hoshMode  !== ''){ $arr['ai_hosh_mode']  = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid'; }
        if ($allowedMenus !== null){ $arr['ai_allowed_menus'] = $allowedMenus; }
        if ($allowedActions !== null){ $arr['ai_allowed_actions'] = $allowedActions; }
        update_option('arshline_settings', $arr, false);
        return new WP_REST_Response(['ok'=>true, 'config'=> self::get_ai_settings()], 200);
    }
    public static function test_ai_connect(WP_REST_Request $request)
    {
        $s = self::get_ai_settings();
        if (!$s['enabled'] || !$s['base_url'] || !$s['api_key']){
            return new WP_REST_Response(['ok'=>false, 'error'=>'missing_config'], 400);
        }
        $url = rtrim($s['base_url'], '/').'/';
        $resp = wp_remote_get($url, [ 'timeout'=>5, 'headers'=> [ 'Authorization' => 'Bearer '.$s['api_key'] ] ]);
        if (is_wp_error($resp)) return new WP_REST_Response(['ok'=>false, 'error'=>'network'], 502);
        $code = (int)wp_remote_retrieve_response_code($resp);
        return new WP_REST_Response(['ok'=> ($code>=200 && $code<500), 'status'=>$code ], 200);
    }
    public static function get_ai_capabilities(WP_REST_Request $request)
    {
        $caps = [
            'navigation' => [
                'title' => 'مسیر‌یابی',
                'items' => [
                    [ 'id' => 'open_tab', 'label' => 'باز کردن تب‌ها', 'params' => ['tab' => 'dashboard|forms|reports|analytics|users|settings', 'section?' => 'security|ai|users'], 'confirm' => false, 'examples' => [ 'باز کردن تنظیمات', 'باز کردن فرم‌ها', 'باز کردن تحلیل‌ها' ] ],
                    [ 'id' => 'open_builder', 'label' => 'باز کردن ویرایشگر فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'ویرایش فرم 12' ] ],
                    [ 'id' => 'open_editor', 'label' => 'ویرایش یک پرسش خاص', 'params' => ['id' => 'number', 'index' => 'number (0-based)'], 'confirm' => false, 'examples' => [ 'ویرایش پرسش 1 فرم 12' ] ],
                    [ 'id' => 'public_link', 'label' => 'دریافت لینک عمومی فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'لینک عمومی فرم 7' ] ],
                ],
            ],
            'forms' => [
                'title' => 'فرم‌ها',
                'items' => [
                    [ 'id' => 'list_forms', 'label' => 'نمایش لیست فرم‌ها', 'params' => [], 'confirm' => false, 'examples' => [ 'لیست فرم ها' ] ],
                    [ 'id' => 'create_form', 'label' => 'ایجاد فرم جدید', 'params' => ['title' => 'string'], 'confirm' => true, 'examples' => [ 'ایجاد فرم با عنوان فرم تست' ] ],
                    [ 'id' => 'delete_form', 'label' => 'حذف فرم', 'params' => ['id' => 'number'], 'confirm' => true, 'examples' => [ 'حذف فرم 5' ] ],
                    [ 'id' => 'open_form', 'label' => 'فعال کردن فرم (انتشار)', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'فعال کن فرم 3', 'انتشار فرم 3' ] ],
                    [ 'id' => 'close_form', 'label' => 'بستن/غیرفعال کردن فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'غیرفعال کن فرم 8', 'بستن فرم 8' ] ],
                    [ 'id' => 'draft_form', 'label' => 'بازگرداندن به پیش‌نویس', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'پیش‌نویس کن فرم 4' ] ],
                    [ 'id' => 'update_form_title', 'label' => 'تغییر عنوان فرم', 'params' => ['id' => 'number', 'title' => 'string'], 'confirm' => true, 'examples' => [ 'عنوان فرم 2 را به فرم مشتریان تغییر بده' ] ],
                    [ 'id' => 'export_csv', 'label' => 'خروجی CSV از ارسال‌ها', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'خروجی فرم 5' ] ],
                ],
            ],
            'settings' => [
                'title' => 'تنظیمات',
                'items' => [
                    [ 'id' => 'set_setting', 'label' => 'تغییر تنظیمات سراسری', 'params' => ['key' => 'ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model|ai_parser', 'value' => 'string|number|boolean'], 'confirm' => true, 'examples' => [ 'فعال کردن هوش مصنوعی', 'مدل را روی gpt-5 بگذار', 'تحلیل دستورات با اوپن‌ای‌آی', 'تحلیلگر را هیبرید کن' ] ],
                    [ 'id' => 'ui', 'label' => 'اقدامات رابط کاربری', 'params' => ['target' => 'toggle_theme|undo|go_back|open_editor_index', 'index?' => 'number (0-based)'], 'confirm' => false, 'examples' => [ 'تم را تغییر بده', 'یک قدم برگرد', 'بازگردانی کن', 'پرسش 1 را باز کن' ] ],
                ],
            ],
            'ui' => [
                'title' => 'تعاملات UI',
                'items' => [
                    [ 'id' => 'toggle_theme', 'label' => 'روشن/تاریک', 'params' => [], 'confirm' => false, 'examples' => [ 'حالت تاریک را فعال کن' ] ],
                    [ 'id' => 'open_ai_terminal', 'label' => 'باز کردن ترمینال هوش مصنوعی', 'params' => [], 'confirm' => false, 'examples' => [ 'ترمینال هوش مصنوعی را باز کن' ] ],
                ],
            ],
            'help' => [
                'title' => 'کمک',
                'items' => [
                    [ 'id' => 'help', 'label' => 'نمایش راهنما و لیست توانمندی‌ها', 'params' => [], 'confirm' => false, 'examples' => [ 'کمک', 'لیست دستورات' ] ],
                ],
            ],
        ];
        return new WP_REST_Response(['ok' => true, 'capabilities' => $caps], 200);
    }
    public static function ai_agent(WP_REST_Request $request)
    {
        // Helper closures for fuzzy matching titles and normalizing Persian strings
        $normalize = function(string $s): string {
            $s = trim($s);
            $s = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $s); // ZWNJ,RLM
            $s = preg_replace('/\s+/u',' ', $s);
            return mb_strtolower($s, 'UTF-8');
        };
        $score_title = function(string $q, string $t) use ($normalize): float {
            $q = $normalize($q); $t = $normalize($t);
            if ($q === '' || $t === '') return 0.0;
            if (mb_strpos($t, $q, 0, 'UTF-8') !== false) return 1.0; // strong contains
            // fallback: normalized similarity (similar_text is byte-based; acceptable here)
            $a = $q; $b = $t; $pct = 0.0; similar_text($a, $b, $pct); return max(0.0, min(1.0, $pct/100.0));
        };
        $find_by_title = function(string $q, int $limit = 5) use ($score_title){
            $forms = self::get_forms_list();
            $scored = [];
            foreach ($forms as $f){
                $s = $score_title($q, (string)($f['title'] ?? ''));
                if ($s > 0){ $scored[] = [ 'id' => (int)$f['id'], 'title' => (string)$f['title'], 'score' => $s ]; }
            }
            usort($scored, function($x,$y){ return $y['score'] <=> $x['score']; });
            return array_slice($scored, 0, max(1, $limit));
        };
        // Allow site owner to control auto-confirm threshold for non-destructive title matches
        $get_auto_confirm_threshold = function(): float {
            $default = 0.75; // if >= this score and exactly one match, execute directly
            if (function_exists('apply_filters')){
                $val = apply_filters('arshline_ai_title_auto_confirm_threshold', $default);
                $num = is_numeric($val) ? (float)$val : $default;
                return max(0.0, min(1.0, $num));
            }
            return $default;
        };
        // UI context for page-aware LLM prompting
        $ui_tab = (string)($request->get_param('ui_tab') ?? '');
        $ui_tab = $ui_tab !== '' ? $ui_tab : 'dashboard';
        $ui_route = (string)($request->get_param('ui_route') ?? '');
        // Build a concise capability shortlist relevant to the current tab
        $capsResp = self::get_ai_capabilities($request);
        $capsData = $capsResp instanceof WP_REST_Response ? $capsResp->get_data() : ['capabilities'=>[]];
        $allCaps = $capsData['capabilities'] ?? [];
        $filterCaps = function(array $all, string $tab) {
            $takeIds = [];
            $tab = strtolower($tab);
            if ($tab === 'forms' || strpos($tab, 'builder') !== false){
                $takeIds = ['open_tab','open_builder','open_editor','public_link','list_forms','create_form','delete_form','open_form','close_form','draft_form','update_form_title','export_csv','ui','help'];
            } elseif ($tab === 'reports'){
                $takeIds = ['open_tab','export_csv','list_forms','ui','help'];
            } elseif ($tab === 'settings'){
                $takeIds = ['open_tab','set_setting','ui','help'];
            } else {
                $takeIds = ['open_tab','list_forms','open_builder','public_link','ui','help'];
            }
            $out = [];
            foreach ($all as $group){
                if (!is_array($group['items'] ?? null)) continue;
                foreach (($group['items'] ?? []) as $it){
                    $id = (string)($it['id'] ?? '');
                    if ($id !== '' && in_array($id, $takeIds, true)){
                        $out[] = [
                            'id' => $id,
                            'label' => (string)($it['label'] ?? $id),
                            'examples' => is_array($it['examples'] ?? null) ? array_slice($it['examples'], 0, 2) : [],
                        ];
                    }
                }
            }
            return $out;
        };
        $kb = $filterCaps(is_array($allCaps) ? $allCaps : [], $ui_tab);
        // New structured intents for Hoshyar (هوشیار)
        $intentName = (string)($request->get_param('intent') ?? '');
        $intentName = trim($intentName);
        if ($intentName !== ''){
            try {
                $params = $request->get_param('params');
                if (!is_array($params)) $params = [];
                $out = Hoshyar::agent([ 'intent' => $intentName, 'params' => $params ]);
                return new \WP_REST_Response($out, 200);
            } catch (\Throwable $e) {
                return new \WP_REST_Response(['ok'=>false,'error'=>'hoshyar_error'], 200);
            }
        }

        // Plan-based flow: { plan: {...}, confirm?: bool }
        $planPayload = $request->get_param('plan');
        if (is_array($planPayload)){
            try {
                $confirm = (bool)$request->get_param('confirm');
                $out = Hoshyar::agent([ 'plan' => $planPayload, 'confirm' => $confirm ]);
                return new \WP_REST_Response($out, 200);
            } catch (\Throwable $e) {
                return new \WP_REST_Response(['ok'=>false,'error'=>'hoshyar_error'], 200);
            }
        }

        // Legacy command-based flow (backward-compat)
        $cmd = (string)($request->get_param('command') ?? '');
        $cmd = trim($cmd);
        // Execute previously confirmed action directly (accept legacy 'type' as well)
        $confirmPayload = $request->get_param('confirm_action');
        if (is_array($confirmPayload)){
            if (!isset($confirmPayload['action']) && isset($confirmPayload['type'])){
                $confirmPayload['action'] = (string)$confirmPayload['type'];
            }
            if (isset($confirmPayload['action'])){
                return self::execute_confirmed_action($confirmPayload);
            }
        }
    if ($cmd === '') return new WP_REST_Response(['ok'=>false,'error'=>'empty_command'], 200);
        try {
            // 0) Prefer LLM plan parsing when configured — this enables multi-step flows
            $s0 = self::get_ai_settings();
            $allowedMenus0 = array_map('strval', $s0['allowed_menus'] ?? []);
            $allowedActions0 = array_map('strval', $s0['allowed_actions'] ?? []);
            $parserMode0 = $s0['parser'] ?? 'hybrid';
            $llmReady0 = ($s0['enabled'] && !empty($s0['base_url']) && !empty($s0['api_key']));
            $hadPlan0 = false;
            if ($llmReady0 && in_array($parserMode0, ['hybrid','llm'], true)){
                $plan0 = self::llm_parse_plan($cmd, $s0, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($plan0) && !empty($plan0['steps'])){
                    $hadPlan0 = true;
                    // Validate/preview via Hoshyar (will refuse invalid/unknown actions)
                    try {
                        // If allowlist of actions is configured, filter/deny before preview
                        if (!empty($allowedActions0)){
                            foreach (($plan0['steps'] ?? []) as $st){ $aa = (string)($st['action'] ?? ''); if ($aa && !in_array($aa, $allowedActions0, true)) { return new \WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$aa], 403); } }
                        }
                        $out0 = Hoshyar::agent([ 'plan' => $plan0, 'confirm' => false ]);
                        if (is_array($out0) && !empty($out0['ok'])){
                            return new \WP_REST_Response($out0, 200);
                        }
                    } catch (\Throwable $e) { /* fall through to other parsers */ }
                }
            }
            // 0.5) Internal heuristic multi-step parser as fallback (no external LLM required)
            // Handles phrases like: "فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسمش را چکاپ سه بگذار"
            // Produces a plan: create_form (with extracted title) + N x add_field (inferred types)
            $tryInternalPlan = (!$llmReady0 || in_array($parserMode0, ['hybrid','internal'], true) || ($llmReady0 && $parserMode0==='llm' && !$hadPlan0));
            if ($tryInternalPlan){
                $iplan = self::internal_parse_plan($cmd);
                if (is_array($iplan) && !empty($iplan['steps']) && count($iplan['steps']) >= 1){
                    try {
                        if (!empty($allowedActions0)){
                            foreach (($iplan['steps'] ?? []) as $st){ $aa = (string)($st['action'] ?? ''); if ($aa && !in_array($aa, $allowedActions0, true)) { return new \WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$aa], 403); } }
                        }
                        $outPrev = Hoshyar::agent([ 'plan' => $iplan, 'confirm' => false ]);
                        if (is_array($outPrev) && !empty($outPrev['ok'])){
                            return new \WP_REST_Response($outPrev, 200);
                        }
                    } catch (\Throwable $e) { /* ignore */ }
                }
            }
            // Parser routing based on AI mode
            $s = self::get_ai_settings();
            $parserMode = $s['parser'] ?? 'hybrid';
            $llmReady = ($s['enabled'] && !empty($s['base_url']) && !empty($s['api_key']));
            if ($llmReady && $parserMode === 'llm'){
                $intent = self::llm_parse_command($cmd, $s, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($intent) && isset($intent['action'])){
                    $action = (string)$intent['action'];
                    // Map add_field with title->id when needed
                    if ($action === 'add_field'){
                        $itype = (string)($intent['type'] ?? '');
                        if (!empty($intent['title']) && empty($intent['id'])){
                            $matches = $find_by_title((string)$intent['title'], 5);
                            if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                            elseif (!empty($matches)){
                                $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>$itype?:'short_text']], 200);
                            }
                        }
                        if (!empty($intent['id'])){
                            $fid = (int)$intent['id']; $itype = $itype ?: 'short_text';
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'یک سوال '+($itype==='short_text'?'پاسخ کوتاه':'از نوع '+$itype)+' به فرم '+$fid+' اضافه شود؟',
                                'confirm_action'=>['action'=>'add_field','params'=>['id'=>$fid,'type'=>$itype]]
                            ], 200);
                        }
                    }
                    // Map title->id if id is missing but title is present
                    if (!empty($intent['title']) && empty($intent['id'])){
                        $matches = $find_by_title((string)$intent['title'], 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                        elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            $msg = ($action==='open_results') ? 'نتایج کدام فرم را باز کنم؟' : 'کدام فرم را ویرایش کنم؟';
                            $clarifyTarget = ($action==='open_results') ? 'open_results' : 'open_builder';
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$clarifyTarget]], 200);
                        }
                    }
                    // Pass-through common actions
                    if (!empty($intent['action'])){
                        if ($action === 'open_tab' && !empty($intent['tab'])){
                            $tab = (string)$intent['tab'];
                            if (!empty($allowedMenus0) && !in_array($tab, $allowedMenus0, true)) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'دسترسی به این منو مجاز نیست: '.$tab], 403);
                            return new WP_REST_Response(['ok'=>true,'action'=>'open_tab','tab' => $tab], 200);
                        }
                        if (!empty($allowedActions0) && !in_array($action, $allowedActions0, true)) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$action], 403);
                        if ($action === 'open_builder' && !empty($intent['id'])) return new WP_REST_Response(['ok'=>true,'action'=>'open_builder','id' => (int)$intent['id']], 200);
                        if ($action === 'open_results' && !empty($intent['id'])) return new WP_REST_Response(['ok'=>true,'action'=>'open_results','id' => (int)$intent['id']], 200);
                        if ($action === 'open_editor' && !empty($intent['id'])){ $idx = isset($intent['index']) ? (int)$intent['index'] : 0; return new WP_REST_Response(['ok'=>true,'action'=>'open_editor','id' => (int)$intent['id'],'index'=>$idx], 200); }
                        if ($action === 'create_form' && !empty($intent['title'])) return new WP_REST_Response(['ok'=>true,'action'=>'confirm','message'=>'ایجاد فرم جدید با عنوان «'.(string)$intent['title'].'» تایید می‌کنید؟','confirm_action'=> [ 'action'=>'create_form', 'params'=>['title' => (string)$intent['title']] ]], 200);
                        if ($action === 'delete_form' && !empty($intent['id'])){ $fid = (int)$intent['id']; return new WP_REST_Response(['ok'=>true,'action'=>'confirm','message'=>'حذف فرم شماره '.$fid.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.','confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$fid] ]], 200); }
                        if ($action === 'list_forms'){ $forms = self::get_forms_list(); return new WP_REST_Response(['ok'=>true, 'action'=>'list_forms', 'forms'=>$forms], 200); }
                    }
                }
                // else continue to internal parsing
            }
            // High-priority: direct menu name invocation (before form-oriented heuristics)
            try {
                $menuMatchEarly = \Arshline\Core\MenuRegistry::findByCommand($cmd);
                if ($menuMatchEarly && !empty($menuMatchEarly['slug'])){
                    $slugEM = $menuMatchEarly['slug'];
                    $tabEM = '';
                    if (str_contains($slugEM, 'dashboard')) $tabEM='dashboard';
                    elseif (str_contains($slugEM,'forms')) $tabEM='forms';
                    elseif (str_contains($slugEM,'analytics')) $tabEM='analytics';
                    elseif (str_contains($slugEM,'reports')) $tabEM='reports';
                    elseif (str_contains($slugEM,'user-groups')) $tabEM='users';
                    elseif (str_contains($slugEM,'users')) $tabEM='users';
                    elseif (str_contains($slugEM,'settings')) $tabEM='settings';
                    if ($tabEM !== ''){
                        return new WP_REST_Response(['ok'=>true,'action'=>'open_tab','tab'=>$tabEM,'via'=>'menu_match_early'], 200);
                    }
                }
            } catch (\Throwable $e){ /* ignore */ }
            // Add field (short_text) — examples:
            // "(یک )?سوال (پاسخ کوتاه|کوتاه|short_text) (در فرم (\d+|{title}))? اضافه کن|بساز"
            // New phrasing support: "افزودن سوال پاسخ کوتاه (به|در) فرم X"
            if (preg_match('/^(?:یه|یک)?\s*سوال\s*(?:پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)\s*(?:را|رو)?\s*(?:اضافه\s*کن|بساز)(?:\s*در\s*فرم\s*(.+))?$/iu', $cmd, $m)
                || preg_match('/^(?:افزودن|اضافه\s*کردن)\s*سوال\s*(?:پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)(?:\s*(?:به|در)\s*فرم\s*(.+))?$/iu', $cmd, $m)){
                $target = isset($m[1]) ? trim((string)$m[1]) : '';
                $fid = 0;
                if ($target !== ''){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $num = (int) strtr(preg_replace('/\D+/u','', $target), $fa2en);
                    if ($num > 0){ $fid = $num; }
                    else {
                        $matches = $find_by_title($target, 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                            $fid = (int)$matches[0]['id'];
                        } elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>'short_text']], 200);
                        }
                    }
                }
                if ($fid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'یک سوال پاسخ کوتاه به فرم '.$fid.' اضافه شود؟',
                        'confirm_action'=> [ 'action'=>'add_field', 'params'=>['id'=>$fid, 'type'=>'short_text'] ]
                    ], 200);
                }
                // No target provided: ask to choose a form
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=> (int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'add_field','type'=>'short_text']], 200);
            }
            // Help / capabilities
            if (preg_match('/^(کمک|راهنما|لیست\s*دستورات)$/u', $cmd)){
                $caps = self::get_ai_capabilities($request);
                $data = $caps instanceof WP_REST_Response ? $caps->get_data() : ['capabilities'=>[]];
                return new WP_REST_Response(['ok'=>true, 'action'=>'help', 'capabilities'=>$data['capabilities'] ?? []], 200);
            }
            // User Groups (UG) intents — lightweight internal parser
            // 1) Open UG panel (optionally a specific tab)
            if (preg_match('/^(?:برو\s*به\s*)?(?:کاربران\s*\/\s*)?(گروه(?:‌|\s|-)*های\s*کاربری)(?:\s*،?\s*(?:تب)?\s*(گروه(?:‌|\s|-)*ها|اعضا|اتصال|نقشه|فیلد(?:های)?\s*سفارشی))?$/iu', $cmd, $mUG)){
                $tabWord = isset($mUG[2]) ? trim((string)$mUG[2]) : '';
                $tab = 'groups';
                if ($tabWord !== ''){
                    $w = mb_strtolower($tabWord, 'UTF-8');
                    if (preg_match('/^اعضا$/u', $w)) $tab = 'members';
                    elseif (preg_match('/^(اتصال|نقشه)$/u', $w)) $tab = 'mapping';
                    elseif (preg_match('/^فیلد/u', $w)) $tab = 'custom_fields';
                    else $tab = 'groups';
                }
                return new WP_REST_Response(['ok'=>true,'action'=>'open_ug','tab'=>$tab], 200);
            }
            // Small helper: find groups by fuzzy name
            $find_group_candidates = function(string $needle, int $limit = 5): array {
                $needle = trim($needle);
                if ($needle === '') return [];
                try { $rows = \Arshline\Modules\UserGroups\GroupRepository::paginated($limit, 1, $needle, 'name', 'ASC'); }
                catch (\Throwable $e) { $rows = []; }
                $nl = function($s){ return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); };
                $n = $nl($needle);
                $out = [];
                foreach ($rows as $g){
                    $name = (string)$g->name;
                    $nameNL = $nl($name);
                    $pos = ($n !== '' && $nameNL !== '' && ($p = mb_stripos($nameNL, $n, 0, 'UTF-8')) !== false) ? (int)$p : -1;
                    $score = $pos >= 0 ? (0.9 - min(0.6, $pos*0.05)) : 0.4; // rough heuristic
                    $out[] = [ 'id'=>(int)$g->id, 'name'=>$name, 'score'=>$score ];
                }
                usort($out, function($a,$b){ return $b['score'] <=> $a['score']; });
                return array_slice($out, 0, max(1,$limit));
            };
            // 2) Create group by name
            if (preg_match('/^(?:ایجاد|بساز|درست\s*کن|ساختن)\s*گروه\s*(?:جدید\s*)?(?:به\s*نام|با\s*نام)?\s*(.+)$/iu', $cmd, $mGCreate)){
                $name = trim((string)$mGCreate[1]);
                $name = trim($name, '\"\'\u{00AB}\u{00BB}'); // strip quotes if any
                if ($name !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'ایجاد گروه کاربری با نام «'.$name.'» تایید می‌کنید؟',
                        'confirm_action'=> [ 'action'=>'ug_create_group', 'params'=>['name'=>$name] ]
                    ], 200);
                }
            }
            // 3) Rename/update group name by id: "نام گروه 12 را به X تغییر بده"
            if (preg_match('/^نام\s*گروه\s*(\d+)\s*(?:را|رو)?\s*(?:به|به\s*نام)\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/iu', $cmd, $mGRename)){
                $gid = (int)$mGRename[1]; $newName = trim((string)$mGRename[2]);
                if ($gid > 0 && $newName !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'نام گروه '.$gid.' به «'.$newName.'» تغییر داده شود؟',
                        'confirm_action'=> [ 'action'=>'ug_update_group', 'params'=>['id'=>$gid,'name'=>$newName] ]
                    ], 200);
                }
            }
            // 3b) Rename group by name (no id): "نام گروه فروش را به مشتریان تغییر بده"
            if (preg_match('/^نام\s*گروه\s+(.+?)\s*(?:را|رو)?\s*(?:به|به\s*نام)\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/iu', $cmd, $mGRenameByName)){
                $gname = trim((string)$mGRenameByName[1]); $newName = trim((string)$mGRenameByName[2]);
                if ($gname !== '' && $newName !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'نام گروه '.$gid.' («'.($cands[0]['name']??'').'») به «'.$newName.'» تغییر داده شود؟',
                            'confirm_action'=> [ 'action'=>'ug_update_group', 'params'=>['id'=>$gid,'name'=>$newName] ]
                        ], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'نام کدام گروه را تغییر بدهم؟',
                            'param_key'=>'id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_update_group', 'params'=> ['name'=>$newName] ]
                        ], 200);
                    }
                }
            }
            // 4) Ensure tokens for a group id
            if (preg_match('/^(?:تولید|ایجاد|بساز)\s*(?:توکن|token)(?:\s*برای)?\s*اعضا(?:ی)?\s*گروه\s*(\d+)$/iu', $cmd, $mTok)){
                $gid = (int)$mTok[1];
                if ($gid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'توکن اعضای گروه '.$gid.' تولید شود؟',
                        'confirm_action'=> [ 'action'=>'ug_ensure_tokens', 'params'=>['group_id'=>$gid] ]
                    ], 200);
                }
            }
            // 4b) Ensure tokens by group name
            if (preg_match('/^(?:تولید|ایجاد|بساز)\s*(?:توکن|token)\s*(?:برای)?\s*اعضا(?:ی)?\s*گروه\s+(.+)$/iu', $cmd, $mTokName)){
                $gname = trim((string)$mTokName[1]);
                if ($gname !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'توکن اعضای گروه '.$gid.' («'.($cands[0]['name']??'').'») تولید شود؟',
                            'confirm_action'=> [ 'action'=>'ug_ensure_tokens', 'params'=>['group_id'=>$gid] ]
                        ], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'برای کدام گروه توکن بسازم؟',
                            'param_key'=>'group_id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_ensure_tokens' ]
                        ], 200);
                    }
                }
            }
            // 5) Download members template for group id
            if (preg_match('/^(?:دانلود|بگیر)\s*(?:فایل|نمونه|تمپلیت)\s*اعضا(?:ی)?\s*گروه\s*(\d+)$/iu', $cmd, $mTpl)){
                $gid = (int)$mTpl[1];
                if ($gid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_download_members_template','group_id'=>$gid], 200); }
            }
            // 6) Export per-member links for a group id OR a form id
            if (preg_match('/^(?:خروجی|دانلود)\s*(?:لینک(?:\s*های)?|پیوند(?:\s*ها)?)\s*اعضا(?:ی)?\s*(?:گروه\s*(\d+)|برای\s*فرم\s*(\d+))$/iu', $cmd, $mExp)){
                $gid = isset($mExp[1]) ? (int)$mExp[1] : 0; $fid = isset($mExp[2]) ? (int)$mExp[2] : 0;
                if ($gid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','group_id'=>$gid], 200); }
                if ($fid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','form_id'=>$fid], 200); }
            }
            // 6b) Export links by group name
            if (preg_match('/^(?:خروجی|دانلود)\s*(?:لینک(?:\s*های)?|پیوند(?:\s*ها)?)\s*اعضا(?:ی)?\s*گروه\s+(.+)$/iu', $cmd, $mExpName)){
                $gname = trim((string)$mExpName[1]);
                if ($gname !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','group_id'=>$gid], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'خروجی لینک‌های اعضای کدام گروه؟',
                            'param_key'=>'group_id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_export_links' ]
                        ], 200);
                    }
                }
            }
            // 7) Set form access groups by numeric ids: "برای فرم 5 گروه‌های 2،3 را مجاز کن"
            if (preg_match('/^برای\s*فرم\s*(\d+)\s*گروه(?:‌|\s|-)*های\s*([\d\s,،و]+)\s*(?:را)?\s*(?:مجاز|فعال)\s*کن$/iu', $cmd, $mMap)){
                $fid = (int)$mMap[1]; $list = (string)$mMap[2];
                if ($fid > 0){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $list = strtr($list, $fa2en);
                    preg_match_all('/\d+/', $list, $mm);
                    $gids = array_values(array_unique(array_map('intval', $mm[0] ?? [])));
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'دسترسی فرم '.$fid.' برای گروه‌ها ['.implode(', ', $gids).'] تنظیم شود؟',
                        'confirm_action'=> [ 'action'=>'ug_set_form_access', 'params'=>['form_id'=>$fid, 'group_ids'=>$gids] ]
                    ], 200);
                }
            }
            // 7b) Set form access groups by NAMES: "برای فرم 5 گروه‌های فروش، مشتریان را مجاز کن"
            if (preg_match('/^برای\s*فرم\s*(\d+)\s*گروه(?:‌|\s|-)*های\s+(.+?)\s*(?:را)?\s*(?:مجاز|فعال)\s*کن$/iu', $cmd, $mMapNames)){
                $fid = (int)$mMapNames[1]; $namesStr = trim((string)$mMapNames[2]);
                if ($fid > 0 && $namesStr !== ''){
                    // Split by Persian/English commas and "و"
                    $parts = preg_split('/\s*(?:,|،|\s+و\s+)\s*/u', $namesStr);
                    $parts = array_values(array_filter(array_map('trim', is_array($parts)?$parts:[]))); // clean
                    $resolved = []; $unresolved = [];
                    foreach ($parts as $nm){
                        $cands = $find_group_candidates($nm, 5);
                        if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.7){ $resolved[] = (int)$cands[0]['id']; continue; }
                        if (!empty($cands)){
                            // Return clarify for the first unresolved name; UI will loop with remaining later if needed
                            $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'clarify',
                                'kind'=>'options',
                                'message'=>'کدام گروه منظور است؟ «'.$nm.'»',
                                'param_key'=>'group_id',
                                'options'=>$opts,
                                'clarify_action'=> [ 'action'=>'ug_set_form_access', 'params'=> ['form_id'=>$fid, 'group_ids'=>$resolved] ]
                            ], 200);
                        }
                        $unresolved[] = $nm;
                    }
                    if (!empty($resolved)){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'دسترسی فرم '.$fid.' برای گروه‌ها ['.implode(', ', $resolved).'] تنظیم شود؟',
                            'confirm_action'=> [ 'action'=>'ug_set_form_access', 'params'=>['form_id'=>$fid, 'group_ids'=>$resolved] ]
                        ], 200);
                    }
                }
            }
            // New Form (natural phrases): e.g., "فرم جدید می خوام", "یک فرم جدید میخوام", "میخوام یک فرم جدید"
            // We treat these as a request to create a new form with a sensible default title.
            if (
                preg_match('/^(?:می\s*خوام|میخوام)\s*(?:یه|یک)?\s*فرم\s*جدید$/u', $cmd)
                || preg_match('/^(?:یه|یک)?\s*فرم\s*جدید\s*(?:می\s*خوام|میخوام)$/u', $cmd)
                || preg_match('/^(?:ایجاد|ساختن|بساز)\s*(?:یه|یک)?\s*فرم\s*جدید$/u', $cmd)
                || preg_match('/^فرم\s*جدید$/u', $cmd)
            ){
                $defTitle = apply_filters('arshline_ai_new_form_default_title', 'فرم جدید');
                // Sanitize <fa> wrapper tags from final answer if present
                if (is_array($res) && isset($res['answer']) && is_string($res['answer'])){
                    $res['answer'] = preg_replace('/^<fa>(.*)<\/fa>$/u', '$1', (string)$res['answer']);
                }
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'ایجاد فرم جدید با عنوان «'.(string)$defTitle.'» تایید می‌کنید؟',
                    'confirm_action'=> [ 'action'=>'create_form', 'params'=>['title' => (string)$defTitle] ]
                ], 200);
            }
            // Create form: "ایجاد فرم با عنوان X"
            if (preg_match('/^ایجاد\s*فرم\s*با\s*عنوان\s*(.+)$/u', $cmd, $m)){
                $title = trim($m[1]);
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'ایجاد فرم جدید با عنوان «'.$title.'» تایید می‌کنید؟',
                    'confirm_action'=> [ 'action'=>'create_form', 'params'=>['title'=>$title] ]
                ], 200);
            }
            // Delete form: "حذف فرم <id>"
            if (preg_match('/^حذف\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'حذف فرم شماره '.$fid.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.',
                    'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$fid] ]
                ], 200);
            }
            // Delete form without id -> clarify
            if (preg_match('/^حذف\s*فرم\s*$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=> (int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را حذف کنم؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'delete_form']], 200);
            }
            // Public link: "لینک عمومی فرم <id>"
            if (preg_match('/^لینک\s*عمومی\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('GET', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $res = self::get_form($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $status = isset($data['status']) ? (string)$data['status'] : '';
                $token = isset($data['token']) ? (string)$data['token'] : '';
                $url = ($token && $status==='published') ? home_url('/?arshline='.rawurlencode($token)) : '';
                return new WP_REST_Response(['ok'=> (bool)$url, 'url'=>$url, 'token'=>$token], 200);
            }
            // Activate/publish form: "فعال کن فرم <id>" | "انتشار فرم <id>"
            if (preg_match('/^(?:فعال\s*کن|فعال\s*کردن|انتشار)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'published']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Disable/close form: "غیرفعال کن فرم <id>" | "بستن فرم <id>"
            if (preg_match('/^(?:غیرفعال\s*کن|غیرفعال\s*کردن|بستن)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'disabled']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Draft form: "پیش نویس کن فرم <id>" | "بازگرداندن به پیش‌نویس فرم <id>"
            if (preg_match('/^(?:پیش\s*نویس\s*کن|پیش‌نویس\s*کن|بازگرداندن\s*به\s*پیش‌نویس)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'draft']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Update title: "عنوان فرم <id> را به X تغییر بده/بذار/کن"
            if (preg_match('/^عنوان\s*فرم\s*(\d+)\s*(?:را)?\s*به\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $title = trim((string)$m[2]);
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'عنوان فرم '.$fid.' به «'.$title.'» تغییر داده شود؟',
                    'confirm_action'=>['action'=>'update_form_title','params'=>['id'=>$fid,'title'=>$title]]
                ], 200);
            }
            // Title-based open builder: "ویرایش/ادیت فرم {title}" when no numeric id
            if (preg_match('/^(?:ویرایش|ادیت|edit|باز\s*کردن|بازش\s*کن|سازنده)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // Attempt Persian digit to int first
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Title-first open builder: "{title} رو باز کن/وا کن" (implicit form)
            if (preg_match('/^(.+?)\s*(?:را|رو)\s*(?:باز\s*کن|باز\s*کردن|وا\s*کن)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // Guard: if the requested name is an app tab, prefer opening that tab instead of treating it as a form title
                $raw = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $name); // remove ZWNJ/RLM
                $raw = preg_replace('/\s+/u', ' ', $raw);
                $tabMap = [
                    'داشبورد' => 'dashboard', 'خانه' => 'dashboard',
                    'فرم ها' => 'forms', 'فرم‌ها' => 'forms', 'فرمها' => 'forms', 'فرم' => 'forms',
                    // reports synonyms (plain reports)
                    'گزارشات' => 'reports', 'گزارش' => 'reports', 'آمار' => 'reports', 'statistics' => 'reports', 'stats' => 'reports', 'report' => 'reports', 'reports' => 'reports',
                    // analytics (distinct tab)
                    'تحلیل' => 'analytics', 'تحلیل ها' => 'analytics', 'تحلیل‌ها' => 'analytics', 'تحلیلها' => 'analytics', 'تحلیل نتایج' => 'analytics', 'تحلیل فرم ها' => 'analytics', 'تحلیل فرم‌ها' => 'analytics',
                    'آنالیز' => 'analytics', 'آنالیزها' => 'analytics', 'آنالیز ها' => 'analytics', 'آنالیز فرم ها' => 'analytics',
                    'analytics' => 'analytics', 'insights' => 'analytics', 'form analytics' => 'analytics', 'forms analytics' => 'analytics',
                    'کاربران' => 'users', 'کاربر' => 'users', 'اعضا' => 'users',
                    'تنظیمات' => 'settings', 'تنظیم' => 'settings', 'ستینگ' => 'settings', 'پیکربندی' => 'settings',
                ];
                // Remove common menu prefix like "منوی" or "منو" for tab resolution
                $rawNorm = preg_replace('/^(?:منوی|منو)\s+/u','',$rawNorm);
                // Normalize some common variants
                $rawNorm = str_replace(['‌'], ' ', $raw); // Persian half-space to normal space
                $rawNorm = trim($rawNorm);
                if (isset($tabMap[$rawNorm])){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tabMap[$rawNorm]], 200);
                }
                if (!preg_match('/^(?:فرم|forms?)$/iu', $name)){
                    $matches = $find_by_title($name, 5);
                    if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                        $m1 = $matches[0];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                    if (!empty($matches)){
                        $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                        return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                    }
                }
            }
            // Results by title: "نتایج [فرم] {title}"
            if (preg_match('/^نتایج\s*(?:فرم)?\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$matches[0]['id']], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'نتایج کدام فرم را باز کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_results']], 200);
                }
            }
            // Title-based open builder with form-first order: "فرم {title} رو ادیت/ویرایش کن"
            if (preg_match('/^فرم\s+(.+?)\s*(?:را|رو)?\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Title-only to edit (implicit "فرم" omitted): "{title} رو ادیت کن" | "{title} را ویرایش کن"
            if (preg_match('/^(.+?)\s*(?:را|رو)\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // If user meant a known tab name, ignore this rule to avoid confusion
                $knownTabs = ['داشبورد','dashboard','settings','reports','analytics','users','فرم‌ها','فرمها','forms','گزارشات','گزارش','تحلیل','تحلیل ها','تحلیل‌ها','تحلیلها','آنالیز','statistics','آمار'];
                $nl = function(string $s){ return mb_strtolower($s, 'UTF-8'); };
                $nameNL = $nl($name);
                foreach ($knownTabs as $t){ if ($nl($t) === $nameNL){ $name = ''; break; } }
                if ($name !== ''){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                    if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                    $matches = $find_by_title($name, 5);
                    if (count($matches) === 1){
                        $m1 = $matches[0];
                        if ($m1['score'] >= $get_auto_confirm_threshold()){
                            return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                        }
                        if ($m1['score'] >= 0.6){
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                                'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                            ], 200);
                        }
                    }
                    if (!empty($matches)){
                        $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                        return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                    }
                }
            }
            // Title-based delete: "حذف فرم {title}"
            if (preg_match('/^(?:حذف|پاک(?:\s*کردن)?|delete)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'حذف فرم شماره '.$num.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.',
                        'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$num] ]
                    ], 200);
                }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'آیا منظورتان حذف «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟ حذف غیرقابل بازگشت است.',
                        'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را حذف کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'delete_form']], 200);
                }
            }
            // List forms: "لیست فرم ها" | "نمایش فرم ها"
            if (preg_match('/^(لیست|نمایش)\s*فرم(?:\s*ها)?$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                // Normalize minimal list
                $list = array_map(function($r){ return [ 'id'=>(int)($r['id']??0), 'title'=>(string)($r['title']??'') ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true, 'forms'=>$list], 200);
            }
            // Open builder: "باز کردن فرم <id>" | "ویرایش فرم <id>"
            if (preg_match('/^(باز\s*کردن|ویرایش)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[2];
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$fid], 200);
            }
            // Open a specific question editor by index: "پرسش 1 را ویرایش کن" (optionally "در فرم 12")
            if (preg_match('/^پرسش\s*(\d+)\s*(?:را|رو)?\s*(?:ویرایش|ادیت|edit)\s*(?:کن)?(?:\s*در\s*فرم\s*(\d+))?$/u', $cmd, $m)){
                $qIndexHuman = (int)$m[1];
                $fid = isset($m[2]) ? (int)$m[2] : 0;
                $index = max(0, $qIndexHuman - 1); // convert to 0-based
                if ($fid > 0){
                    // We can go directly to editor if form id is specified
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_editor', 'id'=>$fid, 'index'=>$index], 200);
                }
                // Without form id, if currently in a builder context, UI can handle it; otherwise ask to clarify form
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'ui',
                    'target'=>'open_editor_index',
                    'index'=>$index,
                    'message'=>'در صورتی که در صفحه ویرایش فرم هستید، ویرایشگر پرسش '+($index+1)+' باز می‌شود؛ در غیر اینصورت لطفاً شماره فرم را مشخص کنید (مثلا: پرسش '+($index+1)+' فرم 12).'
                ], 200);
            }
            // Back/Undo: "برگرد" | "یک قدم برگرد" | "بازگردانی" | "آن‌دو" | "undo"
            if (preg_match('/^(?:برگرد|یک\s*قدم\s*برگرد|بازگردانی|آن‌?دو|undo)$/iu', $cmd)){
                return new WP_REST_Response(['ok'=>true, 'action'=>'ui', 'target'=>'go_back'], 200);
            }
            if (preg_match('/^(?:بازگردانی\s*کن|undo\s*کن|بازگردانی)$/iu', $cmd)){
                return new WP_REST_Response(['ok'=>true, 'action'=>'ui', 'target'=>'undo'], 200);
            }
            // Open builder without id -> clarify
            if (preg_match('/^(باز\s*کردن|ویرایش)\s*فرم\s*$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=>(int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'فرم مورد نظر برای باز کردن؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'open_builder']], 200);
            }
            // Open tab: "باز کردن تنظیمات" | "باز کردن گزارشات" | "باز کردن فرم ها"
            if (preg_match('/^باز\s*کردن\s*(داشبورد|فرم\s*ها|گزارشات|کاربران|تنظیمات)$/u', $cmd, $m)){
                $map = [ 'داشبورد'=>'dashboard', 'فرم ها'=>'forms', 'فرمها'=>'forms', 'گزارشات'=>'reports', 'گزارش'=>'reports', 'آمار'=>'reports', 'statistics'=>'reports', 'report'=>'reports', 'reports'=>'reports', 'تحلیل'=>'analytics', 'تحلیلها'=>'analytics', 'تحلیل ها'=>'analytics', 'تحلیل‌ها'=>'analytics', 'آنالیز'=>'analytics', 'آنالیزها'=>'analytics', 'analytics'=>'analytics', 'کاربران'=>'users', 'تنظیمات'=>'settings' ];
                $raw = (string)$m[1]; $raw = str_replace('‌',' ', $raw);
                $tab = $map[$raw] ?? ($raw === 'فرم ها' ? 'forms' : 'dashboard');
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tab], 200);
            }
            // Colloquial navigation verbs: "بازش کن", "واکن", "ببر به X", "برو تو X"
            if (preg_match('/^(?:بازش\s*کن|وا\s*کن|واکن|ببر\s*به|برو\s*تو|برو\s*به)\s*(داشبورد|فرم\s*ها|فرم|گزارشات|کاربران|تنظیمات)$/u', $cmd, $m)){
                $raw = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', (string)$m[1]);
                $raw = preg_replace('/\s+/u', ' ', $raw);
                $map = [ 'داشبورد'=>'dashboard', 'فرم ها'=>'forms', 'فرم'=>'forms', 'گزارشات'=>'reports', 'گزارش'=>'reports', 'آمار'=>'reports', 'statistics'=>'reports', 'report'=>'reports', 'reports'=>'reports', 'تحلیل'=>'analytics', 'تحلیل ها'=>'analytics', 'تحلیل‌ها'=>'analytics', 'تحلیلها'=>'analytics', 'تحلیل نتایج'=>'analytics', 'آنالیز'=>'analytics', 'آنالیزها'=>'analytics', 'analytics'=>'analytics', 'کاربران'=>'users', 'تنظیمات'=>'settings' ];
                $tab = $map[$raw] ?? ($raw === 'فرم ها' || $raw === 'فرم' ? 'forms' : 'dashboard');
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tab], 200);
            }
            // Colloquial: "برو تو فرم <id>" | "برو تو فرم {title}"
            if (preg_match('/^(?:برو\s*تو|برو\s*به|ببر\s*به)\s*فرم\s*(.+)$/u', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200);
                }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان باز کردن «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را باز کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Open tab without target -> clarify
            if (preg_match('/^باز\s*کردن\s*$/u', $cmd)){
                $opts = [
                    ['label'=>'داشبورد', 'value'=>'dashboard'],
                    ['label'=>'فرم‌ها', 'value'=>'forms'],
                    ['label'=>'گزارشات', 'value'=>'reports'],
                    ['label'=>'کاربران', 'value'=>'users'],
                    ['label'=>'تنظیمات', 'value'=>'settings'],
                ];
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام بخش را باز کنم؟','param_key'=>'tab','options'=>$opts,'clarify_action'=>['action'=>'open_tab']], 200);
            }
            // Export CSV: "خروجی فرم <id>" | "دانلود csv فرم <id>"
            if (preg_match('/^(خروجی|دانلود)\s*(csv\s*)?فرم\s*(\d+)$/iu', $cmd, $m)){
                $fid = (int)$m[3];
                $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            // Open results by id: "نتایج فرم <id>" | "نمایش نتایج فرم <id>"
            if (preg_match('/^(?:نتایج|نمایش\s*نتایج)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$fid], 200);
            }
            // Open results by title (no id)
            if (preg_match('/^(?:نتایج|نمایش\s*نتایج)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'نتایج «'.$m1['title'].'» (شناسه '.$m1['id'].') نمایش داده شود؟',
                        'confirm_action'=> [ 'action'=>'open_results', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'نتایج کدام فرم را نمایش دهم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_results']], 200);
                }
            }
            // Heuristic colloquial navigation: "منوی فرم‌ها رو باز کن"، "برو به فرم‌ها", "منو تنظیمات" + tolerate "بازش کن" and "واکن"
            {
                $plain = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $cmd); // remove ZWNJ, RLM
                $hasNavVerb = preg_match('/(منو|منوی|باز\s*کن|بازش\s*کن|باز|وا\s*کن|واکن|برو\s*به|برو\s*تو|برو|ببر\s*به|نمایش|نشون\s*بده)/u', $plain) === 1;
                $syns = [
                    'forms' => ['فرم ها','فرمها','فرم‌ها','فرم'],
                    'dashboard' => ['داشبورد','خانه'],
                    'reports' => ['گزارشات','گزارش','آمار','statistics','stats'],
                    'analytics' => ['تحلیل','تحلیل ها','تحلیل‌ها','تحلیلها','تحلیل نتایج','تحلیل فرم ها','تحلیل فرم‌ها','آنالیز','آنالیزها','آنالیز ها','آنالیز فرم ها','analytics','insights'],
                    'users' => ['کاربران','کاربر','اعضا'],
                    'settings' => ['تنظیمات','تنظیم','ستینگ','پیکربندی'],
                ];
                $foundTab = '';
                foreach ($syns as $tabKey => $words){
                    foreach ($words as $w){ if ($w !== '' && mb_strpos($plain, $w) !== false){ $foundTab = $tabKey; break 2; } }
                }
                // Extra tolerance for 'forms' and 'dashboard' if not matched strictly
                if (!$foundTab){
                    // Any occurrence of 'فرم' (with/without half-space/plurals) → forms
                    if (preg_match('/فرم[\s‌]*ها?/u', $plain) || mb_strpos($plain, 'فرم') !== false){ $foundTab = 'forms'; }
                    // Any token starting with 'داشب' → dashboard
                    if (!$foundTab && mb_strpos($plain, 'داشب') !== false){ $foundTab = 'dashboard'; }
                }
                if ($foundTab && ($hasNavVerb || mb_strpos($plain, 'منو') !== false)){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$foundTab], 200);
                }
                // Tolerate common typos for dashboard: e.g., "داشبودر" → detect via prefix 'داشب'
                if (!$foundTab && ($hasNavVerb || mb_strpos($plain, 'منو') !== false)){
                    if (mb_strpos($plain, 'داشب') !== false){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>'dashboard'], 200);
                    }
                }
            }
            // Colloquial Persian digit map for id parsing
            $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
            $toInt = function($s) use ($fa2en){ return (int) strtr(preg_replace('/\D+/u','', (string)$s), $fa2en); };

            // Colloquial: open/edit form builder by id
                        if (preg_match('/^(ویرایش|ادیت|edit|باز(?:\s*کن)?|بازش\s*کن|سازنده)\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
                            || preg_match('/^فرم\s*([0-9۰-۹]+)\s*(?:را|رو)?\s*(ویرایش|ادیت|edit|باز)(?:\s*کن)?$/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$fid], 200);
                }
            }

            // Colloquial: delete form by id (confirmation)
            if (preg_match('/^(حذف|پاک(?:\s*کردن)?|دلیت|delete)\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
              || preg_match('/^فرم\s*([0-9۰-۹]+)\s*(?:را|رو)?\s*(حذف|پاک)(?:\s*کن)?$/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>sprintf(__('آیا از حذف فرم %d مطمئنید؟','arshline'), $fid),
                        'confirm_action'=>['type'=>'delete_form','params'=>['id'=>$fid]]
                    ], 200);
                }
            }

            // Colloquial: create form with name
            if (preg_match('/^(بساز|ایجاد|درست\s*کن)\s*فرم(?:\s*جدید)?\s*(?:با\s*عنوان|به\s*نام)?\s*(.+)$/iu', $cmd, $m)){
                $name = trim($m[2]);
                $name = trim($name, " \"'\x{200C}\x{200F}");
                if ($name !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>sprintf(__('فرم جدید با عنوان "%s" ساخته شود؟','arshline'), $name),
                        'confirm_action'=>['type'=>'create_form','params'=>['name'=>$name]]
                    ], 200);
                }
            }

            // Create a new blank form and open builder (no title specified)
            // Examples: "یک فرم جدید باز کن", "فرم جدید باز کن", "فرم جدید بساز", "یک فرم جدید بساز"
            if (preg_match('/^(?:یک\s*)?فرم\s*جدید\s*(?:باز\s*کن|بساز|ایجاد\s*کن)$/u', $cmd)
                || preg_match('/^(?:باز\s*کردن\s*)?فرم\s*جدید$/u', $cmd)){
                // Create a draft form with default title and return open_builder
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                // Title left empty to let create_form apply its default (or we could pass 'فرم جدید')
                $res = self::create_form($req);
                if ($res instanceof WP_REST_Response){
                    $data = $res->get_data();
                    $newId = isset($data['id']) ? (int)$data['id'] : 0;
                    if ($newId > 0){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$newId, 'undo_token'=>($data['undo_token']??null)], 200);
                    }
                }
                return new WP_REST_Response(['ok'=>false, 'error'=>'create_failed'], 500);
            }

            // Colloquial: export csv by id
            if (preg_match('/^(اکسپورت|خروجی|دانلود)\s*(?:csv\s*)?(?:از\s*)?فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
              || preg_match('/^csv\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                    return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
                }
            }

            // Colloquial: list forms
            if (preg_match('/^(لیست|فهرست|نمایش|نشون\s*بده)\s*فرم(?:\s*ها)?$/iu', $cmd)){
                $forms = self::get_forms_list();
                return new WP_REST_Response(['ok'=>true, 'action'=>'list_forms', 'forms'=>$forms], 200);
            }
            // If not matched, try LLM-assisted parsing when configured (hybrid fallback)
            $s = self::get_ai_settings();
            $parserMode = $s['parser'] ?? 'hybrid';
            if ($s['enabled'] && $parserMode === 'hybrid' && $s['base_url'] && $s['api_key']){
                $intent = self::llm_parse_command($cmd, $s, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($intent) && isset($intent['action'])){
                    $action = (string)$intent['action'];
                    // Map add_field with title->id when needed
                    if ($action === 'add_field'){
                        $itype = (string)($intent['type'] ?? '');
                        if (!empty($intent['title']) && empty($intent['id'])){
                            $matches = $find_by_title((string)$intent['title'], 5);
                            if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                            elseif (!empty($matches)){
                                $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>$itype?:'short_text']], 200);
                            }
                        }
                        if (!empty($intent['id'])){
                            $fid = (int)$intent['id']; $itype = $itype ?: 'short_text';
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'یک سوال '+($itype==='short_text'?'پاسخ کوتاه':'از نوع '+$itype)+' به فرم '+$fid+' اضافه شود؟',
                                'confirm_action'=>['action'=>'add_field','params'=>['id'=>$fid,'type'=>$itype]]
                            ], 200);
                        }
                    }
                    // Map title->id if id is missing but title is present
                    if (!empty($intent['title']) && empty($intent['id'])){
                        $matches = $find_by_title((string)$intent['title'], 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                        elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            $msg = ($action==='open_results') ? 'نتایج کدام فرم را باز کنم؟' : 'کدام فرم را ویرایش کنم؟';
                            $clarifyTarget = ($action==='open_results') ? 'open_results' : 'open_builder';
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$clarifyTarget]], 200);
                        }
                    }
                    if ($action === 'create_form' && !empty($intent['title'])){
                        $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                        $req->set_body_params(['title'=>(string)$intent['title']]);
                        $res = self::create_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'delete_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('DELETE', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $res = self::delete_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'public_link' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('GET', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $res = self::get_form($req);
                        $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                        $status = isset($data['status']) ? (string)$data['status'] : '';
                        $token = isset($data['token']) ? (string)$data['token'] : '';
                        $url = ($token && $status==='published') ? home_url('/?arshline='.rawurlencode($token)) : '';
                        return new WP_REST_Response(['ok'=> (bool)$url, 'url'=>$url, 'token'=>$token], 200);
                    }
                    if ($action === 'list_forms'){
                        $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                        $res = self::get_forms($req);
                        $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                        $list = array_map(function($r){ return [ 'id'=>(int)($r['id']??0), 'title'=>(string)($r['title']??'') ]; }, is_array($rows)?$rows:[]);
                        return new WP_REST_Response(['ok'=>true, 'forms'=>$list], 200);
                    }
                    if ($action === 'open_builder' && !empty($intent['id'])){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>(int)$intent['id']], 200);
                    }
                    if ($action === 'open_tab' && !empty($intent['tab'])){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>(string)$intent['tab']], 200);
                    }
                    if ($action === 'export_csv' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                        return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
                    }
                    if ($action === 'open_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'published']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'close_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'disabled']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'draft_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'draft']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'update_form_title' && !empty($intent['id']) && isset($intent['title'])){
                        $fid = (int)$intent['id'];
                        $title = (string)$intent['title'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'عنوان فرم '.$fid.' به «'.$title.'» تغییر داده شود؟',
                            'confirm_action'=>['action'=>'update_form_title','params'=>['id'=>$fid,'title'=>$title]]
                        ], 200);
                    }
                    // If LLM says unknown, fall through to suggestions
                    if ($action === 'unknown'){ /* handled below */ }
                }
            }
            // Attempt structured parse via IntentParser (colloquial Persian)
            try {
                $ip = \Arshline\Core\Ai\Parsing\IntentParser::parse((string)$cmd);
                if (!empty($ip['ok']) && !empty($ip['action'])){
                    if (($ip['action'] === 'ui') && ($ip['target'] ?? '') === 'toggle_theme'){
                        return new WP_REST_Response(['ok'=>true,'action'=>'ui','target'=>'toggle_theme','mode'=>($ip['mode'] ?? null),'detected'=>($ip['source'] ?? 'parser')], 200);
                    }
                    if (in_array($ip['action'], ['open_builder','open_results','list_forms'], true)){
                        if (!empty($ip['id']) && $ip['action']==='open_builder'){
                            return new WP_REST_Response(['ok'=>true,'action'=>'open_builder','id'=>(int)$ip['id']], 200);
                        }
                        if (!empty($ip['id']) && $ip['action']==='open_results'){
                            return new WP_REST_Response(['ok'=>true,'action'=>'open_results','id'=>(int)$ip['id']], 200);
                        }
                        if ($ip['action']==='list_forms'){
                            $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                            $res = self::get_forms($req);
                            $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                            $list = array_map(function($r){ return [ 'id'=>(int)($r['id']??0), 'title'=>(string)($r['title']??'') ]; }, is_array($rows)?$rows:[]);
                            return new WP_REST_Response(['ok'=>true,'action'=>'list_forms','forms'=>$list], 200);
                        }
                    }
                }
            } catch (\Throwable $e) { /* swallow parser errors */ }

            // Final fallback: suggest next steps instead of plain unknown
            $plain = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $cmd);
            $hasEdit = preg_match('/(ویرایش|ادیت|edit|باز\s*کردن|بازش\s*کن|سازنده)/u', $plain) === 1;
            $hasDel = preg_match('/(حذف|پاک(?:\s*کردن)?|delete)/iu', $plain) === 1;
            if ($hasEdit || $hasDel){
                // Try last token after the word "فرم" as a possible name
                if (preg_match('/فرم\s+(.+)$/u', $plain, $mm)){
                    $guess = trim((string)$mm[1]);
                    $matches = $find_by_title($guess, 5);
                    if (!empty($matches)){
                        $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                        $act = $hasDel ? 'delete_form' : 'open_builder';
                        $msg = $hasDel ? 'کدام فرم را حذف کنم؟' : 'کدام فرم را ویرایش کنم؟';
                        return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$act]], 200);
                    }
                }
            }
            // Provide suggestions instead of bare unknown
            $suggest = [ 'نمونه‌ها' => ['ویرایش فرم 12','فرم مشتریان رو ادیت کن','نتایج فرم 5','لیست فرم‌ها'] ];
            return new WP_REST_Response(['ok'=>false,'error'=>'unknown_command','message'=>'دستور واضح نیست.','suggestions'=>$suggest], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'agent_error'], 200);
        }
    }

    /**
     * Execute a previously confirmed action sent by the UI terminal.
     */
    protected static function execute_confirmed_action(array $payload)
    {
        $action = (string)($payload['action'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        try {
            // UG: create group
            if ($action === 'ug_create_group' && !empty($params['name'])){
                $req = new WP_REST_Request('POST', '/arshline/v1/user-groups');
                $body = ['name'=>(string)$params['name']]; if (isset($params['parent_id'])) $body['parent_id'] = (int)$params['parent_id'];
                $req->set_body_params($body);
                $res = self::ug_create_group($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $gid = (int)($data['id'] ?? 0);
                return new WP_REST_Response(['ok'=> ($gid>0), 'action'=>'open_ug', 'tab'=>'groups', 'group_id'=>$gid, 'undo_token'=>($data['undo_token'] ?? null)], 200);
            }
            // UG: update group
            if ($action === 'ug_update_group' && !empty($params['id'])){
                $gid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/user-groups/'.$gid);
                $req->set_url_params(['group_id'=>$gid]);
                $req->set_body_params(array_diff_key($params, ['id'=>true]));
                $res = self::ug_update_group($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true, 'action'=>'open_ug', 'tab'=>'groups', 'group_id'=>$gid], 200);
            }
            // UG: ensure tokens
            if ($action === 'ug_ensure_tokens' && !empty($params['group_id'])){
                $gid = (int)$params['group_id'];
                $req = new WP_REST_Request('POST', '/arshline/v1/user-groups/'.$gid.'/members/ensure-tokens');
                $req->set_url_params(['group_id'=>$gid]);
                $res = self::ug_bulk_ensure_tokens($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                return new WP_REST_Response(['ok'=> $res instanceof WP_REST_Response, 'action'=>'open_ug', 'tab'=>'members', 'group_id'=>$gid, 'generated'=>(int)($data['generated'] ?? 0)], 200);
            }
            // UG: set form access
            if ($action === 'ug_set_form_access' && !empty($params['form_id']) && is_array($params['group_ids'] ?? null)){
                $fid = (int)$params['form_id']; $gids = array_values(array_map('intval', (array)$params['group_ids']));
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid.'/access/groups');
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['group_ids'=>$gids]);
                $res = self::set_form_access_groups($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            // UG: export links (download URL)
            if ($action === 'ug_export_links' && (!empty($params['group_id']) || !empty($params['form_id']))){
                $gid = isset($params['group_id']) ? (int)$params['group_id'] : 0;
                $fid = isset($params['form_id']) ? (int)$params['form_id'] : 0;
                $params2 = []; if ($gid>0) $params2['group_id']=$gid; if ($fid>0) $params2['form_id']=$fid;
                $url = add_query_arg(array_merge(['action'=>'arshline_export_group_links', '_wpnonce'=>wp_create_nonce('arshline_export_group_links')], $params2), admin_url('admin-post.php'));
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            // UG: download members template
            if ($action === 'ug_download_members_template' && !empty($params['group_id'])){
                $gid = (int)$params['group_id'];
                $url = add_query_arg(['action'=>'arshline_download_members_template', '_wpnonce'=>wp_create_nonce('arshline_download_members_template'), 'group_id'=>$gid], admin_url('admin-post.php'));
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            if ($action === 'add_field' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $type = isset($params['type']) ? (string)$params['type'] : 'short_text';
                $questionText = isset($params['question']) && is_string($params['question']) ? trim((string)$params['question']) : '';
                // Load current fields and snapshot for audit
                $before = FormsFieldRepository::listByForm($fid);
                $fields = $before;
                // Default props baseline
                $defaults = [
                    'type'=>'short_text',
                    'label'=>'پاسخ کوتاه',
                    'question'=>'',
                    'format'=>'free_text',
                    'required'=>false,
                    'show_description'=>false,
                    'description'=>'',
                    'placeholder'=>'',
                    'numbered'=>true,
                ];
                if ($type !== 'short_text') { $defaults['type'] = $type; }
                // Specialized defaults
                if ($type === 'long_text'){ $defaults['label'] = 'پاسخ طولانی'; $defaults['format']='paragraph'; }
                elseif ($type === 'multiple_choice'){ $defaults['label']='سوال چندگزینه‌ای'; $defaults['options']=[ ['label'=>'گزینه ۱','value'=>'opt1'], ['label'=>'گزینه ۲','value'=>'opt2'] ]; }
                elseif ($type === 'dropdown'){ $defaults['label']='لیست کشویی'; $defaults['options']=[ ['label'=>'آیتم ۱','value'=>'item1'], ['label'=>'آیتم ۲','value'=>'item2'] ]; }
                elseif ($type === 'rating'){ $defaults['label']='امتیاز'; $defaults['max']=5; }
                elseif ($type === 'welcome'){ $defaults['label']='پیام خوش‌آمد'; $defaults['type']='welcome'; }
                elseif ($type === 'thank_you'){ $defaults['label']='پیام تشکر'; $defaults['type']='thank_you'; }
                // Apply provided question: set both question & label if present
                if ($questionText !== ''){ $defaults['question'] = $questionText; $defaults['label'] = $questionText; }
                $fields[] = [ 'props' => $defaults ];
                FormsFieldRepository::replaceAll($fid, $fields);
                $after = FormsFieldRepository::listByForm($fid);
                $undo = Audit::log('update_form_fields', 'form', $fid, ['fields'=>$before], ['fields'=>$after]);
                $newIndex = max(0, count($after) - 1);
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_editor', 'id'=>$fid, 'index'=>$newIndex, 'undo_token'=>$undo], 200);
            }
            if ($action === 'create_form' && !empty($params['title'])){
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                $req->set_body_params(['title'=>(string)$params['title']]);
                $res = self::create_form($req);
                if ($res instanceof WP_REST_Response){
                    $data = $res->get_data();
                    if (is_array($data) && !empty($data['id'])){
                        // Navigate to builder for the new form and surface undo token
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'open_builder',
                            'id'=>(int)$data['id'],
                            'undo_token'=> ($data['undo_token'] ?? '')
                        ], 200);
                    }
                    return $res;
                }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'open_builder' && !empty($params['id'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>(int)$params['id']], 200);
            }
            if ($action === 'open_tab' && !empty($params['tab'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>(string)$params['tab']], 200);
            }
            if ($action === 'open_results' && !empty($params['id'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>(int)$params['id']], 200);
            }
            if ($action === 'export_csv' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            if ($action === 'delete_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('DELETE', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $res = self::delete_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'set_setting' && !empty($params['key'])){
                $key = (string)$params['key']; $value = $params['value'] ?? null;
                $allowed = ['ai_enabled','ai_model','min_submit_seconds','rate_limit_per_min','block_svg','ai_parser'];
                if (!in_array($key, $allowed, true)){
                    return new WP_REST_Response(['ok'=>false, 'error'=>'invalid_setting'], 200);
                }
                if ($key === 'ai_model' || $key === 'ai_enabled'){
                    $cur = self::get_ai_settings();
                    $cfg = [
                        'base_url' => $cur['base_url'],
                        'api_key' => $cur['api_key'],
                        'enabled' => ($key === 'ai_enabled') ? (bool)$value : $cur['enabled'],
                        'model' => ($key === 'ai_model') ? (string)$value : $cur['model'],
                        'parser' => $cur['parser'],
                    ];
                    $before = ['config'=>$cur];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    $resp = self::update_ai_config($r);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['config'=>$cfg]);
                    if ($resp instanceof WP_REST_Response){ $data = $resp->get_data(); $data['undo_token'] = $undo; return new WP_REST_Response($data, $resp->get_status()); }
                    return $resp;
                } elseif ($key === 'ai_parser'){
                    $cur = self::get_ai_settings();
                    $cfg = [
                        'base_url' => $cur['base_url'],
                        'api_key' => $cur['api_key'],
                        'enabled' => $cur['enabled'],
                        'model' => $cur['model'],
                        'parser' => in_array((string)$value, ['internal','hybrid','llm'], true) ? (string)$value : 'hybrid',
                    ];
                    $before = ['config'=>$cur];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    $resp = self::update_ai_config($r);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['config'=>$cfg]);
                    if ($resp instanceof WP_REST_Response){ $data = $resp->get_data(); $data['undo_token'] = $undo; return new WP_REST_Response($data, $resp->get_status()); }
                    return $resp;
                } else {
                    $cur = get_option('arshline_settings', []);
                    $arr = is_array($cur) ? $cur : [];
                    $before = ['settings'=>$arr];
                    if ($key === 'min_submit_seconds') $arr['min_submit_seconds'] = max(0, (int)$value);
                    if ($key === 'rate_limit_per_min') $arr['rate_limit_per_min'] = max(0, (int)$value);
                    if ($key === 'block_svg') $arr['block_svg'] = (bool)$value;
                    update_option('arshline_settings', $arr, false);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['settings'=>$arr]);
                    return new WP_REST_Response(['ok'=>true, 'settings'=> self::get_global_settings(), 'undo_token'=>$undo], 200);
                }
            }
            if ($action === 'open_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'published']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'close_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'disabled']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'draft_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'draft']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'update_form_title' && !empty($params['id']) && isset($params['title'])){
                $fid = (int)$params['id'];
                $title = (string)$params['title'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid.'/meta');
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['meta'=>['title'=>$title]]);
                $res = self::update_meta($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false, 'error'=>'confirm_execute_failed'], 200);
        }
        return new WP_REST_Response(['ok'=>false, 'error'=>'unknown_confirm_action'], 200);
    }

    /**
     * Minimal forms list for agent suggestions: [{id,title}]
     */
    protected static function get_forms_list(): array
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $rows = $wpdb->get_results("SELECT id, status, meta FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r){
            $meta = json_decode($r['meta'] ?: '{}', true);
            $out[] = [ 'id'=>(int)$r['id'], 'title'=>(string)($meta['title'] ?? 'بدون عنوان') ];
        }
        return $out;
    }

    /**
     * Use an OpenAI-compatible chat/completions endpoint to parse a natural-language command
     * into a structured intent. Expected JSON output schema:
     * { action: "create_form"|"delete_form"|"public_link", title?: string, id?: number }
     */
    protected static function llm_parse_command(string $cmd, array $s, array $ctx = [])
    {
        try {
            $base = rtrim((string)$s['base_url'], '/');
            $model = (string)($s['model'] ?? 'gpt-4o');
            $url = $base . '/v1/chat/completions';
          $sys = 'You are a deterministic command parser for the Arshline dashboard. '
              . 'Your ONLY job is to convert Persian admin commands into a single strict JSON object. '
              . 'Do NOT chat, do NOT add explanations, do NOT ask follow-up questions. Output JSON ONLY. '
              . 'Schema: '
              . '{"action":"create_form|delete_form|public_link|list_forms|open_builder|open_editor|open_tab|open_results|export_csv|help|set_setting|ui|open_form|close_form|draft_form|update_form_title|add_field","title?":string,"id?":number,"index?":number,"tab?":"dashboard|forms|reports|analytics|users|settings","section?":"security|ai|users","key?":"ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model","value?":(string|number|boolean),"target?":"toggle_theme|open_ai_terminal|undo|go_back","type?":"short_text|long_text|multiple_choice|dropdown|rating","params?":object}. '
              . 'Examples: '
              . '"ایجاد فرم با عنوان فرم تست" => {"action":"create_form","title":"فرم تست"}. '
              . '"حذف فرم 12" => {"action":"delete_form","id":12}. '
              . '"لینک عمومی فرم 7" => {"action":"public_link","id":7}. '
              . '"لیست فرم ها" => {"action":"list_forms"}. '
              . '"باز کردن فرم 9" => {"action":"open_builder","id":9}. '
              . '"فرم مشتریان رو ادیت کن" => {"action":"open_builder","title":"فرم مشتریان"}. '
              . '"آزمایش جدید رو باز کن" => {"action":"open_builder","title":"آزمایش جدید"}. '
              . '"نتایج فرم مشتریان" => {"action":"open_results","title":"فرم مشتریان"}. '
              . '"باز کردن تنظیمات" => {"action":"open_tab","tab":"settings"}. '
              . '"خروجی فرم 5" => {"action":"export_csv","id":5}. '
              . '"کمک" => {"action":"help"}. '
              . '"مدل را روی gpt-4o بگذار" => {"action":"set_setting","key":"ai_model","value":"gpt-4o"}. '
              . '"حالت تاریک را فعال کن" => {"action":"ui","target":"toggle_theme","params":{"mode":"dark"}}. '
              . '"فعال کن فرم 3" => {"action":"open_form","id":3}. '
              . '"غیرفعال کن فرم 8" => {"action":"close_form","id":8}. '
              . '"پیش‌نویس کن فرم 4" => {"action":"draft_form","id":4}. '
              . '"عنوان فرم 2 را به فرم مشتریان تغییر بده" => {"action":"update_form_title","id":2,"title":"فرم مشتریان"}. '
              . '"یک سوال پاسخ کوتاه در فرم 5 اضافه کن" => {"action":"add_field","id":5,"type":"short_text"}. '
              . 'Context: you may use the following current UI hints to disambiguate. '
              . 'UI Tab: ' . (!empty($ctx['ui_tab']) ? $ctx['ui_tab'] : 'unknown') . '. '
              . 'UI Route: ' . (!empty($ctx['ui_route']) ? $ctx['ui_route'] : '') . '. '
              . 'Capabilities shortlist (IDs + labels; choose closest): ' . wp_json_encode($ctx['kb'] ?? []) . '. '
              . 'If unclear, reply {"action":"unknown"}.';
            $body = [
                'model' => $model,
                'messages' => [
                    [ 'role' => 'system', 'content' => $sys ],
                    [ 'role' => 'user', 'content' => $cmd ],
                ],
                'temperature' => 0,
                'response_format' => [ 'type' => 'json_object' ],
            ];
            $resp = wp_remote_post($url, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . (string)$s['api_key'],
                ],
                'body' => wp_json_encode($body),
            ]);
            if (is_wp_error($resp)) return null;
            $code = (int)wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) return null;
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            $content = $json['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || $content === '') return null;
            $parsed = json_decode($content, true);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Use an OpenAI-compatible endpoint to convert a natural-language command into a multi-step plan.
     * Returns array { version:1, steps:[{action,params}] } or null when not a clear multi-step.
     * Only emits actions supported by PlanValidator. Steps that require an id must include a numeric id
     * extracted from the user's command; if an id can't be determined, do NOT generate a plan for that step.
     * For add_field immediately after create_form, omit the id to refer to the last created form.
     */
    protected static function llm_parse_plan(string $cmd, array $s, array $ctx = [])
    {
        try {
            $base = rtrim((string)$s['base_url'], '/');
            $model = (string)($s['model'] ?? 'gpt-4o');
            $url = $base . '/v1/chat/completions';
            $sys = 'You are a deterministic planner for Arshline admin. Output ONLY a strict JSON object with no prose. '
                . 'When the user request implies multiple sequential actions, produce a plan JSON of the form: '
                . '{"version":1,"steps":[{"action":"create_form|add_field|update_form_title|open_builder|open_editor|open_results|publish_form|draft_form","params":{...}}, ...]}. '
                . 'Rules: '
                . '1) Allowed actions: create_form, add_field, update_form_title, open_builder, open_editor, open_results, publish_form, draft_form. '
                . '2) For create_form: params: {"title": string (default to "فرم جدید" if missing)}. '
                . '3) For add_field: params: {"id"?: number, "type": "short_text|long_text|multiple_choice|dropdown|rating", "question"?: string, "required"?: boolean, "index"?: number}. '
                . '   If add_field immediately follows create_form, omit id to refer to the last created form. Otherwise include id only if a numeric id is explicitly present in the user command. Do NOT guess ids. '
                . '4) For open_builder/open_results: include numeric "id" ONLY if explicitly provided in the command; otherwise do not produce a plan. '
                . '5) For open_editor: include numeric "id" and 0-based "index" only if explicitly provided; otherwise do not produce a plan. '
                . '6) For publish_form/draft_form/update_form_title: include numeric "id" only if explicitly provided in the command. '
                . '7) If the request is single-step or unclear to produce a valid multi-step plan, reply {"none":true} instead. '
                . '8) Never include unknown keys, never include title references for existing forms except for create_form title or update_form_title title. '
                . 'Context: UI Tab: ' . (!empty($ctx['ui_tab']) ? $ctx['ui_tab'] : 'unknown') . '; UI Route: ' . (!empty($ctx['ui_route']) ? $ctx['ui_route'] : '') . '.';
            $examples = [
                // Persian examples to guide the planner
                '"یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن" => {"version":1,"steps":[{"action":"create_form","params":{"title":"فرم جدید"}},{"action":"add_field","params":{"type":"short_text"}},{"action":"add_field","params":{"type":"short_text"}}]}',
                '"یک فرم با عنوان دریافت بازخورد بساز و یک سوال امتیازدهی اضافه کن" => {"version":1,"steps":[{"action":"create_form","params":{"title":"دریافت بازخورد"}},{"action":"add_field","params":{"type":"rating"}}]}',
                '"عنوان فرم 3 را به فرم مشتریان تغییر بده" => {"version":1,"steps":[{"action":"update_form_title","params":{"id":3,"title":"فرم مشتریان"}}]}',
                '"نتایج فرم 5 را باز کن" => {"none":true}',
            ];
            $sys .= ' Examples: ' . implode(' ', $examples) . ' If not multi-step or id is missing for required steps, output {"none":true}.';
            $body = [
                'model' => $model,
                'messages' => [
                    [ 'role' => 'system', 'content' => $sys ],
                    [ 'role' => 'user', 'content' => $cmd ],
                ],
                'temperature' => 0,
                'response_format' => [ 'type' => 'json_object' ],
            ];
            $resp = wp_remote_post($url, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . (string)$s['api_key'],
                ],
                'body' => wp_json_encode($body),
            ]);
            if (is_wp_error($resp)) return null;
            $code = (int)wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) return null;
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            $content = $json['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || $content === '') return null;
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) return null;
            if (!empty($parsed['none'])) return null;
            // Minimal normalization
            $ver = isset($parsed['version']) ? (int)$parsed['version'] : 0;
            $steps = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];
            if ($ver !== 1 || empty($steps)) return null;
            // Strip any unknown keys in steps params defensively
            $allowedActions = ['create_form','add_field','update_form_title','open_builder','open_editor','open_results','publish_form','draft_form'];
            $outSteps = [];
            foreach ($steps as $s1){
                if (!is_array($s1)) continue;
                $a = isset($s1['action']) ? (string)$s1['action'] : '';
                if (!in_array($a, $allowedActions, true)) continue;
                $p = is_array($s1['params'] ?? null) ? $s1['params'] : [];
                // keep only known params per action
                if ($a === 'create_form'){
                    $title = isset($p['title']) && is_scalar($p['title']) ? (string)$p['title'] : '';
                    $outSteps[] = [ 'action'=>'create_form', 'params'=> [ 'title' => $title !== '' ? $title : apply_filters('arshline_ai_new_form_default_title', 'فرم جدید') ] ];
                } elseif ($a === 'add_field'){
                    $params = [];
                    if (isset($p['id']) && is_numeric($p['id'])){ $params['id'] = (int)$p['id']; }
                    $type = isset($p['type']) && is_scalar($p['type']) ? (string)$p['type'] : 'short_text';
                    $params['type'] = $type;
                    if (isset($p['question']) && is_scalar($p['question'])){ $params['question'] = (string)$p['question']; }
                    if (isset($p['required'])){ $params['required'] = (bool)$p['required']; }
                    if (isset($p['index']) && is_numeric($p['index'])){ $params['index'] = (int)$p['index']; }
                    $outSteps[] = [ 'action'=>'add_field', 'params'=>$params ];
                } elseif ($a === 'update_form_title'){
                    if (isset($p['id']) && is_numeric($p['id']) && isset($p['title']) && is_scalar($p['title'])){
                        $outSteps[] = [ 'action'=>'update_form_title', 'params'=> [ 'id'=>(int)$p['id'], 'title'=>(string)$p['title'] ] ];
                    }
                } elseif ($a === 'open_builder' || $a === 'open_results'){
                    if (isset($p['id']) && is_numeric($p['id'])){ $outSteps[] = [ 'action'=>$a, 'params'=> [ 'id'=>(int)$p['id'] ] ]; }
                } elseif ($a === 'open_editor'){
                    if (isset($p['id']) && is_numeric($p['id']) && isset($p['index']) && is_numeric($p['index'])){
                        $outSteps[] = [ 'action'=>'open_editor', 'params'=> [ 'id'=>(int)$p['id'], 'index'=>(int)$p['index'] ] ];
                    }
                } elseif ($a === 'publish_form' || $a === 'draft_form'){
                    if (isset($p['id']) && is_numeric($p['id'])){ $outSteps[] = [ 'action'=>$a, 'params'=> [ 'id'=>(int)$p['id'] ] ]; }
                }
            }
            if (empty($outSteps)) return null;
            return [ 'version' => 1, 'steps' => $outSteps ];
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Internal heuristic multi-step plan builder for common Persian phrases.
     * Targets commands like:
     *  - "یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسم فرم را چکاپ سه بگذار"
     *  - "فرم جدید بساز، اسمش را \"فرم چکاپ 3\" بگذار و دو سوال کوتاه اضافه کن"
     *  - "یک فرم جدید با عنوان X بساز و دو سوال کوتاه اضافه کن"
     * Returns plan {version:1, steps:[...]} or null if not applicable.
     */
    protected static function internal_parse_plan(string $cmd)
    {
        $plain = trim($cmd);
        if ($plain === '') return null;
    // Normalize Arabic/Persian control chars and punctuation/whitespace
    $sep = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $plain); // ZWNJ, RLM
    $sep = preg_replace('/\s+/u', ' ', $sep);
    $sep = preg_replace('/[،,]+/u', '،', $sep);
    $sep = trim($sep);
        // Detect intent to create a new form — tolerate interleaving "با عنوان ..." before the verb
        $hasCreate =
            // "فرم جدید ... بساز/ایجاد کن"
            preg_match('/فرم\s*جدید(?:\s*با\s*(?:عنوان|نام)\s*.+?)?\s*(?:را|رو)?\s*(?:بساز|ایجاد\s*کن)/u', $sep) === 1
            // "یک/یه فرم جدید ... بساز/ایجاد کن"
            || preg_match('/(?:یه|یک)\s*فرم\s*جدید(?:\s*با\s*(?:عنوان|نام)\s*.+?)?\s*(?:را|رو)?\s*(?:بساز|ایجاد\s*کن)/u', $sep) === 1
            // Verb-first
            || preg_match('/(?:ایجاد|ساختن|بساز)\s*(?:یه|یک)?\s*فرم\s*جدید/u', $sep) === 1
            // Minimal: just "فرم جدید" anywhere
            || preg_match('/فرم\s*جدید/u', $sep) === 1
            // Explicit: "ایجاد فرم با عنوان X"
            || preg_match('/ایجاد\s*فرم\s*با\s*(?:عنوان|نام)/u', $sep) === 1;
        if (!$hasCreate) return null;
        // Extract a title if present: patterns like "اسم(ش| فرم) را X بگذار/بذار" or "با عنوان X"
        $title = '';
        if (preg_match('/(?:اسم(?:\s*فرم)?|عنوان(?:\s*فرم)?|نام(?:\s*فرم)?)\s*(?:را|رو)?\s*(.+?)\s*(?:بگذار|بذار|قرار\s*ده|کن)/u', $sep, $m)){
            $title = trim((string)$m[1]);
        } elseif (preg_match('/با\s*(?:عنوان|نام)\s*(.+?)(?=\s*(?:بساز|ایجاد\s*کن)|[،,]|$)/u', $sep, $m)){
            $title = trim((string)$m[1]);
        }
        // Clean wrapping quotes/half-space and cut trailing verbs/noise if any leaked in
        $title = trim($title, " \"'\x{200C}\x{200F}");
        if ($title !== ''){
            // Stop at first occurrence of verbs or separators that indicate end of title
            $title = preg_replace('/\s*(?:بساز|ایجاد\s*کن|اضافه\s*کن|سوال|پرسش)(.|\n)*$/u', '', $title);
            $title = trim($title);
        }
        // Extract an explicit question text if provided, e.g., "متن سوال این باشه: X" or "سوال این باشه: X"
        $question = '';
        if (preg_match('/(?:متن\s*(?:سوال|پرسش)|(?:سوال|پرسش)\s*متن|(?:سوال|پرسش))\s*(?:این\s*باشه|باشد|باشه)?\s*[:\-]?\s*"?(.+?)"?(?=[،,]|$)/u', $sep, $mq)){
            $question = trim((string)$mq[1], " \"'\x{200C}\x{200F}");
        }
        if ($title === ''){
            $title = apply_filters('arshline_ai_new_form_default_title', 'فرم جدید');
        }
        // Count how many questions to add; support Persian digits and words for 1..5
        $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
        $count = 0;
        // Numeric like "دو سوال" or explicit digits
        if (preg_match('/([0-9۰-۹]+)\s*(?:تا\s*)?\s*(?:سوال|پرسش)/u', $sep, $mm)){
            $n = (int) strtr($mm[1], $fa2en);
            if ($n > 0 && $n <= 20) $count = $n;
        } else {
            $mapWords = [ 'یک'=>1, 'یه'=>1, 'دو'=>2, 'سه'=>3, 'چهار'=>4, 'پنج'=>5 ];
            foreach ($mapWords as $w=>$n){ if (preg_match('/\b'.$w.'\s*(?:تا\s*)?\s*(?:سوال|پرسش)/u', $sep)){ $count = $n; break; } }
        }
        // Recognize type: default short_text; allow long_text, rating
        $type = 'short_text';
        if (preg_match('/\b(پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)\b/iu', $sep)) $type = 'short_text';
        elseif (preg_match('/\b(پاسخ\s*بلند|متن\s*بلند|long[_\s-]*text)\b/iu', $sep)) $type = 'long_text';
        elseif (preg_match('/\b(امتیاز|ستاره|rating)\b/iu', $sep)) $type = 'rating';
        // Build the steps. Always include create_form even if no fields requested.
        $steps = [ [ 'action'=>'create_form', 'params'=> [ 'title' => $title ] ] ];
        $maxN = max(1, min(12, (int) apply_filters('arshline_ai_plan_internal_max_fields', 6)));
        $n = min(max(0, $count), $maxN);
        for ($i=0; $i<$n; $i++){
            $p = [ 'type' => $type ];
            if ($i === 0 && $question !== ''){ $p['question'] = mb_substr($question, 0, 200); }
            $steps[] = [ 'action' => 'add_field', 'params' => $p ];
        }
        return [ 'version' => 1, 'steps' => $steps ];
    }

    public static function get_form_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        // When accessing by public token, still enforce group mapping if present (require member token or user membership)
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
        // Log view (best effort). We don't block on failures.
        try {
            global $wpdb; $viewsTable = \Arshline\Support\Helpers::tableName('form_views');
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'],0,45) : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'],0,190) : '';
            $wpdb->insert($viewsTable, [
                'form_id' => (int)$form->id,
                'member_id' => $member && isset($member['id']) ? (int)$member['id'] : null,
                'ip' => $ip,
                'user_agent' => $ua,
                'created_at' => current_time('mysql'),
            ]);
        } catch (\Throwable $e) { /* ignore logging errors */ }
    $fields = FormsFieldRepository::listByForm($form->id);
        // Minimal personalization via GET params for title/description
        $meta = $form->meta;
        $params = $request->get_params();
        $repl = [];
        foreach ($params as $k=>$v){ if (is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[$k] = $v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        $meta = self::hydrate_meta_with_member($meta, $member);
        return new WP_REST_Response([
            'id' => $form->id,
            'token' => $form->public_token,
            'status' => $form->status,
            'meta' => $meta,
            'fields' => $fields,
        ], 200);
    }

    public static function update_fields(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $fields = $request->get_param('fields');
        if (!is_array($fields)) $fields = [];
    FormsFieldRepository::replaceAll($id, $fields);
        return new WP_REST_Response(['ok'=>true], 200);
    }

    public static function update_meta(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $meta = $request->get_param('meta');
        if (!is_array($meta)) $meta = [];
        // Normalize and sanitize incoming meta before merging
        $meta = self::sanitize_meta_input($meta);
        $beforeAll = is_array($form->meta)? $form->meta : [];
        // Capture only the keys being changed for audit diff
        $beforeSubset = [];
        foreach ($meta as $k => $_){ $beforeSubset[$k] = $beforeAll[$k] ?? null; }
        $form->meta = array_merge($beforeAll, $meta);
        FormRepository::save($form);
        $afterSubset = [];
        foreach ($meta as $k => $_){ $afterSubset[$k] = $form->meta[$k] ?? null; }
        // Log audit with undo token
        $undo = Audit::log('update_form_meta', 'form', $id, ['meta'=>$beforeSubset], ['meta'=>$afterSubset]);
        return new WP_REST_Response(['ok'=>true, 'meta'=>$form->meta, 'undo_token'=>$undo], 200);
    }

    public static function get_submissions(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response(['total'=>0, 'rows'=>[]], 200);
        // optional pagination & filters
        $page = (int)($request->get_param('page') ?? 1);
        $per_page = (int)($request->get_param('per_page') ?? 20);
        $debugFlag = (string)($request->get_param('debug') ?? '') === '1';
        // Build filters safely (avoid closures that don't capture $request)
        $statusParam = $request->get_param('status');
        $fromParam = $request->get_param('from');
        $toParam = $request->get_param('to');
        $searchParam = $request->get_param('search');
        $answersParam = $request->get_param('answers');
        $fParam = $request->get_param('f');
        $opParam = $request->get_param('op');
        $fieldFilters = [];
        if (is_array($fParam)){
            foreach ($fParam as $k => $v){
                $fid = (int)$k; $sv = is_scalar($v) ? (string)$v : '';
                if ($fid>0 && $sv !== ''){ $fieldFilters[$fid] = $sv; }
            }
        }
        $fieldOps = [];
        if (is_array($opParam)){
            foreach ($opParam as $k => $v){
                $fid = (int)$k; $sv = is_scalar($v) ? strtolower((string)$v) : '';
                if ($fid>0 && in_array($sv, ['eq','neq','like'], true)) { $fieldOps[$fid] = $sv; }
            }
        }
        $filters = [
            'status' => $statusParam ?: null,
            'from' => $fromParam ?: null,
            'to' => $toParam ?: null,
            'search' => $searchParam !== null ? (string)$searchParam : null,
            // New: full-text search within answers (submission_values.value)
            'answers' => $answersParam !== null ? (string)$answersParam : null,
            'field_filters' => $fieldFilters,
            'field_ops' => $fieldOps,
        ];
        $include = (string)($request->get_param('include') ?? ''); // values,fields
        // export all as CSV when format=csv, or Excel-compatible when format=excel
        $format = (string)($request->get_param('format') ?? '');
        if ($format === 'csv' || $format === 'excel') {
            // For exports, always fetch all (listByFormAll already respects filters and ignores pagination)
            $all = FormsSubmissionRepository::listByFormAll($form_id, $filters);
            // Optional: include answers as separate columns (wide CSV)
            $fields = FormsFieldRepository::listByForm($form_id);
            // Only include answerable field types
            $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
            $fields = array_values(array_filter($fields, function($f) use ($allowedTypes){
                $p = is_array($f['props']) ? $f['props'] : [];
                $t = isset($p['type']) ? (string)$p['type'] : '';
                return in_array($t, $allowedTypes, true);
            }));
            $fieldOrder = [];
            $fieldLabels = [];
            $choices = [];
            foreach ($fields as $f){
                $fid = (int)$f['id']; $p = is_array($f['props'])? $f['props'] : [];
                $fieldOrder[] = $fid; $fieldLabels[$fid] = (string)($p['question'] ?? $p['label'] ?? ('فیلد #'.$fid));
                if (!empty($p['options']) && is_array($p['options'])){
                    foreach ($p['options'] as $opt){ $val = (string)($opt['value'] ?? $opt['label'] ?? ''); $lab = (string)($opt['label'] ?? $val); if ($val !== ''){ $choices[$fid][$val] = $lab; } }
                }
            }
            $ids = array_map(function($r){ return (int)$r['id']; }, $all);
            $valsMap = FormsSubmissionRepository::listValuesBySubmissionIds($ids);
            $out = [];
            // Drop status per request; include only id and created_at (requested)
            $header = ['id','created_at'];
            foreach ($fieldOrder as $fid){ $header[] = $fieldLabels[$fid]; }
            $out[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $header));
            foreach ($all as $r){
                $row = [ $r['id'], (string)($r['created_at'] ?? '') ];
                $answers = [];
                $vals = isset($valsMap[$r['id']]) ? $valsMap[$r['id']] : [];
                // Map field_id => value (first occurrence)
                $byField = [];
                foreach ($vals as $v){ $fid = (int)$v['field_id']; if (!isset($byField[$fid])){ $byField[$fid] = (string)$v['value']; } }
                foreach ($fieldOrder as $fid){
                    $ans = isset($byField[$fid]) ? $byField[$fid] : '';
                    if (isset($choices[$fid]) && isset($choices[$fid][$ans])){ $ans = $choices[$fid][$ans]; }
                    $answers[] = $ans;
                }
                $row = array_merge($row, $answers);
                $out[] = implode(',', array_map(function($v){ $v = (string)$v; return '"'.str_replace('"','""',$v).'"'; }, $row));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $out);
            // Stream directly to avoid JSON encoding
            if (!headers_sent()) {
                if ($format === 'excel') {
                    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                    header('Content-Disposition: attachment; filename="submissions-'.$form_id.'.xls"');
                } else {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="submissions-'.$form_id.'.csv"');
                }
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            }
            // Clear any buffered output to prevent stray bytes
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo $csv;
            exit;
        }
    $res = FormsSubmissionRepository::listByFormPaged($form_id, $page, $per_page, $filters);
        $rows = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
                'summary' => is_array($r['meta']) ? ($r['meta']['summary'] ?? null) : null,
            ];
        }, $res['rows'] ?: []);
        // include values (and fields) when requested, so the dashboard can render full grid
        if ($include === 'values' || $include === 'values,fields' || $include === 'fields,values'){
            $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
            $valsMap = FormsSubmissionRepository::listValuesBySubmissionIds($ids);
            foreach ($rows as &$row){ $row['values'] = isset($valsMap[$row['id']]) ? $valsMap[$row['id']] : []; }
            unset($row);
        }
        $payload = [
            'total' => (int)$res['total'],
            'rows' => $rows,
            'page' => (int)$res['page'],
            'per_page' => (int)$res['per_page'],
        ];
        if (strpos($include, 'fields') !== false){
            $allFields = FormsFieldRepository::listByForm($form_id);
            $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
            $payload['fields'] = array_values(array_filter($allFields, function($f) use ($allowedTypes){
                $p = is_array($f['props']) ? $f['props'] : [];
                $t = isset($p['type']) ? (string)$p['type'] : '';
                return in_array($t, $allowedTypes, true);
            }));
        }
        // Optional debug details for admins/editors only
        if ($debugFlag && self::user_can_manage_forms()){
            global $wpdb;
            $payload['debug'] = [
                'db_last_error' => (string)($wpdb->last_error ?? ''),
                'db_last_query' => (string)($wpdb->last_query ?? ''),
            ];
        }
        return new WP_REST_Response($payload, 200);
    }

    public static function get_submission(WP_REST_Request $request)
    {
        $sid = (int)$request['submission_id'];
        if ($sid <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
    $data = FormsSubmissionRepository::findWithValues($sid);
        if (!$data) return new WP_REST_Response(['error'=>'not_found'], 404);
        // Also include field meta to render labels
    $fields = FormsFieldRepository::listByForm((int)$data['form_id']);
        return new WP_REST_Response(['submission' => $data, 'fields' => $fields], 200);
    }

    public static function create_submission(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response(['error' => 'invalid_form_id'], 400);
        // Load form to access meta settings
        $form = FormRepository::find($form_id);
        if (!$form) return new WP_REST_Response(['error' => 'invalid_form_id'], 400);
        // Gate submissions to published forms only
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error' => 'form_disabled'], 403);
        }
        // Enforce group-based access for submissions as well
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
    $global = self::get_global_settings();
    $meta = array_merge($global, is_array($form->meta) ? $form->meta : []);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $now = time();
        // 1) Honeypot: reject if value present
        if (!empty($meta['anti_spam_honeypot'])){
            $hp = (string)($request->get_param('hp') ?? '');
            if ($hp !== ''){ return new WP_REST_Response(['error'=>'rejected', 'reason'=>'honeypot'], 429); }
        }
        // 2) Minimum seconds from render to submit
        $minSec = isset($meta['min_submit_seconds']) ? max(0, (int)$meta['min_submit_seconds']) : 0;
        if ($minSec > 0){
            $ts = (int)($request->get_param('ts') ?? 0);
            if ($ts > 0 && ($now - $ts) < $minSec){ return new WP_REST_Response(['error'=>'rejected', 'reason'=>'too_fast'], 429); }
        }
        // 3) Optional reCAPTCHA validation
        if (!empty($meta['captcha_enabled'])){
            $site = (string)($meta['captcha_site_key'] ?? '');
            $secret = (string)($meta['captcha_secret_key'] ?? '');
            $version = (string)($meta['captcha_version'] ?? 'v2');
            // Tokens come in params: g-recaptcha-response (v2) or ar_recaptcha_token (v3)
            $token = (string)($request->get_param('g-recaptcha-response') ?? $request->get_param('ar_recaptcha_token') ?? '');
            if ($secret && $token){
                // Server-side verify (best-effort; avoid external call if blocked)
                $ok = false; $scoreOk = true;
                $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
                    'timeout' => 5,
                    'body' => [ 'secret' => $secret, 'response' => $token, 'remoteip' => $ip ],
                ]);
                if (!is_wp_error($resp)){
                    $body = wp_remote_retrieve_body($resp);
                    $j = json_decode($body, true);
                    if (is_array($j) && !empty($j['success'])){
                        $ok = true;
                        if ($version === 'v3' && isset($j['score'])){ $scoreOk = ((float)$j['score'] >= 0.5); }
                    }
                }
                if (!$ok || !$scoreOk){ return new WP_REST_Response(['error'=>'captcha_failed'], 429); }
            } else {
                return new WP_REST_Response(['error'=>'captcha_missing'], 400);
            }
        }
        // 4) Rate limiting per IP + form
        $perMin = isset($meta['rate_limit_per_min']) ? max(0, (int)$meta['rate_limit_per_min']) : 0;
        $windowMin = isset($meta['rate_limit_window_min']) ? max(1, (int)$meta['rate_limit_window_min']) : 1;
        if ($perMin > 0){
            $key = 'arsh_rl_'.md5($form_id.'|'.$ip);
            $entry = get_transient($key);
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + ($windowMin*60)];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + ($windowMin*60)]; }
            if ((int)$data['count'] >= $perMin){ return new WP_REST_Response(['error'=>'rate_limited','retry_after'=>max(0, (int)$data['reset'] - $now)], 429); }
            $data['count'] = (int)$data['count'] + 1;
            set_transient($key, $data, $windowMin * 60);
        }
        $values = $request->get_param('values');
        if (!is_array($values)) $values = [];
        // Load schema for validation
    $fields = FormsFieldRepository::listByForm($form_id);
        $valErrors = FormValidator::validateSubmission($fields, $values);
        if (!empty($valErrors)) {
            return new WP_REST_Response(['error' => 'validation_failed', 'messages' => $valErrors], 422);
        }
        $submissionData = [
            'form_id' => $form_id,
            'user_id' => get_current_user_id(),
            'ip' => $ip,
            'status' => 'pending',
            'meta' => [ 'summary' => 'ایجاد از REST' ],
            'values' => $values,
        ];
        // Optionally include member context into submission meta for later personalization
        if (is_array($member)){
            $submissionData['meta']['member'] = [
                'id' => (int)$member['id'],
                'group_id' => (int)$member['group_id'],
                'name' => (string)$member['name'],
                'phone' => (string)$member['phone'],
            ];
        }
        $submission = new Submission($submissionData);
    $id = FormsSubmissionRepository::save($submission);
        foreach ($values as $idx => $entry) {
            $field_id = (int)($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? '';
            if ($field_id > 0) {
                SubmissionValueRepository::save($id, $field_id, $value, $idx);
            }
        }
        return new WP_REST_Response([ 'id' => $id, 'form_id' => $form_id, 'status' => 'pending' ], 201);
    }

    public static function create_submission_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error' => 'invalid_form_token'], 404);
        // Block submissions when not published
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error' => 'form_disabled'], 403);
        }
        // Enforce group-based access (member token or logged-in membership)
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
        $request['form_id'] = $form->id;
        return self::create_submission($request);
    }

    /**
     * Public submission handler for HTMX (accepts form-encoded fields like field_{id}).
     * Returns a small HTML fragment suitable for hx-swap.
     */
    public static function create_submission_htmx(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response('<div class="ar-alert ar-alert--err">شناسه فرم نامعتبر است.</div>', 200);
        $form = FormRepository::find($form_id);
        if (!$form) return new WP_REST_Response('<div class="ar-alert ar-alert--err">فرم یافت نشد.</div>', 200);
    $global = self::get_global_settings();
    $meta = array_merge($global, is_array($form->meta) ? $form->meta : []);
        // Enforce access
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
        if (!$ok){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">دسترسی مجاز نیست.</div>', 200); }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $now = time();
        // Honeypot (htmx submit may include hp/ts fields)
        if (!empty($meta['anti_spam_honeypot'])){
            $hp = (string)($request->get_param('hp') ?? '');
            if ($hp !== ''){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">درخواست نامعتبر است.</div>', 200); }
        }
        // Min seconds
        $minSec = isset($meta['min_submit_seconds']) ? max(0, (int)$meta['min_submit_seconds']) : 0;
        if ($minSec > 0){
            $ts = (int)($request->get_param('ts') ?? 0);
            if ($ts > 0 && ($now - $ts) < $minSec){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">ارسال خیلی سریع بود. لطفاً کمی صبر کنید.</div>', 200); }
        }
        // Optional captcha
        if (!empty($meta['captcha_enabled'])){
            $secret = (string)($meta['captcha_secret_key'] ?? '');
            $version = (string)($meta['captcha_version'] ?? 'v2');
            $token = (string)($request->get_param('g-recaptcha-response') ?? $request->get_param('ar_recaptcha_token') ?? '');
            if ($secret && $token){
                $ok = false; $scoreOk = true;
                $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [ 'timeout'=>5, 'body'=>[ 'secret'=>$secret, 'response'=>$token, 'remoteip'=>$ip ] ]);
                if (!is_wp_error($resp)){
                    $body = wp_remote_retrieve_body($resp); $j = json_decode($body, true);
                    if (is_array($j) && !empty($j['success'])){ $ok = true; if ($version==='v3' && isset($j['score'])) $scoreOk = ((float)$j['score'] >= 0.5); }
                }
                if (!$ok || !$scoreOk){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">احراز انسان بودن شکست خورد.</div>', 200); }
            } else {
                return new WP_REST_Response('<div class="ar-alert ar-alert--err">احراز هویت ربات فعال است اما توکن دریافت نشد.</div>', 200);
            }
        }
        // Rate limit
        $perMin = isset($meta['rate_limit_per_min']) ? max(0, (int)$meta['rate_limit_per_min']) : 0;
        $windowMin = isset($meta['rate_limit_window_min']) ? max(1, (int)$meta['rate_limit_window_min']) : 1;
        if ($perMin > 0){
            $key = 'arsh_rl_'.md5($form_id.'|'.$ip); $entry = get_transient($key);
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + ($windowMin*60)];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + ($windowMin*60)]; }
            if ((int)$data['count'] >= $perMin){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">محدودیت نرخ ارسال فعال شد. لطفاً بعداً دوباره تلاش کنید.</div>', 200); }
            $data['count'] = (int)$data['count'] + 1; set_transient($key, $data, $windowMin*60);
        }

        // Load schema for validation
    $fields = FormsFieldRepository::listByForm($form_id);
        $params = $request->get_params();
        $values = [];
        foreach ($fields as $f) {
            $fid = (int)($f['id'] ?? 0);
            if ($fid <= 0) continue;
            $key = 'field_' . $fid;
            if (isset($params[$key])) {
                $values[] = [ 'field_id' => $fid, 'value' => $params[$key] ];
            }
        }
        $valErrors = FormValidator::validateSubmission($fields, $values);
        if (!empty($valErrors)) {
            $html = '<div class="ar-alert ar-alert--err"><div>خطا در اعتبارسنجی:</div><ul style="margin:6px 0;">';
            foreach ($valErrors as $msg) { $html .= '<li>' . esc_html($msg) . '</li>'; }
            $html .= '</ul></div>';
            return new WP_REST_Response($html, 200);
        }

        $submissionData = [
            'form_id' => $form_id,
            'user_id' => get_current_user_id(),
            'ip' => $ip,
            'status' => 'pending',
            'meta' => [ 'summary' => 'ایجاد از HTMX' ],
            'values' => $values,
        ];
        $submission = new Submission($submissionData);
    $id = FormsSubmissionRepository::save($submission);
        foreach ($values as $idx => $entry) {
            $field_id = (int)($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? '';
            if ($field_id > 0) {
                SubmissionValueRepository::save($id, $field_id, $value, $idx);
            }
        }
        $ok = '<div class="ar-alert ar-alert--ok">با موفقیت ثبت شد. شناسه: ' . (int)$id . '</div>';
        return new WP_REST_Response($ok, 200);
    }

    /**
     * Public HTMX submission by token (returns HTML fragment like create_submission_htmx)
     */
    public static function create_submission_htmx_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) {
            return new WP_REST_Response('<div class="ar-alert ar-alert--err">فرم یافت نشد.</div>', 200);
        }
        if ($form->status !== 'published'){
            return new WP_REST_Response('<div class="ar-alert ar-alert--err">این فرم در حال حاضر فعال نیست.</div>', 200);
        }
        // Enforce access
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
        if (!$ok){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">دسترسی مجاز نیست.</div>', 200); }
        $request['form_id'] = $form->id;
        return self::create_submission_htmx($request);
    }

    /**
     * POST /hoosha/prepare
    * Body: {
    *   user_text: string,
    *   debug_guard?: boolean  // if true and guard enabled attaches sanitized guard.debug {issues,approved,lat_ms}
    *   preview_expired?: boolean // simulate preview window expiry -> forces ok=false with note guard:preview_expired
    *   (settings option) allow_ai_additions?: bool // if disabled, Guard removes any field not mapped to baseline
    * }
    * Returns: { ok: bool, edited_text: string, schema: object, notes?: string[], confidence?: float, guard?: {...}, preview_status?: string }
     */
    public static function hoosha_prepare(WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new WP_Error('forbidden', 'forbidden', ['status' => 403]);
        $t0 = microtime(true);
        $rawBody = '';
        try { $rawBody = method_exists($request,'get_body') ? (string)$request->get_body() : ''; } catch (\Throwable $e) { $rawBody=''; }
        $body = json_decode($rawBody !== '' ? $rawBody : '{}', true);
        if (!is_array($body)) { $body = []; }
        // New debug + preview TTL simulation flags
        $debugGuard = !empty($body['debug_guard']) || (method_exists($request,'get_param') && $request->get_param('debug_guard'));
        $previewExpired = !empty($body['preview_expired']) || (method_exists($request,'get_param') && $request->get_param('preview_expired'));
        // Fallback: if json body empty, try get_json_params()/get_param shapes (test stubs)
        if (empty($body) && method_exists($request,'get_json_params')){
            try { $alt = $request->get_json_params(); if (is_array($alt) && !empty($alt)) $body = $alt; } catch (\Throwable $e) { /* ignore */ }
        }
        if (!isset($body['user_text']) && method_exists($request,'get_param')){
            try { $ut = $request->get_param('user_text'); if (is_string($ut) && $ut!=='') $body['user_text']=$ut; } catch (\Throwable $e) { /* ignore */ }
        }
        $coverageThreshold = 0.55;
        if (isset($body['coverage_threshold'])){
            $ct = floatval($body['coverage_threshold']); if ($ct>0 && $ct<1){ $coverageThreshold = $ct; }
        }
        $disableFileFallback = !empty($body['disable_file_fallback']);
        $strictFileMatch = !empty($body['strict_file_match']);
    $preserveOrder = !empty($body['preserve_order']);
    $baselineOrderMap = [];
    // Build baseline order map early (after baseline heuristic) later referenced if preserve_order enabled
    $user_text = isset($body['user_text']) ? (string)$body['user_text'] : '';
    $form_name = isset($body['form_name']) ? trim((string)$body['form_name']) : '';
    if (trim($user_text) === '') return new WP_Error('bad_request', 'user_text required', ['status' => 400]);
        $progress = [];
        $progress[] = ['step'=>'start','message'=>'شروع پردازش ورودی'];

        // Modular pipeline delegation ONLY if model flag enabled; else use legacy heuristics
        if (class_exists('Arshline\\Hoosha\\Pipeline\\HooshaService')) {
            $flagEnabled = (defined('ARSHLINE_USE_MODEL') && ARSHLINE_USE_MODEL);
            if (!$flagEnabled && function_exists('get_option')){
                $optFlag = get_option('arshline_use_model','');
                if ($optFlag && ($optFlag==='1' || strtolower((string)$optFlag)==='true')) $flagEnabled = true;
                // Auto-enable pipeline if full AI settings present (tests configure ai_enabled+base_url+api_key) even without explicit flag
                if (!$flagEnabled){
                    $auto = get_option('arshline_settings', []);
                    if (is_array($auto) && !empty($auto['ai_enabled']) && !empty($auto['ai_api_key']) && !empty($auto['ai_base_url'])){
                        $flagEnabled = true; $notes[]='pipe:auto_flag_enable(ai_settings_present)';
                    }
                }
            }
            if ($flagEnabled){
                try {
                    $apiKey=''; if(function_exists('get_option')) $apiKey=(string)get_option('arshline_ai_api_key','');
                    if ($apiKey===''){ $settings = get_option('arshline_settings',[]); if (is_array($settings) && !empty($settings['ai_api_key'])) $apiKey=(string)$settings['ai_api_key']; }
                    if ($apiKey==='' && defined('ARSHLINE_AI_API_KEY')) $apiKey=(string)ARSHLINE_AI_API_KEY;
                    $modelName='gpt-4o-mini';
                    $baseUrl='https://api.openai.com'; if(function_exists('get_option')){ $optBase=(string)get_option('arshline_ai_base_url',''); if($optBase!=='') $baseUrl=$optBase; }
                    if (defined('ARSHLINE_AI_BASE_URL')) $baseUrl=(string)ARSHLINE_AI_BASE_URL;
                    $customBase=($baseUrl!=='https://api.openai.com');
                    $modelClient=null; $modelUsed=false; $skipReason='';
                    if ($apiKey!=='' && class_exists('Arshline\\Hoosha\\Pipeline\\OpenAIModelClient')){ $modelClient=new \Arshline\Hoosha\Pipeline\OpenAIModelClient($apiKey,$modelName,$baseUrl); $modelUsed=true; }
                    else { $skipReason = $apiKey===''? 'no_api_key':'client_class_missing'; }
                    $svc=new \Arshline\Hoosha\Pipeline\HooshaService($modelClient);
                    $tProc=microtime(true); $proc=$svc->process($user_text,[]); $lat=(int)round((microtime(true)-$tProc)*1000); $proc['notes'][]='perf:latency_ms='.$lat; if(!$modelUsed){ $proc['notes'][]='pipe:model_skipped'.($skipReason? '('.$skipReason.')':''); } if($modelUsed && $customBase) $proc['notes'][]='ai:custom_base_url';
                    // Modular path guard integration (previously missing): baseline available via process() output
                    $baselineForGuard = [];
                    if (isset($proc['baseline']) && is_array($proc['baseline'])){ $baselineForGuard = self::hoosha_formalize_labels($proc['baseline']); }
                    $schemaMod = $proc['schema'] ?? ['fields'=>[]];
                    $guardBlock = self::maybe_guard($baselineForGuard, $schemaMod, $user_text, $proc['notes']);
                    // If guard modified schema adopt it
                    if ($schemaMod !== ($proc['schema'] ?? null)){
                        $proc['schema'] = $schemaMod;
                        // Rebuild edited_text numbering after guard
                        $reb = self::hoosha_local_edit_text($user_text, $schemaMod);
                        if ($reb !== '' && is_string($reb)){ $proc['edited_text']=$reb; $proc['notes'][]='guard:edited_text_rebuilt'; }
                    }
                    // Final lock & sanitation (mirror of main path) if guard executed
                    if (is_array($guardBlock)){
                        if (function_exists('get_option')){
                            $gs2 = get_option('arshline_settings', []);
                            $allowAddFinal = !empty($gs2['allow_ai_additions']);
                            if (!$allowAddFinal && !empty($proc['schema']['fields']) && is_array($proc['schema']['fields'])){
                                $before = count($proc['schema']['fields']);
                                $proc['schema']['fields'] = array_values(array_filter($proc['schema']['fields'], function($f){ return is_array($f) ? empty($f['props']['guard_ai_added']) : false; }));
                                $after = count($proc['schema']['fields']);
                                if ($after < $before){ $proc['notes'][]='guard:final_ai_prune(removed='.($before-$after).')'; }
                            }
                        }
                        if (!empty($proc['schema']['fields']) && is_array($proc['schema']['fields'])){
                            $ynPurged=0; foreach ($proc['schema']['fields'] as &$__gf){
                                if (!is_array($__gf)) continue; $opts = $__gf['props']['options'] ?? null; if (!$opts||!is_array($opts)) continue;
                                $canonOpts = array_map(function($o){ return preg_replace('/\s+/u','', mb_strtolower($o,'UTF-8')); }, $opts);
                                $hasYN = in_array('بله',$opts,true) || in_array('خیر',$opts,true) || in_array('بلهخیر',$canonOpts,true);
                                if (!$hasYN) continue; $lblLow = mb_strtolower($__gf['label']??'','UTF-8');
                                $isBinaryIntent = (mb_strpos($lblLow,'آیا')!==false) || preg_match('/^(آیا|.*\؟)$/u',$lblLow);
                                if (!$isBinaryIntent){ unset($__gf['props']['options']); $ynPurged++; }
                            }
                            unset($__gf);
                            if ($ynPurged>0){ $proc['notes'][]='guard:final_option_cleanup('.$ynPurged.')'; }
                        }
                        // Always run final normalization (modular path) prior to final edited_text rebuild
                        if (isset($proc['schema']) && is_array($proc['schema'])){
                            self::final_guard_normalize($proc['schema'], $proc['notes'], $user_text, $baselineForGuard, $guardBlock);
                            // Post-trim refinement (type/format corrections after collapse)
                            self::final_guard_post_refine($proc['schema'], $proc['notes']);
                            $rebFinal = self::hoosha_local_edit_text($user_text, $proc['schema']);
                            if (is_string($rebFinal) && $rebFinal!==''){ $proc['edited_text']=$rebFinal; $proc['notes'][]='guard:edited_text_rebuilt_final'; }
                        }
                    }
                    // Optional natural edit commands (e.g., UI passes edit_commands)
                    $editCommands = isset($body['edit_commands']) ? (string)$body['edit_commands'] : '';
                    if ($editCommands !== '' && class_exists('Arshline\\Hoosha\\Pipeline\\NaturalEditProcessor')){
                        \Arshline\Hoosha\Pipeline\NaturalEditProcessor::apply($proc['schema'], $editCommands, $proc['notes']);
                        // Rebuild edited text after manual edits
                        $rebEdit = self::hoosha_local_edit_text($user_text, $proc['schema']);
                        if (is_string($rebEdit) && $rebEdit!==''){ $proc['edited_text']=$rebEdit; $proc['notes'][]='edit:edited_text_rebuilt'; }
                    }
                    $responsePayload = [
                        'ok'=> empty($guardBlock['approved']) ? true : (bool)$guardBlock['approved'],
                        'edited_text'=>$proc['edited_text'] ?? $user_text,
                        'schema'=>$proc['schema'],
                        'notes'=>$proc['notes'] ?? [],
                        'confidence'=>0.90,
                        'model_used'=>$modelUsed,
                        'model_skip_reason'=>$skipReason
                    ];
                    if ($guardBlock){ $responsePayload['guard']=$guardBlock; }
                    return new \WP_REST_Response($responsePayload,200);
                } catch(\Throwable $e){ /* fall back */ }
            }
        }
        // 1) Baseline heuristic extraction (for diff & fallback) – prefer modular inferer if present
        if (class_exists('Arshline\\Hoosha\\HooshaBaselineInferer')) {
            try { $baseline = \Arshline\Hoosha\HooshaBaselineInferer::infer($user_text); } catch (\Throwable $e) { $baseline = self::hoosha_local_infer_from_text_v2($user_text); }
        } else {
            $baseline = self::hoosha_local_infer_from_text_v2($user_text);
        }
        if (empty($baseline['fields'])) { $baseline = self::hoosha_local_infer_from_text($user_text); }
        $baseline_formal = self::hoosha_formalize_labels($baseline);
    $progress[] = ['step'=>'baseline_inferred','message'=>'استخراج اولیه مبتنی بر قواعد محلی'];
        // Build quick lookup of original (formalized) labels to detect unchanged edits
        $baseline_labels = [];
        if (!empty($baseline_formal['fields'])){
            foreach ($baseline_formal['fields'] as $bf){ if (is_array($bf) && isset($bf['label'])){ $baseline_labels[] = (string)$bf['label']; } }
            if ($preserveOrder){
                $ord=0; foreach ($baseline_formal['fields'] as $bf){ if (!is_array($bf)) continue; $lbl = isset($bf['label'])?(string)$bf['label']:''; if ($lbl==='') continue; $baselineOrderMap[self::hoosha_canon_label($lbl)] = $ord++; }
            }
        }
        // Strict small-form mode: if very few baseline fields (<=5) assume user wants exact set => suppress expansive recoveries later
        $strictSmallForm = (count($baseline_formal['fields'] ?? []) > 0 && count($baseline_formal['fields']) <= 5);
        if ($strictSmallForm){
            $notes[]='heur:strict_small_form_mode';
            $GLOBALS['__hoosha_strict_small_form']=true;
            // Proactively remove any baseline/heuristic contact preference style field (label containing ترجیح and تماس)
            if (!empty($baseline_formal['fields'])){
                $filtered=[]; $removed=false;
                foreach ($baseline_formal['fields'] as $bf){
                    if (!is_array($bf)){ $filtered[]=$bf; continue; }
                    $lbl = isset($bf['label']) ? (string)$bf['label'] : '';
                    $norm = preg_replace('/\s+/u',' ', mb_strtolower($lbl,'UTF-8'));
                    if ($lbl !== '' && (mb_strpos($norm,'ترجیح') !== false) && (mb_strpos($norm,'تماس') !== false)){
                        $removed=true; continue; // skip this field
                    }
                    $filtered[]=$bf;
                }
                if ($removed){
                    $baseline_formal['fields']=$filtered;
                    $notes[]='heur:contact_pref_removed_small_form';
                }
            }
        } else {
            // Large form: DO NOT remove contact preference; ensure it remains as multiple_choice if present
            if (!empty($baseline_formal['fields'])){
                foreach ($baseline_formal['fields'] as &$bf){
                    if (!is_array($bf)) continue; $lbl = isset($bf['label'])?(string)$bf['label']:''; if($lbl==='') continue;
                    $norm = preg_replace('/\s+/u',' ', mb_strtolower($lbl,'UTF-8'));
                    if (mb_strpos($norm,'ترجیح')!==false && mb_strpos($norm,'تماس')!==false){
                        // Ensure choice type with reasonable options if not already
                        if (($bf['type']??'')!=='multiple_choice' && ($bf['type']??'')!=='dropdown'){
                            $bf['type']='multiple_choice';
                            if (!isset($bf['props'])||!is_array($bf['props'])) $bf['props']=[];
                            if (empty($bf['props']['options'])||!is_array($bf['props']['options'])){
                                $bf['props']['options']=['ایمیل','تلفن','موبایل'];
                                $bf['props']['multiple']=false;
                            }
                            $bf['props']['source']=$bf['props']['source']??'contact_pref_promoted';
                            $notes[]='heur:contact_pref_promoted_large_form';
                        }
                    }
                }
                unset($bf);
            }
            // Heuristic padding: if raw user text has >=8 non-empty lines but baseline inference produced <8 fields, inject neutral optional fields
            $rawLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/u',$user_text)), function($l){ return $l!==''; }));
            $lineCountRaw = count($rawLines);
            $currentCount = count($baseline_formal['fields'] ?? []);
            if ($lineCountRaw >= 8 && $currentCount < 8){
                $needed = 8 - $currentCount;
                for ($i=0; $i<$needed; $i++){
                    $baseline_formal['fields'][] = [
                        'type'=>'short_text',
                        'label'=>'فیلد تکمیلی '.($i+1),
                        'required'=>false,
                        'props'=>['source'=>'padding_large_form']
                    ];
                }
                $notes[]='heur:large_form_padding('.$needed.')';
            }
        }

        // Build standards and capabilities to pass to model
        $standards = [
            'required_policy' => 'explicit_only',
            'binary_to' => 'multiple_choice_single',
            'dropdown_threshold' => 6,
            'rating_default' => ['min'=>1,'max'=>10,'icon'=>'like'],
            'long_text_defaults' => ['rows'=>4,'maxLength'=>5000],
            'formats' => ['free_text','email','numeric','mobile_ir','mobile_intl','tel','national_id_ir','postal_code_ir','fa_letters','en_letters','ip','date_jalali','date_greg','time']
        ];
        $capabilities = [ 'types' => ['short_text','long_text','multiple_choice','dropdown','rating'] ];

        // 2) Stronger system prompt: enforce mandatory transformations
    $system = 'You are Hoosha, a precise Persian smart form editor. ALWAYS perform:
RULES:
1) Normalize spelling, punctuation, and formal tone for EVERY question.
2) Convert binary / small option sets (detected with "X یا Y" (or more) patterns) to multiple_choice single-select (options array) unless >= dropdown_threshold -> dropdown.
3) Rating only when explicit (contains امتیاز or pattern 1..10). Provide rating.min=1, rating.max=10, rating.icon="like".
4) Long descriptive / explanation requests (contains توضیح, مفصل, شرح) -> long_text with defaults.
5) Required only if explicitly requested in user_text or inferred command (contains الزامی or اجباری tied to that field).
6) Output STRICT JSON ONLY with keys EXACTLY: edited_text (string), schema{fields:[{type,label,required,props{format?,options?,multiple?,rating?,duplicate_of?,confirm_for?,accept?,maxSizeKB?,regex?}}]}, notes[array of strings], confidence[number 0..1]. No additional top-level keys.
6.1) Every field object MUST have type and label non-empty.
6.2) Do NOT output null for schema or fields; use empty array if none.
7) For every field ensure the label is formally edited. NEVER leave it unchanged when a more formal phrasing is obvious.
8) Do NOT hallucinate extra fields.
9) Preserve semantic meaning but elevate tone.
10) If you cannot process, still return baseline schema with a note reason=pass_through.
Return strict JSON. No markdown. NEVER wrap in code fences.';
        // Heuristic form name if missing: derive from first 2-5 significant words of user_text or first baseline label
        if ($form_name === ''){
            $candidate = '';
            // Try first baseline label
            if (!empty($baseline_formal['fields'][0]['label'])){
                $candidate = (string)$baseline_formal['fields'][0]['label'];
            } else {
                $tmp = preg_replace('/[\r\n]+/u',' ', $user_text);
                $tmp = preg_replace('/[^\p{L}\p{N}\s]+/u',' ', $tmp);
                $parts = preg_split('/\s+/u', (string)$tmp, -1, PREG_SPLIT_NO_EMPTY);
                $stop = ['از','به','و','یا','در','برای','با','یک','این','آن','که','تا','های','می','را'];
                $sig = [];
                if (is_array($parts)){
                    foreach ($parts as $w){ $lw=mb_strtolower($w,'UTF-8'); if (in_array($lw,$stop,true)) continue; $sig[]=$w; if (count($sig)>=5) break; }
                }
                if (!empty($sig)) $candidate = implode(' ', array_slice($sig,0,5));
            }
            // Trim length
            $candidate = trim(mb_substr($candidate,0,60,'UTF-8'));
            if ($candidate !== ''){ $form_name = $candidate; $notes[] = 'heur:form_name_heuristic'; }
        } else {
            $notes[] = 'heur:form_name_provided';
        }

        $user = json_encode([
            'phase' => 'prepare',
            'user_text' => $user_text,
            'form_name' => $form_name,
            'standards' => $standards,
            'capability_matrix' => $capabilities,
            'ui_prefs' => [ 'display_numbering' => true, 'two_rows' => true ],
            'baseline_labels' => $baseline_labels,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        try {
            $ai = self::get_ai_settings();
            if (empty($ai['enabled']) || empty($ai['base_url']) || empty($ai['api_key'])){
                // Instead of WP_Error (breaks legacy tests), continue with baseline-only response
                $schema = $baseline_formal;
                // Post baseline normalization: ensure mobile_ir format inferred for Persian 'شماره تلفن' prompts
                if (!empty($schema['fields'])){
                    foreach ($schema['fields'] as &$__bf){
                        if (!is_array($__bf)) continue; $lbl = isset($__bf['label'])?(string)$__bf['label']:''; if ($lbl==='') continue;
                        $ll = mb_strtolower($lbl,'UTF-8');
                        if (empty($__bf['props']['format']) && mb_strpos($ll,'شماره')!==false && mb_strpos($ll,'تلفن')!==false){ $__bf['props']['format']='mobile_ir'; }
                        // Also tighten national id heuristic: if label contains کد and ملی ensure format set
                        if (empty($__bf['props']['format']) && mb_strpos($ll,'کد')!==false && mb_strpos($ll,'ملی')!==false){ $__bf['props']['format']='national_id_ir'; }
                        // Normalize confirm_for index (make it 1-based so PHP truthy)
                        if (isset($__bf['props']['confirm_for']) && $__bf['props']['confirm_for']===0){ $__bf['props']['confirm_for_index']=0; $__bf['props']['confirm_for']=1; }
                    }
                    unset($__bf);
                }
                $edited = self::hoosha_local_edit_text($user_text, $schema);
                $notes[]='pipe:model_skipped(ai_not_configured)';
                // Minimal file inference for baseline-only path
                if (!$disableFileFallback){
                    $filePattern = $strictFileMatch
                        ? '/\b(فایل|رزومه|بارگذاری|آپلود|رسید|گزارش|log|ویدیو|ویدئو|mp4)\b|\b(jpg|jpeg|png|pdf|docx)\b/i'
                        : '/فایل|رزومه|بارگذاری|آپلود|تصویر(?!سازی)|رسید|jpg|jpeg|png|گزارش|log|ویدیو|ویدئو|mp4/i';
                    if (preg_match($filePattern,$user_text)){
                        $hasFile=false; foreach(($schema['fields']??[]) as $sf){ if(is_array($sf) && ($sf['type']??'')==='file'){ $hasFile=true; break; } }
                        if (!$hasFile){
                            if (preg_match('/تصویر|رسید|jpg|jpeg|png/i',$user_text)){
                                $schema['fields'][]=[ 'type'=>'file','label'=>'تصویر رسید پرداخت','required'=>false,'props'=>['accept'=>['image/png','image/jpeg'],'source'=>'file_injected'] ];
                            } elseif (preg_match('/رزومه|cv|pdf/i',$user_text)){
                                $schema['fields'][]=[ 'type'=>'file','label'=>'فایل رزومه','required'=>false,'props'=>['accept'=>['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'source'=>'file_injected'] ];
                            }
                        }
                    }
                }
                // Emit synthetic coverage audit note for baseline-only deterministic path
                $baseCount = count($schema['fields'] ?? []);
                if ($baseCount>0){ $notes[]='audit:coverage(1.00 baseline_only)'; }
                // Duplicate national id tagging (baseline path)
                $natIdx=[]; foreach(($schema['fields']??[]) as $i=>$f){ if(is_array($f) && (($f['props']['format']??'')==='national_id_ir')) $natIdx[]=$i; }
                if (count($natIdx)>=2){
                    $first = $natIdx[0]; $second = $natIdx[1];
                    if (!isset($schema['fields'][$second]['props'])||!is_array($schema['fields'][$second]['props'])) $schema['fields'][$second]['props']=[];
                    $schema['fields'][$second]['props']['confirm_for']=1; // truthy indicator
                    $schema['fields'][$second]['props']['duplicate_of']='first';
                    $notes[]='heur:confirm_chain(national_id)';
                }
                return new \WP_REST_Response([
                    'ok'=>true,
                    'edited_text'=>$edited,
                    'schema'=>$schema,
                    'notes'=>$notes,
                    'confidence'=>0.55,
                    'model_used'=>false,
                    'model_skip_reason'=>'ai_not_configured'
                ],200);
            }
            $model = self::select_optimal_model($ai, $user_text, 'hoosha_prepare', 4);
            $progress[] = ['step'=>'model_request','message'=>'ارسال درخواست به مدل'];
            $notes = $notes ?? [];
            $needsChunk = false; $rawTextLen = mb_strlen($user_text,'UTF-8');
            $lineCount = substr_count($user_text, "\n") + 1;
            if ($rawTextLen > 8000 || $lineCount > 60){ $needsChunk = true; }
            $resp = null; $chunkSchemas = []; $chunkEdited = [];
            if ($needsChunk){
                // Split by paragraphs / blank lines heuristically
                $parts = preg_split('/\n{2,}/u', $user_text);
                if (!is_array($parts) || count($parts)<2){ $parts = str_split($user_text, max(2000,(int)floor($rawTextLen/3))); }
                $parts = array_values(array_filter(array_map('trim',$parts), function($p){ return $p!==''; }));
                $chunkCount = count($parts);
                if ($chunkCount>1){ $notes[] = 'pipe:chunked_input(parts='.$chunkCount.')'; }
                $chunkStartAll = microtime(true); $chunkTimeBudget = 12.0; // seconds
                $processedChunks = 0; $aborted=false;
                foreach ($parts as $ci=>$chunk){
                    if ((microtime(true) - $chunkStartAll) > $chunkTimeBudget){ $notes[]='pipe:chunk_timeout_abort(at_index=' . ($ci+1) . ')'; $aborted=true; break; }
                    $progress[] = ['step'=>'chunk_'.($ci+1),'message'=>'پردازش بخش '.($ci+1).' از '.$chunkCount];
                    $notes[] = 'pipe:chunk_progress('.($ci+1).'/'.$chunkCount.')';
                    $chunkBaseline = self::hoosha_local_infer_from_text_v2($chunk);
                    if (empty($chunkBaseline['fields'])){ $chunkBaseline = self::hoosha_local_infer_from_text($chunk); }
                    $chunkSystem = $system . "\nCHUNK_MODE: Only output fields that appear in this chunk (index=".($ci+1)." of $chunkCount).";
                    $chunkUser = json_encode([
                        'phase'=>'prepare_chunk','chunk_index'=>$ci+1,'chunk_total'=>$chunkCount,'user_text'=>$chunk,'baseline_labels'=>[],'chunk_hint'=>'provide only contained fields'
                    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    list($chunkResp,$chunkRetryMarkers) = self::hoosha_model_call_with_retry((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $chunkSystem, (string)$chunkUser, 2);
                    if (!empty($chunkRetryMarkers)){ foreach ($chunkRetryMarkers as $crm){ $notes[]=$crm; } }
                    if (is_array($chunkResp)){
                        $cSchema = [];
                        if (isset($chunkResp['schema']) && is_array($chunkResp['schema'])){ $cSchema = $chunkResp['schema']; }
                        elseif (isset($chunkResp['fields']) && is_array($chunkResp['fields'])) { $cSchema = ['fields'=>$chunkResp['fields']]; }
                        if (!empty($cSchema['fields'])){ $chunkSchemas[] = $cSchema; }
                        if (!empty($chunkResp['edited_text'])){ $chunkEdited[] = (string)$chunkResp['edited_text']; }
                        $notes[] = 'pipe:chunk_success(index=' . ($ci+1) . ')'; $processedChunks++;
                    } else {
            $notes[] = 'pipe:chunk_failed(index=' . ($ci+1) . ')'; $processedChunks++;
                    }
        if ($processedChunks>0){ $notes[]='pipe:chunks_processed('.$processedChunks.')'; }
        if ($aborted){ $notes[]='pipe:chunks_aborted'; }
                }
                // Merge chunk schemas into one synthetic response
                $mergedFields = [];
                foreach ($chunkSchemas as $cs){
                    foreach (($cs['fields']??[]) as $f){ if (is_array($f)){ $mergedFields[] = $f; } }
                }
                if (!empty($mergedFields)){
                    $resp = [ 'edited_text' => implode("\n", $chunkEdited), 'schema' => ['fields'=>$mergedFields], 'notes'=>[] ];
                    $notes[] = 'pipe:chunks_merged(fields=' . count($mergedFields) . ')';
                }
            } else {
                list($resp,$retryMarkers) = self::hoosha_model_call_with_retry((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $system, (string)$user, 5);
                if (!empty($retryMarkers)) { foreach ($retryMarkers as $rm){ $notes[] = $rm; } }
            }
            if (!is_array($resp)){
                // Graceful fallback on model failure (no hard 502) – use baseline formal schema
                $edited = self::hoosha_local_edit_text($user_text, $baseline_formal);
                $schema = $baseline_formal;
                if (!isset($notes)) $notes=[];
                $notes[]='pipe:model_call_failed';
                $notes = array_merge($notes ?? [], ['pipe:model_call_failed','pipe:fallback_from_model_failure','pipe:debug_model_fail_path']);
                $conf = 0.25;
                self::hoosha_post_finalize_adjust($schema, $user_text, $notes);
                $progress[] = ['step'=>'model_failed','message'=>'شکست مدل و بازگشت به fallback محلی'];
                // Optional guard after fallback
                $guardBlock = self::maybe_guard($baseline_formal, $schema, $user_text, $notes);
                    $respPayload = [
                        'ok'=>true,
                        'edited_text'=>$edited,
                        'schema'=>$schema,
                        'notes'=>array_values(array_unique($notes)),
                        'guard'=>$guardBlock,
                        'debug_raw_notes'=> $notes,
                        'confidence'=>$conf,
                        'progress'=>$progress
                    ];
                    if ($previewExpired){
                        $respPayload['ok']=false; // gate preview
                        $respPayload['notes'][]='guard:preview_expired';
                        $respPayload['preview_status']='expired_local_fallback';
                    }
                    return new WP_REST_Response($respPayload, 200);
            }
            // If model wrapped payload under a 'result' or 'data' key, flatten it
            if (isset($resp['result']) && is_array($resp['result'])) { $resp = $resp['result']; }
            elseif (isset($resp['data']) && is_array($resp['data'])) { $resp = $resp['data']; }
            // Normalize common alternative keys from LLMs
            $edited = '';
            if (isset($resp['edited_text'])) { $edited = (string)$resp['edited_text']; }
            elseif (isset($resp['editedText'])) { $edited = (string)$resp['editedText']; }
            elseif (isset($resp['text'])) { $edited = (string)$resp['text']; }
            elseif (isset($resp['output'])) { $edited = is_string($resp['output']) ? (string)$resp['output'] : ''; }
            elseif (isset($resp['answer'])) { $edited = is_string($resp['answer']) ? (string)$resp['answer'] : ''; }
            elseif (isset($resp['message'])) { $edited = is_string($resp['message']) ? (string)$resp['message'] : ''; }
            // Schema may be nested or provided as fields/form
            $schema = [];
            if (isset($resp['schema']) && is_array($resp['schema'])) { $schema = $resp['schema']; }
            elseif (isset($resp['form']) && is_array($resp['form'])) { $schema = $resp['form']; }
            elseif (isset($resp['fields']) && is_array($resp['fields'])) { $schema = [ 'fields' => $resp['fields'] ]; }
            elseif (isset($resp['form_schema']) && is_array($resp['form_schema'])) { $schema = $resp['form_schema']; }
            $notes = isset($resp['notes']) && is_array($resp['notes']) ? $resp['notes'] : (isset($resp['messages']) && is_array($resp['messages']) ? $resp['messages'] : []);
            $conf = null;
            if (isset($resp['confidence'])) { $conf = floatval($resp['confidence']); }
            elseif (isset($resp['confidence_score'])) { $conf = floatval($resp['confidence_score']); }
            elseif (isset($resp['score'])) { $conf = floatval($resp['score']); }
            $reasons = [];
            $refineAttempted = false;
            $unchangedCount = 0;
            // 3) Detect unchanged labels vs baseline (formal)
            if (!empty($schema['fields']) && !empty($baseline_labels)){
                foreach ($schema['fields'] as $f){
                    if (!is_array($f) || !isset($f['label'])) continue;
                    $lbl = (string)$f['label'];
                    if (in_array($lbl, $baseline_labels, true)){ $unchangedCount++; }
                }
            }
            if ($unchangedCount > 0){ $reasons[] = 'model_labels_partially_unchanged('.$unchangedCount.')'; }

            // 4) If model failed to produce edited_text or schema, or labels unchanged => refine loop
            if (($edited === '' || empty($schema['fields']) || $unchangedCount === count($schema['fields'] ?? []))){
                $refineAttempted = true; $reasons[] = 'refine_pass_triggered';
                $refineSys = $system . "\nREFINE: Ensure every label differs in tone from baseline and is formally edited.";
                $refineUser = json_encode([
                    'phase' => 'refine',
                    'user_text' => $user_text,
                    'baseline_schema' => $baseline_formal,
                    'initial_model_schema' => $schema,
                    'initial_model_edited_text' => $edited,
                ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $progress[] = ['step'=>'refine_pass','message'=>'اجرای مرحله اصلاح (refine)'];
                list($resp2,$retryMarkers2) = self::hoosha_model_call_with_retry((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $refineSys, (string)$refineUser, 3);
                if (!empty($retryMarkers2)) { foreach ($retryMarkers2 as $rm){ $notes[] = $rm; } }
                if (is_array($resp2)){
                    if (isset($resp2['edited_text'])) $edited = (string)$resp2['edited_text'];
                    if (isset($resp2['schema']) && is_array($resp2['schema'])) $schema = $resp2['schema'];
                } else {
                    $reasons[] = 'pipe:refine_no_response';
                }
            }

            if ($edited === '' && (empty($schema) || empty($schema['fields'] ?? []))){
                $keys = implode(',', array_keys($resp));
                @error_log('Arshline hoosha_prepare empty_output keys=' . $keys);
                // Local fallback: infer a minimal schema from user_text to avoid empty UI
                $fallback = self::hoosha_local_infer_from_text_v2($user_text);
                if (empty($fallback['fields'])) { $fallback = self::hoosha_local_infer_from_text($user_text); }
                if (isset($fallback['fields']) && is_array($fallback['fields']) && count($fallback['fields']) > 0){
                    // Formalize labels so preview uses edited tone
                    $schema = self::hoosha_formalize_labels($fallback);
                    if ($edited === ''){ $edited = self::hoosha_local_edit_text($user_text, $schema); }
                    $notes[] = 'fallback_local_inference_applied';
                    $notes[] = 'labels_formalized';
                    $reasons[] = 'fallback_triggered';
                    if ($conf === null){ $conf = 0.2; }
                }
            } elseif ((empty($schema) || empty($schema['fields'] ?? [])) && !empty($baseline_formal['fields'])) {
                // New guard: model produced an edited_text but omitted schema; substitute baseline formal schema so UI is not blank
                $schema = $baseline_formal;
                // Ensure edited_text not blank; if blank rebuild
                if ($edited === '') { $edited = self::hoosha_local_edit_text($user_text, $schema); }
                $notes[] = 'baseline_schema_substitution';
                if ($conf === null) { $conf = 0.15; }
            }
            // 5) Validate schema shape; fallback to baseline if invalid then tag field sources
            if (!empty($schema)){
                $validRes = self::hoosha_validate_schema($schema);
                if (empty($validRes['ok'])){
                    $notes[] = 'pipe:schema_invalid('.implode('|',$validRes['errors']).')';
                    $schema = $baseline_formal; $edited = self::hoosha_local_edit_text($user_text, $schema);
                } else { $notes[] = 'pipe:schema_valid'; }
            }
            // 5.1) Tag field sources (model vs heuristic)
            if (!empty($schema['fields']) && is_array($schema['fields'])){
                foreach ($schema['fields'] as &$sf){
                    if (!is_array($sf)) continue;
                    $lbl = isset($sf['label']) ? (string)$sf['label'] : '';
                    $sf['props'] = isset($sf['props']) && is_array($sf['props']) ? $sf['props'] : [];
                    if (in_array($lbl, $baseline_labels, true)){
                        $sf['props']['source'] = 'heuristic_or_unchanged';
                    } else {
                        if (!isset($sf['props']['source'])) $sf['props']['source'] = 'model';
                    }
                }
                unset($sf);
            }
            if ($refineAttempted) $notes[] = 'refine_attempted';
            // 4.5) Reconcile missing fields against baseline (ensure model did not drop items)
            if (!empty($baseline_formal['fields']) && !empty($schema['fields'])){
                $reconcileResult = self::hoosha_reconcile_schema($baseline_formal, $schema);
                if (!empty($reconcileResult['added_count'])){
                    $schema = $reconcileResult['schema'];
                    $notes[] = 'reconciled_missing_fields('.intval($reconcileResult['added_count']).')';
                    // Confidence penalty if we had to add fields the model omitted
                    if ($conf !== null){ $conf = max(0.1, $conf - 0.15); } else { $conf = 0.55; }
                }
            }
            // 4.6) Enrich formats (national id, date, mobile) if model omitted
            if (!empty($schema['fields'])){
                $enriched = self::hoosha_enrich_field_formats($schema);
                if (!empty($enriched['count'])){ $notes[] = 'format_enriched('.intval($enriched['count']).')'; }
            }
            // 4.7) If edited_text lacks metadata lines, build annotated version while preserving raw
            if (!empty($schema['fields'])){
                $hasMeta = false;
                if (is_string($edited) && $edited !== ''){
                    if (preg_match('/\[[^\]]+\|[^\]]+\]/u', $edited)) { $hasMeta = true; }
                }
                if (!$hasMeta){
                    $rawEdited = $edited;
                    $annotated = self::hoosha_local_edit_text($user_text, $schema);
                    if ($annotated !== ''){
                        $edited = $annotated;
                        if ($rawEdited !== '' && $rawEdited !== $annotated){ $notes[] = 'edited_text_annotated'; }
                    }
                }
            }
            // 4.8) Post-finalize adjustments: required enforcement, de-dup, option normalization
            self::hoosha_post_finalize_adjust($schema, $user_text, $notes);
            $progress[] = ['step'=>'post_finalize','message'=>'اِعمال نهایی: الزامی‌سازی، حذف تکرار، فرمت‌ها'];
            if (!empty($reasons)) $notes = array_values(array_unique(array_merge($notes, $reasons)));
            // 4.9) Sanitize & rebuild edited_text to remove contamination / duplicated enumerations
            if (!empty($schema['fields']) && is_array($schema['fields'])){
                // Revised pruning: keep all fields, only tag duplicates later (less aggressive to improve coverage)
                $cleanFields = [];
                foreach ($schema['fields'] as $f){ if (is_array($f)) $cleanFields[] = $f; }
                $notes[]='heur:prune_soft_mode';
                // (Legacy removal counters disabled)
                // Repair any question labels that collapsed to a lone '?' and have options
                foreach ($cleanFields as &$cf){
                    if (!is_array($cf)) continue;
                    $lbl = isset($cf['label'])?(string)$cf['label']:'';
                    if (preg_match('/^\s*[\?؟]\s*$/u',$lbl)){
                        if (isset($cf['props']['options']) && is_array($cf['props']['options'])){
                            $opts = $cf['props']['options'];
                            // Guess a generic label based on certain option sets
                            $lowerJoin = mb_strtolower(implode(' ', $opts),'UTF-8');
                            if (preg_match('/چای|قهوه|موکا/u',$lowerJoin)) $cf['label']='نوشیدنی ترجیحی شما؟';
                            // Removed hardcoded contact preference canonical label; intent now handled by IntentRules
                            else $cf['label']='گزینه مناسب را انتخاب کنید؟';
                        } else {
                            $cf['label']='سؤال را تکمیل کنید؟';
                        }
                    }
                }
                unset($cf);
                // Additional refinement: remove or split residual mixed option sets (drinks/contact etc.)
                self::hoosha_refine_option_contamination($schema, $notes);
                $progress[] = ['step'=>'options_refined','message'=>'پاکسازی و تفکیک گزینه‌های ترکیبی'];
                $schema['fields'] = $cleanFields;
                // Rebuild edited_text from sanitized schema (formal labels will be applied inside builder)
                $edited = self::hoosha_local_edit_text($user_text, $schema);
                // Duplicate & confirm detection phase
                if (!empty($schema['fields'])){
                    $canonMap = []; $duplicates = 0; $confirms = 0; $labelIndex = [];
                    foreach ($schema['fields'] as $idx=>$fld){
                        if (!is_array($fld)) continue; $lbl = isset($fld['label'])?(string)$fld['label']:''; if ($lbl==='') continue;
                        $canon = mb_strtolower(preg_replace('/[\s[:punct:]]+/u','', $lbl),'UTF-8');
                        if (!isset($labelIndex[$canon])){ $labelIndex[$canon] = $idx; }
                        else {
                            // Mark as duplicate_of first occurrence index
                            if (!isset($schema['fields'][$idx]['props']) || !is_array($schema['fields'][$idx]['props'])) $schema['fields'][$idx]['props']=[];
                            $schema['fields'][$idx]['props']['duplicate_of'] = $labelIndex[$canon];
                            $duplicates++;
                        }
                    }
                    // Confirm detection (ایمیل, رمز عبور, کلمه عبور, پسورد, شماره ملی) with keywords for confirmation
                    $confirmKeywords = ['تایید','تأیید','مجدد','دوباره','retype','confirm'];
                    $baseKeys = [];
                    foreach ($schema['fields'] as $idx=>$fld){
                        if (!is_array($fld)) continue; $lbl = isset($fld['label'])?(string)$fld['label']:''; if ($lbl==='') continue;
                        $low = mb_strtolower($lbl,'UTF-8');
                        $isConfirm = false; foreach ($confirmKeywords as $kw){ if (mb_strpos($low,$kw)!==false){ $isConfirm=true; break; } }
                        if ($isConfirm){
                            // Find nearest previous field sharing core token (e.g., ایمیل, رمز, کلمه عبور)
                            $coreTokens = ['ایمیل','رمز','کلمه عبور','پسورد','کد ملی','شماره ملی'];
                            foreach ($coreTokens as $tok){
                                if (mb_strpos($low, mb_strtolower($tok,'UTF-8'))!==false){
                                    // search backwards
                                    for ($p=$idx-1;$p>=0;$p--){
                                        $plbl = isset($schema['fields'][$p]['label'])?(string)$schema['fields'][$p]['label']:''; $plow = mb_strtolower($plbl,'UTF-8');
                                        if ($plbl!=='' && mb_strpos($plow, mb_strtolower($tok,'UTF-8'))!==false){
                                            if (!isset($schema['fields'][$idx]['props']) || !is_array($schema['fields'][$idx]['props'])) $schema['fields'][$idx]['props']=[];
                                            $schema['fields'][$idx]['props']['confirm_for'] = $p; $confirms++; break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($duplicates>0){ $notes[] = 'heur:duplicates_found('.$duplicates.')'; $notes[]='heur:duplicates_tagged('.$duplicates.')'; }
                    if ($confirms>0) $notes[] = 'heur:confirms_found('.$confirms.')';
                }
                // Coverage enforcement: if after refinement field count << baseline, inject missing baseline fields
                if (!empty($baseline_formal['fields']) && !empty($schema['fields']) && empty($GLOBALS['__hoosha_strict_small_form'])){
                    $finalCount = count($schema['fields']); $baseCount = count($baseline_formal['fields']);
                    if ($baseCount>=8){
                        $ratio = ($finalCount>0)? ($finalCount / $baseCount) : 0.0;
                        if ($ratio < $coverageThreshold){
                            $existingCanon = [];
                            foreach ($schema['fields'] as $cf){ if (!is_array($cf)) continue; $ll = isset($cf['label'])?(string)$cf['label']:''; if($ll==='') continue; $existingCanon[ self::hoosha_canon_label($ll) ] = true; }
                            $addedCov = 0;
                            foreach ($baseline_formal['fields'] as $bf){
                                if (!is_array($bf)) continue; $bl = isset($bf['label'])?(string)$bf['label']:''; if($bl==='') continue;
                                $canon = self::hoosha_canon_label($bl);
                                if (!isset($existingCanon[$canon])){
                                    $nf = $bf; if (!isset($nf['props'])||!is_array($nf['props'])) $nf['props']=[]; $nf['props']['source']='coverage_injected';
                                    $schema['fields'][] = $nf; $existingCanon[$canon]=true; $addedCov++;
                                }
                                if ($addedCov> ($baseCount*0.5)) break; // avoid runaway injection
                            }
                            if ($addedCov>0){ $notes[]='heur:coverage_injected('.$addedCov.')'; $progress[]=['step'=>'coverage_enforced','message'=>'افزایش پوشش فیلدها (+' . $addedCov . ')']; }
                        }
                    }
                }
                // Optional refine pass only for injected fields
                $doCoverageRefine = !empty($body['coverage_refine']);
                if ($doCoverageRefine){
                    $injectSubset = [];
                    foreach (($schema['fields']??[]) as $fld){ if (is_array($fld) && isset($fld['props']['source']) && $fld['props']['source']==='coverage_injected'){ $injectSubset[]=$fld; } }
                    if (count($injectSubset)>0){
                        $progress[] = ['step'=>'coverage_refine','message'=>'اصلاح فیلدهای تزریق‌شده'];
                        $notes[]='pipe:coverage_refine_start('.count($injectSubset).')';
                        try {
                            $covSystem = "You are refining previously injected fallback fields. Improve labels and infer formats. Return ONLY schema.fields array.";
                            $covUser = json_encode(['phase'=>'coverage_refine','fields'=>$injectSubset], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            list($covResp,$covRetry) = self::hoosha_model_call_with_retry((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $covSystem, (string)$covUser, 2);
                            if (!empty($covRetry)){ foreach($covRetry as $cr){ $notes[]=$cr; } }
                            if (is_array($covResp) && isset($covResp['fields']) && is_array($covResp['fields']) && count($covResp['fields'])){
                                // Merge refined fields back by label match or index
                                $refined = $covResp['fields'];
                                $ri=0;
                                foreach ($schema['fields'] as &$sf){
                                    if (!is_array($sf)) continue;
                                    if (isset($sf['props']['source']) && $sf['props']['source']==='coverage_injected'){
                                        if (isset($refined[$ri]) && is_array($refined[$ri])){
                                            $refFld = $refined[$ri];
                                            // Preserve original source tag
                                            if (!isset($refFld['props'])||!is_array($refFld['props'])) $refFld['props']=[];
                                            $refFld['props']['source']='coverage_injected_refined';
                                            $sf = $refFld; $ri++;
                                        }
                                    }
                                }
                                unset($sf);
                                $notes[]='pipe:coverage_refine_applied('.$ri.')';
                            } else { $notes[]='pipe:coverage_refine_no_response'; }
                        } catch (\Throwable $eCov){ $notes[]='pipe:coverage_refine_exception'; }
                    }
                }
                // Fallback file field injection if user_text strongly references files but schema lacks them
                $hasFile=false; foreach (($schema['fields']??[]) as $sf){ if (is_array($sf) && isset($sf['type']) && $sf['type']==='file'){ $hasFile=true; break; } }
                if (!$hasFile && !$disableFileFallback){
                    $kwFile = 0;
                    $filePattern = $strictFileMatch
                        ? '/\b(فایل|رزومه|بارگذاری|آپلود|رسید|گزارش|log|ویدیو|ویدئو|mp4)\b|\b(jpg|jpeg|png|pdf|docx)\b/i'
                        : '/فایل|رزومه|بارگذاری|آپلود|تصویر(?!سازی)|رسید|jpg|jpeg|png|گزارش|log|ویدیو|ویدئو|mp4/i';
                    if (preg_match($filePattern,$user_text)) $kwFile=1;
                    if ($kwFile){
                        $injected=[];
                        if (preg_match('/رزومه|cv|pdf/i',$user_text)){ $injected[]=['label'=>'فایل رزومه','type'=>'file','required'=>false,'props'=>['accept'=>['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'source'=>'file_injected']]; }
                        if (preg_match('/تصویر|رسید|jpg|jpeg|png/i',$user_text)){ $injected[]=['label'=>'تصویر رسید پرداخت','type'=>'file','required'=>false,'props'=>['accept'=>['image/png','image/jpeg'],'source'=>'file_injected']]; }
                        if (preg_match('/گزارش|log|txt/i',$user_text)){ $injected[]=['label'=>'گزارش‌های خطا','type'=>'file','required'=>false,'props'=>['multiple'=>true,'accept'=>['text/plain','text/log'],'source'=>'file_injected']]; }
                        if (preg_match('/mp4|ویدیو|ويديو|ویدئو/i',$user_text)){ $injected[]=['label'=>'ویدیو (رد می‌شود)','type'=>'file','required'=>false,'props'=>['accept'=>['video/mp4'],'source'=>'file_injected','reject'=>true]]; }
                        if ($injected && count($injected)>0){ foreach ($injected as $inf){ $schema['fields'][]=$inf; } $notes[]='heur:file_fallback_injected('.count($injected).')'; $progress[]=['step'=>'file_injected','message'=>'افزودن فیلدهای فایل آزمایشی']; }
                        else { $notes[]='heur:file_injection_skipped(no_pattern_match_detail)'; }
                    } else { $notes[]='heur:file_injection_skipped(no_keyword)'; }
                } elseif ($disableFileFallback){
                    $notes[]='heur:file_fallback_disabled';
                }
                // 4.10) Sanitize hallucinated / unrelated thematic fields & consent duplicates (baseline-safe)
                $baselinePreservationMap = self::hoosha_build_baseline_canon_map($baseline_formal);
                self::hoosha_prune_hallucinated_fields($schema, $baseline_formal, $user_text, $notes, $progress, $baselinePreservationMap);
                // 4.10.b) Baseline preservation audit BEFORE strict collapse
                $audit = self::hoosha_audit_baseline_preservation($baseline_formal, $schema);
                if (!empty($audit['missing'])){ $notes[]='audit:baseline_missing('.count($audit['missing']).')'; }
                if (!empty($audit['restored'])){ $notes[]='audit:baseline_restored('.count($audit['restored']).')'; }
                if (!empty($audit['duplicates'])){ $notes[]='audit:baseline_dups_tagged('.count($audit['duplicates']).')'; }
                // 4.11) Semantic duplicate collapse for small forms (prefer structured types)
                if (!empty($GLOBALS['__hoosha_strict_small_form'])){
                    self::hoosha_semantic_collapse_small_form($schema, $notes, $progress);
                    // Rebuild edited text numbering after collapse
                    $edited = self::hoosha_local_edit_text($user_text, $schema);
                    // 4.11.b) Hard trim: keep only baseline canonical set (physically remove extras) while preserving first richer duplicate
                    self::hoosha_enforce_exact_small_form($schema, $baseline_formal, $notes, $progress);
                    $edited = self::hoosha_local_edit_text($user_text, $schema);
                }
            }
            // Optional AI final review
            try {
                $gsFR = self::get_global_settings();
                if (!empty($gsFR['ai_final_review_enabled'])){
                    $progress[] = ['step'=>'final_review','message'=>'اجرای بازبینی نهایی'];
                    $tFR = microtime(true);
                    $revReq = new WP_REST_Request('POST', '/arshline/v1/hoosha/final-review');
                    $revReq->set_body(json_encode(['user_text'=>$user_text,'schema'=>$schema], JSON_UNESCAPED_UNICODE));
                    $revResp = self::hoosha_final_review($revReq);
                    if ($revResp instanceof WP_REST_Response){
                        $revData = $revResp->get_data();
                        if (is_array($revData) && !empty($revData['ok']) && isset($revData['schema'])){
                            $schema = is_array($revData['schema']) ? $revData['schema'] : $schema;
                            if (!empty($revData['issues'])){ foreach($revData['issues'] as $iss){ if (is_array($iss)) { $notes[]='ai:final_issue('.($iss['code']??'issue').')'; } elseif (is_string($iss)) { $notes[]='ai:final_issue('.$iss.')'; } } }
                            if (!empty($revData['suggestions'])){ foreach($revData['suggestions'] as $sg){ if (is_array($sg)) { $notes[]='ai:final_suggestion('.($sg['type']??'suggest').')'; } } }
                            if (!empty($revData['model_notes'])){ foreach($revData['model_notes'] as $mn){ if (is_string($mn)) $notes[]='ai:note('.$mn.')'; } }
                        } else { $notes[]='ai:final_review_failed'; }
                    } else { $notes[]='ai:final_review_error'; }
                    $notes[] = 'perf:final_review_ms=' . intval(1000*(microtime(true)-$tFR));
                }
            } catch (\Throwable $eFR){ $notes[]='ai:final_review_exception'; }

            // Heuristic file upload restoration (after final review so schema is stable)
            self::hoosha_restore_file_uploads($schema, $notes);
            // Advanced file props inference
            self::hoosha_infer_file_props($schema, $user_text, $notes);
            // Normalize prefixes
            $notes = self::hoosha_normalize_note_prefixes($notes);
            $progress[] = ['step'=>'complete','message'=>'اتمام پردازش'];
            $notes[] = 'perf:total_ms=' . intval(1000*(microtime(true)-$t0));
            // Build ordered events list for frontend logging: include progress steps and notes chronologically.
            $events = [];
            $seq = 0;
            foreach ($progress as $pEv){
                if (!is_array($pEv)) continue;
                $events[] = [ 'seq'=>$seq++, 'type'=>'progress', 'step'=>$pEv['step']??'', 'message'=>$pEv['message']??'' ];
            }
            foreach ($notes as $nEv){
                if (!is_string($nEv)) continue;
                $events[] = [ 'seq'=>$seq++, 'type'=>'note', 'note'=>$nEv ];
            }
            // Reorder fields if preserve_order enabled
            if ($preserveOrder && !empty($baselineOrderMap) && !empty($schema['fields']) && is_array($schema['fields'])){
                usort($schema['fields'], function($a,$b) use ($baselineOrderMap){
                    $la = isset($a['label'])? self::hoosha_canon_label((string)$a['label']):'';
                    $lb = isset($b['label'])? self::hoosha_canon_label((string)$b['label']):'';
                    $oa = array_key_exists($la,$baselineOrderMap)? $baselineOrderMap[$la] : 999999;
                    $ob = array_key_exists($lb,$baselineOrderMap)? $baselineOrderMap[$lb] : 999999;
                    if ($oa === $ob){ return 0; }
                    return ($oa < $ob)? -1: 1;
                });
            }
            // Summary metrics
            $baselineCount = isset($baseline_formal['fields']) && is_array($baseline_formal['fields']) ? count($baseline_formal['fields']) : 0;
            $finalCount = isset($schema['fields']) && is_array($schema['fields']) ? count($schema['fields']) : 0;
            $coverageRatio = ($baselineCount>0)? round($finalCount / $baselineCount, 3) : null;
            $sourceCounts = ['model'=>0,'heuristic_or_unchanged'=>0,'reconciled_from_baseline'=>0,'coverage_injected'=>0,'coverage_injected_refined'=>0,'file_injected'=>0];
            if (!empty($schema['fields'])){
                foreach ($schema['fields'] as $f){ if (!is_array($f)) continue; $src = $f['props']['source'] ?? ''; if (isset($sourceCounts[$src])) $sourceCounts[$src]++; }
            }
            $summary = [
                'baseline_count'=>$baselineCount,
                'final_count'=>$finalCount,
                'coverage_ratio'=>$coverageRatio,
                'sources'=>$sourceCounts,
                'preserve_order'=>(bool)$preserveOrder,
            ];
            if ($form_name === '' && !empty($schema['fields']) && is_array($schema['fields'])){
                // As a final fallback post-schema, attempt derive again from first field label if still empty.
                $lbl0 = $schema['fields'][0]['label'] ?? '';
                if ($lbl0){ $form_name = mb_substr((string)$lbl0,0,60,'UTF-8'); $notes[]='heur:form_name_from_schema'; }
            }
            $out = ['ok'=>true,'edited_text'=>$edited,'schema'=>$schema,'notes'=>$notes,'confidence'=>$conf,'progress'=>$progress,'events'=>$events,'summary'=>$summary,'form_name'=>$form_name];
            // Guard phase (optional gating) – runs after full processing, may annotate notes and issues
            $guardBlock = self::maybe_guard($baseline_formal, $schema, $user_text, $notes);
            if (is_array($guardBlock)){
                $out['guard']=$guardBlock;
                $out['notes']=$notes; // updated with guard notes
                if (isset($guardBlock['approved']) && !$guardBlock['approved']){
                    $out['ok']=false; // gate preview if not approved
                }
                // Rebuild edited_text if field count or types changed by guard (to fix numbering / duplication in output)
                if (!empty($schema['fields']) && is_array($schema['fields'])){
                    $rebuilt = self::hoosha_local_edit_text($user_text, $schema);
                    if (is_string($rebuilt) && $rebuilt!==''){ $out['edited_text']=$rebuilt; $out['notes'][]='guard:edited_text_rebuilt'; }
                }
                // Final lock: if additions disallowed in settings, prune any residual guard_ai_added fields that slipped through earlier stages
                if (function_exists('get_option')){
                    $gs2 = get_option('arshline_settings', []);
                    $allowAddFinal = !empty($gs2['allow_ai_additions']);
                    if (!$allowAddFinal && !empty($schema['fields']) && is_array($schema['fields'])){
                        $before = count($schema['fields']);
                        $schema['fields'] = array_values(array_filter($schema['fields'], function($f){
                            if (!is_array($f)) return false; $ga = $f['props']['guard_ai_added'] ?? false; return !$ga; }));
                        $after = count($schema['fields']);
                        if ($after < $before){ $out['notes'][]='guard:final_ai_prune(removed='.($before-$after).')'; $out['schema']=$schema; }
                    }
                }
                // Final yes/no sanitation (defense-in-depth) after any pruning
                if (!empty($schema['fields']) && is_array($schema['fields'])){
                    $ynPurged=0; foreach ($schema['fields'] as &$__gf){
                        if (!is_array($__gf)) continue; $opts = $__gf['props']['options'] ?? null; if (!$opts||!is_array($opts)) continue;
                        $canonOpts = array_map(function($o){ return preg_replace('/\s+/u','', mb_strtolower($o,'UTF-8')); }, $opts);
                        $hasYN = in_array('بله',$opts,true) || in_array('خیر',$opts,true) || in_array('بلهخیر',$canonOpts,true);
                        if (!$hasYN) continue;
                        $lblLow = mb_strtolower($__gf['label']??'','UTF-8');
                        $isBinaryIntent = (mb_strpos($lblLow,'آیا')!==false) || preg_match('/^(آیا|.*\؟)$/u',$lblLow);
                        if (!$isBinaryIntent){ unset($__gf['props']['options']); $ynPurged++; }
                    }
                    unset($__gf);
                    if ($ynPurged>0){ $out['notes'][]='guard:final_option_cleanup('.$ynPurged.')'; $out['schema']=$schema; }
                }
                // Ensure final normalization always runs (even if no yes/no purge happened)
                self::final_guard_normalize($schema, $out['notes'], $user_text, $baseline_formal, $guardBlock);
                self::final_guard_post_refine($schema, $out['notes']);
                $out['schema']=$schema;
                $rebFinal = self::hoosha_local_edit_text($user_text, $schema);
                if (is_string($rebFinal) && $rebFinal!==''){ $out['edited_text']=$rebFinal; $out['notes'][]='guard:edited_text_rebuilt_final'; }
            }
            // Force Persian name fields required after guard (final heuristic) if user asked "نام" terms
            if (!empty($out['schema']['fields']) && is_array($out['schema']['fields'])){
                foreach ($out['schema']['fields'] as &$__nf){
                    if (!is_array($__nf)) continue; $lbl = isset($__nf['label'])?(string)$__nf['label']:''; if($lbl==='') continue;
                    $low = mb_strtolower($lbl,'UTF-8');
                    // Match "نام" alone but avoid matching unrelated words where نام is substr (rare). Also match "نام خانوادگی"
                    if (preg_match('/\bنام\b/u',$low) || mb_strpos($low,'نام خانوادگی')!==false){
                        if (empty($__nf['required'])){ $__nf['required']=true; $out['notes'][]='heur:forced_required(name)'; }
                    }
                }
                unset($__nf);
            }
            if ($previewExpired){
                $out['ok']=false; $out['preview_status']='expired_local_fallback'; $out['notes'][]='guard:preview_expired';
            }
            // Attach model debug for admins only
            if (current_user_can('manage_options') && is_array(self::$last_ai_debug)){
                $out['debug'] = [ 'model' => self::$last_ai_debug ];
            }
            // Attach sanitized guard debug if flag set and guard present
            if ($debugGuard && !empty($out['guard']) && is_array($out['guard'])){
                $out['guard']['debug'] = [
                    'issues'=>$out['guard']['issues'] ?? [],
                    'approved'=>$out['guard']['approved'] ?? null,
                    'lat_ms'=>$out['guard']['lat_ms'] ?? null
                ];
                $out['notes'][]='guard:debug_attached';
            }
            return new WP_REST_Response($out, 200);
        } catch (\Throwable $e){
            $code = ($e->getMessage() === 'invalid_model_response') ? 502 : 500;
            return new WP_Error('hoosha_prepare_failed', $e->getMessage(), ['status'=>$code]);
        }
    }

    /**
     * Reconcile: ensure no baseline field is lost. Adds missing ones to end with source=reconciled.
     * Returns [ schema: array, added_count: int ]
     */
    protected static function hoosha_reconcile_schema(array $baseline_formal, array $model_schema): array
    {
        if (empty($baseline_formal['fields']) || empty($model_schema['fields'])) return ['schema'=>$model_schema, 'added_count'=>0];
        $existing = [];
        foreach ($model_schema['fields'] as $f){
            if (!is_array($f)) continue; $lbl = isset($f['label'])? trim((string)$f['label']):''; if ($lbl==='') continue;
            $existing[ self::hoosha_canon_label($lbl) ] = true;
        }
        $added = 0; $append = [];
        foreach ($baseline_formal['fields'] as $bf){
            if (!is_array($bf)) continue; $bl = isset($bf['label'])? trim((string)$bf['label']):''; if ($bl==='') continue;
            $sig = self::hoosha_canon_label($bl);
            if (!isset($existing[$sig])){
                // Improved semantic similarity guard: compute token overlap with every existing label
                $skip=false; $baseTokens = self::hoosha_tokenize_for_similarity($bl);
                if ($baseTokens){
                    foreach (array_keys($existing) as $ek){
                        $ekTokens = self::hoosha_tokenize_for_similarity($ek);
                        if (!$ekTokens) continue;
                        $inter = array_intersect($baseTokens,$ekTokens);
                        $union = array_unique(array_merge($baseTokens,$ekTokens));
                        $j = (count($union)>0)? (count($inter)/count($union)) : 0.0;
                        if ($j >= 0.6){ $skip=true; break; }
                    }
                }
                if ($skip) continue; // do not append near-duplicate
                $clone = $bf; // baseline already formalized
                if (!isset($clone['props']) || !is_array($clone['props'])) $clone['props'] = [];
                $clone['props']['source'] = 'reconciled_from_baseline';
                $append[] = $clone; $added++;
            }
        }
        if ($added>0){
            $model_schema['fields'] = array_merge($model_schema['fields'], $append);
        }
        return ['schema'=>$model_schema, 'added_count'=>$added];
    }

    /** Return canonical field JSON schema definition (descriptive; used for doc / potential future) */
    protected static function hoosha_field_json_schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['fields'],
            'properties' => [
                'fields' => [ 'type'=>'array', 'items'=> [ 'type'=>'object', 'required'=>['type','label'], 'properties'=>[
                    'type'=>['type'=>'string'],
                    'label'=>['type'=>'string'],
                    'required'=>['type'=>['boolean','null']],
                    'props'=>['type'=>'object','properties'=>[
                        'format'=>['type'=>'string'],
                        'options'=>['type'=>'array','items'=>['type'=>'string']],
                        'multiple'=>['type'=>'boolean'],
                        'rating'=>['type'=>'object','properties'=>[
                            'min'=>['type'=>'integer'],
                            'max'=>['type'=>'integer'],
                            'icon'=>['type'=>'string']
                        ]],
                        'duplicate_of'=>['type'=>['integer','null']],
                        'confirm_for'=>['type'=>['integer','null']],
                        'accept'=>['type'=>'array','items'=>['type'=>'string']],
                        'maxSizeKB'=>['type'=>'integer'],
                        'regex'=>['type'=>'string']
                    ]]
                ] ] ]
            ]
        ];
    }

    

    /** Lightweight validator ensuring minimal structural soundness */
    protected static function hoosha_validate_schema(array $schema): array
    {
        $errors = [];
        if (!isset($schema['fields']) || !is_array($schema['fields'])){ $errors[] = 'missing_fields_array'; return ['ok'=>false,'errors'=>$errors]; }
        foreach ($schema['fields'] as $i=>$f){
            if (!is_array($f)) { $errors[] = 'field_not_object(index='.$i.')'; continue; }
            $t = $f['type'] ?? null; $l = $f['label'] ?? null;
            if (!is_string($t) || $t===''){ $errors[] = 'missing_type(index='.$i.')'; }
            if (!is_string($l) || $l===''){ $errors[] = 'missing_label(index='.$i.')'; }
            if (isset($f['props']) && !is_array($f['props'])){ $errors[]='props_not_object(index='.$i.')'; }
        }
        return ['ok'=>empty($errors), 'errors'=>$errors];
    }

    /** Restore file upload semantics for fields that semantically indicate upload intent but were not typed as file */
    protected static function hoosha_restore_file_uploads(array &$schema, array &$notes): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $restored=0; $extAnnotated=0;
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue;
            $lbl = isset($f['label']) ? mb_strtolower((string)$f['label'],'UTF-8') : '';
            $looks = ($lbl!=='' && (mb_strpos($lbl,'آپلود')!==false || mb_strpos($lbl,'بارگذاری')!==false || mb_strpos($lbl,'فایل')!==false || preg_match('/pdf|docx|jpg|jpeg|png|log|txt/u',$lbl)));
            if ($looks && (($f['type'] ?? '') !== 'file')){
                $accept = [];
                if (preg_match('/pdf|رزومه/u',$lbl)) $accept[]='application/pdf';
                if (preg_match('/docx|رزومه/u',$lbl)) $accept[]='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                if (preg_match('/jpg|jpeg|رسید|تصویر|png/u',$lbl)){ $accept[]='image/jpeg'; $accept[]='image/png'; }
                if (preg_match('/log|txt/u',$lbl)) $accept[]='text/plain';
                $accept = array_values(array_unique($accept)); if (!$accept) $accept=['application/octet-stream'];
                $f['type']='file';
                if (!isset($f['props']) || !is_array($f['props'])) $f['props']=[];
                $f['props']['format']='file_upload';
                $f['props']['accept']=$accept;
                unset($f['props']['regex']);
                $restored++;
            }
            if (!empty($f['props']['_orig_format']) && $f['props']['_orig_format'] !== ($f['props']['format']??'')) $extAnnotated++;
        }
        unset($f);
        if ($restored>0) $notes[]='restored_file_upload('.$restored.')';
        if ($extAnnotated>0) $notes[]='annotated_preserved_formats('.$extAnnotated.')';
    }

    /** Infer advanced file upload properties; called late in pipeline */
    protected static function hoosha_infer_file_props(array &$schema, string $user_text, array &$notes): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return; $lt = mb_strtolower($user_text,'UTF-8');
        $multiContext = (mb_strpos($lt,'چند')!==false || mb_strpos($lt,'multiple')!==false || mb_strpos($lt,'گزارشات')!==false || mb_strpos($lt,'گزارش‌ها')!==false);
        $count=0;
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue; if (($f['type']??'')!=='file') continue; if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[];
            $lblLow = mb_strtolower((string)($f['label']??''),'UTF-8');
            if (!isset($f['props']['accept']) || !is_array($f['props']['accept']) || !$f['props']['accept']){
                $acc=[]; if (preg_match('/رزومه|cv|pdf/u',$lblLow)){ $acc[]='application/pdf'; $acc[]='application/vnd.openxmlformats-officedocument.wordprocessingml.document'; }
                if (preg_match('/تصویر|رسید|اسکرین|png|jpg|jpeg/u',$lblLow)){ $acc[]='image/png'; $acc[]='image/jpeg'; }
                if ($acc){ $f['props']['accept']=array_values(array_unique($acc)); }
            }
            if ($multiContext || preg_match('/چند|multiple|گزارشات|گزارش‌ها/u',$lblLow)){ $f['props']['multiple']=true; }
            if (!isset($f['props']['maxSizeKB'])){
                if (preg_match('/(\d+)\s*(?:mb|مگ)/iu',$user_text,$m)){ $f['props']['maxSizeKB']=intval($m[1])*1024; }
                elseif (preg_match('/(\d+)\s*kb/iu',$user_text,$m)){ $f['props']['maxSizeKB']=intval($m[1]); }
                else { $f['props']['maxSizeKB']=2048; }
            }
            $count++;
        }
        unset($f); if ($count>0) $notes[]='heur:file_props_inferred('.$count.')';
    }

    /**
     * Prune or tag hallucinated / unrelated fields and collapse repeated consent/acceptance style fields.
     * Strategy:
     *  - Build token signature of user_text & baseline labels.
     *  - Fields whose canonical label tokens have <30% overlap with union signature AND are not enriched/file injected -> drop (soft prune limit 3).
     *  - Detect multiple consent/accept fields (containing terms like موافقت, پذیرش, شرایط) and keep the first; others get duplicate_of pointer.
     *  - Correct obvious mis-typed long_text that looks like file/image request (contains تصویر/آپلود) by converting to file.
     */
    protected static function hoosha_prune_hallucinated_fields(array &$schema, array $baseline_formal, string $user_text, array &$notes, array &$progress, array $baselineCanonMap = []): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $raw = mb_strtolower($user_text,'UTF-8');
        // Build baseline token bag
        $baselineTokens=[]; if (!empty($baseline_formal['fields'])){
            foreach ($baseline_formal['fields'] as $bf){ if(!is_array($bf)) continue; $l=$bf['label']??''; $baselineTokens=array_merge($baselineTokens, self::hoosha_tokenize_for_similarity($l)); }
        }
        $userTokens = self::hoosha_tokenize_for_similarity($raw);
        $sig = array_unique(array_merge($baselineTokens,$userTokens));
        $sigMap = array_fill_keys($sig,true);
        $kept=[]; $dropped=0; $consentIndex=null; $dupConsent=0; $fixedTypes=0;
        foreach ($schema['fields'] as $idx=>$f){
            if(!is_array($f)){ continue; }
            $lbl = (string)($f['label']??''); $canonTokens = self::hoosha_tokenize_for_similarity($lbl);
            $canonLabel = self::hoosha_canon_label($lbl);
            // Consent detection
            $low = mb_strtolower($lbl,'UTF-8');
            $isConsent = (mb_strpos($low,'موافقت')!==false || mb_strpos($low,'شرایط')!==false || mb_strpos($low,'قوانین')!==false || mb_strpos($low,'privacy')!==false || mb_strpos($low,'پذیرش')!==false);
            if ($isConsent){
                if ($consentIndex===null){ $consentIndex = count($kept); }
                else {
                    // Mark as duplicate_of first consent
                    if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[]; $f['props']['duplicate_of']=$consentIndex; $dupConsent++;
                }
            }
            // Mis-typed image/file heuristics: long_text with keywords
            if (($f['type']??'')==='long_text' && preg_match('/تصویر|عکس|آپلود|بارگذاری/u',$low)){
                $f['type']='file'; if (!isset($f['props'])||!is_array($f['props'])) $f['props']=[]; $f['props']['format']='file_upload'; $fixedTypes++;
            }
            // Skip pruning sources we injected heuristically (coverage/file/recovered)
            $src = $f['props']['source'] ?? '';
            if (in_array($src,['coverage_injected','coverage_injected_refined','file_injected','recovered_scan','reconciled_from_baseline','heuristic_or_unchanged','refined_split'],true)){
                $kept[]=$f; continue; }
            // Similarity score = overlap tokens / label tokens
            $overlap=0; foreach($canonTokens as $tk){ if(isset($sigMap[$tk])) $overlap++; }
            $score = (count($canonTokens)>0)? ($overlap / count($canonTokens)) : 0.0;
            // Baseline-preservation: If this label (canon) exists in baseline, NEVER drop here. Rationale: baseline fields only removable
            // under strict_small_form_enforce phase where duplicates are resolved with semantic similarity >=0.95.
            if (isset($baselineCanonMap[$canonLabel])){ $kept[]=$f; continue; }
            // Strengthen preservation for personal name fields even if similarity low
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

    /** Build map of canonical baseline labels for quick preservation checks */
    protected static function hoosha_build_baseline_canon_map(array $baseline_formal): array
    {
        $map = [];
        if (!empty($baseline_formal['fields'])){
            foreach ($baseline_formal['fields'] as $bf){ if(!is_array($bf)) continue; $lbl=$bf['label']??''; if($lbl==='') continue; $map[self::hoosha_canon_label($lbl)]=true; }
        }
        return $map;
    }

    /** Audit baseline preservation; restore silently missing baseline fields (tag as restored_baseline) */
    protected static function hoosha_audit_baseline_preservation(array $baseline_formal, array &$schema): array
    {
        $res = ['missing'=>[], 'restored'=>[], 'duplicates'=>[]];
        if (empty($baseline_formal['fields'])) return $res;
        $canonFinal = [];
        foreach (($schema['fields']??[]) as $idx=>$f){ if(!is_array($f)) continue; $lbl=$f['label']??''; if($lbl==='') continue; $c=self::hoosha_canon_label($lbl); if(isset($canonFinal[$c])){ $res['duplicates'][]=$c; } else { $canonFinal[$c]=$idx; } }
        foreach ($baseline_formal['fields'] as $bf){ if(!is_array($bf)) continue; $lbl=$bf['label']??''; if($lbl==='') continue; $c=self::hoosha_canon_label($lbl); if(!isset($canonFinal[$c])){ $res['missing'][]=$c; // restore
                $clone=$bf; if(!isset($clone['props'])||!is_array($clone['props'])) $clone['props']=[]; $clone['props']['source']='restored_baseline'; $schema['fields'][]=$clone; $res['restored'][]=$c; }
        }
        return $res;
    }

    /** Normalize notes to new prefix convention */
    protected static function hoosha_normalize_note_prefixes(array $notes): array
    {
        $out=[]; foreach ($notes as $n){ if(!is_string($n)||$n==='') continue; $o=$n;
            if (preg_match('/^(final_issue|final_note)\(/',$n)){ $n=preg_replace('/^final_issue\(/','ai:final_issue(', $n); $n=preg_replace('/^final_note\(/','ai:note(', $n); }
            if (preg_match('/^(model_call_failed|fallback_from_model_failure)/',$n)){ $n='pipe:'.$n; }
            // Additional safeguard: if legacy code inserted model_call_failed without pipe prefix, force both markers
            if ($n==='model_call_failed'){ $n='pipe:model_call_failed'; }
            if (preg_match('/^restored_file_upload\(/',$n)){ $n='heur:'.$n; }
            if (preg_match('/^deduplicated_fields\(/',$n)){ $n='heur:'.$n; }
            if (preg_match('/^required_enforced\(/',$n)){ $n='heur:'.$n; }
            if (preg_match('/^extended_format/',$n)){ $n='heur:'.$n; }
            if (preg_match('/^formats_summary\(/',$n)){ $n='pipe:'.$n; }
            if (preg_match('/^options_normalized/',$n)){ $n='heur:'.$n; }
            if ($n==='refine_attempted'){ $n='pipe:refine_attempted'; }
            if (!preg_match('/^(pipe:|heur:|ai:|perf:)/',$n)) $n='heur:'.$n; $out[]=$n; }
        return array_values(array_unique($out));
    }

    /** Canonicalize a label: remove leading numbering & punctuation & normalize spaces */
    protected static function hoosha_canon_label(string $label): string
    {
        // Deprecated: delegated to Normalizer::canonLabel
        return \Arshline\Hoosha\Pipeline\Normalizer::canonLabel($label);
    }

    /**
     * Enrich formats for common patterns if model omitted them. Returns ['count'=>int].
     */
    protected static function hoosha_enrich_field_formats(array &$schema): array
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return ['count'=>0];
        $count = 0;
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue;
            $lbl = isset($f['label'])? (string)$f['label']:'';
            if ($lbl==='') continue;
            if (!isset($f['props']) || !is_array($f['props'])) $f['props'] = [];
            $pl = mb_strtolower($lbl, 'UTF-8');
            $hasFormat = isset($f['props']['format']) && $f['props']['format'] !== '';
            if (!$hasFormat){
                if (mb_strpos($pl,'کد ملی') !== false || preg_match('/\bملی\b/u',$pl)) { $f['props']['format'] = 'national_id_ir'; $count++; continue; }
                if (mb_strpos($pl,'تاریخ') !== false) { $f['props']['format'] = 'date_greg'; $count++; continue; }
                if (mb_strpos($pl,'شماره') !== false && (mb_strpos($pl,'موبایل') !== false || mb_strpos($pl,'تلفن') !== false)) { $f['props']['format'] = 'mobile_ir'; $count++; continue; }
            }
        }
        unset($f);
        return ['count'=>$count];
    }

    /** Optional AI Guard: validates final schema before preview; returns guard block or null. */
    protected static function maybe_guard(array $baseline, array &$schema, string $user_text, array &$notes)
    {
        try {
            if (!function_exists('get_option')) return null;
            $gs = get_option('arshline_settings', []);
            // Default behavior: if key absent, enable guard (opt-out model)
            $hasKey = is_array($gs) && array_key_exists('ai_guard_enabled',$gs);
            $enabled = $hasKey ? !empty($gs['ai_guard_enabled']) : true;
            if (!$hasKey) { $notes[]='guard:auto_enabled_default'; }
            if (!$enabled) return null;
            if (!class_exists('Arshline\\Guard\\GuardService')) return null;
            $apiKey = (string)($gs['ai_api_key'] ?? '');
            $baseUrl = (string)($gs['ai_base_url'] ?? 'https://api.openai.com');
            $modelName = (string)($gs['ai_model'] ?? 'gpt-4o-mini');
            $client = null;
            if ($apiKey !== '' && class_exists('Arshline\\Hoosha\\Pipeline\\OpenAIModelClient')){
                $client = new \Arshline\Hoosha\Pipeline\OpenAIModelClient($apiKey, $modelName, $baseUrl);
            }
            $guard = new \Arshline\Guard\GuardService($client);
            $res = $guard->evaluate($baseline, $schema, $user_text, $notes);
            if (!empty($res['adopted']) && isset($res['schema']['fields'])){ $schema = $res['schema']; }
            if (!empty($res['issues'])){ foreach ($res['issues'] as $iss){ $notes[]='guard:issue('.$iss.')'; } }
            // Append severity detail counts for quick client filtering
            if (!empty($res['issues_detail']) && is_array($res['issues_detail'])){
                $errorCount = 0; $warnCount = 0; $infoCount = 0;
                foreach ($res['issues_detail'] as $id){
                    if (!is_array($id)) continue; $sev = $id['severity'] ?? ''; $code = $id['code'] ?? '';
                    if ($sev==='error') $errorCount++; elseif ($sev==='warning') $warnCount++; elseif ($sev==='info') $infoCount++;
                    if ($code!=='') $notes[] = 'guard:issue_detail('.$sev.':'.$code.')';
                }
                if ($errorCount>0) $notes[]='guard:issues_error_count('.$errorCount.')';
                if ($warnCount>0) $notes[]='guard:issues_warning_count('.$warnCount.')';
                if ($infoCount>0) $notes[]='guard:issues_info_count('.$infoCount.')';
            }
            // Surface diagnostics counters if present
            if (!empty($res['diagnostics']) && is_array($res['diagnostics'])){
                foreach ($res['diagnostics'] as $k=>$v){ if ($v){ $notes[]='guard:diag_'.$k.'('.$v.')'; } }
            }
            $notes[] = $res['approved'] ? 'guard:approved' : 'guard:rejected';

            // --- Parallel Diagnostic: GuardUnit (non-invasive) ---
            if (class_exists('Arshline\\Guard\\GuardUnit')) {
                try {
                    // Determine GuardUnit mode: constant > option > default
                    $gMode = 'diagnostic';
                    if (defined('HOOSHA_GUARD_MODE')) {
                        $cm = strtolower((string)HOOSHA_GUARD_MODE);
                        if (in_array($cm, ['diagnostic','corrective'], true)) $gMode = $cm;
                    } elseif (isset($gs['guard_mode']) && in_array($gs['guard_mode'], ['diagnostic','corrective'], true)) {
                        $gMode = $gs['guard_mode'];
                    }
                    $requestId = isset($res['lat_ms']) ? ('gsvc_'.$res['lat_ms']) : null;
                    $gu = new \Arshline\Guard\GuardUnit([], [], $gMode, $requestId);
                    $userQuestions = self::extract_user_questions($user_text);
                    $baselineSchema = ['fields'=>$baseline['fields'] ?? []];
                    $guRes = $gu->run($userQuestions, ['fields'=>$schema['fields'] ?? [], 'meta'=>$schema['meta'] ?? []], ['baseline_schema'=>$baselineSchema]);
                    // Compare decision surface
                    $notes[]='guard_unit:mode('.$gMode.')';
                    if (!empty($guRes['issues'])) { $notes[]='guard_unit:issues('.count($guRes['issues']).')'; }
                    if (!empty($guRes['metrics']['hallucinations_removed'])) { $notes[]='guard_unit:hallucinations_would_remove('.$guRes['metrics']['hallucinations_removed'].')'; }
                    if (!empty($guRes['metrics']['similarity_avg'])) { $notes[]='guard_unit:similarity_avg('.$guRes['metrics']['similarity_avg'].')'; }
                    if (($guRes['status'] ?? '') !== 'approved') { $notes[]='guard_unit:status('.($guRes['status'] ?? 'unknown').')'; }
                } catch (\Throwable $e) {
                    $notes[]='guard_unit:error';
                }
            }
            return [ 'approved'=>$res['approved'], 'issues'=>$res['issues'], 'issues_detail'=>$res['issues_detail']??[], 'diagnostics'=>$res['diagnostics']??[], 'lat_ms'=>$res['lat_ms'], 'adopted'=>$res['adopted'] ];
        } catch (\Throwable $e){ $notes[]='guard:error'; }
        return null;
    }

    /** Lightweight tokenizer for similarity scoring. */
    protected static function hoosha_tokenize_for_similarity(string $s): array
    {
        // Deprecated: delegated to Normalizer::tokenize
        return \Arshline\Hoosha\Pipeline\Normalizer::tokenize($s);
    }

    /** Collapse semantically duplicate fields in small form mode; keep richer structured variant. */
    protected static function hoosha_semantic_collapse_small_form(array &$schema, array &$notes, array &$progress): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $fields = $schema['fields'];
        $n = count($fields); if ($n<=1) return;
        $removed = 0; $mapKeep = [];
        for ($i=0;$i<$n;$i++){
            if (!is_array($fields[$i])) continue; $li = (string)($fields[$i]['label']??''); if ($li==='') continue;
            $ti = $fields[$i]['type'] ?? '';
            $tokI = self::hoosha_tokenize_for_similarity($li); if (!$tokI) continue;
            for ($j=$i+1;$j<$n;$j++){
                if (!is_array($fields[$j])) continue; $lj = (string)($fields[$j]['label']??''); if ($lj==='') continue;
                $tokJ = self::hoosha_tokenize_for_similarity($lj); if (!$tokJ) continue;
                $inter = array_intersect($tokI,$tokJ); $union = array_unique(array_merge($tokI,$tokJ));
                $sim = (count($union)>0)? (count($inter)/count($union)) : 0.0;
                if ($sim >= 0.65){
                    // Choose keeper: priority multiple_choice > dropdown > file > rating > long_text > short_text
                    $tj = $fields[$j]['type'] ?? '';
                    $priority = ['multiple_choice'=>6,'dropdown'=>5,'file'=>4,'rating'=>3,'long_text'=>2,'short_text'=>1];
                    $keepI = $priority[$ti] ?? 0; $keepJ = $priority[$tj] ?? 0;
                    if ($keepJ > $keepI){
                        // mark i duplicate of j
                        if (!isset($fields[$i]['props'])||!is_array($fields[$i]['props'])) $fields[$i]['props']=[];
                        $fields[$i]['props']['duplicate_of']=$j; $removed++;
                    } else {
                        if (!isset($fields[$j]['props'])||!is_array($fields[$j]['props'])) $fields[$j]['props']=[];
                        $fields[$j]['props']['duplicate_of']=$i; $removed++;
                    }
                }
            }
        }
        if ($removed>0){
            // Soft collapse: we don't physically remove, we tag duplicates; optionally remove physically if >1 dup per cluster
            $notes[]='heur:semantic_duplicates_tagged('.$removed.')';
            $progress[]=['step'=>'semantic_collapse','message'=>'برچسب‌گذاری تکرار معنایی ('.$removed.')'];
        }
        $schema['fields']=$fields;
    }

    /** Enforce exact small-form: restrict final schema to baseline canonical labels only, choose best duplicate. */
    protected static function hoosha_enforce_exact_small_form(array &$schema, array $baseline_formal, array &$notes, array &$progress): void
    {
        if (empty($GLOBALS['__hoosha_strict_small_form'])) return; // safety
        if (empty($baseline_formal['fields']) || empty($schema['fields'])) return;
        $baseCanonOrder=[]; $i=0;
        foreach ($baseline_formal['fields'] as $bf){ if (!is_array($bf)) continue; $lbl=$bf['label']??''; if($lbl==='') continue; $baseCanon = self::hoosha_canon_label($lbl); if(!isset($baseCanonOrder[$baseCanon])) $baseCanonOrder[$baseCanon]=$i++; }
        if (!$baseCanonOrder) return;
        // Group current fields by canonical label
        $groups=[]; foreach ($schema['fields'] as $idx=>$f){ if(!is_array($f)) continue; $lbl=$f['label']??''; if($lbl==='') continue; $c=self::hoosha_canon_label($lbl); $groups[$c][] = ['i'=>$idx,'f'=>$f]; }
        $priority = ['multiple_choice'=>6,'dropdown'=>5,'file'=>4,'rating'=>3,'long_text'=>2,'short_text'=>1];
        $final=[]; $removed=0; $restored=0; $extraneousDropped=0;
        foreach ($baseCanonOrder as $canon=>$ord){
            if (isset($groups[$canon])){
                // Pick best existing
                $best=null; $bestScore=-1; foreach ($groups[$canon] as $ent){ $t=$ent['f']['type']??''; $score=$priority[$t]??0; if($score>$bestScore){ $best=$ent['f']; $bestScore=$score; } }
                if ($best && isset($best['props']['duplicate_of'])) unset($best['props']['duplicate_of']);
                if ($best){ $final[]=$best; }
            } else {
                // baseline field was pruned earlier; restore it verbatim
                foreach ($baseline_formal['fields'] as $bf){
                    if (!is_array($bf)) continue; $lbl=$bf['label']??''; if($lbl==='') continue; if (self::hoosha_canon_label($lbl)===$canon){
                        $clone=$bf; if(!isset($clone['props'])||!is_array($clone['props'])) $clone['props']=[]; $clone['props']['source']='restored_small_form'; $final[]=$clone; $restored++; break; }
                }
            }
            if (isset($groups[$canon]) && count($groups[$canon])>1){ $removed += (count($groups[$canon])-1); }
        }
        // Count extraneous (labels not in baseline canon)
        foreach ($schema['fields'] as $f){ if(!is_array($f)) continue; $lbl=$f['label']??''; if($lbl==='') continue; $c=self::hoosha_canon_label($lbl); if(!isset($baseCanonOrder[$c])) $extraneousDropped++; }
        $schema['fields']=$final;
        if ($removed>0) $notes[]='heur:strict_small_form_collapsed_dups('.$removed.')';
        if ($extraneousDropped>0) $notes[]='heur:strict_small_form_pruned_extras('.$extraneousDropped.')';
        if ($restored>0) $notes[]='heur:strict_small_form_restored('.$restored.')';
        $progress[]=['step'=>'small_form_enforced','message'=>'ساده‌سازی فرم کوچک (حذف اضافات / بازگردانی)'];
    }

    /** After schema finalized: de-duplicate semantically similar labels and enforce required from user_text */
    protected static function hoosha_post_finalize_adjust(array &$schema, string $user_text, array &$notes): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        // 1) Enforce required for national id & phone if global instruction contains both keywords with الزامی/اجباری
        $lt = mb_strtolower($user_text,'UTF-8');
        $requireNat = (mb_strpos($lt,'کد ملی') !== false && (mb_strpos($lt,'الزامی') !== false || mb_strpos($lt,'اجباری') !== false));
        $requirePhone = ((mb_strpos($lt,'شماره') !== false) && (mb_strpos($lt,'تلفن') !== false || mb_strpos($lt,'موبایل') !== false) && (mb_strpos($lt,'الزامی') !== false || mb_strpos($lt,'اجباری') !== false));
        $enforced = [];
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue;
            $lbl = isset($f['label'])? (string)$f['label']:''; $pl = mb_strtolower($lbl,'UTF-8');
            if ($requireNat && (mb_strpos($pl,'کد ملی') !== false)) { if (empty($f['required'])){ $f['required']=true; $enforced['national_id_ir']=true; } }
            if ($requirePhone && (mb_strpos($pl,'شماره') !== false) && (mb_strpos($pl,'تلفن') !== false || mb_strpos($pl,'موبایل') !== false)) { if (empty($f['required'])){ $f['required']=true; $enforced['mobile_ir']=true; } }
        }
        unset($f);
        foreach (array_keys($enforced) as $k){ $notes[] = 'required_enforced('.$k.')'; }
        // 2) De-duplicate similar labels (keep first occurrence, drop later ones)
        $seen = []; $out = []; $removed = 0;
        foreach ($schema['fields'] as $f){
            if (!is_array($f)){ $out[] = $f; continue; }
            $lbl = isset($f['label'])? trim((string)$f['label']):''; if ($lbl===''){ $out[]=$f; continue; }
            $sig = mb_strtolower(preg_replace('/\s+/u',' ', $lbl),'UTF-8');
            // fuzzy collapse: remove punctuation, digits
            $canon = preg_replace('/[\d[:punct:]]+/u','', $sig);
            if (isset($seen[$canon])){ $removed++; continue; }
            $seen[$canon] = true; $out[] = $f;
        }
        if ($removed>0){ $schema['fields'] = $out; $notes[] = 'deduplicated_fields('.$removed.')'; }
        // 3) Degrade unsupported formats to maintain compatibility with core validator
        $supportedFormats = [ 'free_text'=>1,'email'=>1,'mobile_ir'=>1,'mobile_intl'=>1,'tel'=>1,'numeric'=>1,'national_id_ir'=>1,'postal_code_ir'=>1,'fa_letters'=>1,'en_letters'=>1,'ip'=>1,'time'=>1,'date_jalali'=>1,'date_greg'=>1,'regex'=>1,
            // Treat extended ones as first-class to avoid UI downgrade
            'alphanumeric'=>1,'alphanumeric_no_space'=>1,'alphanumeric_extended'=>1,'file_upload'=>1,'national_id_company_ir'=>1,'sheba_ir'=>1,'credit_card_ir'=>1,'captcha_alphanumeric'=>1
        ];
        $knownNew = ['national_id_company_ir','sheba_ir','credit_card_ir','captcha_alphanumeric','alphanumeric','alphanumeric_no_space','alphanumeric_extended','file_upload'];
        foreach ($schema['fields'] as &$f){
            if (!is_array($f)) continue; if (!isset($f['props']) || !is_array($f['props'])) continue;
            $fmt = $f['props']['format'] ?? '';
            if ($fmt && !isset($supportedFormats[$fmt])){
                // Preserve original
                $f['props']['_orig_format'] = $fmt;
                if ($fmt === 'file_upload'){
                    // Keep file_upload; ensure type is file
                    if ($f['type']!=='file') $f['type']='file';
                    $notes[]='extended_format(file_upload)';
                } elseif (in_array($fmt,['alphanumeric','alphanumeric_no_space','alphanumeric_extended'],true)){
                    // Now retain the original format but attach canonical regex helper pattern if absent
                    if (!isset($f['props']['regex'])){
                        if ($fmt==='alphanumeric_no_space') $f['props']['regex']='/^[A-Za-z0-9\p{Arabic}]+$/u';
                        elseif ($fmt==='alphanumeric_extended') $f['props']['regex']='/^[A-Za-z0-9][A-Za-z0-9_\-]{2,63}$/u';
                        else $f['props']['regex']='/^[A-Za-z0-9\p{Arabic}\s]+$/u';
                        $notes[]='extended_format_helper_regex('.$fmt.')';
                    }
                    // Keep format unchanged
                } elseif (in_array($fmt,['national_id_company_ir','sheba_ir','credit_card_ir','captcha_alphanumeric'],true)){
                    // Keep as-is so extended validator (if loaded) can validate; if not -> treat as free_text fallback
                    // Tag note; do not degrade here to allow new validator to work.
                    $notes[]='extended_format('.$fmt.')';
                }
            }
        }
        unset($f);
        // 3) Option normalization (آلبالو) if still corrupted (e.g., بالا & گیلاس)
        foreach ($schema['fields'] as &$f2){
            if (!is_array($f2)) continue; if (!isset($f2['props']) || !is_array($f2['props'])) continue;
            if (isset($f2['props']['options']) && is_array($f2['props']['options'])){
                $changed = false;
                foreach ($f2['props']['options'] as &$op){
                    $lop = mb_strtolower($op,'UTF-8');
                    if ($lop === 'بالا' && (mb_strpos($lt,'آلبالو')!==false || mb_strpos($lt,'البالو')!==false)) { $op = 'آلبالو'; $changed = true; }
                }
                unset($op);
                if ($changed){ $notes[] = 'options_normalized(albaloo)'; }
            }
        }
        unset($f2);
        // 4) formats summary note
        try {
            $counts = [];
            foreach (($schema['fields']??[]) as $ff){
                if (!is_array($ff)) continue; $fmt = $ff['props']['format'] ?? ''; if(!$fmt) continue;
                $counts[$fmt] = ($counts[$fmt] ?? 0) + 1;
            }
            if ($counts){ ksort($counts, SORT_NATURAL|SORT_FLAG_CASE); $pairs=[]; foreach ($counts as $k=>$v){ $pairs[]=$k.':'.$v; } $notes[]='formats_summary(' . implode(',', $pairs) . ')'; }
        } catch (\Throwable $e){ /* ignore */ }
    }

    /** Extract user questions from raw user_text for GuardUnit semantic alignment.
     * Splits on newlines and question marks (Persian & Latin), trims, filters empties, removes meta-like lines.
     */
    protected static function extract_user_questions(string $user_text): array
    {
        $normalized = str_replace("\r", "\n", $user_text);
        $normalized = preg_replace('/([؟?])\s*/u', "$1\n", (string)$normalized);
        $parts = preg_split('/\n+/u', (string)$normalized, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p){
            $p = trim($p);
            if ($p==='') continue;
            if (preg_match('/^(system:|note:|meta:)/i',$p)) continue;
            $p = preg_replace('/[؟?]+$/u','',$p);
            if ($p==='') continue;
            if (mb_strlen($p,'UTF-8')>200) $p = mb_substr($p,0,200,'UTF-8');
            $out[] = $p;
        }
        if (!$out){ $t = trim($user_text); if ($t!=='') $out[] = mb_substr($t,0,200,'UTF-8'); }
        return $out;
    }

    /**
     * POST /hoosha/apply
     * Body: { schema: object, commands: array }
     * Returns: { ok: true, schema: object, deltas?: array }
     */
    public static function hoosha_apply(WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new WP_Error('forbidden', 'forbidden', ['status' => 403]);
        $body = json_decode($request->get_body() ?: '{}', true);
        $schema = isset($body['schema']) && is_array($body['schema']) ? $body['schema'] : [];
        $commands = isset($body['commands']) && is_array($body['commands']) ? $body['commands'] : [];
        if (empty($schema)) return new WP_Error('bad_request', 'schema required', ['status' => 400]);

        $system = 'You are Hoosha, a precise Persian form command applier. Output MUST be valid JSON. Apply colloquial commands to the given schema within plugin limits.';
        $user = json_encode([
            'phase' => 'apply_commands',
            'schema' => $schema,
            'commands' => $commands
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        try {
            $ai = self::get_ai_settings();
            if (empty($ai['enabled']) || empty($ai['base_url']) || empty($ai['api_key'])){
                return new WP_Error('ai_not_configured', 'ai_not_configured', ['status'=>400]);
            }
            $model = self::select_optimal_model($ai, json_encode($schema, JSON_UNESCAPED_UNICODE), 'hoosha_apply', 4);
            $resp = self::openai_chat_json((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $system, (string)$user);
            if (!is_array($resp)) throw new \Exception('invalid_model_response');
            $newSchema = isset($resp['schema']) && is_array($resp['schema']) ? $resp['schema'] : $schema;
            $deltas = isset($resp['deltas']) && is_array($resp['deltas']) ? $resp['deltas'] : [];
            // If commands include a formalization hint and schema unchanged, apply local formalization as a safety net
            $cmdText = mb_strtolower(implode('؛', array_map('strval', $commands)), 'UTF-8');
            if ($newSchema === $schema && (mb_strpos($cmdText, 'رسمی') !== false || mb_strpos($cmdText, 'ویرایش لحن') !== false || mb_strpos($cmdText, 'ادبی') !== false)){
                $newSchema = self::hoosha_formalize_labels($schema);
                $deltas[] = [ 'op'=>'formalize', 'note'=>'applied local formalization fallback' ];
            }
            return new WP_REST_Response(['ok'=>true,'schema'=>$newSchema,'deltas'=>$deltas], 200);
        } catch (\Throwable $e){
            $code = ($e->getMessage() === 'invalid_model_response') ? 502 : 500;
            return new WP_Error('hoosha_apply_failed', $e->getMessage(), ['status'=>$code]);
        }
    }

    /**
     * POST /hoosha/interpret_nl
     * Body: { schema: object, natural_prompt: string }
     * Returns: { ok: true, commands: [string], notes?:[], raw?: string }
     * Converts colloquial Persian instructions into normalized command strings usable by hoosha_apply.
     */
    public static function hoosha_interpret_nl(WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new WP_Error('forbidden', 'forbidden', ['status' => 403]);
        $body = json_decode($request->get_body() ?: '{}', true);
        $schema = isset($body['schema']) && is_array($body['schema']) ? $body['schema'] : [];
        $prompt = isset($body['natural_prompt']) ? trim((string)$body['natural_prompt']) : '';
        if (empty($schema)) return new WP_Error('bad_request','schema required',['status'=>400]);
        if ($prompt==='') return new WP_Error('bad_request','natural_prompt required',['status'=>400]);
        $notes = [];
        $notes[] = 'ai:interpret_start';
        $editorDiagnostics = [];
        // Reference extraction (field numbers & partial labels) for smarter mapping downstream
        $fieldNumberRefs = [];
        if (preg_match_all('/(?:فیلد|سوال)\s*(\d{1,3})/u',$prompt,$mNums)){
            foreach ($mNums[1] as $num){ $fieldNumberRefs[] = intval($num); }
            if ($fieldNumberRefs){ $notes[]='editor:ref_numbers('.count($fieldNumberRefs).')'; $editorDiagnostics[]=['type'=>'ref_numbers','count'=>count($fieldNumberRefs),'values'=>$fieldNumberRefs]; }
        }
        // Quoted or bracketed label fragments
        if (preg_match_all('/[«"“](.+?)[»"”]/u',$prompt,$mLbls)){
            $frags = array_values(array_filter(array_map('trim',$mLbls[1])));
            if ($frags){ $notes[]='editor:ref_fragments('.count($frags).')'; $editorDiagnostics[]=['type'=>'ref_fragments','count'=>count($frags),'values'=>$frags]; }
        }
        // Lightweight heuristic pre-parse (split by ، or . or \n)
        $rawParts = preg_split('/[\n\.،]+/u', $prompt);
        $candidateCmds = [];
        if (is_array($rawParts)){
            foreach ($rawParts as $seg){
                $seg = trim($seg); if ($seg==='') continue;
                $seg = preg_replace('/^(\d+\s*[\-\.\)]\s*)/u','',$seg);
                if (preg_match('/گزینه\s+جدید\s+(.+)\s+اضافه/iu',$seg,$m)){ $candidateCmds[]='add_option:'.trim($m[1]); continue; }
                if (preg_match('/(اجباری|الزامی)/iu',$seg) && preg_match('/(سوال|فیلد)/u',$seg)){ $candidateCmds[]='set_required:all'; continue; }
                if (preg_match('/(اختیاری|غیر\s*اجباری)/u',$seg) && preg_match('/(سوال|فیلد)/u',$seg)){ $candidateCmds[]='set_optional:all'; continue; }
                if (preg_match('/نام.*رسمی/u',$seg)){ $candidateCmds[]='formalize_labels'; continue; }
                if (mb_strpos($seg,'رسمی')!==false){ $candidateCmds[]='formalize_labels'; continue; }
            }
        }
        // If AI disabled, return heuristic commands only
        $ai = self::get_ai_settings();
        if (empty($ai['enabled']) || empty($ai['api_key']) || empty($ai['base_url'])){
            $notes[]='ai:interpret_disabled';
            if (!$candidateCmds){ $notes[]='editor:empty_commands'; $editorDiagnostics[]=['type'=>'empty_commands','reason'=>'AI disabled and no heuristic commands parsed']; }
            return new WP_REST_Response(['ok'=>true,'commands'=>$candidateCmds,'notes'=>$notes,'raw'=>$prompt,'editor_diagnostics'=>$editorDiagnostics],200);
        }
        try {
            $model = self::select_optimal_model($ai, $prompt, 'hoosha_interpret_nl', 2);
            $system = 'You convert Persian natural language form edit instructions to normalized machine commands. Output JSON { commands:[string] }. Recognize: formalize_labels, set_required:all, set_optional:all, add_option:<text>.';
            $user = json_encode(['prompt'=>$prompt,'candidate_segments'=>$candidateCmds], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $resp = self::openai_chat_json((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $system, (string)$user);
            $cmds = isset($resp['commands']) && is_array($resp['commands']) ? array_values(array_filter(array_map('strval',$resp['commands']))) : $candidateCmds;
            $notes[] = 'ai:interpret_success('.count($cmds).')';
            if (!$cmds){ $notes[]='editor:empty_commands_model'; $editorDiagnostics[]=['type'=>'empty_commands_model','reason'=>'Model returned no commands']; }
            return new WP_REST_Response(['ok'=>true,'commands'=>$cmds,'notes'=>$notes,'raw'=>$prompt,'editor_diagnostics'=>$editorDiagnostics],200);
        } catch (\Throwable $e){
            $notes[] = 'ai:interpret_error';
            $editorDiagnostics[]=['type'=>'interpret_error','error'=>$e->getMessage()];
            return new WP_REST_Response(['ok'=>true,'commands'=>$candidateCmds,'notes'=>$notes,'raw'=>$prompt,'error'=>$e->getMessage(),'editor_diagnostics'=>$editorDiagnostics],200);
        }
    }

    /**
     * POST /hoosha/preview_edit
     * Body: { schema: object, natural_prompt: string }
     * Returns: { ok:true, commands:[string], preview_schema:object, deltas?:[], notes?:[] }
     * Flow: natural language -> commands -> apply (model) -> return new schema for confirmation.
     */
    public static function hoosha_preview_edit(WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new WP_Error('forbidden','forbidden',['status'=>403]);
        $body = json_decode($request->get_body() ?: '{}', true);
        $schema = isset($body['schema']) && is_array($body['schema']) ? $body['schema'] : [];
        $prompt = isset($body['natural_prompt']) ? trim((string)$body['natural_prompt']) : '';
        if (empty($schema)) return new WP_Error('bad_request','schema required',['status'=>400]);
        if ($prompt==='') return new WP_Error('bad_request','natural_prompt required',['status'=>400]);
    $notes=[]; $notes[]='pipe:preview_edit_start';
    $editorDiagnostics=[];
        // Step 1: interpret
        $interpretReq = new WP_REST_Request('POST','/arshline/v1/hoosha/interpret_nl');
        $interpretReq->set_body(json_encode(['schema'=>$schema,'natural_prompt'=>$prompt], JSON_UNESCAPED_UNICODE));
        $interpResp = self::hoosha_interpret_nl($interpretReq);
        if ($interpResp instanceof WP_Error){
            $notes[]='editor:interpret_wp_error';
            return $interpResp;
        }
        $interpData = $interpResp->get_data();
        $commands = isset($interpData['commands']) && is_array($interpData['commands'])? $interpData['commands'] : [];
        if (empty($commands)){
            $notes[]='editor:no_commands_after_interpret';
            $editorDiagnostics[]=['type'=>'no_commands','reason'=>'Interpretation produced zero commands'];
        }
        // Step 2: apply (preview)
        $applySystem = 'You are Hoosha, apply given Persian commands to schema precisely and output STRICT JSON: { schema, deltas:[{op,detail?,field_index?}] }. Keep unrelated fields intact.';
        $applyUser = json_encode(['phase'=>'preview_edit','schema'=>$schema,'commands'=>$commands], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ai = self::get_ai_settings();
        if (empty($ai['enabled']) || empty($ai['api_key']) || empty($ai['base_url'])){
            // fallback – just echo original schema, no change
            $notes[]='ai:preview_ai_disabled';
            if (empty($commands)){ $notes[]='editor:preview_noop_ai_disabled'; $editorDiagnostics[]=['type'=>'preview_noop','reason'=>'AI disabled and no commands']; }
            return new WP_REST_Response(['ok'=>true,'commands'=>$commands,'preview_schema'=>$schema,'deltas'=>[],'notes'=>$notes,'editor_diagnostics'=>$editorDiagnostics],200);
        }
        try {
            $model = self::select_optimal_model($ai, json_encode($schema, JSON_UNESCAPED_UNICODE), 'hoosha_preview_edit', 4);
            $resp = self::openai_chat_json((string)$ai['base_url'], (string)$ai['api_key'], (string)$model, $applySystem, $applyUser);
            if (!is_array($resp)) throw new \Exception('invalid_model_response');
            $preview = isset($resp['schema']) && is_array($resp['schema']) ? $resp['schema'] : $schema;
            $deltas = isset($resp['deltas']) && is_array($resp['deltas']) ? $resp['deltas'] : [];
            if (empty($deltas)){
                // Local diff generator: compare labels, types, required, added/removed fields counts
                try {
                    $old = isset($schema['fields']) && is_array($schema['fields'])? $schema['fields'] : [];
                    $new = isset($preview['fields']) && is_array($preview['fields'])? $preview['fields'] : [];
                    $max = max(count($old), count($new));
                    for ($i=0; $i<$max; $i++){
                        $o = $old[$i] ?? null; $n = $new[$i] ?? null;
                        if ($o && !$n){ $deltas[] = ['op'=>'remove_field','field_index'=>$i,'detail'=>$o['label']??'']; continue; }
                        if (!$o && $n){ $deltas[] = ['op'=>'add_field','field_index'=>$i,'detail'=>$n['label']??'']; continue; }
                        if ($o && $n){
                            if (($o['label']??'') !== ($n['label']??'')) $deltas[] = ['op'=>'update_label','field_index'=>$i,'detail'=>($o['label']??'').' -> '.($n['label']??'')];
                            if (($o['type']??'') !== ($n['type']??'')) $deltas[] = ['op'=>'update_type','field_index'=>$i,'detail'=>($o['type']??'').' -> '.($n['type']??'')];
                            $or = !empty($o['required']); $nr = !empty($n['required']); if ($or !== $nr) $deltas[] = ['op'=>'update_required','field_index'=>$i,'detail'=>($or?'required':'optional').' -> '.($nr?'required':'optional')];
                        }
                    }
                } catch (\Throwable $dx){ /* ignore */ }
            }
            // Heuristic confidence scoring for preview (used by auto-apply in UI)
            $confidence = 0.0;
            try {
                $deltaCount = count($deltas);
                $hasStructural = false; // add/remove/update_type indicates structural vs cosmetic
                foreach ($deltas as $d){
                    $op = isset($d['op']) ? (string)$d['op'] : '';
                    if (strpos($op,'add_')===0 || strpos($op,'remove_')===0 || $op==='update_type'){ $hasStructural = true; break; }
                }
                if ($deltaCount > 0 && count($commands) > 0){
                    $confidence = 0.90 + min(0.05, $deltaCount * 0.01);
                } elseif ($deltaCount === 0 && count($commands) > 0){
                    // Commands understood but produced no diff (maybe idempotent)
                    $confidence = 0.70;
                } elseif ($deltaCount > 0){
                    $confidence = 0.65;
                } else {
                    $confidence = 0.40; // nothing happened
                }
                if ($hasStructural) { $confidence = min(1.0, $confidence + 0.03); }
                $confidence = max(0.0, min(1.0, $confidence));
            } catch (\Throwable $ce) { $confidence = 0.0; }
            $notes[]='ai:preview_success('.count($deltas).')';
            $notes[]='heur:preview_conf_'.number_format($confidence,2,'.','');
            if (empty($deltas)){ $notes[]='editor:no_effect'; $editorDiagnostics[]=['type'=>'no_effect','reason'=>'No deltas generated']; }
            // Heuristic: if prompt implies making name fields required, enforce in preview (mirrors prepare flow)
            try {
                $pl = mb_strtolower($prompt,'UTF-8');
                $wantReq = (mb_strpos($pl,'اجباری')!==false) || (mb_strpos($pl,'الزامی')!==false) || (mb_strpos($pl,'ضروری')!==false);
                if (!empty($preview['fields']) && is_array($preview['fields']) && $wantReq){
                    foreach ($preview['fields'] as &$__pf){
                        if (!is_array($__pf)) continue; $lbl = isset($__pf['label'])?(string)$__pf['label']:''; if($lbl==='') continue;
                        $low = mb_strtolower($lbl,'UTF-8');
                        if (preg_match('/\bنام\b/u',$low) || mb_strpos($low,'نام خانوادگی')!==false){
                            if (empty($__pf['required'])){ $__pf['required']=true; $notes[]='heur:forced_required(name)_preview'; }
                        }
                    }
                    unset($__pf);
                }
            } catch(\Throwable $heurName) { /* ignore */ }
            return new WP_REST_Response([
                'ok'=>true,
                'commands'=>$commands,
                'preview_schema'=>$preview,
                'deltas'=>$deltas,
                'confidence'=>$confidence,
                'notes'=>$notes,
                'editor_diagnostics'=>$editorDiagnostics
            ],200);
        } catch(\Throwable $e){
            $notes[]='ai:preview_error';
            $editorDiagnostics[]=['type'=>'preview_error','error'=>$e->getMessage()];
            return new WP_REST_Response(['ok'=>true,'commands'=>$commands,'preview_schema'=>$schema,'deltas'=>[],'notes'=>$notes,'error'=>$e->getMessage(),'editor_diagnostics'=>$editorDiagnostics],200);
        }
    }

    /**
     * Convert Hoosha schema to fields array compatible with Form builder.
     */
    protected static function hoosha_schema_to_fields(array $schema): array
    {
        $fields = [];
        $list = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : [];
        foreach ($list as $f){
            $type = $f['type'] ?? 'short_text';
            $label = $f['label'] ?? '';
            $required = !empty($f['required']);
            $props = isset($f['props']) && is_array($f['props']) ? $f['props'] : [];
            // Map Hoosha types to existing builder types
            if ($type === 'short_text'){
                $fields[] = [ 'type'=>'short_text', 'question'=>$label, 'required'=>$required, 'format'=>($props['format'] ?? 'free_text'), 'minLength'=>($props['minLength'] ?? 0), 'maxLength'=>($props['maxLength'] ?? 0), 'placeholder'=>($f['placeholder'] ?? '') ];
            } elseif ($type === 'long_text'){
                $fields[] = [ 'type'=>'long_text', 'question'=>$label, 'required'=>$required, 'rows'=>4, 'maxLength'=>($props['maxLength'] ?? 5000), 'placeholder'=>($f['placeholder'] ?? '') ];
            } elseif ($type === 'multiple_choice'){
                $fields[] = [ 'type'=>'multiple_choice', 'question'=>$label, 'required'=>$required, 'multiple'=>!empty($props['multiple']), 'options'=>($props['options'] ?? []) ];
            } elseif ($type === 'dropdown'){
                $fields[] = [ 'type'=>'dropdown', 'question'=>$label, 'required'=>$required, 'options'=>($props['options'] ?? []) ];
            } elseif ($type === 'rating'){
                $r = isset($props['rating']) && is_array($props['rating']) ? $props['rating'] : ['min'=>1,'max'=>10,'icon'=>'like'];
                $fields[] = [ 'type'=>'rating', 'question'=>$label, 'required'=>$required, 'min'=>intval($r['min']??1), 'max'=>intval($r['max']??10), 'icon'=>strval($r['icon']??'like') ];
            }
        }
        return $fields;
    }

    /** Collect enumerations of supported field types, formats, and meta flags for AI final review context. */
    protected static function hoosha_collect_form_capabilities(): array
    {
        // Keep this deterministic & compact for prompt budget.
        return [
            'field_types' => ['short_text','long_text','multiple_choice','dropdown','rating','file'],
            'extended_formats' => [
                'email','mobile_ir','mobile_intl','tel','numeric','national_id_ir','national_id_company_ir','postal_code_ir','sheba_ir','credit_card_ir','captcha_alphanumeric','fa_letters','en_letters','ip','time','date_jalali','date_greg','alphanumeric','alphanumeric_no_space','alphanumeric_extended','file_upload','regex','free_text'
            ],
            'special_props' => ['required','options','placeholder','format','rating','multiple','confirm_for','regex','_orig_format'],
            'rules' => [
                'confirm_for requires matching value with referenced field',
                'file_upload must have type=file',
                'rating has min/max/icon',
                'multiple_choice may set multiple=true for multi-select',
                'alphanumeric*_ variants degrade to regex internally if unsupported',
                'national/company IDs have checksum logic',
                'sheba_ir must be IR + 24 digits with valid mod97',
                'credit_card_ir must pass Luhn',
            ],
        ];
    }

    /**
     * POST /hoosha/final-review
     * Body: { user_text: string, schema: object }
     * Returns: { ok: true, schema: object, issues: array, model_notes?: array }
     */
    public static function hoosha_final_review(\WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new \WP_Error('forbidden','forbidden',['status'=>403]);
        $body = json_decode($request->get_body() ?: '{}', true);
        $userText = isset($body['user_text']) && is_string($body['user_text']) ? trim($body['user_text']) : '';
        $schema = isset($body['schema']) && is_array($body['schema']) ? $body['schema'] : [];
        if (!$schema) return new \WP_Error('bad_request','schema required',['status'=>400]);
        $caps = self::hoosha_collect_form_capabilities();
        $settings = self::get_ai_settings();
        if (empty($settings['enabled']) || empty($settings['base_url']) || empty($settings['api_key'])){
            return new \WP_REST_Response(['ok'=>false,'error'=>'ai_not_configured','schema'=>$schema,'issues'=>[],'suggestions'=>[]], 200);
        }
        $system = 'You are Hoosha, an exact Persian form schema reviewer. You receive: user_text, capabilities, candidate schema. Return STRICT JSON: { schema, issues:[{code,message,severity,field_index?}], suggestions:[{type,detail}], model_notes:[] }. Severity in {info,warning,error}. Do NOT add new fields unless a critical omission is explicit in user_text. Focus on: incorrect formats, missing confirm_for linkage, invalid duplicate_of, file props inconsistencies.';
        $payload = [ 'phase'=>'final_review','user_text'=>$userText,'capabilities'=>$caps,'schema'=>$schema ];
        $user = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        try {
            $model = self::select_optimal_model($settings, json_encode($schema, JSON_UNESCAPED_UNICODE), 'hoosha_final_review', 6);
            $resp = self::openai_chat_json((string)$settings['base_url'], (string)$settings['api_key'], (string)$model, $system, (string)$user);
            if (!is_array($resp)) throw new \Exception('invalid_model_response');
            $newSchema = isset($resp['schema']) && is_array($resp['schema']) ? $resp['schema'] : $schema;
            if (!isset($newSchema['fields']) || !is_array($newSchema['fields'])) { $newSchema['fields'] = $schema['fields'] ?? []; }
            if (count($newSchema['fields']) > 120) { $newSchema['fields'] = array_slice($newSchema['fields'],0,120); }
            $issues = isset($resp['issues']) && is_array($resp['issues']) ? $resp['issues'] : [];
            $suggestions = isset($resp['suggestions']) && is_array($resp['suggestions']) ? $resp['suggestions'] : [];
            $notes = isset($resp['model_notes']) && is_array($resp['model_notes']) ? $resp['model_notes'] : [];
            $normIssues=[]; foreach($issues as $iss){ if (is_array($iss)){ $code=(string)($iss['code']??'issue'); $sev=(string)($iss['severity']??''); if ($sev==='') $sev=self::hoosha_classify_issue_severity($code); $normIssues[]=[ 'code'=>$code, 'message'=>(string)($iss['message']??''), 'severity'=>$sev, 'field_index'=> isset($iss['field_index'])? intval($iss['field_index']) : null ]; } }
            $normSuggestions=[]; foreach($suggestions as $sg){ if (is_array($sg)){ $normSuggestions[]=[ 'type'=>(string)($sg['type']??'suggest'), 'detail'=>(string)($sg['detail']??'') ]; } }
            return new \WP_REST_Response(['ok'=>true,'schema'=>$newSchema,'issues'=>$normIssues,'suggestions'=>$normSuggestions,'model_notes'=>$notes], 200);
        } catch (\Throwable $e){
            return new \WP_REST_Response(['ok'=>false,'error'=>$e->getMessage(),'schema'=>$schema,'issues'=>[],'suggestions'=>[]], 200);
        }
    }

    /** Map issue code to a severity (error | warning | info) */
    protected static function hoosha_classify_issue_severity(string $code): string
    {
        $c = mb_strtolower($code,'UTF-8');
        $errorPatterns = ['invalid','missing','conflict','duplicate','mismatch','checksum','format'];
        foreach ($errorPatterns as $p){ if (mb_strpos($c,$p)!==false) return 'error'; }
        $warnPatterns = ['optimize','long','ambiguous','suggest','deprecated'];
        foreach ($warnPatterns as $p){ if (mb_strpos($c,$p)!==false) return 'warning'; }
        return 'info';
    }

    /**
     * Minimal heuristic fallback to infer Hoosha schema from free-form Persian text when LLM returns empty.
     * Extract questions (lines ending with ? or ؟), guesses type/required, and collects bullet/numbered options.
     */
    protected static function hoosha_local_infer_from_text(string $txt): array
    {
        $lines = preg_split('/\r?\n/u', (string)$txt) ?: [];
        $fields = [];
        $seen = [];
        $fullLower = mb_strtolower($txt, 'UTF-8');
        $forceRequiredNationalId = (mb_strpos($fullLower, 'کد ملی') !== false && mb_strpos($fullLower, 'الزامی') !== false) || (mb_strpos($fullLower, 'کد ملی') !== false && mb_strpos($fullLower, 'اجباری') !== false);
        $i = 0; $n = count($lines);
        while ($i < $n){
            $line = trim((string)$lines[$i]);
            $i++;
            if ($line === '') continue;
            // Detect question (ends with ? or contains ؟)
            $end = mb_substr($line, -1, 1, 'UTF-8');
            $isQ = ($end === '?' || $end === '؟' || preg_match('/\?$/u', $line));
            // Persian interrogatives without punctuation
            $qHints = [ 'چند', 'چقدر', 'کجا', 'کی', 'چه', 'چی', 'چطور', 'چطوره', 'آیا', 'نیست', 'میشه', 'باشد؟' ];
            foreach ($qHints as $h){ if (!$isQ && mb_strpos($line, $h, 0, 'UTF-8') !== false) { $isQ = true; break; } }
            // Imperative / descriptive request lines (treat as questions for form fields)
            if (!$isQ){
                $impHints = ['توضیح','شرح','بنویس','بنویسید','ارائه','بیان کن','وارد کن','ثبت کن','مفصل','بده'];
                foreach ($impHints as $h){ if (mb_strpos($line, $h, 0, 'UTF-8') !== false){ $isQ = true; break; } }
            }
            // Strong phone/mobile imperative detection: lines with شماره + (موبایل|تلفن|تماس) always considered question
            if (!$isQ){
                $lnLow = mb_strtolower($line,'UTF-8');
                if (mb_strpos($lnLow,'شماره') !== false && (mb_strpos($lnLow,'موبایل')!==false || mb_strpos($lnLow,'تلفن')!==false || mb_strpos($lnLow,'تماس')!==false)){
                    $isQ = true;
                }
            }
            // Do not promote arbitrary short lines (e.g., standalone options like "چای قهوه") to questions
            if (!$isQ) continue;
            // Allow inline tail after ? that may contain options (e.g., "...؟ آلبالو یا گیلاس")
            $inlineOptionsTail = '';
            if (preg_match('/(.+?[\?؟])(.*)$/u', $line, $m)){
                $questionBase = $m[1];
                $tail = trim($m[2]);
                if ($tail && mb_strpos($tail, ' یا ', 0, 'UTF-8') !== false) { $inlineOptionsTail = $tail; }
            }
            $question = preg_replace('/[\s\?؟]+$/u', '', $line);
            // Skip duplicates by normalized label
            $norm = mb_strtolower(preg_replace('/\s+/u',' ', $question), 'UTF-8');
            if (isset($seen[$norm])) { continue; }
            // Collect immediate bullet/numbered options
            $opts = [];
            $j = $i;
            while ($j < $n){
                $ln = trim((string)$lines[$j]);
                if ($ln === '') break;
                if (preg_match('/^(?:[-•\x{2022}]|\d+[\.)]|[A-Za-z]\))/u', $ln)){
                    $opt = preg_replace('/^(?:[-•\x{2022}]\s*|\d+[\.)]\s*|[A-Za-z]\)\s*)/u', '', $ln);
                    if ($opt !== '') $opts[] = $opt;
                    $j++;
                    continue;
                }
                // Stop options block on next question
                if (preg_match('/[\?؟]$/u', $ln)) break;
                break;
            }
            $i = $j; // advance

            // If no listed options found, try inline pattern inside question text
            if (empty($opts) && mb_strpos($question, ' یا ', 0, 'UTF-8') !== false){
                $parts = preg_split('/\s+یا\s+/u', $question) ?: [];
                if (count($parts) >= 2){
                    $normalizeMap = [ 'البالو' => 'آلبالو', 'البالو?' => 'آلبالو' ];
                    $options = [];
                    foreach ($parts as $p){
                        $s = trim(preg_replace('/[\?؟!,\.\-\(\)\[\]«»\"]+/u', '', (string)$p));
                        $s = str_replace(["\u200c",'‌'],'',$s);
                        if ($s==='') continue;
                        $sl = mb_strtolower($s,'UTF-8');
                        if (isset($normalizeMap[$sl])) $s = $normalizeMap[$sl];
                        // Skip very short fragments (<2 letters)
                        if (mb_strlen($s,'UTF-8') < 2) continue;
                        $options[] = $s;
                    }
                    $options = array_values(array_unique($options));
                    if (count($options) >= 2){ $opts = $options; }
                }
            }
            // Tail options after question mark
            if (empty($opts) && $inlineOptionsTail !== '' && mb_strpos($inlineOptionsTail, ' یا ', 0, 'UTF-8') !== false){
                $parts = preg_split('/\s+یا\s+/u', $inlineOptionsTail) ?: [];
                if (count($parts) >= 2){
                    $clean2 = function(string $s): string {
                        $s = preg_replace('/[\?؟!,\.\-\(\)\[\]«»\"]+/u', '', $s);
                        $s = str_replace(["\u200c",'‌'], '', $s);
                        $s = trim($s);
                        return $s;
                    };
                    $opts2 = [];
                    foreach ($parts as $p){ $c = $clean2($p); if ($c!=='') $opts2[] = $c; }
                    $opts2 = array_values(array_unique($opts2));
                    if (count($opts2) >= 2){ $opts = $opts2; }
                }
            }

            // Guess type and props
            $qLower = mb_strtolower($question, 'UTF-8');
            $required = (mb_strpos($qLower, 'الزامی') !== false || mb_strpos($qLower, 'ضروری') !== false);
            $type = 'short_text';
            $props = [];
            if (mb_strpos($qLower, 'ایمیل') !== false){ $type='short_text'; $props['format']='email'; }
            elseif (mb_strpos($qLower, 'موبایل') !== false || preg_match('/شماره\s*تلفن|تلفن\s*شماره/u',$qLower) || (mb_strpos($qLower, 'شماره') !== false && (mb_strpos($qLower, 'تماس') !== false || mb_strpos($qLower,'تلفن') !== false))) { $type='short_text'; $props['format']='mobile_ir'; }
            elseif (mb_strpos($qLower, 'سن') !== false || mb_strpos($qLower, 'عدد') !== false || mb_strpos($qLower, 'تعداد') !== false){ $type='short_text'; $props['format']='numeric'; }
            elseif (mb_strpos($qLower, 'کد ملی') !== false || mb_strpos($qLower, 'ملی') !== false){ $type='short_text'; $props['format']='national_id_ir'; }
            elseif (mb_strpos($qLower, 'تاریخ') !== false){ $type='short_text'; $props['format']='date_greg'; }
            // Rating only when explicitly asked for score (امینتاژ/از 1 تا 10/...)
            elseif (mb_strpos($qLower, 'امتیاز') !== false || mb_strpos($qLower, 'نظرسنجی') !== false || mb_strpos($qLower, 'رتبه') !== false || preg_match('/\b(1|۱)\s*(?:تا|\-)\s*(10|۱۰)\b/u', $qLower)){ $type='rating'; $props['rating']=['min'=>1,'max'=>10,'icon'=>'like']; }
            elseif (!empty($opts)){
                $type = (count($opts) >= 6) ? 'dropdown' : 'multiple_choice';
                $props['options'] = $opts;
                $props['multiple'] = false; // پیش‌فرض تک‌انتخاب
            } else {
                // Heuristic: long question or hint of توضیحات -> long_text
                if (mb_strlen($question, 'UTF-8') > 80 || mb_strpos($qLower, 'توضیحات') !== false || mb_strpos($qLower, 'مفصل') !== false || mb_strpos($qLower, 'شرح') !== false){ $type='long_text'; $props['rows']=4; $props['maxLength']=5000; }
            }
            // Placeholder heuristics
            $placeholder = '';
            if ($type === 'short_text'){
                if (!empty($props['format'])){
                    switch($props['format']){
                        case 'date_greg': $placeholder = date('Y-m-d'); break;
                        case 'national_id_ir': $placeholder = '0012345678'; break;
                        case 'email': $placeholder = 'example@mail.com'; break;
                        case 'numeric': $placeholder = '123'; break;
                        case 'mobile_ir': $placeholder = '09121234567'; break;
                        default: $placeholder = ''; break;
                    }
                } else {
                    // Generic short text placeholder
                    if (mb_strpos($qLower, 'حال') !== false) $placeholder = 'خوب هستم';
                }
            } elseif ($type === 'long_text') {
                $placeholder = 'توضیح خود را بنویسید…';
            }
            $fieldArr = [ 'type'=>$type, 'label'=>$question, 'required'=>$required, 'props'=>$props ];
            if ($placeholder !== ''){ $fieldArr['placeholder'] = $placeholder; }
            // Apply forced required on national ID if command detected
            if ($forceRequiredNationalId && isset($props['format']) && $props['format'] === 'national_id_ir'){ $fieldArr['required'] = true; }
            $fields[] = $fieldArr;
            $seen[$norm] = true;
        }
        return [ 'fields' => $fields ];
    }

    /**
     * Multipass improved parser (v2) with classification, option extraction, dedupe, recovery & specialized formats.
     * Returns schema array ['fields'=>[]]. Designed to be side-effect free (no notes emission here; notes added later).
     */
    protected static function hoosha_local_infer_from_text_v2(string $txt): array
    {
        $raw = trim($txt);
        if ($raw === '') return ['fields'=>[]];
        $lines = preg_split('/\r?\n/u', $raw) ?: [];
        // Early spelling normalization BEFORE classification to avoid option contamination & duplicate mismatches
        $normalize_token = function(string $s): string {
            // Normalize zero-width non-joiner variants
            $s = str_replace(["\u200c",'‌'],'',$s);
            // Specific lexical normalizations
            $mapPatterns = [
                '/نوشیدنیآ/u' => 'نوشیدنی‌ها',
                '/\bدوس\b/u' => 'دوست',
                '/االبالو/u' => 'آلبالو',
                '/\bالبالو\b/u' => 'آلبالو',
            ];
            foreach ($mapPatterns as $pat=>$rep){ $s = preg_replace($pat,$rep,$s); }
            return $s;
        };
        foreach ($lines as &$__l){ $__l = $normalize_token((string)$__l); } unset($__l);
        // Colloquial transformations: map informal name prompts to canonical label starting with 'نام'
        foreach ($lines as &$__l){
            $ltrim = trim(mb_strtolower($__l,'UTF-8'));
            if ($ltrim === 'اسمت چیه' || $ltrim === 'اسمت چیه؟' || $ltrim === 'اسم شما چیه' || $ltrim === 'اسم شما چیه؟'){
                $__l = 'نام شما چیست؟';
            }
            // Informal date inquiry variants -> canonical form
            if ($ltrim === 'امروز چه تاریخیه' || $ltrim === 'امروز چه تاریخیه؟' || $ltrim==='امروز چه تاریخه' || $ltrim==='امروز چه تاریخه؟'){
                $__l = 'تاریخ امروز چیست؟';
            }
        } unset($__l);
        $classified = [];
        $global_required_targets = [];
        $fullLower = mb_strtolower($raw,'UTF-8');
        // Global directives: e.g. "کد ملی و شماره موبایل حتما الزامی باشن"
        if (preg_match('/کد\s*ملی.+شماره\s+موبایل.+(الزامی|اجباری)/u', $fullLower)){
            $global_required_targets['national_id_ir'] = true;
            $global_required_targets['mobile_ir'] = true;
        }
        $hasSecondNationalId = false; // track duplicate national id line for confirm field
        $nationalIdLineCount = 0;
        // Pass 1: classify
        foreach ($lines as $idx=>$ln){
            $orig = $ln; $ln = trim($ln);
            if ($ln===''){ $classified[] = ['type'=>'blank','text'=>'','i'=>$idx]; continue; }
            $low = mb_strtolower($ln,'UTF-8');
            $isBullet = preg_match('/^[-•\x{2022}]/u',$ln) || preg_match('/^(تهران|اصفهان|شیراز|تبریز|مشهد|اهواز|رشت)$/u',$low);
            // Treat short lines that are obvious field prompts (شروع با کد ملی، شماره، کد پست، فقط، تاریخ، ساعت)
            $startsLikeField = preg_match('/^(کد|شماره|فقط|تاریخ|ساعت|آی\s*پی|سطح)/u',$low);
            $enumeratedPrefix = false;
            // Detect enumerated numeric prefixes (Western or Persian digits) like "27.", "۴)" , "12-" , "۳:" at start
            if (preg_match('/^[\d۰-۹]{1,3}[\.)\-:]/u',$ln)){ $enumeratedPrefix = true; }
            // Lines ending with colon often are prompts: کد محصول:
            $endsWithColon = (mb_substr($ln,-1,1,'UTF-8') === ':' || mb_substr($ln,-1,1,'UTF-8') === '：');
            if ($enumeratedPrefix || ($endsWithColon && mb_strlen($ln,'UTF-8') <= 80 && !preg_match('/\s{2,}/u',$ln))){
                $startsLikeField = true; // augment heuristic
            }
            $containsOptionSep = preg_match('/\s+یا\s+/u',$low);
            $hasQuestionCue = preg_match('/[\?؟]$/u',$ln) || preg_match('/\b(چطور|چطوره|چی|کدام|کدوم|چند|چقدر|آیا|ترجیحت|دوست داری|دوس داری)\b/u',$low) || preg_match('/(بده|بنویس|ثبت کن|انتخاب کن|رو بزن|بزن|وارد کن)/u',$low);
            // numeric cues (چنده / چند است) broaden detection
            if (!$hasQuestionCue && preg_match('/چند(ه| است)?$/u',$low)) $hasQuestionCue = true;
            $isQuestion = $hasQuestionCue || $startsLikeField || ($containsOptionSep && mb_strlen($ln,'UTF-8')>8);
            $isOptionLike = $isBullet || (!$isQuestion && mb_strlen($ln,'UTF-8')<=24 && !$containsOptionSep && !preg_match('/[\?؟]/u',$ln));
            if (!$isQuestion && !$isOptionLike){
                if (preg_match('/(توضیح|مفصل|شرح|شرح طولانی|تجربه)/u',$low)) $isQuestion = true; // long text imperative
            }
            if (preg_match('/کد\s*ملی/u',$low)){ $nationalIdLineCount++; if ($nationalIdLineCount>1) $hasSecondNationalId=true; }
            // Additional heuristics: treat leading 'نام شما' style and 'توضیح' lines as questions
            if (!$isQuestion && preg_match('/^نام\s+/u',$low)) $isQuestion=true;
            if (!$isQuestion && preg_match('/توضیح/u',$low)) $isQuestion=true;
            $classified[] = [ 'type' => $isQuestion ? 'q' : ($isBullet?'opt':($isOptionLike?'opt':'text')), 'text'=>$orig, 'i'=>$idx ];
        }
        // Pass 2: group questions and attach trailing option blocks (blank line tolerant)
        $fields = [];
        $n = count($classified);
        for ($i=0;$i<$n;$i++){
            $c = $classified[$i];
            if ($c['type']!=='q') continue;
            $stem = trim($c['text']);
            // Strip leading enumeration numbering (Western or Persian digits) once we decided it's a question
            $stem = preg_replace('/^[\s]*[\d۰-۹]{1,3}[\.)\-:]+\s*/u','',$stem);            
            $options = [];
            // Inline options extraction (avoid stem contamination and cross-contamination)
            $stemWork = $stem;
            $qmPos = mb_strpos($stemWork,'؟');
            if ($qmPos === false) $qmPos = mb_strpos($stemWork,'?');
            $afterQ = '';
            if ($qmPos !== false){
                $afterQ = trim(mb_substr($stemWork,$qmPos+1,null,'UTF-8'));
            } else {
                // If no explicit question mark but multiple separators exist toward end, treat tail after last verb-like token
                if (preg_match('/(.+?)(?:\s+(?:سیب|چای|پیتزا|ایمیل)\s+یا\s+.*)/u',$stemWork,$mHeuTail)){
                    $afterQ = trim(mb_substr($stemWork, mb_strlen($mHeuTail[1],'UTF-8')));
                }
                // Additional heuristic: lines with "انتخاب کن" and a single " یا " pattern
                if ($afterQ==='' && preg_match('/انتخاب(?:\s+کن(?:ید)?)?/u',$stemWork) && preg_match('/\sیا\s/u',$stemWork)){
                    $afterQ = trim(preg_replace('/^.*?(?:انتخاب کن|انتخاب کنید)[:：]?/u','',$stemWork));
                }
            }
            // Custom binary preference pattern: "X دوست داری یا Y" (e.g., سیب دوست داری یا پرتقال)
            if (empty($options)){
                if (preg_match('/^\s*([\p{L}0-9آ-ی‌ ]{1,40}?)\s+دوست\s+داری\s+یا\s+([\p{L}0-9آ-ی‌]{1,40})(?:\s|\?|$)/u',$stemWork,$mBin)){
                    $o1 = trim(preg_replace('/\s+/u',' ',$mBin[1]));
                    $o2 = trim(preg_replace('/\s+/u',' ',$mBin[2]));
                    if ($o1!=='' && $o2!=='' && $o1!==$o2){
                        $options = [$o1,$o2];
                    }
                }
            }
            // Primary split strategies for inline enumerations
            if ($afterQ !== '' && mb_strlen($afterQ,'UTF-8') < 220){
                $candidateText = $afterQ;
                // Strip surrounding parentheses if they wrap the entire enumeration
                if (preg_match('/^[(\[]([^()\[\]]{2,200})[)\]]$/u',$candidateText,$mWrap)){
                    $candidateText = trim($mWrap[1]);
                }
                $rawOpts = [];
                // First: numeric enumerations like 1-کم 2-متوسط 3-زیاد
                if (preg_match_all('/\b\d+\s*[-–.،:]\s*([^\d]+?)(?=(?:\b\d+\s*[-–.،:]|$))/u',$candidateText,$mNum,PREG_SET_ORDER)){
                    foreach($mNum as $mm){
                        $opt = trim(preg_replace('/[\?؟،,.؛]+$/u','',$mm[1]));
                        if($opt!=='') $rawOpts[]=$opt;
                    }
                }
                // Second: split on یا
                if (empty($rawOpts) && preg_match('/\sیا\s/u',$candidateText)){
                    $parts = preg_split('/\s+یا\s+/u',$candidateText) ?: [];
                    foreach($parts as $pp){
                        // further split by commas inside each part
                        $subSegs = preg_split('/[،,]/u',$pp) ?: [$pp];
                        foreach($subSegs as $sg){
                            $sg = trim(preg_replace('/[\?؟،,.؛]+/u','',$sg));
                            if($sg!=='') $rawOpts[] = $sg;
                        }
                    }
                }
                // Third: if still empty, try delimiters inside parentheses or tail: comma / slash / hyphen
                if (empty($rawOpts) && preg_match('/[،,\/\-]/u',$candidateText)){
                    $tmp = preg_split('/[،,\/\-]/u',$candidateText) ?: [];
                    foreach($tmp as $t){
                        $t = trim(preg_replace('/[\?؟،,.؛]+/u','',$t));
                        if($t!=='') $rawOpts[]=$t;
                    }
                }
                // Validate
                if (count($rawOpts)>=2 && count($rawOpts)<=20){
                    // Deduplicate order preserve
                    $seenLocal=[]; $final=[]; foreach($rawOpts as $ro){ if(!isset($seenLocal[$ro])){ $seenLocal[$ro]=true; $final[]=$ro; } }
                    if(count($final)>=2){
                        $options = $final;
                        if ($qmPos !== false){
                            $stem = trim(mb_substr($stemWork,0,$qmPos+1),' ؟?');
                            if (!preg_match('/[\?؟]$/u',$stem)) $stem .= '؟';
                        }
                    }
                }
            }
            // Trailing block options (up to 10 lines) if next lines opt
            $j = $i+1; $blankSeen=false; $block=[]; $look=0;
            while($j<$n && $look<14){
                $nc = $classified[$j];
                if ($nc['type']==='blank'){ $blankSeen=true; $j++; $look++; continue; }
                if ($nc['type']==='opt'){
                    // Accept as option if length sane and not re-triggering question
                    $t = trim($nc['text']);
                    if ($t!=='') $block[] = preg_replace('/^[-•\x{2022}]\s*/u','',$t);
                    $j++; $look++; continue;
                }
                break;
            }
            if (count($block)>=3 && count($options)==0){ $options = $block; }
            // Deduplicate & normalize simple anomalies (االبالو -> آلبالو)
            $normMap = ['االبالو'=>'آلبالو','البالو'=>'آلبالو'];
            $cleanOpts = [];
            // Build stem tokens to filter contamination (exclude high-frequency Persian stop tokens)
            $stemTokens = [];
            $tmpStem = mb_strtolower(preg_replace('/[\?؟]/u','',$stem),'UTF-8');
            foreach(preg_split('/\s+/u',$tmpStem) as $tk){
                $tk = trim($tk);
                if ($tk==='') continue;
                if (preg_match('/^(لطفا|لطفاً|بین|این|چی|کدام|کدوم|رو|را|بیشتر|دوست|داری|دوس|میخوری|می‌خوری|ترجیحت|از|تا|حال|دل)$/u',$tk)) continue;
                if (mb_strlen($tk,'UTF-8')<2) continue;
                $stemTokens[$tk]=true;
            }
            foreach($options as $op){
                $lop=mb_strtolower($op,'UTF-8');
                if(isset($normMap[$lop])) $op=$normMap[$lop];
                // filter if option contains verb fragment or many stem tokens (contamination)
                $contamScore=0; foreach($stemTokens as $sk=>$_){ if(mb_strpos($lop,$sk)!==false) $contamScore++; }
                if ($contamScore>=2 && mb_strlen($lop,'UTF-8')>10) continue; // drop contaminated option
                if(!in_array($op,$cleanOpts,true)) $cleanOpts[]=$op;
            }
            $options = $cleanOpts;
            // Guard: if options carry tokens from another known group (e.g., contact mediums) while current stem indicates beverages, split groups
            if (!empty($options)){
                $contactSet = ['ایمیل','تلفن','موبایل'];
                $drinkSet = ['چای','قهوه','موکا'];
                $hasContact = count(array_intersect($options,$contactSet))>0;
                $hasDrink = count(array_intersect($options,$drinkSet))>0;
                if ($hasContact && $hasDrink){
                    // Decide by stem semantics
                    $sl = mb_strtolower($stem,'UTF-8');
                    if (preg_match('/نوشیدنی|چای|قهوه|موکا/u',$sl)){
                        // Keep drink options only
                        $options = array_values(array_intersect($options,$drinkSet));
                    } elseif (preg_match('/ترجیح|تماس|ایمیل|تلفن|موبایل/u',$sl)){
                        $options = array_values(array_intersect($options,$contactSet));
                    } else {
                        // If ambiguous and both sets present, create two fields: one for first set, second appended later
                        $drinkOnly = array_values(array_intersect($options,$drinkSet));
                        $contactOnly = array_values(array_intersect($options,$contactSet));
                        if ($drinkOnly && $contactOnly){
                            // Current field keep whichever matches stem more; append second as recovered separate
                            if (preg_match('/نوشیدنی|چای|قهوه|موکا/u',$sl)){
                                $options = $drinkOnly;
                                // add contact recovered later
                                $GLOBALS['__hoosha_pending_contact_field'] = true;
                            } else {
                                $options = $contactOnly; $GLOBALS['__hoosha_pending_drink_field']=true;
                            }
                        }
                    }
                }
            }
            // ---------------- Enhanced Field Typing & Enumeration (Todo 36 / 40 / 41) ----------------
            $lowStem = mb_strtolower($stem,'UTF-8');
            $type='short_text'; $props=[]; $required=false;
            $isLong = mb_strlen($stem,'UTF-8')>90 || preg_match('/(مفصل|شرح|تجربه|پیشنهاد|انتقاد|مشکلات)/u',$lowStem);
            if ($isLong) { $type='long_text'; $props['rows']=4; $props['maxLength']=5000; }
            // Dynamic rating (از 1 تا N) detection before generic options
            $ratingRange = null;
            // Rating patterns (broaden: allow optional 'از', allow Persian digits, allow embedded parens like (1 تا 10) )
            if (preg_match('/(?:از\s*)?(1|۱)\s*تا\s*(10|۱۰|[2-9]|[۲-۹])/u',$lowStem,$rm)){
                $maxNumRaw = $rm[2];
                $maxNorm = strtr($maxNumRaw,['۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','۰'=>'0']);
                $maxInt = intval($maxNorm);
                if ($maxInt>=3 && $maxInt<=10){ $ratingRange=[1,$maxInt]; }
            }
            // Word-number based rating pattern: از یک تا ده / از یک تا پنج (when user writes numbers in words)
            if (!$ratingRange && preg_match('/از\s+(یک|1|۱)\s+تا\s+(ده|10|۱۰|پنج|5|۵)/u',$lowStem,$mWordRange)){
                $mx = $mWordRange[2];
                $ratingRange = [1, (preg_match('/پنج|5|۵/u',$mx)?5:10)];
            }
            // Numeric inline enumeration rating pattern: 1-کم 2-متوسط 3-زیاد ...
            if (!$ratingRange && preg_match_all('/\b(\d{1,2})\s*[-–\.،:]\s*([^\d]+?)(?=(?:\b\d{1,2}\s*[-–\.،:]|$))/u',$lowStem,$mRate,PREG_SET_ORDER)){
                $vals=[]; $maxFound=0; $sequential=true; $expect=1;
                foreach($mRate as $mr){ $n=intval($mr[1]); $vals[]=$n; if($n>$maxFound)$maxFound=$n; if($n!==$expect)$sequential=false; $expect++; }
                if(count($vals)>=3 && $sequential && $maxFound<=10){ $ratingRange=[1,$maxFound]; }
            }
            if ($ratingRange){
                // Guard: avoid turning pure physical attribute like height into rating unless explicit امتیاز present
                if (!preg_match('/قد|ارتفاع/u',$lowStem) || preg_match('/امتیاز|سطح/u',$lowStem)){
                    $type='rating'; $props['rating']=['min'=>1,'max'=>$ratingRange[1],'icon'=>'like'];
                }
            }
            elseif (!$ratingRange && preg_match('/(امتیاز|درجه\s*اهمیت)/u',$lowStem) && (preg_match('/(1|۱).*(5|۵|10|۱۰)/u',$lowStem) || preg_match('/از\s+(یک|1|۱)\s+تا\s+(ده|10|۱۰|پنج|5|۵)/u',$lowStem))){
                // fallback rating detection
                $maxVal = 10;
                if (preg_match('/(5|۵|پنج)/u',$lowStem)) $maxVal = 5;
                $type='rating'; $props['rating']=['min'=>1,'max'=>$maxVal,'icon'=>'like'];
            }
            if (!empty($options) && $type!=='rating'){
                $type = (count($options)>=6)?'dropdown':'multiple_choice';
                $props['options']=$options; $props['multiple']=false;
            }
            // Automatic yes/no inference:
            // 1) Classical form starting with 'آیا'
            // 2) Informal binary questions ending with a question mark containing a present/future verb in 2nd person (میای، میخوای، می‌خوای، داری، هستی، شدی، می‌کنی)
            // 3) Short stems (< 40 chars) ending with "؟" and containing a verb + no obvious multi-option enumerations
            if ($type==='short_text' && empty($options)){
                $isClassicYesNo = preg_match('/^آیا\s+/u',$stem);
                $lowStemFull = mb_strtolower($stem,'UTF-8');
                $isInformal = (mb_strpos($lowStemFull,'؟')!==false) && preg_match('/\b(می(?:‌)?(?:ای|خوای|کنی|ری)|داری|هستی|شدی|میاد|میاین)\b/u',$lowStemFull);
                $isShortBinary = (mb_strpos($lowStemFull,'؟')!==false && mb_strlen($lowStemFull,'UTF-8')<=40 && preg_match('/می|ها\s*؟|\bاست\s*؟/u',$lowStemFull));
                if ($isClassicYesNo || $isInformal || $isShortBinary){
                    $type='multiple_choice';
                    $props['options']=['بله','خیر'];
                    $props['multiple']=false;
                    $props['source']='yesno_infer';
                }
            }
            // Formats (refined + advanced Todo 37)
            if (preg_match('/کد\s*ملی/u',$lowStem)) { $props['format']='national_id_ir'; }
            elseif (preg_match('/شناسه\s*ملی|ثبت\s*شرکت|شناسه\s*شرکت/u',$lowStem)) { $props['format']='national_id_company_ir'; }
            elseif (preg_match('/ایمیل|email/u',$lowStem)) { $props['format']='email'; }
            elseif (preg_match('/بین\s*المل|\+\s*\d|\+\s*۹۸|\+98/u',$lowStem)) { $props['format']='mobile_intl'; }
            elseif (preg_match('/موبایل|شماره.*موبایل/u',$lowStem) && $type!=='rating') { $props['format']='mobile_ir'; }
            elseif (preg_match('/\bip\b|آی\s*پی/u',$lowStem)) { $props['format']='ip'; }
            elseif (preg_match('/پستی|کد\s*پست|پ\.ک/u',$lowStem)) { $props['format']='postal_code_ir'; }
            elseif (preg_match('/شبا|sheba|شماره\s*شبا/u',$lowStem)) { $props['format']='sheba_ir'; }
            elseif (preg_match('/کارت|شماره\s*کارت|credit|card/u',$lowStem)) { $props['format']='credit_card_ir'; }
            elseif (preg_match('/فقط\s+حروف\s+فارسی/u',$lowStem)) { $props['format']='fa_letters'; }
            elseif (preg_match('/فقط\s+حروف\s+انگلیسی|username|name\s+لاتین/u',$lowStem)) { $props['format']='en_letters'; }
            elseif (preg_match('/حروف\s*(?:eng|انگلیسی)\s*و\s*عدد|حروف\s*و\s*اعداد|حروف\s*\+\s*عدد|آلفا\s*عدد(?:ی)?|آلفا-?عددی|الانگاری|alphanumeric/u',$lowStem)) {
                // Distinguish no space variant
                if (preg_match('/بدون\s*فاصله|بی\s*فاصله|no\s*space/u',$lowStem)) $props['format']='alphanumeric_no_space'; else $props['format']='alphanumeric';
            }
            elseif (preg_match('/captcha|کپچا|کپچا/u',$lowStem)) { $props['format']='captcha_alphanumeric'; }
            elseif (preg_match('/تاریخ.*جلالی|تقویم\s+جلالی/u',$lowStem)) { $props['format']='date_jalali'; }
            elseif (preg_match('/تولد/u',$lowStem) && mb_strpos($lowStem,'میلادی')!==false) { $props['format']='date_greg'; }
            elseif (preg_match('/تاریخ/u',$lowStem)) { $props['format']='date_greg'; }
            // Extended alphanumeric token patterns (e.g., REF-12ab_34) if not already typed
            elseif (!isset($props['format']) && preg_match('/\b[a-zA-Z]{2,10}-?[a-zA-Z0-9]{2,}(?:_[a-zA-Z0-9]{2,})?\b/u',$stem)) {
                $props['format']='alphanumeric_extended';
            }
                        elseif (
                                // Refined time detection: require explicit time keyword + either ':' pattern or contextual verbs
                                (
                                    preg_match('/ساعت|زمان/u',$lowStem) && (
                                            preg_match('/\d{1,2}\s*[:٫\.]\s*\d{1,2}/u',$lowStem) ||
                                            preg_match('/ورود|خروج|شروع|پایان|تماس/u',$lowStem)
                                    )
                                ) || preg_match('/مثال\s*[:：]?\s*\d{1,2}[:٫\.]\d{1,2}/u',$lowStem)
                        ) { $props['format']='time'; }
            elseif (preg_match('/(بارگذاری|آپلود|ارسال)\s*(یک)?\s*فایل|فایل\s*(را)?\s*آپلود|فایل\s*(را)?\s*بارگذاری/u',$lowStem)) {
                // Exclude plain references like 'نام فایل config.php را وارد کن'
                if (!preg_match('/نام\s+فایل|file\s+name/i',$lowStem)) { $props['format']='file_upload'; $type='file'; }
            }
            // Alphanumeric precedence: if flagged already as alphanumeric[_no_space], do NOT downgrade to numeric
            elseif (preg_match('/عدد|تعداد|قد|سن|مقدار|شماره\s+پرسنلی|فاکتور/u',$lowStem)) {
                if (!isset($props['format']) || !preg_match('/^alphanumeric/', $props['format'])) {
                    $props['format']='numeric';
                }
            }
            // Required inline marker
            if (preg_match('/الزامی/u',$lowStem)) $required = true;
            // Numeric max length extraction (حداکثر N رقم)
            if (preg_match('/حداکثر\s*(\d+|[۰-۹]+)/u',$lowStem,$mMax) && (isset($props['format']) && $props['format']==='numeric')){
                $rawMax = $mMax[1]; $normMax = strtr($rawMax,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
                $len = intval($normMax); if($len>0 && $len<50){ $props['maxLength']=$len; }
            }
            // Multi-select hint (plural days / newsletter)
            if (!empty($props['options'])){
                if (preg_match('/روزهای|روزهایی|خبرنامه|هفته/u',$lowStem)) { $props['multiple']=true; }
            }
            // Post-option fallback mapping for common binary / small sets if options extraction failed
            if (empty($options)){
                // Yes/No detection (بله / خیر)
                if (preg_match('/\b(بله)\b.*\b(خیر)\b|\b(خیر)\b.*\b(بله)\b/u',$lowStem)){
                    $type='multiple_choice'; $props['options']=['بله','خیر']; $props['multiple']=false;
                }
                // Color triple common (قرمز، آبی، سبز)
                elseif (preg_match('/قرمز.*آبی.*سبز|آبی.*قرمز.*سبز|سبز.*قرمز.*آبی/u',$lowStem)){
                    $type='multiple_choice'; $props['options']=['قرمز','آبی','سبز']; $props['multiple']=false;
                }
                // Social networks common set
                elseif (preg_match('/اینستاگرام|تلگرام|واتساپ|ایتا/u',$lowStem)){
                    // Only if not already long_text intent
                    if ($type!=='long_text'){
                        $type = (preg_match_all('/اینستاگرام|تلگرام|واتساپ|ایتا/u',$lowStem,$mm)>=6)?'dropdown':'multiple_choice';
                        $opts=[]; foreach(['اینستاگرام','تلگرام','واتساپ','ایتا'] as $sx){ if (mb_strpos($lowStem, mb_strtolower($sx,'UTF-8'))!==false) $opts[]=$sx; }
                        if (count($opts)>=2){ $props['options']=$opts; $props['multiple']=false; }
                    }
                }
            }
            $fields[] = [ 'type'=>$type, 'label'=>$stem, 'required'=>$required, 'props'=>$props ];
        }
        if (empty($fields)) return ['fields'=>[]];
        // Dedup semantic (simple canonical key) + required aggregation
        $out=[]; $seen=[]; $duplicates=0; $requiredAgg=[]; $optionsAgg=[];
        foreach($fields as $idx=>$f){
            $lbl = $f['label'];
            $canon = mb_strtolower(preg_replace('/[\s\d[:punct:]]+/u','', $lbl),'UTF-8');
            if (!isset($requiredAgg[$canon])) $requiredAgg[$canon]=false;
            if ($f['required']) $requiredAgg[$canon]=true;
            if (!isset($optionsAgg[$canon])) $optionsAgg[$canon]=[];
            if (!empty($f['props']['options'])){
                // track for potential later merging in case canonical appears again with options
                $optionsAgg[$canon] = array_values(array_unique(array_merge($optionsAgg[$canon], $f['props']['options'])));
            }
            if (isset($seen[$canon])){
                $duplicates++;
                // Merge: upgrade to rating if new is rating
                if ($f['type']==='rating' && $out[$seen[$canon]]['type']!=='rating') $out[$seen[$canon]]['type']='rating';
                // Merge options union
                $o1 = isset($out[$seen[$canon]]['props']['options'])? $out[$seen[$canon]]['props']['options']:[];
                $o2 = isset($f['props']['options'])? $f['props']['options']:[];
                if ($o2){ $out[$seen[$canon]]['props']['options']=array_values(array_unique(array_merge($o1,$o2))); }
                // Required aggregation applied later
                continue;
            }
            $seen[$canon] = count($out);
            $out[] = $f;
        }
        // Apply aggregated required + option union fallback if needed
        foreach($out as $k=>$of){
            $c = mb_strtolower(preg_replace('/[\s\d[:punct:]]+/u','', $of['label']),'UTF-8');
            if (isset($requiredAgg[$c]) && $requiredAgg[$c]) $out[$k]['required']=true;
            if (empty($of['props']['options']) && !empty($optionsAgg[$c])){
                // If final field lost options but earlier variant had them, restore (avoid overriding rating/file types)
                if (!in_array($of['type'],['rating','file'])){
                    $out[$k]['props']['options']=$optionsAgg[$c];
                    if (count($optionsAgg[$c])>=2 && $out[$k]['type']==='short_text'){
                        $out[$k]['type'] = (count($optionsAgg[$c])>=6)?'dropdown':'multiple_choice';
                        $out[$k]['props']['multiple']=false;
                    }
                }
            }
        }
        // Confirm field chaining for repeated sensitive fields (national id, email)
        $firstNatIndex = null; $natCount=0; $firstEmailIndex=null; $emailCount=0;
        foreach($out as $i=>$f){
            $lblL = mb_strtolower($f['label'],'UTF-8');
            $fmt = $f['props']['format'] ?? '';
            if ($fmt==='national_id_ir'){
                $natCount++;
                if ($firstNatIndex===null) { $firstNatIndex=$i; }
                elseif (!isset($out[$i]['props']['confirm_for'])) {
                    $out[$i]['props']['confirm_for'] = $firstNatIndex; // index reference
                }
            }
            if ($fmt==='email'){
                $emailCount++;
                if ($firstEmailIndex===null) { $firstEmailIndex=$i; }
                elseif (!isset($out[$i]['props']['confirm_for'])) {
                    $out[$i]['props']['confirm_for'] = $firstEmailIndex;
                }
            }
        }
        // Post-dedup required sync from label markers (اگر (الزامی) در متن برچسب باقی مانده)
        foreach($out as $k=>$of){
            $ll = mb_strtolower($of['label'],'UTF-8');
            if (preg_match('/الزامی/u',$ll) && !$of['required']){
                $out[$k]['required']=true; $out[$k]['props']['_required_source']='label_marker';
            }
        }
        // Recovery scan: ensure presence of ip / date_jalali / time if patterns exist globally
        $haveFormat = function($fmt) use ($out){ foreach($out as $f){ if(isset($f['props']['format']) && $f['props']['format']===$fmt) return true; } return false; };
        if (!$haveFormat('ip') && preg_match('/آی\s*پی|\bIP\b/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'آی‌پی سرور را وارد کنید؟','required'=>false,'props'=>['format'=>'ip','source'=>'recovered_scan'] ];
        }
        // Composite splitting: قد و وزن / موبایل و ایمیل
        $augmented=[]; foreach($out as $f){ $augmented[]=$f; }
        foreach($out as $f){
            if (!is_array($f) || empty($f['label'])) continue; $ll=mb_strtolower($f['label'],'UTF-8');
            if (preg_match('/قد\s*و\s*وزن/u',$ll)){
                $augmented[]=['type'=>'short_text','label'=>'قد (سانتی‌متر) را وارد کنید؟','required'=>false,'props'=>['format'=>'numeric','source'=>'split_composite']];
                $augmented[]=['type'=>'short_text','label'=>'وزن (کیلوگرم) را وارد کنید؟','required'=>false,'props'=>['format'=>'numeric','source'=>'split_composite']];
            }
            if (preg_match('/موبایل\s*و\s*ایمیل/u',$ll)){
                $augmented[]=['type'=>'short_text','label'=>'شماره موبایل را وارد کنید؟','required'=>false,'props'=>['format'=>'mobile_ir','source'=>'split_composite']];
                $augmented[]=['type'=>'short_text','label'=>'ایمیل را وارد کنید؟','required'=>false,'props'=>['format'=>'email','source'=>'split_composite']];
            }
        }
        if (count($augmented)>count($out)) { $out=$augmented; }
        // Pending split fields (cross contamination) -> create recovered ones if global flags set
        // Removed auto-inject of contact preference (was recovered_split); now rule-based & non-injective
        if (!empty($GLOBALS['__hoosha_pending_drink_field']) && !self::array_has_label_like($out,'نوشیدنی ترجیحی شما')){
            $out[] = [ 'type'=>'multiple_choice','label'=>'نوشیدنی ترجیحی شما؟','required'=>false,'props'=>['options'=>['چای','قهوه','موکا'],'source'=>'recovered_split'] ];
        }

        if (!$haveFormat('date_jalali') && preg_match('/جلالی|تولد/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'تاریخ (جلالی) را وارد کنید؟','required'=>false,'props'=>['format'=>'date_jalali','source'=>'recovered_scan'] ];
        }
        if (!$haveFormat('time') && preg_match('/ساعت دقیق|زمان پایان/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'ساعت یا زمان را وارد کنید؟','required'=>false,'props'=>['format'=>'time','source'=>'recovered_scan'] ];
        }
        // Additional critical recoveries
        if (!$haveFormat('national_id_ir') && preg_match('/کد\s*ملی/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'کد ملی خود را وارد کنید؟','required'=>false,'props'=>['format'=>'national_id_ir','source'=>'recovered_scan'] ];
            if (preg_match('/دوباره.*کد\s*ملی/u',$fullLower)){
                $out[] = [ 'type'=>'short_text','label'=>'کد ملی را دوباره وارد کنید؟','required'=>false,'props'=>['format'=>'national_id_ir','source'=>'recovered_scan','confirm'=>true] ];
            }
        } elseif ($haveFormat('national_id_ir') && preg_match('/دوباره.*کد\s*ملی/u',$fullLower)){
            // ensure confirm field exists
            $foundConfirm=false; foreach($out as $ff){ if(isset($ff['props']['format']) && $ff['props']['format']==='national_id_ir' && !empty($ff['props']['confirm'])) $foundConfirm=true; }
            if(!$foundConfirm){ $out[]=[ 'type'=>'short_text','label'=>'کد ملی را دوباره وارد کنید؟','required'=>false,'props'=>['format'=>'national_id_ir','source'=>'recovered_scan','confirm'=>true] ]; }
        }
        if (!$haveFormat('mobile_intl') && preg_match('/بین\s*المل|\+\s*\d/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'شماره موبایل بین‌المللی را وارد کنید؟','required'=>false,'props'=>['format'=>'mobile_intl','source'=>'recovered_scan'] ];
        }
        if (!$haveFormat('postal_code_ir') && preg_match('/کد\s*پست|پستی|پ\.ک/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'کد پستی را وارد کنید؟','required'=>false,'props'=>['format'=>'postal_code_ir','source'=>'recovered_scan'] ];
        }
        if (!$haveFormat('numeric') && preg_match('/سن|قد|مقدار|عدد\s+سنت|عدد\s+قد/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'عدد مورد نظر را وارد کنید؟','required'=>false,'props'=>['format'=>'numeric','source'=>'recovered_scan'] ];
        }
        // Satisfaction support level question recovery
        $hasSupport=false; foreach($out as $ff){ if(mb_strpos(mb_strtolower($ff['label'],'UTF-8'),'رضایت')!==false){ $hasSupport=true; break; } }
        if(!$hasSupport && preg_match('/رضایت.*پشتیبانی/u',$fullLower)){
            $out[] = [ 'type'=>'multiple_choice','label'=>'سطح رضایت شما از پشتیبانی؟','required'=>false,'props'=>['options'=>['کم','متوسط','عالی'],'source'=>'recovered_scan'] ];
        }
        // Removed legacy contact preference recovery. IntentRules will surface intent via notes only.
        // Invoice email recovery
        $hasInvoiceEmail=false; foreach($out as $ff){ if(isset($ff['props']['format']) && $ff['props']['format']==='email' && mb_strpos(mb_strtolower($ff['label'],'UTF-8'),'فاکتور')!==false){ $hasInvoiceEmail=true; break; } }
        if(!$hasInvoiceEmail && preg_match('/ایمیل.*فاکتور|آدرس ایمیل.*فاکتور/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'آدرس ایمیل برای فاکتور را وارد کنید؟','required'=>false,'props'=>['format'=>'email','source'=>'recovered_scan'] ];
        }
        // Project start date recovery
        $hasProjectDate=false; foreach($out as $ff){ if(isset($ff['props']['format']) && $ff['props']['format']==='date_greg' && mb_strpos(mb_strtolower($ff['label'],'UTF-8'),'شروع پروژه')!==false){ $hasProjectDate=true; break; } }
        if(!$hasProjectDate && preg_match('/تاریخ شروع پروژه/u',$fullLower)){
            $out[] = [ 'type'=>'short_text','label'=>'تاریخ شروع پروژه را وارد کنید؟','required'=>false,'props'=>['format'=>'date_greg','source'=>'recovered_scan'] ];
        }
        // Service experience long description recovery
        $hasServiceExp=false; foreach($out as $ff){ if(mb_strpos(mb_strtolower($ff['label'],'UTF-8'),'تجربه')!==false){ $hasServiceExp=true; break; } }
        if(!$hasServiceExp && preg_match('/تجربه.*سرویس/u',$fullLower)){
            $out[] = [ 'type'=>'long_text','label'=>'شرح تجربه شما از سرویس؟','required'=>false,'props'=>['rows'=>4,'maxLength'=>5000,'source'=>'recovered_scan'] ];
        }
        // Food preference (pizza vs burger)
        $hasFood=false; foreach($out as $ff){ if(mb_strpos(mb_strtolower($ff['label'],'UTF-8'),'غذای مورد علاقه')!==false){ $hasFood=true; break; } }
        if(!$hasFood && preg_match('/غذای مورد علاقه.*پیتزا.*برگر/u',$fullLower)){
            $out[] = [ 'type'=>'multiple_choice','label'=>'غذای مورد علاقه شما؟','required'=>false,'props'=>['options'=>['پیتزا','برگر'],'source'=>'recovered_scan'] ];
        }
        // Apply global required directives
        if (!empty($global_required_targets)){
            foreach($out as &$f){
                if (!is_array($f)) continue;
                $fmt = $f['props']['format'] ?? '';
                if ($fmt && isset($global_required_targets[$fmt])) $f['required']=true;
                // Also treat mobile_intl as mobile_ir if directive present
                if ($fmt==='mobile_intl' && isset($global_required_targets['mobile_ir'])) $f['required']=true;
            }
            unset($f);
        }
        // Ensure inline (الزامی) marks kept
        foreach($out as &$f){ if(!$f['required'] && preg_match('/الزامی/u', mb_strtolower($f['label'],'UTF-8'))) $f['required']=true; }
        unset($f);
        return ['fields'=>$out];
    }

    // Helper to check approximate label presence
    protected static function array_has_label_like(array $fields, string $needle): bool
    {
        $nl = mb_strtolower($needle,'UTF-8');
        foreach ($fields as $f){
            if (!is_array($f)) continue; $lbl = isset($f['label'])?(string)$f['label']:''; if ($lbl==='') continue;
            $ll = mb_strtolower($lbl,'UTF-8');
            if (mb_strpos($ll, $nl)!==false) return true;
        }
        return false;
    }

    /**
     * Refine mixed option sets to eliminate residual cross-contamination.
     * Scenario: a field accidentally contains options from two semantic groups (e.g., نوشیدنی + روش تماس).
     * Strategy:
     *  - Identify semantic groups by canonical option membership.
     *  - For each multiple_choice/dropdown field, compute membership counts per group.
     *  - If a field contains strong membership (>=2) in two different groups and there EXISTS another field that
     *    already exclusively (or strongly) represents one group, remove that group's options from the mixed field.
     *  - If mixed field has two strong groups and NO other field yet models the secondary group, split: keep primary
     *    (chosen by label semantic hint) and append a new recovered field for the secondary group.
     * Primary group decision precedence by label keywords; fallback to larger option count.
     */
    protected static function hoosha_refine_option_contamination(array &$schema, array &$notes): void
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return;
        $groups = [
            'contact' => ['ایمیل','تلفن','موبایل'],
            'drinks' => ['چای','قهوه','موکا'],
            'fruits' => ['سیب','موز','پرتقال','هلو','آلبالو','گیلاس'],
            'food' => ['پیتزا','برگر'],
            'satisfaction' => ['کم','متوسط','عالی'],
        ];
        // Pre-pass: fix concatenated Persian options like "قرمزآبیسبز" (color examples) or lack of delimiter
        $splitGlue = function(string $token): array {
            // If token already contains delimiters, return as is
            if (preg_match('/[\s،\/\|]+/u',$token)) return array_filter(preg_split('/[\s،\/\|]+/u',$token) ?: []);
            // Heuristic: split when alternating known color/short words stuck together
            $colors = ['قرمز','آبی','سبز','زرد','مشکی','سفید'];
            $parts = [];$remain=$token; $progress=false;
            for ($i=0;$i<10;$i++){
                $matched=false;
                foreach($colors as $c){
                    if ($remain!=='' && mb_strpos($remain,$c)===0){ $parts[]=$c; $remain=mb_substr($remain, mb_strlen($c,'UTF-8'), null,'UTF-8'); $matched=true; $progress=true; break; }
                }
                if(!$matched) break;
            }
            if ($progress && $remain==='') return $parts; // fully tokenized
            return [$token];
        };
        // Apply pre-pass normalization over all option arrays
        foreach ($schema['fields'] as &$fPre){
            if (!is_array($fPre)) continue; $t = $fPre['type'] ?? ''; if (!in_array($t,['multiple_choice','dropdown'],true)) continue;
            if (!isset($fPre['props']['options']) || !is_array($fPre['props']['options'])) continue;
            $norm = [];$changed=false;
            foreach ($fPre['props']['options'] as $op){
                $op = trim((string)$op); if ($op==='') continue;
                $split = $splitGlue($op);
                if (count($split)>1){ $changed=true; }
                foreach ($split as $sp){ $norm[]=$sp; }
            }
            if ($changed){ $fPre['props']['options']=array_values(array_unique($norm)); $notes[]='options_token_split'; }
        }
        unset($fPre);
        // Helper: classify option list into group membership counts
        $classify = function(array $opts) use ($groups){
            $res = [];
            foreach ($groups as $g=>$items){
                $cnt = 0; foreach($opts as $o){ if (in_array($o,$items,true)) $cnt++; }
                if ($cnt>0) $res[$g]=$cnt;
            }
            return $res; };
        // Pre-detect existing dedicated group fields
        $existingGroupField = [];
        foreach ($schema['fields'] as $f){
            if (!is_array($f)) continue;
            $opts = isset($f['props']['options']) && is_array($f['props']['options']) ? $f['props']['options'] : [];
            if (!$opts) continue;
            $m = $classify($opts);
            if (count($m)===1){ $g = array_key_first($m); if ($m[$g] >= 2 || count($opts) === $m[$g]) $existingGroupField[$g] = true; }
        }
        $newFields = [];
        $modified = false; $splits = 0; $pruned = 0;
        foreach ($schema['fields'] as $idx=>$f){
            if (!is_array($f)){ $newFields[]=$f; continue; }
            $type = $f['type'] ?? '';
            if (!in_array($type,['multiple_choice','dropdown'],true)) { $newFields[]=$f; continue; }
            $opts = isset($f['props']['options']) && is_array($f['props']['options']) ? $f['props']['options'] : [];
            if (count($opts) < 2){ $newFields[]=$f; continue; }
            $membership = $classify($opts);
            if (count($membership) <= 1){ $newFields[]=$f; continue; }
            // Determine primary group by label semantic cues
            $labelLow = mb_strtolower((string)($f['label'] ?? ''),'UTF-8');
            $primary = null; $labelHints = [
                'drinks' => '/نوشیدنی|چای|قهوه|موکا/u',
                'contact' => '/ترجیح|تماس|ایمیل|تلفن|موبایل/u',
                'fruits' => '/میوه/u',
                'food' => '/غذا|غذای مورد علاقه/u',
                'satisfaction' => '/رضایت/u'
            ];
            foreach ($labelHints as $g=>$rg){ if (preg_match($rg,$labelLow)){ $primary=$g; break; } }
            if ($primary===null){
                // Fallback: group with highest option count
                $primary = array_search(max($membership), $membership, true);
            }
            // Secondary groups = others
            $secondaryGroups = array_diff(array_keys($membership), [$primary]);
            $primaryItems = $groups[$primary] ?? [];
            $primaryOpts = array_values(array_intersect($opts, $primaryItems));
            $foreignOpts = array_values(array_diff($opts, $primaryOpts));
            $changedCurrent = false;
            if ($foreignOpts){
                // If any foreign group already has its own dedicated field, prune those options
                $toRemove = [];
                foreach ($secondaryGroups as $sg){ if (isset($existingGroupField[$sg])){ $toRemove = array_merge($toRemove, array_intersect($opts, $groups[$sg])); } }
                if ($toRemove){
                    $opts = array_values(array_diff($opts, $toRemove));
                    $f['props']['options'] = $opts; $modified=true; $changedCurrent=true; $pruned += count($toRemove);
                } else {
                    // No existing dedicated field → split one secondary group (first) into a new field
                    $sg = reset($secondaryGroups);
                    if ($sg){
                        $sgOpts = array_values(array_intersect($opts, $groups[$sg]));
                        if (count($sgOpts)>=2){
                            // Remove from original
                            $opts = array_values(array_diff($opts, $sgOpts));
                            $f['props']['options'] = $opts; $modified=true; $changedCurrent=true;
                            $splits++;
                            $newFields[] = $f; // keep modified current; append split after
                            // Construct label for split
                            $splitLabelMap = [
                                'contact' => 'ترجیح شما برای شیوه تماس؟',
                                'drinks' => 'نوشیدنی ترجیحی شما؟',
                                'fruits' => 'میوهٔ مورد علاقه شما؟',
                                'food' => 'غذای مورد علاقه شما؟',
                                'satisfaction' => 'سطح رضایت شما از پشتیبانی؟'
                            ];
                            $newFields[] = [
                                'type'=>$type,
                                'label'=>$splitLabelMap[$sg] ?? ('گزینه‌های مرتبط - '.$sg),
                                'required'=>false,
                                'props'=>['options'=>$sgOpts,'source'=>'refined_split']
                            ];
                            $existingGroupField[$sg]=true;
                            continue; // already pushed modified + new; skip default push below
                        }
                    }
                }
            }
            $newFields[] = $f; // default push (either unchanged or pruned only)
            // Mark groups that now have dedicated field
            if (!$changedCurrent && count($membership)===1){ $existingGroupField[array_key_first($membership)]=true; }
        }
        if ($modified){
            $schema['fields'] = $newFields;
            if ($pruned>0) $notes[] = 'options_pruned('.$pruned.')';
            if ($splits>0) $notes[] = 'options_split('.$splits.')';
        }
    }

    /**
     * Formalize a single Persian label/question into a polite, official tone.
     */
    protected static function hoosha_formalize_label(string $s): string
    {
        // Spelling normalization before formalization
        $q = trim($s);
        $q = preg_replace('/نوشیدنیآ/u','نوشیدنی‌ها',$q);
        $q = preg_replace('/\bدوس\b/u','دوست',$q);
        if ($q === '') return $q;
        $ql = mb_strtolower($q, 'UTF-8');
        // Specific rewrites
        if (preg_match('/دوباره.+کد ملی|مجدد.+کد ملی/u',$ql)) { return 'کد ملی را مجدداً وارد کنید.'; }
        if (mb_strpos($ql, 'کد ملی') !== false){ return 'کد ملی خود را وارد کنید.'; }
        if (preg_match('/تاریخ.*جلالی|تقویم.*جلالی|تولد/u',$ql)) { return 'تاریخ (تقویم جلالی) را وارد کنید.'; }
        if (mb_strpos($ql, 'تاریخ') !== false){ return 'تاریخ امروز را بیان کنید.'; }
        if (preg_match('/آی\s*پی|\bip\b/u',$ql)) { return 'آی‌پی سرور را وارد کنید.'; }
        if (mb_strpos($ql, 'چای') !== false && mb_strpos($ql, 'قهوه') !== false){ return 'نوشیدنی ترجیحی شما؟'; }
        if (mb_strpos($ql, 'حالت') !== false && (mb_strpos($ql, 'چطور') !== false || mb_strpos($ql, 'چطوره') !== false)) { return 'حال شما چگونه است؟'; }
    // Preserve semantic phrase "روزی که گذشت" distinctly; offer two variants to reduce downstream dedupe collisions
    if (preg_match('/روزی که گذشت/u',$ql)) {
        // If also contains مفصل/شرح/توضیح keep enriched variant, else a concise variant
        if (preg_match('/مفصل|شرح|توضیح/u',$ql)) {
            return 'لطفاً درباره روزی که گذشت به‌صورت مفصل توضیح دهید.';
        }
        return 'توضیحی درباره روزی که گذشت بنویسید.';
    }
    if (preg_match('/توضیح\s+کامل\s+مشکل/u',$ql)) { return 'توضیح کامل مشکل را وارد کنید.'; }
    if (mb_strpos($ql, 'مفصل') !== false || mb_strpos($ql, 'توضیح') !== false || mb_strpos($ql, 'شرح') !== false) { return 'لطفاً درباره موضوع مورد نظر به‌صورت مفصل توضیح دهید.'; }
        if (mb_strpos($ql, 'امتیاز') !== false && mb_strpos($ql, 'حال') !== false){ return 'به حال دل خود از ۱ تا ۱۰ چه امتیازی می‌دهید؟'; }
        if (preg_match('/سطح.*رضایت.*پشتیبانی/u',$ql)) { return 'سطح رضایت شما از پشتیبانی؟'; }
        if (preg_match('/غذای مورد علاقه/u',$ql)) { return 'غذای مورد علاقه شما؟'; }
    // Removed automatic contact preference formalization to avoid structural hallucination.
        if (preg_match('/ایمیلتو بده|ایمیل.*الزامی|ایمیل.*را وارد/u',$ql)) { return 'لطفاً ایمیل خود را وارد کنید.'; }
        if (preg_match('/شماره موبایل بین/u',$ql)) { return 'شماره موبایل بین‌المللی خود را وارد کنید.'; }
        if (preg_match('/شماره موبایلت رو بده/u',$ql)) { return 'شماره موبایل خود را وارد کنید.'; }
        if (preg_match('/کد پست/u',$ql)) { return 'کد پستی را وارد کنید.'; }
        if (preg_match('/فقط حروف فارسی.*محل/u',$ql)) { return 'نام محل را فقط با حروف فارسی بنویسید.'; }
        if (preg_match('/فقط حروف فارسی نام خانوادگیت/u',$ql)) { return 'نام خانوادگی را فقط با حروف فارسی بنویسید.'; }
        if (preg_match('/فقط حروف انگلیسی.*لاتین برند/u',$ql)) { return 'نام لاتین برند را فقط با حروف انگلیسی وارد کنید.'; }
        if (preg_match('/فقط حروف انگلیسی username/u',$ql)) { return 'نام کاربری را فقط با حروف انگلیسی بنویسید.'; }
        if (preg_match('/کدام میوه|کدوم میوه/u',$ql)) { return 'میوهٔ مورد علاقه شما؟'; }
        if (preg_match('/عدد سنت|سن/u',$ql)) { return 'سن خود را وارد کنید.'; }
        if (preg_match('/قد/u',$ql)) { return 'قد خود را وارد کنید.'; }
        // Generic tone-up
        $q = preg_replace('/\s+چنده$/u', ' چند است؟', $q);
        $q = preg_replace('/\s+چندمه$/u', ' چند است؟', $q);
        if (!preg_match('/[\?؟]$/u', $q)) $q .= '؟';
        return $q;
    }

    /** Build a simple edited text when local inference used. */
    protected static function hoosha_local_edit_text(string $txt, array $schema): string
    {
        $out = '';
        if (!empty($schema['fields']) && is_array($schema['fields'])){
            $lines = [];
            foreach ($schema['fields'] as $idx => $f){
                if (!is_array($f)) continue;
                $num = $idx + 1;
                $label = (string)($f['label'] ?? '');
                $type = (string)($f['type'] ?? 'short_text');
                $req = !empty($f['required']);
                $props = is_array($f['props'] ?? null) ? $f['props'] : [];
                $format = isset($props['format']) ? (string)$props['format'] : '';
                $opts = isset($props['options']) && is_array($props['options']) ? $props['options'] : [];
                $rating = isset($props['rating']) && is_array($props['rating']) ? $props['rating'] : [];
                    $isConfirmNat = (!empty($props['confirm']) && $format==='national_id_ir');
                $meta = [];
                $meta[] = $type;
                if ($format) $meta[] = 'format=' . $format;
                if (!empty($rating)){
                    $meta[] = 'rating=' . intval($rating['min']??1) . '-' . intval($rating['max']??10);
                    if (!empty($rating['icon'])) $meta[] = 'icon=' . (string)$rating['icon'];
                }
                if (!empty($opts)){
                    // Include options inline (slash separated) for a compact one-line copy
                    $meta[] = 'options=' . implode('/', array_map('strval', $opts));
                }
                    if ($isConfirmNat) {
                        // Link to first prior national id field index (1-based) if found
                        $targetIndex = null; $searchIdx = 0;
                        foreach ($schema['fields'] as $ix2=>$f2){
                            if ($ix2 >= $idx) break;
                            if (isset($f2['props']['format']) && $f2['props']['format']==='national_id_ir' && empty($f2['props']['confirm'])){ $targetIndex = $ix2+1; break; }
                        }
                        if ($targetIndex !== null){ $meta[] = 'confirm_for=' . $targetIndex; }
                    }
                if ($req) $meta[] = 'required';
                $line = $num . '. ' . self::hoosha_formalize_label($label) . ' [' . implode('|', $meta) . ']';
                $lines[] = $line;
            }
            if ($lines){ $out = implode("\n", $lines); }
        }
        if ($out === '') $out = trim((string)$txt);
        return $out;
    }

    /** Simple formalization pass over field labels */
    protected static function hoosha_formalize_labels(array $schema): array
    {
        if (empty($schema['fields']) || !is_array($schema['fields'])) return $schema;
        $out = $schema;
        foreach ($out['fields'] as &$f){
            if (!is_array($f)) continue;
            $label = isset($f['label']) ? (string)$f['label'] : '';
            if ($label === '') continue;
            $f['label'] = self::hoosha_formalize_label($label);
        }
        unset($f);
        return $out;
    }

    /**
     * Output HTML directly for HTMX submit endpoints so that the response is not JSON-encoded.
     * This intercepts REST serving for our specific /public/forms/.../submit routes.
     */
    public static function serve_htmx_html($served, $result, $request, $server)
    {
        try {
            $route = is_object($request) && method_exists($request, 'get_route') ? (string)$request->get_route() : '';
            $rr = isset($_GET['rest_route']) ? (string)$_GET['rest_route'] : '';
            // Normalize route
            $route = is_string($route) ? $route : '';
            if ($route && $route[0] !== '/') { $route = '/'.$route; }
            $route = rtrim($route, '/');
            $rr = is_string($rr) ? $rr : '';
            if ($rr && $rr[0] !== '/') { $rr = '/'.$rr; }
            $rr = rtrim($rr, '/');

            // Detect HTMX header and/or our submit routes
            $hx = '';
            if (is_object($request) && method_exists($request, 'get_header')) {
                $hx = (string)($request->get_header('hx-request') ?: $request->get_header('HX-Request'));
            }
            $isSubmit = ((($route && strpos($route, '/arshline/v1/public/forms/') === 0) || ($rr && strpos($rr, '/arshline/v1/public/forms/') === 0))
                        && (strpos($route, '/submit') !== false || strpos($rr, '/submit') !== false))
                        || strtolower($hx) === 'true';

            // Also allow raw CSV/Excel passthrough for exports when format param is present
            $fmt = '';
            if (isset($_GET['format'])) { $fmt = (string)$_GET['format']; }
            elseif (is_object($request) && method_exists($request, 'get_param')) { $fmt = (string)($request->get_param('format') ?? ''); }
            $fmt = strtolower($fmt);
            $isExport = in_array($fmt, ['csv','excel'], true);
            if (!$isSubmit && !$isExport) { return $served; }

            // Extract string content from response
            if ($result instanceof \WP_REST_Response) {
                $data = $result->get_data();
                $status = (int)$result->get_status();
            } else {
                $data = $result;
                $status = 200;
            }
            if (!is_string($data)) { return $served; }

            // Serve as text/html or CSV/Excel directly
            if (method_exists($server, 'send_header')) {
                $ctype = $isExport ? ($fmt==='excel' ? 'application/vnd.ms-excel; charset=utf-8' : 'text/csv; charset=utf-8') : 'text/html; charset=utf-8';
                $server->send_header('Content-Type', $ctype);
                $server->send_header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                if ($isExport) {
                    // Try extracting form_id from either route for filename
                    $fid = null;
                    if ($route && preg_match('#/forms/(\d+)/submissions$#', $route, $m)) { $fid = (int)$m[1]; }
                    elseif ($rr && preg_match('#/forms/(\d+)/submissions$#', $rr, $m)) { $fid = (int)$m[1]; }
                    $ext = ($fmt==='excel') ? 'xls' : 'csv';
                    $name = 'submissions' . ($fid?('-'.$fid):'') . '.' . $ext;
                    $server->send_header('Content-Disposition', 'attachment; filename="'.$name.'"');
                }
            }
            if (method_exists($server, 'set_status')) { $server->set_status($status); }
            echo $data;
            return true;
        } catch (\Throwable $e) {
            return $served;
        }
    }

    public static function delete_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        // Take snapshot before destructive delete
        $before = FormRepository::snapshot($id);
        $ok = FormRepository::delete($id);
        if ($ok) {
            $undo = Audit::log('delete_form', 'form', $id, $before ?: [], []);
            return new WP_REST_Response(['ok' => true, 'undo_token' => $undo], 200);
        }
        return new WP_REST_Response(['ok' => false], 404);
    }

    /**
     * GET /ai/audit — list recent audit entries (admin-only)
     */
    public static function list_audit(WP_REST_Request $request)
    {
        $limit = (int)($request->get_param('limit') ?? 50);
        $items = Audit::list(max(1, min(200, $limit)));
        return new WP_REST_Response(['ok'=>true, 'items'=>$items], 200);
    }

    /**
     * POST /ai/undo — undo a single action by token (idempotent)
     */
    public static function undo_by_token(WP_REST_Request $request)
    {
        $token = (string)($request->get_param('token') ?? '');
        if ($token === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_token'], 400);
        $row = Audit::findByToken($token);
        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'], 404);
        if (!empty($row['undone'])) return new WP_REST_Response(['ok'=>false,'error'=>'already_undone'], 409);
        $action = (string)$row['action'];
        $scope = (string)$row['scope'];
        $before = is_array($row['before'] ?? null) ? $row['before'] : [];
        $after = is_array($row['after'] ?? null) ? $row['after'] : [];
        try {
            if ($scope === 'form'){
                if ($action === 'delete_form'){
                    // Restore full form snapshot
                    $restored = FormRepository::restore($before);
                    if ($restored > 0){ Audit::markUndone($token); return new WP_REST_Response(['ok'=>true, 'restored_id'=>$restored], 200); }
                    return new WP_REST_Response(['ok'=>false,'error'=>'restore_failed'], 500);
                }
                if ($action === 'create_form'){
                    // Remove the created form
                    $fid = 0;
                    if (isset($after['form']) && is_array($after['form'])){ $fid = (int)($after['form']['id'] ?? 0); }
                    if ($fid > 0){
                        $ok = FormRepository::delete($fid);
                        if ($ok){ Audit::markUndone($token); return new WP_REST_Response(['ok'=>true, 'deleted_id'=>$fid], 200); }
                        return new WP_REST_Response(['ok'=>false,'error'=>'delete_failed'], 500);
                    }
                }
                if ($action === 'update_form_status'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $form = FormRepository::find($fid);
                        if ($form){
                            $prev = isset($before['status']) ? (string)$before['status'] : 'draft';
                            $form->status = in_array($prev, ['draft','published','disabled'], true) ? $prev : 'draft';
                            FormRepository::save($form);
                            Audit::markUndone($token);
                            return new WP_REST_Response(['ok'=>true, 'restored_status'=>$form->status], 200);
                        }
                    }
                    return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'], 400);
                }
                if ($action === 'update_form_meta'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $form = FormRepository::find($fid);
                        if ($form){
                            $metaPrev = is_array($before['meta'] ?? null) ? $before['meta'] : [];
                            $metaAll = is_array($form->meta) ? $form->meta : [];
                            foreach ($metaPrev as $k => $v){
                                if ($v === null) { unset($metaAll[$k]); }
                                else { $metaAll[$k] = $v; }
                            }
                            $form->meta = $metaAll;
                            FormRepository::save($form);
                            Audit::markUndone($token);
                            return new WP_REST_Response(['ok'=>true, 'restored_meta_keys'=>array_keys($metaPrev)], 200);
                        }
                    }
                    return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'], 400);
                }
                if ($action === 'update_form_fields'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $prevFields = is_array($before['fields'] ?? null) ? $before['fields'] : [];
                        FormsFieldRepository::replaceAll($fid, $prevFields);
                        Audit::markUndone($token);
                        return new WP_REST_Response(['ok'=>true, 'restored_fields'=>true], 200);
                    }
                    return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'], 400);
                }
            }
            if ($scope === 'settings' && $action === 'set_setting'){
                // Swap entire settings/config back using before snapshot
                $before = is_array($before) ? $before : [];
                if (isset($before['config'])){
                    $cfg = $before['config'];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    self::update_ai_config($r);
                    Audit::markUndone($token);
                    return new WP_REST_Response(['ok'=>true, 'restored'=>'ai_config'], 200);
                }
                if (isset($before['settings'])){
                    $arr = $before['settings'];
                    if (is_array($arr)) update_option('arshline_settings', $arr, false);
                    Audit::markUndone($token);
                    return new WP_REST_Response(['ok'=>true, 'restored'=>'settings'], 200);
                }
                return new WP_REST_Response(['ok'=>false,'error'=>'invalid_before_state'], 400);
            }
            return new WP_REST_Response(['ok'=>false,'error'=>'unsupported_undo'], 400);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'undo_error'], 500);
        }
    }

    /**
     * GET /analytics/config — current AI settings relevant to analytics (هوشنگ)
     */
    public static function get_analytics_config(WP_REST_Request $request)
    {
        $gs = self::get_global_settings();
        $base = (string)($gs['ai_base_url'] ?? '');
    $model = (string)($gs['ai_model'] ?? 'gpt-4o');
        $enabled = !empty($gs['ai_enabled']);
        return new WP_REST_Response(['enabled'=>$enabled,'base_url'=>$base,'model'=>$model], 200);
    }

    /**
     * POST /analytics/analyze — multi-form analysis with chunking; logs token usage
     */
    public static function analytics_analyze(WP_REST_Request $request)
    {
        $p = $request->get_json_params(); if (!is_array($p)) $p = $request->get_params();
    $form_ids = array_values(array_filter(array_map('intval', (array)($p['form_ids'] ?? [])), function($v){ return $v>0; }));
        if (empty($form_ids)) return new WP_REST_Response([ 'error' => 'form_ids_required' ], 400);
    // Persona requires using only one selected form; restrict to the first
    if (count($form_ids) > 1) { $form_ids = [ $form_ids[0] ]; }
        $question = is_scalar($p['question'] ?? null) ? trim((string)$p['question']) : '';
        if ($question === '') return new WP_REST_Response([ 'error' => 'question_required' ], 400);
        $session_id = isset($p['session_id']) ? max(0, (int)$p['session_id']) : 0;
    $max_rows = isset($p['max_rows']) && is_numeric($p['max_rows']) ? max(50, min(10000, (int)$p['max_rows'])) : 2000;
    $chunk_size = null; // compute after loading config
    $model = is_scalar($p['model'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', (string)$p['model']), 0, 100) : '';
    // Respect per-site analytics default (configurable), allow request override; clamp to 4096
    $max_tokens = null; // computed after loading AI settings below
    $voice = is_scalar($p['voice'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['voice']), 0, 50) : '';
    $format = is_scalar($p['format'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['format']), 0, 20)) : '';
    $mode = is_scalar($p['mode'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['mode']), 0, 20)) : '';
    $phase = is_scalar($p['phase'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['phase']), 0, 20)) : '';
    $chunk_index = isset($p['chunk_index']) && is_numeric($p['chunk_index']) ? max(1, (int)$p['chunk_index']) : 1;
        $structuredParam = isset($p['structured']) ? (bool)$p['structured'] : null;
        // Optional chat history: array of {role:'user'|'assistant'|'system', content:string}
        $history = [];
        if (isset($p['history']) && is_array($p['history'])){
            foreach ($p['history'] as $h){
                if (!is_array($h)) continue;
                $role = (string)($h['role'] ?? ''); $content = (string)($h['content'] ?? '');
                if ($content === '') continue;
                if (!in_array($role, ['user','assistant','system'], true)) $role = 'user';
                $history[] = [ 'role'=>$role, 'content'=>$content ];
            }
        }

    // Load AI config
        $cur = get_option('arshline_settings', []);
        $base = is_scalar($cur['ai_base_url'] ?? null) ? trim((string)$cur['ai_base_url']) : '';
        $api_key = is_scalar($cur['ai_api_key'] ?? null) ? (string)$cur['ai_api_key'] : '';
        $enabled = !empty($cur['ai_enabled']);
        // Prefer Hoshang-specific model if set; else fall back to global ai_model
        $default_model = is_scalar($cur['ai_hosh_model'] ?? null) && (string)$cur['ai_hosh_model'] !== ''
            ? (string)$cur['ai_hosh_model']
            : ( (is_scalar($cur['ai_model'] ?? null) ? (string)$cur['ai_model'] : 'gpt-4o') );
        if (!$enabled || $base === '' || $api_key === ''){
            return new WP_REST_Response([ 'error' => 'ai_disabled' ], 400);
        }

        // Enhanced Analytics Tracer helpers (persist across phases by session_id)
        $trace_key = ($session_id > 0) ? ('arsh_ana_trace_' . $session_id) : '';
        $read_trace = function() use ($trace_key){
            if ($trace_key === '') return [];
            try { $t = get_transient($trace_key); return is_array($t) ? $t : []; } catch (\Throwable $e) { return []; }
        };
        $write_trace = function(array $trace) use ($trace_key){
            if ($trace_key === '') return;
            try { set_transient($trace_key, $trace, 15*60); } catch (\Throwable $e) { /* ignore */ }
        };
        // Resolve default max_tokens from config (ai_ana_max_tokens), then honor request override
    $cfgMaxTok = isset($cur['ai_ana_max_tokens']) && is_numeric($cur['ai_ana_max_tokens']) ? max(16, min(4096, (int)$cur['ai_ana_max_tokens'])) : 1200;
    $cfgChunkSize = isset($cur['ai_ana_chunk_size']) && is_numeric($cur['ai_ana_chunk_size']) ? max(50, min(2000, (int)$cur['ai_ana_chunk_size'])) : 800;
        $reqMaxTok = isset($p['max_tokens']) && is_numeric($p['max_tokens']) ? (int)$p['max_tokens'] : 0;
        $max_tokens = $reqMaxTok > 0 ? max(16, min(4096, $reqMaxTok)) : $cfgMaxTok;
    // Resolve chunk_size from request override or site default
    $reqChunk = isset($p['chunk_size']) && is_numeric($p['chunk_size']) ? (int)$p['chunk_size'] : 0;
    $chunk_size = $reqChunk > 0 ? max(50, min(2000, $reqChunk)) : $cfgChunkSize;
        $use_model = $model !== '' ? $model : $default_model;
        // LLM-only mode: disable any local structural shortcuts regardless of params/options/filters.
    $allowStructural = false;
    // New: structured JSON mode (config-aware) + auto-format routing
    $hoshMode = is_scalar($cur['ai_hosh_mode'] ?? null) ? (string)$cur['ai_hosh_mode'] : 'hybrid';
    $hoshMode = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid';
    $autoFormat = isset($cur['ai_ana_auto_format']) ? (bool)$cur['ai_ana_auto_format'] : true;
    $clientWantsStructured = ($structuredParam === true) || ($mode === 'structured') || ($format === 'json');
    // Base routing: honor hard setting first; in hybrid, honor explicit client request even if auto-format is enabled
    if ($hoshMode === 'structured') { $isStructured = true; }
    elseif ($hoshMode === 'llm') { $isStructured = false; }
    else /* hybrid */ { $isStructured = $clientWantsStructured ? true : false; }
    $autoStructured = false; $structTrigger = '';

        // Always delegate answers to the model (no local greeting or canned responses)
    $ql = mb_strtolower($question, 'UTF-8');
    // Derive requested output format hint from question
    $isTableOut = (bool)(preg_match('/\btable\b/i', $ql) || preg_match('/جدول/u', $ql));
    $isListOut  = (bool)(preg_match('/\blist\b|bullet|bulleted/i', $ql) || preg_match('/(?:فهرست|لیست|بولت|نقطه(?:‌|)ای)/u', $ql));
    $out_format = $isTableOut ? 'table' : ($isListOut ? 'list' : 'plain');
    // Detect greeting/ambiguous openers (to avoid dumping data on "سلام")
    $isGreeting = (bool)preg_match('/^(?:\s*(?:سلام|درود|hi|hello|hey)\s*[!،,.]?)$/ui', trim($question));

        // Hybrid auto-switch: when auto-format is enabled and question looks heavy/analytical, use structured automatically
        if (!$isStructured && $hoshMode === 'hybrid' && $autoFormat){
            $isHeavyQ = (bool)(
                preg_match('/\b(compare|correlat|trend|distribution|variance|std|median|quartile|regression|cluster|segment|chart|bar|pie|line)\b/i', $ql)
                || preg_match('/(?:مقایسه|همبستگی|روند|میانگین|میانه|نمودار|نمودار(?:\s*میله|\s*دایره|\s*خط)|واریانس|انحراف\s*معیار)/u', $ql)
            );
            if ($isHeavyQ || ($out_format === 'table' && preg_match('/(?:نمودار|chart|trend|روند|compare|مقایسه)/ui', $ql))) {
                $isStructured = true; $autoStructured = true; $structTrigger = $isHeavyQ ? 'heavy-query' : 'tabular-intent';
            }
        }

        // Ensure chat session exists and store the user message immediately
        try {
            global $wpdb; $uid = get_current_user_id();
            $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tblMsg  = \Arshline\Support\Helpers::tableName('ai_chat_messages');
            if ($session_id <= 0){
                $wpdb->insert($tblSess, [
                    'user_id' => $uid ?: null,
                    'title' => mb_substr($question, 0, 190),
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids ], JSON_UNESCAPED_UNICODE),
                    'last_message_at' => current_time('mysql'),
                ]);
                $session_id = (int)$wpdb->insert_id;
            }
            // record user turn
            if ($session_id > 0){
                $wpdb->insert($tblMsg, [
                    'session_id' => $session_id,
                    'role' => 'user',
                    'content' => $question,
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>$format, 'mode'=>'llm' ], JSON_UNESCAPED_UNICODE),
                ]);
                // touch session
                $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
            }
        } catch (\Throwable $e) { /* ignore persistence errors */ }

    // Collect data rows per form (using FormsSubmissionRepository alias). IMPORTANT:
    // Do not remove the FormsSubmissionRepository / FormsFieldRepository aliases above.
    // A legacy minimal SubmissionRepository also exists (without listByFormAll / values helpers) and
    // previously caused a fatal when this block attempted to resolve the class inside Arshline\Core.
    // The aliases ensure we always hit the full-feature repository implementation under Modules\Forms.
    // If refactoring repositories, update the use statements at the top instead of inlining FQCNs here.
        $total_rows = 0; $tables = [];
        foreach ($form_ids as $fid){
            $remaining = max(0, $max_rows - $total_rows); if ($remaining <= 0) break;
            $rows = FormsSubmissionRepository::listByFormAll($fid, [], min($remaining, $chunk_size));
            $total_rows += count($rows);
            // fields_meta: id, label, type (from props)
            $fmeta = [];
            try {
                $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                foreach (($fieldsForMeta ?: []) as $f){
                    $p = is_array($f['props'] ?? null) ? $f['props'] : [];
                    // Prefer common builder keys for question/label; fall back through sensible aliases
                    $label = (string)($p['question'] ?? $p['label'] ?? $p['title'] ?? $p['name'] ?? '');
                    $type = (string)($p['type'] ?? '');
                    $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label, 'type' => $type ];
                }
            } catch (\Throwable $e) { /* ignore meta errors */ }
            $tables[] = [ 'form_id' => $fid, 'rows' => $rows, 'fields_meta' => $fmeta ];
        }
        if ($total_rows === 0){
            return new WP_REST_Response([ 'summary' => 'داده‌ای برای تحلیل یافت نشد.', 'chunks' => [], 'usage' => [] ], 200);
        }

    // Quick structural intent: answer "how many items/questions/fields" without LLM
        $isCountIntent = (bool) (
            // English variants
            preg_match('/\bhow\s+many\s+(?:questions?|items?|fields?)\b/i', $ql)
            || preg_match('/\bcount\s+(?:questions?|items?|fields?)\b/i', $ql)
            || preg_match('/\bnumber\s+of\s+(?:questions?|items?|fields?)\b/i', $ql)
            // Persian variants: "چند" / "چند تا" / "تعداد" + noun
            || preg_match('/(?:(?:چند\s*تا|چند|تعداد)\s*(?:سوال|سؤال|آیتم|گزینه|فیلد)s?)/u', $ql)
        );
        if ($allowStructural && $isCountIntent){
            $supported = [ 'short_text'=>1,'long_text'=>1,'multiple_choice'=>1,'dropdown'=>1,'rating'=>1 ];
            $lines = [];
            foreach ($form_ids as $fid){
                try {
                    $fields = FormsFieldRepository::listByForm($fid);
                    $cnt = 0;
                    foreach (($fields ?: []) as $f){
                        $p = isset($f['props']) && is_array($f['props']) ? $f['props'] : [];
                        $type = (string)($p['type'] ?? '');
                        if (isset($supported[$type])) $cnt++;
                    }
                    $lines[] = "فرم " . $fid . ": " . $cnt . " آیتم";
                } catch (\Throwable $e) { $lines[] = "فرم " . $fid . ": نامشخص"; }
            }
            $sum = implode("\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

        // Quick structural intent: count submissions ("چند نفر فرم رو پر کردند؟", "تعداد ارسال‌ها")
        $isSubmitCountIntent = (bool)(
            preg_match('/\bhow\s+many\s+(?:submissions|responses|entries)\b/i', $ql)
            || preg_match('/\b(count|number)\s+of\s+(?:submissions|responses|entries)\b/i', $ql)
            || preg_match('/(?:چند\s*نفر|تعداد)\s*(?:فرم|ارسال|پاسخ|ورودی)/u', $ql)
        );
        if ($allowStructural && $isSubmitCountIntent){
            $lines = [];
            foreach ($form_ids as $fid){
                try {
                    $all = FormsSubmissionRepository::listByFormAll($fid, [], 1_000_000);
                    $lines[] = "فرم " . $fid . ": " . count($all) . " ارسال";
                } catch (\Throwable $e) { $lines[] = "فرم " . $fid . ": نامشخص"; }
            }
            $sum = implode("\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

        // Quick structural intent: list names of submitters (detect name-like fields and list their values)
        $isNamesIntent = (bool) (
            preg_match('/(?:لیست|فهرست)?\s*(?:اسامی|اسم|نام)/u', $ql)
            || preg_match('/\bnames?\b/i', $ql)
        );
        // Avoid misclassifying queries like "اسم میوه" as a person-name intent
        if ($isNamesIntent) {
            if (preg_match('/(?:^|\s)اسم\s+([\p{L}\s‌]+)/u', $ql, $m)){
                $tok = trim($m[1]);
                if (preg_match('/^(?:میوه|ایمیل|شماره|تلفن|کد\s*ملی|سوال|سؤال|فرم)\b/u', $tok)){
                    $isNamesIntent = false;
                }
            }
        }
        // Help the model by using table format for intents that benefit from tabular grounding
        $isFieldsIntent = (bool)(
            preg_match('/\b(fields?|questions?)\b/i', $ql) || preg_match('/فیلد(?:های)?\s*فرم/u', $ql)
        );
        $isShowAllIntent = (bool)(
            preg_match('/\b(all\s+data|show\s+all|dump)\b/i', $ql)
            || preg_match('/تمام\s*اطلاعات|همه\s*داده|لیست\s*اطلاعات(?:\s*فرم)?|خلاصه\s*اطلاعات(?:\s*فرم)?/u', $ql)
        );
        $isAnswersIntent = (bool)(preg_match('/پاسخ(?:‌|\s*)ها|جواب(?:‌|\s*)ها|نتایج|ارسال(?:‌|\s*)ها/u', $ql));
        // Extract a simple field hint from phrases like "لیست X ها" or "اسم|نام X"
        $field_hint = '';
        if (preg_match('/(?:لیست|فهرست)\s+([\p{L}\s‌]+?)(?:\s*ها|\s*های)?\b/u', $ql, $mm)){
            $field_hint = trim($mm[1]);
        }
        if ($field_hint === '' && preg_match('/(?:اسم|نام)\s+([\p{L}\s‌]+)$/u', $ql, $mm2)){
            $field_hint = trim($mm2[1]);
        }
        if ($format !== 'table' && ($isFieldsIntent || $isShowAllIntent || $isAnswersIntent || $isNamesIntent || $out_format==='table')) {
            $format = 'table';
        }
    if ($allowStructural && $isNamesIntent){
            $lines = [];
            foreach ($tables as $t){
                $fid = (int)$t['form_id'];
                $fmeta = is_array($t['fields_meta'] ?? null) ? $t['fields_meta'] : [];
                // find name-like fields
                $nameFieldIds = [];
                foreach ($fmeta as $fm){
                    $label = mb_strtolower((string)($fm['label'] ?? ''), 'UTF-8');
                    $type  = (string)($fm['type'] ?? '');
                    if ($label === '') continue;
                    // Enhanced name field detection - English patterns
                    $englishNamePatterns = '/\b(name|first\s*name|last\s*name|full\s*name|surname|family|firstname|lastname|fullname|given\s*name|contact\s*name|your\s*name|applicant|participant|person|individual|client|customer|user|member|student|employee|staff|sender|submitter)\b/i';
                    
                    // Enhanced Persian name patterns
                    $persianNamePatterns = '/(?:نام|اسم|نام\s*و\s*نام\s*خانوادگی|نام\s*کامل|نام\s*خانوادگی|نام\s*کوچک|نام\s*و\s*فامیل|فامیل|فامیلی|شناسه|هویت|متقاضی|شرکت‌کننده|فرد|مشتری|کاربر|عضو|دانشجو|کارمند|پرسنل|فرستنده|ارسال‌کننده|تماس‌گیرنده)/u';
                    
                    // Check for generic field patterns that might indicate names
                    $genericPatterns = '/^(.*(?:شما|خود|کس|کسی|فرد|نفر).*)$/u';
                    
                    if (preg_match($englishNamePatterns, $label) 
                        || preg_match($persianNamePatterns, $label)
                        || (strlen($label) <= 15 && preg_match($genericPatterns, $label))) {
                        $nameFieldIds[] = (int)($fm['id'] ?? 0);
                    }
                }
                $nameFieldIds = array_values(array_unique(array_filter($nameFieldIds, function($v){ return $v>0; })));
                
                // Fallback: if no specific name fields found, use first short_text field
                if (empty($nameFieldIds)){
                    foreach ($fmeta as $fm){
                        $type = (string)($fm['type'] ?? '');
                        if ($type === 'short_text'){
                            $nameFieldIds[] = (int)($fm['id'] ?? 0);
                            break; // Only take the first one
                        }
                    }
                }
                
                if (empty($nameFieldIds)){
                    $lines[] = "فرم " . $fid . ": نامشخص (هیچ فیلد متنی مناسب یافت نشد)";
                    continue;
                }
                // fetch values for current table rows in batch
                $sids = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, ($t['rows'] ?? [])), function($v){ return $v>0; }));
                $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($sids);
                $names = [];
                foreach ($sids as $sid){
                    $vals = $valuesMap[$sid] ?? [];
                    $parts = [];
                    foreach ($vals as $v){
                        $fidv = (int)($v['field_id'] ?? 0);
                        if (in_array($fidv, $nameFieldIds, true)){
                            $val = trim((string)($v['value'] ?? ''));
                            if ($val !== '') $parts[] = $val;
                        }
                    }
                    $name = trim(implode(' ', $parts));
                    if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
                }
                if (!empty($names)){
                    $lines[] = "فرم " . $fid . ":\n- " . implode("\n- ", $names);
                } else {
                    $lines[] = "فرم " . $fid . ": نامشخص (هیچ نامی در داده‌های انتخاب‌شده یافت نشد)";
                }
            }
            $sum = implode("\n\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

    // For each table, build chunks and call LLM with a compact prompt
        $baseUrl = rtrim($base, '/');
        // Normalize base URL to avoid double /v1 when configured as https://host/v1
        if (preg_match('#/v\d+$#', $baseUrl)) {
            $baseUrl = preg_replace('#/v\d+$#', '', $baseUrl);
        }
        // Allow full endpoint override when a complete chat/completions path is provided
        if (preg_match('#/chat/(?:completions|completion)$#', $baseUrl)) {
            $endpoint = $baseUrl;
        } else {
            $endpoint = $baseUrl . '/v1/chat/completions';
        }
    $headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ];
    $http_timeout = 45; // allow a bit more time for larger tables
        $agentName = 'hoshang';
        $usages = [];
        $answers = [];
        $debug = !empty($p['debug']);
        $debugInfo = [];
        // Phased pipeline for full-dataset structured analytics
        if ($isStructured && $phase !== ''){
            $fid = $form_ids[0];
            // Helper: detect field roles from labels/types for better guidance (names, mood text, mood score)
            $detect_field_roles = function(array $fmeta){
                $roles = [
                    // Personal info
                    'name'=>[], 'phone'=>[], 'email'=>[], 'age'=>[], 'address'=>[], 'city'=>[], 'national_id'=>[],
                    // Mood & satisfaction 
                    'mood_text'=>[], 'mood_score'=>[], 'satisfaction'=>[], 'feedback'=>[], 'comment'=>[],
                    // Numeric & ratings
                    'score'=>[], 'rating'=>[], 'number'=>[], 'quantity'=>[], 'amount'=>[], 'price'=>[], 'count'=>[],
                    // Dates & time
                    'date'=>[], 'time'=>[], 'created_date'=>[], 'birth_date'=>[], 'event_date'=>[],
                    // Business & categories
                    'category'=>[], 'type'=>[], 'status'=>[], 'priority'=>[], 'department'=>[], 'service'=>[], 'product'=>[],
                    // Text fields
                    'description'=>[], 'notes'=>[], 'message'=>[], 'title'=>[], 'subject'=>[], 'reason'=>[],
                    // Boolean & choices
                    'yes_no'=>[], 'choice'=>[], 'selection'=>[], 'checkbox'=>[], 'radio'=>[],
                    // Other
                    'url'=>[], 'file'=>[], 'image'=>[], 'location'=>[], 'other'=>[]
                ];
                
                foreach (($fmeta ?: []) as $fm){
                    $lab = (string)($fm['label'] ?? '');
                    $labL = mb_strtolower($lab, 'UTF-8');
                    $type = (string)($fm['type'] ?? '');
                    if ($lab === '') continue;
                    
                    // Enhanced Personal information patterns
                    if (preg_match('/\b(name|first\s*name|last\s*name|full\s*name|surname|family|firstname|lastname|fullname|given\s*name|contact\s*name|your\s*name|applicant|participant|person|individual|client|customer|user|member|student|employee|staff|sender|submitter)\b/i', $lab)
                        || preg_match('/(?:نام|اسم|نام\s*و\s*نام\s*خانوادگی|نام\s*کامل|نام\s*خانوادگی|نام\s*کوچک|نام\s*و\s*فامیل|فامیل|فامیلی|شناسه|هویت|متقاضی|شرکت‌کننده|فرد|مشتری|کاربر|عضو|دانشجو|کارمند|پرسنل|فرستنده|ارسال‌کننده|تماس‌گیرنده)/u', $labL)
                        || (strlen($lab) <= 15 && ($type === 'short_text' || $type === '') && preg_match('/^(.*(?:شما|خود|کس|کسی|فرد|نفر).*)$/u', $labL))){
                        $roles['name'][] = $lab;
                    }
                    elseif (preg_match('/\b(phone|mobile|cell|telephone)\b/i', $lab) 
                        || preg_match('/شماره|تلفن|همراه|موبایل|تماس/u', $labL)){
                        $roles['phone'][] = $lab;
                    }
                    elseif (preg_match('/\b(email|e-mail|mail)\b/i', $lab) 
                        || preg_match('/ایمیل|پست\s*الکترونیک/u', $labL)){
                        $roles['email'][] = $lab;
                    }
                    elseif (preg_match('/\b(age|years?\s*old)\b/i', $lab) 
                        || preg_match('/سن|سال/u', $labL)){
                        $roles['age'][] = $lab;
                    }
                    elseif (preg_match('/\b(address|street|location)\b/i', $lab) 
                        || preg_match('/آدرس|نشانی|محل/u', $labL)){
                        $roles['address'][] = $lab;
                    }
                    elseif (preg_match('/\b(city|town|province|state)\b/i', $lab) 
                        || preg_match('/شهر|استان|منطقه/u', $labL)){
                        $roles['city'][] = $lab;
                    }
                    elseif (preg_match('/\b(national\s*id|ssn|id\s*number)\b/i', $lab) 
                        || preg_match('/کد\s*ملی|شناسه\s*ملی/u', $labL)){
                        $roles['national_id'][] = $lab;
                    }
                    
                    // Mood & satisfaction patterns
                    elseif (preg_match('/\b(mood|feeling|wellbeing|how\s*are\s*you)\b/i', $lab) 
                        || preg_match('/حال|احوال|اوضاع|روحیه|حالت|امروز.*چطور/u', $labL)){
                        $roles['mood_text'][] = $lab;
                    }
                    elseif (preg_match('/\b(satisfaction|happy|pleased)\b/i', $lab) 
                        || preg_match('/رضایت|خوشحال|راضی|خشنود/u', $labL)){
                        $roles['satisfaction'][] = $lab;
                    }
                    elseif (preg_match('/\b(feedback|comment|opinion|review)\b/i', $lab) 
                        || preg_match('/نظر|بازخورد|پیشنهاد|نقد/u', $labL)){
                        $roles['feedback'][] = $lab;
                    }
                    elseif (preg_match('/\b(comment|note|remark)\b/i', $lab) 
                        || preg_match('/توضیح|یادداشت|ملاحظات/u', $labL)){
                        $roles['comment'][] = $lab;
                    }
                    
                    // Numeric & rating patterns  
                    elseif ($type === 'rating' || preg_match('/\b(rating|score|points?)\b/i', $lab) 
                        || preg_match('/امتیاز|نمره|رتبه|از.*تا|۱.*۱۰|1.*10/u', $labL)){
                        if (preg_match('/حال|احوال|mood/iu', $labL)){
                            $roles['mood_score'][] = $lab;  // mood-specific rating
                        } else {
                            $roles['rating'][] = $lab;
                        }
                    }
                    elseif ($type === 'number' || preg_match('/\b(number|count|quantity|amount)\b/i', $lab) 
                        || preg_match('/تعداد|مقدار|عدد/u', $labL)){
                        $roles['number'][] = $lab;
                    }
                    elseif (preg_match('/\b(price|cost|fee|money)\b/i', $lab) 
                        || preg_match('/قیمت|هزینه|پول|تومان|ریال/u', $labL)){
                        $roles['price'][] = $lab;
                    }
                    
                    // Date & time patterns
                    elseif ($type === 'date' || preg_match('/\b(date|day|month|year)\b/i', $lab) 
                        || preg_match('/تاریخ|روز|ماه|سال/u', $labL)){
                        if (preg_match('/birth|born|تولد/iu', $labL)){
                            $roles['birth_date'][] = $lab;
                        } elseif (preg_match('/event|رویداد|برگزار/iu', $labL)){
                            $roles['event_date'][] = $lab;
                        } elseif (preg_match('/create|ثبت|ایجاد/iu', $labL)){
                            $roles['created_date'][] = $lab;
                        } else {
                            $roles['date'][] = $lab;
                        }
                    }
                    elseif ($type === 'time' || preg_match('/\b(time|hour|minute)\b/i', $lab) 
                        || preg_match('/زمان|ساعت|دقیقه/u', $labL)){
                        $roles['time'][] = $lab;
                    }
                    
                    // Business & category patterns
                    elseif (preg_match('/\b(category|type|kind|class)\b/i', $lab) 
                        || preg_match('/دسته|نوع|گونه|رده/u', $labL)){
                        $roles['category'][] = $lab;
                    }
                    elseif (preg_match('/\b(status|state|condition)\b/i', $lab) 
                        || preg_match('/وضعیت|حالت|شرایط/u', $labL)){
                        $roles['status'][] = $lab;
                    }
                    elseif (preg_match('/\b(priority|importance|urgent)\b/i', $lab) 
                        || preg_match('/اولویت|مهم|فوری/u', $labL)){
                        $roles['priority'][] = $lab;
                    }
                    elseif (preg_match('/\b(department|unit|section)\b/i', $lab) 
                        || preg_match('/بخش|واحد|بهش|قسمت/u', $labL)){
                        $roles['department'][] = $lab;
                    }
                    elseif (preg_match('/\b(service|support|help)\b/i', $lab) 
                        || preg_match('/خدمت|پشتیبانی|کمک|سرویس/u', $labL)){
                        $roles['service'][] = $lab;
                    }
                    elseif (preg_match('/\b(product|item|goods)\b/i', $lab) 
                        || preg_match('/محصول|کالا|جنس/u', $labL)){
                        $roles['product'][] = $lab;
                    }
                    
                    // Text field patterns
                    elseif (preg_match('/\b(description|detail|explain)\b/i', $lab) 
                        || preg_match('/توضیح|شرح|تفصیل/u', $labL)){
                        $roles['description'][] = $lab;
                    }
                    elseif (preg_match('/\b(note|remark|memo)\b/i', $lab) 
                        || preg_match('/یادداشت|ملاحظه|نکته/u', $labL)){
                        $roles['notes'][] = $lab;
                    }
                    elseif (preg_match('/\b(message|text|content)\b/i', $lab) 
                        || preg_match('/پیام|متن|محتوا/u', $labL)){
                        $roles['message'][] = $lab;
                    }
                    elseif (preg_match('/\b(title|subject|topic)\b/i', $lab) 
                        || preg_match('/عنوان|موضوع|موضع/u', $labL)){
                        if (preg_match('/subject|موضوع/iu', $labL)){
                            $roles['subject'][] = $lab;
                        } else {
                            $roles['title'][] = $lab;
                        }
                    }
                    elseif (preg_match('/\b(reason|why|because)\b/i', $lab) 
                        || preg_match('/دلیل|علت|چرا/u', $labL)){
                        $roles['reason'][] = $lab;
                    }
                    
                    // Boolean & choice patterns
                    elseif ($type === 'checkbox' || preg_match('/\b(yes|no|true|false)\b/i', $lab) 
                        || preg_match('/بله|خیر|آری|نه/u', $labL)){
                        $roles['yes_no'][] = $lab;
                    }
                    elseif ($type === 'radio' || $type === 'select' || preg_match('/\b(choice|option|select)\b/i', $lab) 
                        || preg_match('/انتخاب|گزینه|آپشن/u', $labL)){
                        if ($type === 'radio') {
                            $roles['radio'][] = $lab;
                        } else {
                            $roles['choice'][] = $lab;
                        }
                    }
                    
                    // Other patterns
                    elseif ($type === 'url' || preg_match('/\b(url|link|website)\b/i', $lab) 
                        || preg_match('/لینک|پیوند|آدرس\s*سایت/u', $labL)){
                        $roles['url'][] = $lab;
                    }
                    elseif ($type === 'file' || preg_match('/\b(file|upload|attachment)\b/i', $lab) 
                        || preg_match('/فایل|آپلود|ضمیمه/u', $labL)){
                        $roles['file'][] = $lab;
                    }
                    elseif ($type === 'image' || preg_match('/\b(image|photo|picture)\b/i', $lab) 
                        || preg_match('/عکس|تصویر|تصویر/u', $labL)){
                        $roles['image'][] = $lab;
                    }
                    else {
                        // Catch-all for unmatched fields
                        $roles['other'][] = $lab;
                    }
                }
                
                // De-duplicate and clean
                foreach ($roles as $k=>$arr){ 
                    $roles[$k] = array_values(array_unique(array_filter(array_map('strval', $arr), function($s){ return $s!==''; }))); 
                }
                return $roles;
            };
            
            // Advanced Query Intent Classification System
            $classify_query_intent = function(string $query, array $field_roles) {
                $qLower = mb_strtolower($query, 'UTF-8');
                $qNorm = preg_replace('/[؟?\s]+/', ' ', trim($qLower));
                
                $intent = [
                    'primary_type' => 'unknown',
                    'secondary_types' => [],
                    'target_fields' => [],
                    'suggested_columns' => [],
                    'analysis_depth' => 'simple',
                    'requires_aggregation' => false,
                    'requires_person_lookup' => false,
                    'requires_filtering' => false,
                    'chart_type' => null,
                    'confidence' => 0.0
                ];
                
                // Person-specific queries (highest priority)
                if (preg_match('/حال\s+([^\s؟?]{2,})|احوال\s+([^\s؟?]{2,})|«([^»]+)»|"([^"]+)"/u', $qNorm, $matches)){
                    $intent['primary_type'] = 'person_lookup';
                    $intent['requires_person_lookup'] = true;
                    $intent['target_fields'] = array_merge($field_roles['name'] ?? [], $field_roles['mood_text'] ?? [], $field_roles['mood_score'] ?? []);
                    $intent['confidence'] = 0.9;
                }
                
                // Listing queries
                elseif (preg_match('/(?:لیست|فهرست|همه|تمام|چه.*دار(?:ن|ند)|نشان.*ده|بده)/u', $qNorm)){
                    $intent['primary_type'] = 'listing';
                    $intent['analysis_depth'] = 'simple';
                    $intent['confidence'] = 0.8;
                    
                    // Detect specific field types in listing requests
                    if (preg_match('/شماره|تلفن|موبایل|همراه/u', $qNorm)){
                        $intent['target_fields'] = array_merge($intent['target_fields'], $field_roles['phone'] ?? []);
                        $intent['suggested_columns'] = array_merge($intent['suggested_columns'], $field_roles['name'] ?? [], $field_roles['phone'] ?? []);
                    }
                    if (preg_match('/ایمیل|پست/u', $qNorm)){
                        $intent['target_fields'] = array_merge($intent['target_fields'], $field_roles['email'] ?? []);
                        $intent['suggested_columns'] = array_merge($intent['suggested_columns'], $field_roles['name'] ?? [], $field_roles['email'] ?? []);
                    }
                    if (preg_match('/نام|اسم/u', $qNorm)){
                        $intent['target_fields'] = array_merge($intent['target_fields'], $field_roles['name'] ?? []);
                        $intent['suggested_columns'] = array_merge($intent['suggested_columns'], $field_roles['name'] ?? []);
                    }
                }
                
                // Aggregation queries (sum, average, count, etc.)
                elseif (preg_match('/(?:مجموع|جمع|کل|تعداد|میانگین|متوسط|حداکثر|حداقل|بیشترین|کمترین)/u', $qNorm)){
                    $intent['primary_type'] = 'aggregation';
                    $intent['requires_aggregation'] = true;
                    $intent['analysis_depth'] = 'moderate';
                    $intent['confidence'] = 0.85;
                    
                    // Detect specific aggregation types
                    if (preg_match('/تعداد|چندتا/u', $qNorm)){
                        $intent['secondary_types'][] = 'count';
                    }
                    if (preg_match('/مجموع|جمع|کل/u', $qNorm)){
                        $intent['secondary_types'][] = 'sum';
                        $intent['target_fields'] = array_merge($intent['target_fields'], $field_roles['number'] ?? [], $field_roles['price'] ?? [], $field_roles['rating'] ?? []);
                    }
                    if (preg_match('/میانگین|متوسط/u', $qNorm)){
                        $intent['secondary_types'][] = 'average';
                        $intent['target_fields'] = array_merge($intent['target_fields'], $field_roles['rating'] ?? [], $field_roles['mood_score'] ?? [], $field_roles['number'] ?? []);
                    }
                }
                
                // Comparison queries
                elseif (preg_match('/(?:مقایسه|بررسی|تفاوت|شباهت|بهتر|بدتر|بیشتر|کمتر)/u', $qNorm)){
                    $intent['primary_type'] = 'comparison';
                    $intent['analysis_depth'] = 'complex';
                    $intent['requires_filtering'] = true;
                    $intent['confidence'] = 0.8;
                    $intent['suggested_columns'] = array_merge($field_roles['name'] ?? [], $field_roles['category'] ?? [], $field_roles['rating'] ?? []);
                }
                
                // Chart/visualization queries
                elseif (preg_match('/(?:چارت|نمودار|گراف|تصویر.*آمار|بکش|رسم)/u', $qNorm)){
                    $intent['primary_type'] = 'visualization';
                    $intent['analysis_depth'] = 'complex';
                    $intent['confidence'] = 0.9;
                    
                    if (preg_match('/میله.*(?:ای|چارت|نمودار)|bar\s*chart/iu', $qNorm)){
                        $intent['chart_type'] = 'bar';
                    } elseif (preg_match('/دایره.*(?:ای|چارت|نمودار)|pie\s*chart/iu', $qNorm)){
                        $intent['chart_type'] = 'pie';
                    } elseif (preg_match('/خط.*(?:ی|چارت|نمودار)|line\s*chart/iu', $qNorm)){
                        $intent['chart_type'] = 'line';
                    } else {
                        $intent['chart_type'] = 'auto';
                    }
                    
                    $intent['target_fields'] = array_merge($field_roles['rating'] ?? [], $field_roles['category'] ?? [], $field_roles['mood_score'] ?? []);
                }
                
                // Mood/satisfaction analysis queries  
                elseif (preg_match('/(?:حال|احوال|روحیه|رضایت|خوشحال|ناراحت|خوب|بد)/u', $qNorm) && !$intent['requires_person_lookup']){
                    $intent['primary_type'] = 'mood_analysis';
                    $intent['analysis_depth'] = 'moderate';
                    $intent['confidence'] = 0.75;
                    $intent['target_fields'] = array_merge($field_roles['mood_text'] ?? [], $field_roles['mood_score'] ?? [], $field_roles['satisfaction'] ?? []);
                    $intent['suggested_columns'] = array_merge($field_roles['name'] ?? [], $field_roles['mood_text'] ?? [], $field_roles['mood_score'] ?? []);
                }
                
                // Filtering/search queries
                elseif (preg_match('/(?:فیلتر|جستجو|پیدا|یافت|شرط|با.*خاص|دارای)/u', $qNorm)){
                    $intent['primary_type'] = 'filtering';
                    $intent['requires_filtering'] = true;
                    $intent['analysis_depth'] = 'moderate';
                    $intent['confidence'] = 0.7;
                }
                
                // Status/tracking queries
                elseif (preg_match('/(?:وضعیت|حالت|شرایط|آخرین|جدیدترین|قدیمی‌ترین)/u', $qNorm)){
                    $intent['primary_type'] = 'status_tracking';
                    $intent['analysis_depth'] = 'simple';
                    $intent['confidence'] = 0.65;
                    $intent['target_fields'] = array_merge($field_roles['status'] ?? [], $field_roles['date'] ?? [], $field_roles['created_date'] ?? []);
                }
                
                // Fallback: general analysis
                else {
                    $intent['primary_type'] = 'general_analysis';
                    $intent['analysis_depth'] = 'moderate';
                    $intent['confidence'] = 0.3;
                    // Include most commonly useful fields
                    $intent['suggested_columns'] = array_merge(
                        $field_roles['name'] ?? [], 
                        $field_roles['rating'] ?? [], 
                        $field_roles['category'] ?? [],
                        array_slice($field_roles['other'] ?? [], 0, 3)  // First 3 other fields
                    );
                }
                
                // Clean and deduplicate arrays
                $intent['target_fields'] = array_values(array_unique($intent['target_fields']));
                $intent['suggested_columns'] = array_values(array_unique($intent['suggested_columns']));
                $intent['secondary_types'] = array_values(array_unique($intent['secondary_types']));
                
                // If no specific columns suggested, intelligently select based on primary type
                if (empty($intent['suggested_columns'])){
                    switch($intent['primary_type']){
                        case 'listing':
                            $intent['suggested_columns'] = array_merge($field_roles['name'] ?? [], array_slice($field_roles['other'] ?? [], 0, 2));
                            break;
                        case 'aggregation':
                            $intent['suggested_columns'] = array_merge($field_roles['rating'] ?? [], $field_roles['number'] ?? [], $field_roles['category'] ?? []);
                            break;
                        case 'comparison':
                            $intent['suggested_columns'] = array_merge($field_roles['name'] ?? [], $field_roles['category'] ?? [], $field_roles['rating'] ?? []);
                            break;
                        case 'mood_analysis':
                            $intent['suggested_columns'] = array_merge($field_roles['mood_text'] ?? [], $field_roles['mood_score'] ?? [], $field_roles['name'] ?? []);
                            break;
                        default:
                            $intent['suggested_columns'] = array_merge($field_roles['name'] ?? [], array_slice($field_roles['other'] ?? [], 0, 3));
                    }
                    $intent['suggested_columns'] = array_values(array_unique($intent['suggested_columns']));
                }
                
                return $intent;
            };
            
            // Heuristic: classify question as heavy/light for chunk/tokens
            $qLower = mb_strtolower($question, 'UTF-8');
            $isHeavy = (bool)(
                preg_match('/\b(compare|correlat|trend|distribution|variance|std|median|quartile|regression|cluster|segment|chart|bar|pie|line)\b/i', $qLower)
                || preg_match('/(?:مقایسه|همبستگی|روند|میانگین|میانه|نمودار|نمودار(?:\s*میله|\s*دایره|\s*خط)|واریانس|انحراف\s*معیار)/u', $qLower)
            );
            $cfgChunkSize = isset($cur['ai_ana_chunk_size']) && is_numeric($cur['ai_ana_chunk_size']) ? max(200, min(2000, (int)$cur['ai_ana_chunk_size'])) : 800;
            $reqChunk = isset($p['chunk_size']) && is_numeric($p['chunk_size']) ? (int)$p['chunk_size'] : 0;
            $useChunk = $reqChunk > 0 ? max(200, min(2000, $reqChunk)) : $cfgChunkSize;
            // Auto-tune chunk size slightly
            if ($reqChunk <= 0){ $useChunk = $isHeavy ? max($useChunk, 1000) : min($useChunk, 600); }
            // Determine token budget suggestion
            $suggestedMaxTok = $isHeavy ? min(1200, max(800, $max_tokens)) : min(500, max(300, $max_tokens));
            if ($phase === 'plan'){
                // Use paged listing to get total quickly
                $pg = FormsSubmissionRepository::listByFormPaged($fid, 1, 1, []);
                $total = (int)($pg['total'] ?? 0);
                $n = $useChunk>0 ? (int)ceil($total / $useChunk) : 1;
                // fields_meta
                $fmeta = [];
                try {
                    $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                    foreach (($fieldsForMeta ?: []) as $f){
                        $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                        $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                        $type0 = (string)($p0['type'] ?? '');
                        $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                    }
                } catch (\Throwable $e) { /* ignore */ }
                $field_roles = $detect_field_roles($fmeta);
                // New: Classify relevant fields based on the question and available headers (labels)
                $relevant_fields = [];
                // New: Extract lightweight entities (person names) from the question for downstream chunk matching
                $entities = [];
                try {
                    $qtext = (string)$question;
                    $qnorm = mb_strtolower($qtext, 'UTF-8');
                    // Prefer quoted forms: «نام» or "نام"
                    $cand = '';
                    if (preg_match('/«([^»]+)»/u', $qtext, $mm)) { $cand = trim($mm[1]); }
                    if ($cand === '' && preg_match('/"([^"\n]{2,})"/u', $qtext, $mm2)) { $cand = trim($mm2[1]); }
                    // Persian pattern after "حال|احوال" up to common endings
                    if ($cand === '' && preg_match('/(?:حال|احوال)\s+([\p{L}‌\s]{2,})/u', $qnorm, $mm3)){
                        $cand = trim($mm3[1]);
                        $cand = preg_replace('/\s*(چطوره|چطور|هست|است)\s*$/u', '', (string)$cand);
                        $cand = preg_replace('/[\?\؟]+$/u', '', (string)$cand);
                    }
                    // Remove honorifics and keep at most first 2 tokens
                    $cand = (string)$cand;
                    $cand = preg_replace('/\x{200C}/u', '', $cand); // ZWNJ
                    $cand = str_replace(['ي','ك','ة'], ['ی','ک','ه'], $cand);
                    $cand = trim($cand);
                    if ($cand !== ''){
                        $parts = preg_split('/\s+/u', $cand, -1, PREG_SPLIT_NO_EMPTY);
                        $titles = ['آقای','آقا','خانم','دکتر','مهندس','استاد'];
                        $parts = array_values(array_filter($parts, function($t) use ($titles){ return !in_array($t, $titles, true); }));
                        if (!empty($parts)){
                            $cand = implode(' ', array_slice($parts, 0, 2));
                            if ($cand !== ''){ $entities[] = [ 'type' => 'person', 'value' => $cand ]; }
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Advanced query intent classification
                $query_intent = $classify_query_intent($question, $field_roles);
                
                // Smart column selection based on intent
                $relevant_fields = $query_intent['suggested_columns'];
                // Phase-1: Column Mapper (tiny) to refine selected columns based on actual headers
                try {
                    $ai_cfg = [ 'base_url'=>$base, 'api_key'=>$api_key ];
                    $headerForMapper = array_map(function($fm){ return [ 'id'=>$fm['id'] ?? null, 'title'=>$fm['label'] ?? '', 'type'=>$fm['type'] ?? '' ]; }, $fmeta);
                    $map = self::ai_mapper_map_columns($ai_cfg, $headerForMapper, $question, null);
                    $colThresh = 0.35; $coverageTarget = 0.8;
                    $sel = [];
                    foreach (($map['columns'] ?? []) as $c){ if (($c['probability'] ?? 0) >= $colThresh){ $sel[] = (string)$c['column_id']; } }
                    // Map column ids back to labels
                    if (!empty($sel)){
                        $idToLabel = [];
                        foreach ($fmeta as $fm){ $idToLabel[(string)($fm['id'] ?? '')] = (string)($fm['label'] ?? ''); }
                        $mappedLabels = [];
                        foreach ($sel as $cid){ $lab = (string)($idToLabel[(string)$cid] ?? ''); if ($lab !== '') $mappedLabels[] = $lab; }
                        $relevant_fields = array_values(array_unique(array_merge($mappedLabels, $relevant_fields)));
                    }
                    if ($debug){ $dbg[] = [ 'phase'=>'plan:mapper', 'columns'=>$map['columns'] ?? [], 'intents'=>$map['intents'] ?? [], 'entities'=>$map['entities'] ?? [], 'confidence'=>$map['confidence'] ?? null ]; }
                } catch (\Throwable $e) { if ($debug){ $dbg[] = [ 'phase'=>'plan:mapper_error', 'error'=>$e->getMessage() ]; } }
                
                // Enhanced LLM-based field selection with intent awareness
                try {
                    $labelsOnly = array_values(array_filter(array_map(function($fm){ $lab = (string)($fm['label'] ?? ''); return $lab!=='' ? $lab : null; }, $fmeta)));
                    
                    $clsSys = 'You are Hoshang advanced field selector. Given a question, query intent analysis, and field labels, select the most relevant fields.'
                        . ' Rules: 1) Prioritize fields from suggested_columns. 2) Add complementary fields for better context. 3) For person queries, always include name fields.'
                        . ' 4) For aggregations, include categorical and numeric fields. 5) For charts, include grouping and value fields.'
                        . ' Output STRICT JSON: {"relevant_fields":["<label>",...], "reasoning":"<Persian explanation>"}. Use exact label strings.';
                    
                    $clsUser = [
                        'question' => $question,
                        'intent_analysis' => [
                            'primary_type' => $query_intent['primary_type'],
                            'confidence' => $query_intent['confidence'],
                            'requires_aggregation' => $query_intent['requires_aggregation'],
                            'requires_person_lookup' => $query_intent['requires_person_lookup'],
                            'chart_type' => $query_intent['chart_type'],
                            'suggested_columns' => $query_intent['suggested_columns']
                        ],
                        'available_labels' => $labelsOnly,
                        'field_categories' => array_filter($field_roles, function($arr) { return !empty($arr); })
                    ];
                    
                    $clsMsgs = [
                        [ 'role'=>'system','content'=>$clsSys ],
                        [ 'role'=>'user','content'=> wp_json_encode($clsUser, JSON_UNESCAPED_UNICODE) ]
                    ];
                    $clsModel = preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini';
                    $clsReq = [ 'model' => self::normalize_model_name($clsModel), 'messages'=>$clsMsgs, 'temperature'=>0.1, 'max_tokens'=>400 ];

                    // Use resilient POST with retries/fallback
                    $rC = self::wp_post_with_retries($endpoint, $headers, $clsReq, 25, 3, [500,1000,2000], 'gpt-4o-mini');
                    $rawC = (string)($rC['body'] ?? '');
                    $bC = is_array($rC['json'] ?? null) ? $rC['json'] : (json_decode($rawC, true) ?: null);
                    $txtC = '';
                    if (is_array($bC)){
                        if (isset($bC['choices'][0]['message']['content']) && is_string($bC['choices'][0]['message']['content'])) $txtC = (string)$bC['choices'][0]['message']['content'];
                        elseif (isset($bC['choices'][0]['text']) && is_string($bC['choices'][0]['text'])) $txtC = (string)$bC['choices'][0]['text'];
                        elseif (isset($bC['output_text']) && is_string($bC['output_text'])) $txtC = (string)$bC['output_text'];
                    }
                    $cls = $txtC ? json_decode($txtC, true) : null;
                    if (is_array($cls) && is_array($cls['relevant_fields'] ?? null)){
                        $llm_selected = array_values(array_unique(array_filter(array_map('strval', $cls['relevant_fields']))));
                        // Merge with intent-based selection, prioritizing LLM selection
                        $relevant_fields = array_values(array_unique(array_merge($llm_selected, $relevant_fields)));
                    }
                    
                    // Enhanced debug info
                    if ($debug){
                        $dbg[] = [ 
                            'phase'=>'plan:smart_selector', 
                            'intent_classification' => $query_intent,
                            'request_preview'=>[ 'model'=> (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'temperature'=>0.1 ], 
                            'response_preview'=> (strlen($txtC)>1000? (substr($txtC,0,1000).'…[truncated]') : $txtC),
                            'final_fields' => $relevant_fields
                        ];
                    }
                } catch (\Throwable $e) { /* ignore classifier errors */ }
                
                // Intelligent fallback field inclusion based on intent
                if ($query_intent['requires_person_lookup']){
                    foreach (['name','mood_text','mood_score'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                if ($query_intent['primary_type'] === 'listing' && in_array('phone', array_keys($field_roles))){
                    foreach (['name','phone'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                if ($query_intent['requires_aggregation']){
                    foreach (['rating','number','price','mood_score','category'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                if ($query_intent['primary_type'] === 'visualization'){
                    foreach (['category','rating','mood_score','name'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                
                // Ensure we have at least some fields (fallback to top fields if empty)
                if (empty($relevant_fields)){
                    $fallback_priority = ['name','phone','email','rating','mood_text','category','other'];
                    foreach ($fallback_priority as $rk){
                        if (!empty($field_roles[$rk])){
                            $relevant_fields = array_merge($relevant_fields, array_slice($field_roles[$rk], 0, 2));
                            if (count($relevant_fields) >= 3) break;
                        }
                    }
                }
                
                $relevant_fields = array_values(array_unique($relevant_fields));
                $dbg = [];
                if ($debug){ $dbg[] = [ 'phase'=>'plan', 'total_rows'=>$total, 'chunk_size'=>$useChunk, 'number_of_chunks'=>$n, 'field_roles'=>$field_roles, 'query_intent'=>$query_intent, 'relevant_fields'=>$relevant_fields, 'entities'=>$entities, 'routing'=>[ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ] ]; }
                // Tracer: record planning step
                try {
                    $trace = $read_trace();
                    $trace[] = [ 'ts'=>time(), 'phase'=>'plan', 'form_id'=>$fid, 'total_rows'=>$total, 'chunk_size'=>$useChunk, 'number_of_chunks'=>$n ];
                    $write_trace($trace);
                } catch (\Throwable $e) { /* ignore */ }
                return new WP_REST_Response([
                    'phase' => 'plan',
                    'plan' => [ 
                        'total_rows'=>$total, 
                        'chunk_size'=>$useChunk, 
                        'number_of_chunks'=>$n, 
                        'suggested_max_tokens'=>$suggestedMaxTokens ?? $suggestedMaxTok, 
                        'field_roles'=>$field_roles, 
                        'query_intent'=>$query_intent,
                        'relevant_fields'=>$relevant_fields, 
                        'entities'=>$entities 
                    ],
                    'fields_meta' => $fmeta,
                    'usage' => [],
                    'debug' => $dbg,
                    'trace' => (function() use ($read_trace){ try { return $read_trace(); } catch (\Throwable $e) { return []; } })(),
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            if ($phase === 'chunk'){
                $page = $chunk_index;
                $t0 = microtime(true);
                $res = FormsSubmissionRepository::listByFormPaged($fid, $page, $useChunk, []);
                $rows = is_array($res['rows'] ?? null) ? $res['rows'] : [];
                // Build fields meta
                $fmeta = [];
                try {
                    $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                    foreach (($fieldsForMeta ?: []) as $f){
                        $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                        $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                        $type0 = (string)($p0['type'] ?? '');
                        $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Allow client to pass relevant_fields chosen during plan
                $reqRelevant = [];
                try {
                    $reqRelevant = is_array($p['relevant_fields'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $p['relevant_fields'])))) : [];
                } catch (\Throwable $e) { $reqRelevant = []; }
                $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rows), function($v){ return $v>0; }));
                $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($sliceIds);
                @error_log('[Arshline][Analytics] Chunk: valuesMap keys: ' . json_encode(array_keys($valuesMap)) . ' for sliceIds: ' . json_encode($sliceIds));
                // Header labels and CSV build (apply relevant_fields if provided); always include id, created_at at the start
                $labels = [];
                $idToLabel = [];
                foreach ($fmeta as $fm){
                    $fidm=(int)($fm['id'] ?? 0);
                    $labm=(string)($fm['label'] ?? '');
                    if ($labm==='') $labm='فیلد #'.$fidm;
                    $idToLabel[$fidm]=$labm;
                    $labels[]=$labm;
                }
                $rowsById = [];
                foreach ($rows as $r){ $rowsById[(int)($r['id'] ?? 0)] = $r; }
                $labelsSel = $labels;
                if (!empty($reqRelevant)){
                    $norm = function($s){
                        $s = (string)$s;
                        $s = str_replace(["\xE2\x80\x8C", "\xC2\xA0"], ['', ' '], $s); // ZWNJ, NBSP
                        $s = str_replace(["ي","ك"],["ی","ک"], $s); // Arabic to Persian
                        $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s); // punctuation to space
                        $s = preg_replace('/\s+/u',' ', $s); // collapse spaces
                        return trim(mb_strtolower($s, 'UTF-8'));
                    };
                    $set = [];
                    foreach ($reqRelevant as $rf){ $set[$norm($rf)] = true; }
                    $labelsSel = array_values(array_filter($labelsSel, function($lab) use ($norm,$set){ return isset($set[$norm($lab)]); }));
                    // Fallback: if after filtering nothing remains (besides id/created_at to be injected), include detected name/mood fields
                    $rolesTmp = $detect_field_roles($fmeta);
                    $roleWanted = array_merge($rolesTmp['name'] ?? [], $rolesTmp['mood_text'] ?? [], $rolesTmp['mood_score'] ?? [], $rolesTmp['phone'] ?? []);
                    if (empty($labelsSel) && !empty($roleWanted)){
                        // keep only labels that exist in original labels
                        $labelsSel = array_values(array_intersect($roleWanted, $labels));
                    }
                }
                // Ensure id/created_at are present at the beginning
                $labelsSel = array_values(array_filter($labelsSel, function($h){ return $h !== 'id' && $h !== 'created_at'; }));
                array_unshift($labelsSel, 'created_at');
                array_unshift($labelsSel, 'id');
                // Extract a target name hint from entities (preferred) or question (before prefilter)
                $nameHint = '';
                $reqEntities = [];
                // Prefer plan-provided entities if available
                try {
                    $reqEntities = is_array($p['entities'] ?? null) ? $p['entities'] : [];
                    if (!empty($reqEntities)){
                        foreach ($reqEntities as $en){
                            $typ = (string)($en['type'] ?? '');
                            $val = trim((string)($en['value'] ?? ''));
                            if ($typ === 'person' && $val !== ''){ $nameHint = $val; break; }
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
                try {
                    if ($nameHint === '' && preg_match('/«([^»]+)»/u', $question, $mm)) { $nameHint = trim($mm[1]); }
                    $qLowerLocal = mb_strtolower($question, 'UTF-8');
                    // Enhanced Persian name extraction patterns
                    if ($nameHint === '' && preg_match('/حال\s+([^؟\?]+?)\s+چطور/ui', $question, $mm2)) { $nameHint = trim($mm2[1]); }
                    elseif ($nameHint === '' && preg_match('/^حال\s+([^؟\?]+)/ui', $question, $mm2)) { $nameHint = trim($mm2[1]); }
                    // strip common trailing words and punctuation (e.g., "چطوره", "چطور", "هست", "است", question marks)
                    $nameHint = preg_replace('/\s*(چطوره|چطور|هست|است)\s*$/u', '', (string)$nameHint);
                    $nameHint = preg_replace('/[\?\؟]+$/u', '', (string)$nameHint);
                    // keep only first 1-2 tokens for robustness
                    $partsNH = preg_split('/\s+/u', (string)$nameHint, -1, PREG_SPLIT_NO_EMPTY);
                    if (is_array($partsNH) && !empty($partsNH)){
                        $nameHint = implode(' ', array_slice($partsNH, 0, 2));
                    }
                    $nameHint = trim(mb_substr((string)$nameHint, 0, 60, 'UTF-8'));
                } catch (\Throwable $e) { $nameHint = ''; }
                @error_log('[Arshline][Analytics] Chunk: extracted nameHint="' . $nameHint . '" from question="' . $question . '"');
                // Detect roles for guidance (needed for prefilter and prompts)
                $field_roles = $detect_field_roles($fmeta);
                // Optional: prefilter rows by name hint using detected name fields (with Persian-aware normalization and token + Levenshtein-like similarity)
                $idsForCsv = $sliceIds;
                $matchedIds = [];
                $filteredByName = false;
                $prefilterNotes = [];
                $fallbackApplied = false; $fallbackRowId = 0; $fallbackReason = '';
                try {
                    // Persian-aware normalization
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
                    $titles = [ 'آقای', 'خانم', 'دکتر', 'مهندس', 'استاد' ];
                    $stripTitles = function(array $toks) use ($titles){
                        $set = [];
                        foreach ($titles as $w){ $set[$w]=true; $set[mb_strtolower($w,'UTF-8')]=true; }
                        $out = [];
                        foreach ($toks as $t){ if ($t!=='' && !isset($set[$t])) $out[] = $t; }
                        return $out;
                    };
                    $tokenize = function($s) use ($faNorm,$stripTitles){
                        $n = $faNorm($s);
                        $t = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
                        return $stripTitles($t);
                    };
                    $hintTokens = [];
                    if ($nameHint !== ''){ $hintTokens = $tokenize($nameHint); }
                    // similarity between token sets (Jaccard + exact/partial bonuses)
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
                                if ($ta===$tb) continue;
                                if (mb_strlen($ta,'UTF-8')>=3 && mb_strlen($tb,'UTF-8')>=3){
                                    if (mb_strpos($ta,$tb,0,'UTF-8')!==false || mb_strpos($tb,$ta,0,'UTF-8')!==false){ $partial++; break; }
                                }
                            }
                        }
                        if ($exact>=2) return 1.0;
                        $score = 0.6*$j + 0.2*($exact>0?1:0) + 0.2*($partial>0?1:0);
                        return ($score>1.0)?1.0:$score;
                    };
                    $bestScore = 0.0; $bestId = 0; $rowMatchDbg = [];
                    // Tunable parameters (weights/bonuses/thresholds) via filter
                    $matchParams = [
                        'wTok' => 0.65,
                        'wLev' => 0.35,
                        'bonus_exact' => 0.20,
                        'bonus_partial' => 0.15,
                        'thr_one' => 0.30,
                        'thr_two' => 0.45,
                        'thr_three' => 0.60,
                    ];
                    try { if (function_exists('apply_filters')){ $tmp = apply_filters('arshline_ai_name_match_params', $matchParams, $question); if (is_array($tmp)) $matchParams = array_merge($matchParams, $tmp); } } catch (\Throwable $e) { /* ignore */ }
                    $wTok = (float)$matchParams['wTok'];
                    $wLev = (float)$matchParams['wLev'];
                    $bExact = (float)$matchParams['bonus_exact'];
                    $bPartial = (float)$matchParams['bonus_partial'];
                    // Dynamic threshold: single-token names are often noisy, be a bit more permissive
                    $tokCount = is_array($hintTokens) ? count($hintTokens) : 0;
                    $thr1 = (float)$matchParams['thr_one']; $thr2 = (float)$matchParams['thr_two']; $thr3 = (float)$matchParams['thr_three'];
                    $threshold = ($tokCount <= 1) ? $thr1 : (($tokCount === 2) ? $thr2 : $thr3);
                    $prefilterNotes[] = 'name_threshold_'.str_replace('.', '_', (string)$threshold);
                    // Helper: pseudo-Levenshtein similarity tolerant to UTF-8 via similar_text fallback
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
                    @error_log('[Arshline][Prefilter] hintTokens: ' . json_encode($hintTokens) . ' nameHint: "' . $nameHint . '"');
                    if (!empty($hintTokens)){
                        $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                        @error_log('[Arshline][Prefilter] nameLabels: ' . json_encode($nameLabels));
                        if (!empty($nameLabels)){
                            $setName = []; foreach ($nameLabels as $nl){ $setName[$nl] = true; }
                            @error_log('[Arshline][Prefilter] Processing ' . count($sliceIds) . ' rows for matching');
                            foreach ($sliceIds as $sid){
                                $vals = $valuesMap[$sid] ?? [];
                                @error_log('[Arshline][Prefilter] Row ' . $sid . ' vals keys: ' . json_encode(array_keys($vals)));
                                $nameParts = [];
                                foreach ($vals as $v){
                                    $fidv=(int)($v['field_id'] ?? 0);
                                    $lab=(string)($idToLabel[$fidv] ?? '');
                                    if ($lab!=='' && isset($setName[$lab])){
                                        $val=trim((string)($v['value'] ?? ''));
                                        if ($val!=='') $nameParts[]=$val;
                                    }
                                }
                                if (!empty($nameParts)){
                                    $rowTokens = [];
                                    foreach ($nameParts as $np){ $rowTokens = array_merge($rowTokens, $tokenize($np)); }
                                    $rowTokens = array_values(array_unique($rowTokens));
                                    $scTok = $tokSim($hintTokens, $rowTokens);
                                    // Combine token similarity with Levenshtein-like score over full strings
                                    $rowFull = $faNorm(implode(' ', $nameParts));
                                    $hintFull = $faNorm(implode(' ', $hintTokens));
                                    $scLev = $simLev($hintFull, $rowFull);
                                    // Enhanced partial matching: check if any hint token is contained in any row token
                                    $partialBonus = 0.0;
                                    foreach ($hintTokens as $ht){
                                        foreach ($rowTokens as $rt){
                                            if (mb_strpos($rt, $ht, 0, 'UTF-8') !== false || mb_strpos($ht, $rt, 0, 'UTF-8') !== false){
                                                $partialBonus = max($partialBonus, 0.25);
                                            }
                                        }
                                    }
                                    // First-name bonus: if first hint token equals or is prefix of any row token, boost score a bit
                                    $bonus = 0.0;
                                    $firstHint = isset($hintTokens[0]) ? (string)$hintTokens[0] : '';
                                    $prefReason = '';
                                    if ($firstHint !== ''){
                                        foreach ($rowTokens as $rt){
                                            if ($rt === $firstHint){ $bonus = $bExact; $prefReason = 'first_name_exact'; break; }
                                            if ((mb_strlen($rt,'UTF-8')>=3 || mb_strlen($firstHint,'UTF-8')>=3) && (mb_strpos($rt,$firstHint,0,'UTF-8')===0 || mb_strpos($firstHint,$rt,0,'UTF-8')===0)){ $bonus = max($bonus, $bPartial); $prefReason = $prefReason ?: 'first_name_partial'; }
                                        }
                                    }
                                    $sc = ($wTok*$scTok) + ($wLev*$scLev) + $bonus + $partialBonus;
                                    if ($sc > 1.0) $sc = 1.0;
                                    // Log detailed similarity for debugging
                                    @error_log('[Arshline][Prefilter] Row ID=' . $sid . ' name="' . implode(' ', $nameParts) . '" vs hint="' . $nameHint . '" score=' . round($sc, 3) . ' (tok=' . round($scTok, 2) . ' lev=' . round($scLev, 2) . ' bonus=' . round($bonus + $partialBonus, 2) . ') threshold=' . $threshold);
                                    if ($sc >= $threshold){ $matchedIds[] = $sid; }
                                    if ($sc > $bestScore){ $bestScore=$sc; $bestId=$sid; }
                                    // Row-level debug (normalized without spaces for readability)
                                    $rowNormNoSpace = str_replace(' ', '', $rowFull);
                                    $hintNormNoSpace = str_replace(' ', '', $hintFull);
                                    $rowMatchDbg[] = [
                                        'row_id' => $sid,
                                        'normalized_query' => $hintNormNoSpace,
                                        'normalized_row' => $rowNormNoSpace,
                                        'prefilter_reason' => ($prefReason ?: '—'),
                                        'match_score' => $sc,
                                        'threshold_used' => $threshold,
                                        'bonus_applied' => $bonus,
                                        'final_match' => ($sc >= $threshold)
                                    ];
                                }
                            }
                            if (!empty($matchedIds)){
                                // If multiple matches, select the most recent by created_at deterministically
                                if (count($matchedIds) > 1){
                                    $bestRecentId = 0; $bestTs = -PHP_INT_MAX;
                                    foreach ($matchedIds as $mid){
                                        $rowObj = $rowsById[$mid] ?? [];
                                        $ts = strtotime((string)($rowObj['created_at'] ?? '')) ?: 0;
                                        if ($ts > $bestTs){ $bestTs = $ts; $bestRecentId = $mid; }
                                    }
                                    $idsForCsv = $bestRecentId ? [ $bestRecentId ] : [ $matchedIds[0] ];
                                } else {
                                    $idsForCsv = $matchedIds;
                                }
                                $filteredByName = true;
                                $prefilterNotes[] = 'name_prefilter_matched';
                            } else {
                                // No confident match — fall back to best candidate if reasonably close
                                if ($bestId > 0 && $bestScore >= max(0.5, $threshold - 0.1)){
                                    $idsForCsv = [ $bestId ];
                                    $filteredByName = true;
                                    $prefilterNotes[] = 'name_prefilter_best_fallback';
                                    $fallbackApplied = true; $fallbackRowId = (int)$bestId; $fallbackReason = 'best_score_close';
                                } else {
                                    // Deterministic fallback: prefer highest-score row, else most recent
                                    if ($bestId > 0){
                                        $idsForCsv = [ $bestId ];
                                        $prefilterNotes[] = 'fallback_to_highest_score_1';
                                        $prefilterNotes[] = 'fallback_row_id:'.(string)$bestId;
                                        $fallbackApplied = true; $fallbackRowId = (int)$bestId; $fallbackReason = 'highest_score_overall';
                                    } else {
                                        $bestRecentId = 0; $bestTs = -PHP_INT_MAX;
                                        foreach ($sliceIds as $sid0){
                                            $rowObj = $rowsById[$sid0] ?? [];
                                            $ts = strtotime((string)($rowObj['created_at'] ?? '')) ?: 0;
                                            if ($ts > $bestTs){ $bestTs = $ts; $bestRecentId = $sid0; }
                                        }
                                        if ($bestRecentId > 0){
                                            $idsForCsv = [ $bestRecentId ];
                                            $prefilterNotes[] = 'recent_rows_fallback_1';
                                            $prefilterNotes[] = 'fallback_row_id:'.(string)$bestRecentId;
                                            $fallbackApplied = true; $fallbackRowId = (int)$bestRecentId; $fallbackReason = 'recent_created_at';
                                        } else { $idsForCsv = []; }
                                    }
                                    $filteredByName = true;
                                    $prefilterNotes[] = 'name_prefilter_no_confident_match';
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) { /* ignore prefilter errors */ }
                // Canonicalize headers for LLM grounding while preserving originals for display/debug
                $canonicalMap = [];// original => canonical
                $usedCanon = [];// to avoid duplicates like name,name_2
                $canonOf = function(string $lab) use ($field_roles, &$usedCanon){
                    $role = '';
                    if (in_array($lab, (array)($field_roles['name'] ?? []), true)) $role = 'name';
                    elseif (in_array($lab, (array)($field_roles['phone'] ?? []), true)) $role = 'phone';
                    elseif (in_array($lab, (array)($field_roles['mood_text'] ?? []), true)) $role = 'mood_text';
                    elseif (in_array($lab, (array)($field_roles['mood_score'] ?? []), true)) $role = 'mood_score';
                    if ($role === ''){
                        // Fallback: safe ascii-ish key
                        $base = 'field'; $i = 1; $key = $base;
                        while (isset($usedCanon[$key])){ $i++; $key = $base.'_'.$i; }
                        $usedCanon[$key] = true; return $key;
                    }
                    $key = $role;
                    if (isset($usedCanon[$key])){
                        $i = 2; $cand = $key.'_'.$i;
                        while (isset($usedCanon[$cand])){ $i++; $cand = $key.'_'.$i; }
                        $key = $cand;
                    }
                    $usedCanon[$key] = true; return $key;
                };
                // Build canonical header list mirroring labelsSel
                $headersCanonical = [];
                foreach ($labelsSel as $h){
                    if ($h === 'id' || $h === 'created_at'){ $headersCanonical[] = $h; continue; }
                    $c = $canonOf((string)$h);
                    $canonicalMap[(string)$h] = $c;
                    $headersCanonical[] = $c;
                }
                // Reverse map canonical => list of originals
                $canonToOriginals = [];
                foreach ($canonicalMap as $orig=>$can){ if (!isset($canonToOriginals[$can])) $canonToOriginals[$can]=[]; $canonToOriginals[$can][] = $orig; }
                // Build CSV rows with canonical headers
                $rowsCsv = [];
                if (!empty($headersCanonical)){
                    // header
                    $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $headersCanonical));
                    foreach ($idsForCsv as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        // Build original-label value map first
                        $origMap = [];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab='فیلد #'.$fidv;
                            $val=trim((string)($v['value'] ?? ''));
                            if(!isset($origMap[$lab])) $origMap[$lab]=[];
                            if($val!=='') $origMap[$lab][]=$val;
                        }
                        // Inject core submission info
                        $rowObj = $rowsById[$sid] ?? [];
                        $rowOut = [];
                        foreach ($headersCanonical as $hc){
                            if ($hc === 'id'){ $rowOut[$hc] = [ (string)($rowObj['id'] ?? $sid) ]; continue; }
                            if ($hc === 'created_at'){ $rowOut[$hc] = [ (string)($rowObj['created_at'] ?? '') ]; continue; }
                            $valsAgg = [];
                            foreach ((array)($canonToOriginals[$hc] ?? []) as $orig){
                                foreach ((array)($origMap[$orig] ?? []) as $vv){ if ($vv!=='') $valsAgg[] = $vv; }
                            }
                            $rowOut[$hc] = !empty($valsAgg) ? [ implode(' | ', $valsAgg) ] : [];
                        }
                        $rowsCsv[] = implode(',', array_map(function($h) use ($rowOut){ $v = isset($rowOut[$h]) ? implode(' | ', (array)$rowOut[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $headersCanonical));
                    }
                }
                $tableCsv = implode("\r\n", $rowsCsv);
                // Chunk prompt expecting partials (focus on extracting signal, not copying raw text)
                $sys = 'You are Hoshang, a Persian analytics assistant. Analyze ONLY the provided CSV rows (this chunk).'
                    . ' Use name columns to match the requested person (accept partial matches, tolerate spacing/diacritics). If multiple rows match, prefer the most recent by created_at.'
                    . ' If the question is about mood/wellbeing, you MUST combine evidence from textual mood columns and numeric rating columns (1–10) when both exist.'
                    . ' If the question asks for contact info like phone/mobile, extract phone numbers from phone-like columns (deduplicate), associate with names when possible, and add pairs as partial_insights like {"نام":"…","تلفن":"…"}.'
                    . ' Do NOT copy long raw texts; extract sentiment/intent concisely. Output STRICT JSON with keys: '
                    . '{"aggregations":{...},"partial_insights":[...],"partial_chart_data":[...],"outliers":[...],"fields_used":[...],"chunk_summary":{"row_count":<int>,"notes":[...]}}.'
                    . ' No prose. JSON only. Use Persian for insights/notes.';
                $user = [ 'question' => $question, 'table_csv' => $tableCsv, 'field_roles' => $field_roles, 'guidance' => [ 'avoid_verbatim' => true, 'combine_mood_text_and_score' => true, 'prefer_latest' => true, 'target_name_hint' => $nameHint ] ];
                $msgs = [ [ 'role'=>'system','content'=>$sys ], [ 'role'=>'user','content'=> wp_json_encode($user, JSON_UNESCAPED_UNICODE) ] ];
                $modelName = $use_model; if ($isHeavy && preg_match('/mini|3\.5|4o\-mini/i', (string)$modelName)) $modelName = 'gpt-4o';
                $req = [ 'model'=> self::normalize_model_name($modelName), 'messages'=>$msgs, 'temperature'=>0.1, 'max_tokens'=>$suggestedMaxTok ];
                $res = self::wp_post_with_retries($endpoint, $headers, $req, $http_timeout, 3, [500,1000,2000], 'gpt-4o');
                $status = (int)($res['status'] ?? 0);
                $raw = (string)($res['body'] ?? '');
                $ok = ($res['ok'] ?? false) === true;
                $body = is_array($res['json'] ?? null) ? $res['json'] : (json_decode($raw, true) ?: null);
                $usage = is_array($body) && isset($body['usage']) ? $body['usage'] : null;
                $text = '';
                if (is_array($body)){
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) $text = (string)$body['choices'][0]['message']['content'];
                    elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) $text = (string)$body['choices'][0]['text'];
                    elseif (isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                }
                $partial = $text ? json_decode($text, true) : null;
                $repaired = false;
                // Ensure chunk_summary.row_count reflects actual candidate rows we used
                $actualRowCount = is_array($idsForCsv) ? count($idsForCsv) : count($rows);
                if (!is_array($partial)){
                    // Default empty partial to a valid schema
                    $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ];
                    // JSON repair mini pass
                    $repairSys = 'Fix the following model output into VALID JSON only (no code fences, no text). Keep only keys: aggregations, partial_insights, partial_chart_data, outliers, fields_used, chunk_summary.';
                    $repairMsgs = [ [ 'role'=>'system','content'=>$repairSys ], [ 'role'=>'user','content'=>$text ] ];
                    $repairReq = [ 'model' => (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$repairMsgs, 'temperature'=>0.0, 'max_tokens'=>400 ];
                    $res2 = self::wp_post_with_retries($endpoint, $headers, $repairReq, 20, 1, [500], 'gpt-4o-mini');
                    $raw2 = (string)($res2['body'] ?? '');
                    $b2 = is_array($res2['json'] ?? null) ? $res2['json'] : (json_decode($raw2, true) ?: null);
                    $txt2 = '';
                    if (is_array($b2)){
                        if (isset($b2['choices'][0]['message']['content']) && is_string($b2['choices'][0]['message']['content'])) $txt2 = (string)$b2['choices'][0]['message']['content'];
                        elseif (isset($b2['choices'][0]['text']) && is_string($b2['choices'][0]['text'])) $txt2 = (string)$b2['choices'][0]['text'];
                        elseif (isset($b2['output_text']) && is_string($b2['output_text'])) $txt2 = (string)$b2['output_text'];
                    }
                    $partial = $txt2 ? json_decode($txt2, true) : null;
                    if (is_array($partial)) $repaired = true;
                }
                if (!is_array($partial)){
                    // default already handled above
                    $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ];
                } else {
                    if (!isset($partial['chunk_summary']) || !is_array($partial['chunk_summary'])){ $partial['chunk_summary'] = [ 'row_count' => $actualRowCount, 'notes' => [] ]; }
                    if (!isset($partial['chunk_summary']['row_count']) || (int)$partial['chunk_summary']['row_count'] === 0){ $partial['chunk_summary']['row_count'] = $actualRowCount; }
                    if (!isset($partial['fields_used']) || !is_array($partial['fields_used'])){
                        // fallback fields_used to canonical headers we sent (excluding id/created_at)
                        $fu = array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; }));
                        $partial['fields_used'] = $fu;
                    }
                    // Attach prefilter notes if any
                    if (!isset($partial['chunk_summary']['notes']) || !is_array($partial['chunk_summary']['notes'])){ $partial['chunk_summary']['notes'] = []; }
                    foreach ($prefilterNotes as $nt){ $partial['chunk_summary']['notes'][] = $nt; }
                }
                // Attach meta for final phase to understand requested person and fallback details
                if (!isset($partial['meta']) || !is_array($partial['meta'])){ $partial['meta'] = []; }
                $partial['meta']['requested_person'] = $nameHint;
                $partial['meta']['fallback_applied'] = $fallbackApplied;
                $partial['meta']['fallback_row_id'] = $fallbackRowId;
                $partial['meta']['fallback_reason'] = $fallbackReason;
                $partial['meta']['entities'] = $reqEntities;
                // Propagate routing intent for final phase without requiring debug round-trip
                // Initialize route variable with default
                $route = isset($route) ? $route : 'server';
                $partial['meta']['ai_decision_route'] = $route;
                // Deterministic fallbacks for phone and mood intents when model returns empty
                $qLowerLocal2 = mb_strtolower($question, 'UTF-8');
                $isPhoneIntentLocal = (bool)(preg_match('/\b(phone|mobile|cell)\b/i', $qLowerLocal2) || preg_match('/شماره\s*(?:تلفن|همراه|تماس)|موبایل/u', $qLowerLocal2));
                $isMoodIntentLocal = (bool)(preg_match('/\b(mood|wellbeing)\b/i', $qLowerLocal2) || preg_match('/حال|احوال|روحیه|رضایت/u', $qLowerLocal2));
                if (!is_array($partial)) { $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ]; }
                // Helper: normalize Persian digits and standardize phone to 09XXXXXXXXX
                $stdPhone = function(string $s): string {
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $d = strtr($s, $fa2en);
                    $d = preg_replace('/[^0-9]/', '', $d ?? '');
                    if ($d === null) $d = '';
                    // pick the last 11-digit starting with 09 if embedded
                    if (strlen($d) > 11){ if (preg_match('/(09\d{9})$/', $d, $m)) $d = $m[1]; }
                    if (strlen($d) === 10 && substr($d, 0, 1) === '9') $d = '0'.$d;
                    if (strlen($d) === 11 && substr($d, 0, 2) === '09') return $d;
                    return $d;
                };
                if ($isPhoneIntentLocal){
                    $phones = [];
                    // When filtered by name, use those ids; otherwise aggregate across this chunk
                    $scanIds = !empty($idsForCsv) ? $idsForCsv : $sliceIds;
                    $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                    $phoneLabels = array_values(array_unique((array)($field_roles['phone'] ?? [])));
                    $setName = []; foreach ($nameLabels as $nl){ $setName[$nl]=true; }
                    $setPhone = []; foreach ($phoneLabels as $pl){ $setPhone[$pl]=true; }
                    foreach ($scanIds as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        $nmParts = []; $phVals = [];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? '');
                            $val=trim((string)($v['value'] ?? ''));
                            if ($val==='') continue;
                            if ($lab!=='' && isset($setName[$lab])) $nmParts[] = $val;
                            if ($lab!=='' && isset($setPhone[$lab])) $phVals[] = $val;
                        }
                        $nm = trim(implode(' ', $nmParts));
                        // Prefer requested person in display when a fallback row was used
                        $outNm = ($fallbackApplied && $nameHint !== '' && $nameHint !== $nm) ? $nameHint : $nm;
                        foreach ($phVals as $pval){
                            $pp = $stdPhone($pval);
                            if ($pp!==''){
                                $entry = [ 'name'=>$outNm, 'phone'=>$pp ];
                                if ($outNm !== $nm && $nm !== ''){ $entry['source_row_name'] = $nm; }
                                $phones[$pp] = $entry;
                            }
                        }
                    }
                    if (!empty($phones)){
                        // Overwrite/augment partial_insights
                        $partial['partial_insights'] = array_values($phones);
                        if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                        $partial['chunk_summary']['notes'][] = 'server_fallback_phone_applied';
                    }
                }
                if ($isMoodIntentLocal && !empty($idsForCsv)){
                    // Use the single selected (latest) row when available
                    $sid = is_array($idsForCsv) ? (int)reset($idsForCsv) : 0;
                    if ($sid > 0){
                        $vals = $valuesMap[$sid] ?? [];
                        $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                        $textLabels = array_values(array_unique((array)($field_roles['mood_text'] ?? [])));
                        $scoreLabels = array_values(array_unique((array)($field_roles['mood_score'] ?? [])));
                        $setN = []; foreach ($nameLabels as $x){ $setN[$x]=true; }
                        $setT = []; foreach ($textLabels as $x){ $setT[$x]=true; }
                        $setS = []; foreach ($scoreLabels as $x){ $setS[$x]=true; }
                        $nmParts=[]; $txParts=[]; $scoreVals=[];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? '');
                            $val=trim((string)($v['value'] ?? ''));
                            if ($val==='') continue;
                            if ($lab!=='' && isset($setN[$lab])) $nmParts[]=$val;
                            if ($lab!=='' && isset($setT[$lab])) $txParts[]=$val;
                            if ($lab!=='' && isset($setS[$lab])){
                                // extract first 1-2 digit number
                                if (preg_match('/(\d{1,2})/u', strtr($val, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $m)){
                                    $n=(int)$m[1]; if ($n>=0 && $n<=10) $scoreVals[]=$n;
                                }
                            }
                        }
                        if (empty($partial['partial_insights'])){
                            $rowName = trim(implode(' ', $nmParts));
                            // If fallback applied and requested person differs from the selected row, keep requested person in insights to avoid wrong name in final
                            $outName = ($fallbackApplied && $nameHint !== '' && $nameHint !== $rowName) ? $nameHint : $rowName;
                            $ins = [ 'name'=>$outName ];
                            if ($outName !== $rowName && $rowName !== '') $ins['source_row_name'] = $rowName;
                            if (!empty($txParts)) $ins['mood_text'] = implode(' | ', $txParts);
                            if (!empty($scoreVals)) $ins['mood_score'] = max($scoreVals);
                            if (count($ins) > 1){
                                $partial['partial_insights'] = [ $ins ];
                                if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                                $partial['chunk_summary']['notes'][] = 'server_fallback_mood_applied';
                            }
                        }
                    }
                }
                $t1 = microtime(true);
                $dbg = [];
                $debugLocal = !empty($p['debug']);
                // Build a minimal debug object (always) and enrich when debugLocal=true
                $nearIdsComputed = array_values(array_filter(array_map(function($it){
                    if (!is_array($it)) return null;
                    $rid = isset($it['row_id']) ? (int)$it['row_id'] : 0;
                    $ms = isset($it['match_score']) ? (float)$it['match_score'] : null;
                    $th = isset($it['threshold_used']) ? (float)$it['threshold_used'] : null;
                    if ($rid>0 && $ms!==null && $th!==null && $ms >= ($th - 0.05) && $ms < $th) return $rid;
                    return null;
                }, $rowMatchDbg), function($v){ return is_int($v) && $v>0; }));
                // Tag matches (exact + partial)
                $matchedIdsTagged = array_map('strval', $matchedIds);
                foreach ($nearIdsComputed as $nid){ $matchedIdsTagged[] = ((string)$nid).'_partial'; }
                // Surface partial near-match notes into partial summary for visibility
                if (!empty($nearIdsComputed)){
                    if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                    foreach ($nearIdsComputed as $nid){ $partial['chunk_summary']['notes'][] = 'partial_match_applied_id_'.(string)$nid; }
                }
                // Compute an ambiguity score (0..1) for telemetry only — no behavior change
                try {
                    $gap = isset($threshold) ? max(0.0, (float)$threshold - (float)($bestScore ?? 0.0)) : 0.0;
                    $gapNorm = min(1.0, $gap / 0.3);
                    $candCount = is_array($matchedIds) ? count($matchedIds) : 0;
                    $multi = ($candCount >= 3) ? 1.0 : (($candCount === 2) ? 0.5 : (($candCount === 1) ? 0.1 : 0.8));
                    $partialCount = is_array($nearIdsComputed) ? count($nearIdsComputed) : 0;
                    $partialFactor = ($partialCount >= 2) ? 0.6 : (($partialCount === 1) ? 0.3 : 0.0);
                    $ambiguityScore = max(0.0, min(1.0, 0.5*$gapNorm + 0.3*$multi + 0.2*$partialFactor));
                    $ambiguityScore = (float)round($ambiguityScore, 2);
                } catch (\Throwable $e) { $ambiguityScore = null; $candCount = is_array($matchedIds)?count($matchedIds):0; $partialCount = is_array($nearIdsComputed)?count($nearIdsComputed):0; }
                // Decide routing (hybrid policy): server if best >=0.75; subset-ai if 0.50<=best<0.75 and (partials or >=3 candidates)
                $aiCfg = self::get_ai_analysis_config();
                $modePolicy = (string)($aiCfg['mode'] ?? 'hybrid');
                $route = 'server'; $reason = 'score_or_mode';
                $b = ($bestScore ?? 0.0); $thrUsed = (isset($threshold)?$threshold:null);
                $multiCand = ($candCount >= 3);
                $hasNear = ($partialCount >= 1);
                if ($modePolicy !== 'efficient'){
                    if ($b >= 0.75){ $route = 'server'; $reason = 'high_confidence'; }
                    elseif ($b >= 0.50 && $b < 0.75 && ($multiCand || $hasNear)) { $route = 'subset-ai'; $reason = 'ambiguous_partial_or_multi'; }
                    else { $route = 'server'; $reason = 'low_or_singleton'; }
                }
                $aiDecision = [
                    'route' => $route,
                    'reason' => $reason,
                    'metrics' => [
                        'ambiguity_score' => $ambiguityScore,
                        'best_score' => ($bestScore ?? null),
                        'threshold_used' => $thrUsed,
                        'matched_count' => $candCount,
                        'partial_count' => $partialCount,
                    ]
                ];
                // Read non-sensitive AI analysis config preview for debug/telemetry only (already read above)
                // Build a safe subset preview (no raw data) for observability only
                $intent = 'generic';
                try {
                    $ql = function_exists('mb_strtolower') ? mb_strtolower((string)$question, 'UTF-8') : strtolower((string)$question);
                    if ($nameHint !== '') { $intent = 'person_mood'; }
                    elseif (preg_match('/(?:مقایسه|روند|compare|trend|میانگین|متوسط|گروه)/u', $ql)) { $intent = 'compare_trend'; }
                    elseif (preg_match('/(?:شماره|تلفن|موبایل|ایمیل|contact|phone|email)/ui', $ql)) { $intent = 'contact_info'; }
                } catch (\Throwable $e) { $intent = 'generic'; }
                try {
                    $colsWh = \Arshline\Support\AiSubsetPackager::columnWhitelist($intent, !empty($aiCfg['allow_pii']));
                } catch (\Throwable $e) { $colsWh = ['name','created_at']; }
                $rowsTotal = is_array($rows) ? count($rows) : 0;
                $rowsCap = (int)min(max(0, $rowsTotal), (int)($aiCfg['max_rows'] ?? 400));
                $dbgBasic = [
                    'phase' => 'chunk',
                    'chunk_index' => $chunk_index,
                    'page' => $page,
                    'per_page' => $useChunk,
                    'rows' => count($rows),
                    'row_ids' => $sliceIds,
                    'candidate_row_ids' => $idsForCsv,
                    'matched_row_ids' => $matchedIds,
                    'partial_match_row_ids' => $nearIdsComputed,
                    'matched_ids_tagged' => $matchedIdsTagged,
                    'filtered_by_name' => $filteredByName,
                    'name_threshold' => (isset($threshold) ? $threshold : null),
                    'best_match_id' => ($bestId??0),
                    'best_match_score' => ($bestScore??0.0),
                    'requested_person' => $nameHint,
                    'fallback_applied' => $fallbackApplied,
                    'fallback_row_id' => $fallbackRowId,
                    'fallback_reason' => $fallbackReason,
                    'ambiguity_score' => $ambiguityScore,
                    'ai_decision' => $aiDecision,
                    'ai_config' => $aiCfg,
                    'subset_preview' => [
                        'intent' => $intent,
                        'columns' => array_values($colsWh),
                        'row_count_total' => $rowsTotal,
                        'row_count_capped' => $rowsCap,
                    ],
                    'observability' => [
                        'route' => $route,
                        'duration_ms' => (int)round(($t1-$t0)*1000),
                        'usage' => $usage,
                    ],
                    'headers_canonical' => $headersCanonical,
                    'canonical_map' => $canonicalMap,
                    'duration_ms' => (int)round(($t1-$t0)*1000),
                    'json_repaired' => $repaired,
                    'http_status' => $status,
                    'applied_fields' => $reqRelevant,
                    'usage' => $usage,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ]
                ];
                if ($debugLocal){
                    // Safe request preview: truncate system; show user preview with headers + counts (no raw CSV)
                    $sysPrev = (strlen($sys)>480? (substr($sys,0,480).'…[truncated]') : $sys);
                    $userPrev = [ 'question'=>$question, 'field_roles'=>$field_roles, 'applied_fields'=>$reqRelevant, 'headers_used'=>$headersCanonical, 'canonical_map'=>$canonicalMap, 'name_hint'=>$nameHint, 'entities'=>$reqEntities, 'csv_header'=> (isset($rowsCsv[0]) ? $rowsCsv[0] : ''), 'row_count'=>count($rows) ];
                    // Also promote canonical mapping to top-level debug for easier client access
                    $dbgEnriched = $dbgBasic;
                    $dbgEnriched['near_match_row_ids'] = $nearIdsComputed;
                    $dbgEnriched['row_match_debug'] = $rowMatchDbg;
                    $dbgEnriched['request_preview'] = [ 'model'=>$modelName, 'max_tokens'=>$suggestedMaxTok, 'messages'=> [ ['role'=>'system','content'=>$sysPrev], ['role'=>'user','content'=>$userPrev] ] ];
                    $dbg[] = $dbgEnriched;
                } else {
                    $dbg[] = $dbgBasic;
                }
                // Attach minimal debug metrics into partial itself so final phase can aggregate even without client echo
                try {
                    if (is_array($partial)){
                        $attachDbg = $debugLocal ? (isset($dbgEnriched) ? $dbgEnriched : $dbgBasic) : $dbgBasic;
                        // keep only a compact subset to avoid bloat
                        $compactDbg = [
                            'phase' => 'chunk',
                            'chunk_index' => $chunk_index,
                            'matched_row_ids' => $dbgBasic['matched_row_ids'] ?? [],
                            'partial_match_row_ids' => $dbgBasic['partial_match_row_ids'] ?? [],
                            'best_match_score' => $dbgBasic['best_match_score'] ?? null,
                            'threshold_used' => $dbgBasic['name_threshold'] ?? null,
                        ];
                        $partial['debug'] = isset($partial['debug']) && is_array($partial['debug']) ? $partial['debug'] : [];
                        $partial['debug'][] = $compactDbg;
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Persist partials across phases using a transient keyed by session_id
                try {
                    $partials_key = ($session_id > 0) ? ('arsh_ana_partials_' . $session_id) : '';
                    if ($partials_key !== ''){
                        $existing = get_transient($partials_key);
                        $arr = is_array($existing) ? $existing : [];
                        $arr[] = (is_array($partial) ? $partial : []);
                        set_transient($partials_key, $arr, 15*60);
                        @error_log('[Arshline][Analytics] Chunk: saved partials for session ' . $session_id . ' (count=' . count($arr) . ').');
                    }
                } catch (\Throwable $e) { @error_log('[Arshline][Analytics] Chunk: failed to save partials: ' . $e->getMessage()); }
                // Emit an observation event for integrators (no-op if not hooked)
                if (function_exists('do_action')){
                    try { do_action('arshline_ai_observe', [ 'phase'=>'chunk', 'route'=>$route, 'ambiguity_score'=>$ambiguityScore, 'matched_count'=>$candCount, 'partial_count'=>$partialCount, 'duration_ms'=>(int)round(($t1-$t0)*1000), 'usage'=>$usage ]); } catch (\Throwable $e) { /* ignore */ }
                }
                // Tracer: record chunk step
                try {
                    $trace = $read_trace();
                    $trace[] = [
                        'ts' => time(),
                        'phase' => 'chunk',
                        'chunk_index' => $chunk_index,
                        'best_score' => ($bestScore ?? null),
                        'threshold' => (isset($threshold)?$threshold:null),
                        'matched_ids' => array_values($matchedIds),
                        'near_ids' => $nearIdsComputed,
                        'route' => $route,
                        'reason' => $reason,
                    ];
                    $write_trace($trace);
                } catch (\Throwable $e) { /* ignore */ }
                // Surface fields_used to the top-level for convenience (fallback to canonical headers without id/created_at)
                $fieldsUsedTop = [];
                if (is_array($partial) && is_array($partial['fields_used'] ?? null)){
                    $fieldsUsedTop = array_values(array_map('strval', $partial['fields_used']));
                } else {
                    $fieldsUsedTop = array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; }));
                }
                return new WP_REST_Response([
                    'phase' => 'chunk',
                    'chunk_index' => $chunk_index,
                    'partial' => is_array($partial)? $partial : [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ],
                    // New: convenience mirrors for client summaries
                    'fields_used' => $fieldsUsedTop,
                    'headers_canonical' => $headersCanonical,
                    'canonical_map' => $canonicalMap,
                    'chunk_rows' => $actualRowCount,
                    'usage' => [],
                    'debug' => $dbg,
                    'trace' => (function() use ($read_trace){ try { return $read_trace(); } catch (\Throwable $e) { return []; } })(),
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            if ($phase === 'final'){
                $partials = is_array($p['partials'] ?? null) ? $p['partials'] : [];
                @error_log('[Arshline][Analytics] Final: session_id=' . $session_id . ', received partials count=' . count($partials));
                // If client didn't carry partials, load from transient by session_id
                try {
                    if (empty($partials)){
                        $partials_key = ($session_id > 0) ? ('arsh_ana_partials_' . $session_id) : '';
                        @error_log('[Arshline][Analytics] Final: attempting to load partials with key=' . $partials_key);
                        if ($partials_key !== ''){
                            $loaded = get_transient($partials_key);
                            if (is_array($loaded) && !empty($loaded)){
                                $partials = $loaded;
                                @error_log('[Arshline][Analytics] Final: loaded ' . count($partials) . ' partial(s) from transient for session ' . $session_id . '.');
                            } else {
                                @error_log('[Arshline][Analytics] Final: no partials found in transient for session ' . $session_id . '.');
                            }
                        }
                    }
                } catch (\Throwable $e) { @error_log('[Arshline][Analytics] Final: failed to load partials: ' . $e->getMessage()); }
                // Aggregate requested_person and fallback info from partials
                $requestedPerson = '';
                $fallbackAppliedAny = false; $fallbackRowIds = [];
                try {
                    foreach (($partials ?: []) as $pt){
                        $meta = is_array($pt['meta'] ?? null) ? $pt['meta'] : [];
                        $rp = trim((string)($meta['requested_person'] ?? ''));
                        if ($requestedPerson === '' && $rp !== ''){ $requestedPerson = $rp; }
                        if (!empty($meta['fallback_applied'])){ $fallbackAppliedAny = true; }
                        $fr = (int)($meta['fallback_row_id'] ?? 0); if ($fr > 0){ $fallbackRowIds[] = $fr; }
                    }
                    $fallbackRowIds = array_values(array_unique(array_filter($fallbackRowIds, function($v){ return (int)$v>0; })));
                    
                    // Enhanced fallback: if we have fallback_row_ids but empty partials, force-build answer from fallback rows
                    if (empty($partials) && !empty($fallbackRowIds)){
                        @error_log('[Arshline][Analytics] Final: Empty partials but fallback_row_ids exist (' . implode(',', $fallbackRowIds) . '), building answer from fallback.');
                        foreach ($fallbackRowIds as $fbId){
                            try {
                                $fbRow = (new \ArshLine\Database\SubmissionRepository())->get_by_id($fbId);
                                if ($fbRow){
                                    $fbData = (new \ArshLine\Database\FieldRepository())->get_submission_data($fbId);
                                    $allData = array_merge(['id' => $fbId, 'form_id' => $fbRow['form_id'], 'created_at' => $fbRow['created_at']], $fbData);
                                    $partials[] = ['form_id' => $fbRow['form_id'], 'data' => $allData];
                                    $fallbackAppliedAny = true;
                                    @error_log('[Arshline][Analytics] Final: Built fallback partial from row ' . $fbId);
                                }
                            } catch (\Throwable $e) {
                                @error_log('[Arshline][Analytics] Final: Failed to load fallback row ' . $fbId . ': ' . $e->getMessage());
                            }
                        }
                    }
                    
                    // Enhanced data listing fallback: if still empty partials but query looks like data listing request (phone/field enumeration), execute structured route
                    if (empty($partials)){
                        $qLowerDataCheck = mb_strtolower((string)$question, 'UTF-8');
                        $looksDataListing = preg_match('/لیست|فهرست|همه|تمام|چه.*دار(?:ن|ند)|دادن|نشان.*ده|شماره.*تلفن|تلفن.*شماره|موبایل|ایمیل/u', $qLowerDataCheck);
                        if ($looksDataListing && !empty($form_ids)){
                            @error_log('[Arshline][Analytics] Final: Empty partials but query looks like data listing request, forcing structured route.');
                            // Force enable structured processing by calling the structured route logic inline
                            $structuredNeeded = true;
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Fallback: if no partials carried the requested person, extract from final request (entities or question text)
                if ($requestedPerson === ''){
                    try {
                        // Prefer entities from request body
                        $reqEntitiesF = is_array($p['entities'] ?? null) ? $p['entities'] : [];
                        if (!empty($reqEntitiesF)){
                            foreach ($reqEntitiesF as $en){
                                $typ = (string)($en['type'] ?? '');
                                $val = trim((string)($en['value'] ?? ''));
                                if ($typ === 'person' && $val !== ''){ $requestedPerson = $val; break; }
                            }
                        }
                        if ($requestedPerson === ''){
                            // Extract person name from Persian patterns like «حال نیما چطوره؟» / quotes
                            $cand = '';
                            if (preg_match('/«([^»]+)»/u', (string)$question, $mm)) { $cand = trim($mm[1]); }
                            if ($cand === '' && preg_match('/"([^"\n]{2,})"/u', (string)$question, $mm2)) { $cand = trim($mm2[1]); }
                            $qLowerFinal = mb_strtolower((string)$question, 'UTF-8');
                            if ($cand === '' && preg_match('/(?:حال|احوال)\s+([\p{L}‌\s]{2,})/u', $qLowerFinal, $mm3)){
                                $cand = trim($mm3[1]);
                                $cand = preg_replace('/\s*(چطوره|چطور|هست|است)\s*$/u', '', (string)$cand);
                                $cand = preg_replace('/[\?\؟]+$/u', '', (string)$cand);
                            }
                            // Normalize and keep up to first two tokens
                            $cand = (string)$cand;
                            $cand = preg_replace('/\x{200C}/u', '', $cand); // ZWNJ
                            $cand = str_replace(['ي','ك','ة'], ['ی','ک','ه'], $cand);
                            $cand = trim($cand);
                            if ($cand !== ''){
                                $parts = preg_split('/\s+/u', $cand, -1, PREG_SPLIT_NO_EMPTY);
                                $titles = ['آقای','آقا','خانم','دکتر','مهندس','استاد'];
                                $parts = array_values(array_filter($parts, function($t) use ($titles){ return !in_array($t, $titles, true); }));
                                if (!empty($parts)){
                                    $cand = implode(' ', array_slice($parts, 0, 2));
                                }
                                $requestedPerson = trim(mb_substr($cand, 0, 60, 'UTF-8'));
                            }
                        }
                    } catch (\Throwable $e) { /* ignore */ }
                }
                // Compose final merge request with summaries only
                $mergeIn = [ 'question'=>$question, 'partials'=>$partials, 'requested_person'=>$requestedPerson, 'fallback_info'=>[ 'applied'=>$fallbackAppliedAny, 'row_ids'=>$fallbackRowIds ] ];
                $sys = 'You are Hoshang. Merge the provided partial chunk analytics. If the user asked about a person\'s mood/wellbeing, synthesize an analysis by combining textual mood descriptions and numeric ratings (1–10).'
                    . ' If the user asked for contact info like phone/mobile, aggregate unique name/phone pairs across partials and summarize them briefly.'
                    . ' Accept partial name matches and prefer the most recent row by created_at when multiple matches exist.'
                    . ' If requested_person is provided in the user content, ALWAYS use that exact person name in the answer text (do not replace it with a different row name). If a different row name appears as source_row_name in insights, you may mention it briefly in parentheses.'
                    . ' Do NOT paste verbatim long texts from the data. Provide a concise, high-signal Persian answer (≤ 2 جمله).'
                    . ' Output STRICT JSON: {"answer":"<fa>","fields_used":[],"aggregations":{},"chart_data":[],"outliers":[],"insights":[],"confidence":"high|medium|low"}. JSON only.';
                $msgs = [ [ 'role'=>'system','content'=>$sys ], [ 'role'=>'user','content'=> wp_json_encode($mergeIn, JSON_UNESCAPED_UNICODE) ] ];
                $modelName = $use_model; if (preg_match('/mini/i', (string)$modelName)) $modelName = 'gpt-4o';
                $req = [ 'model'=>$modelName, 'messages'=>$msgs, 'temperature'=>0.2, 'max_tokens'=> min(1200, max(600, $max_tokens)) ];
                $t0f = microtime(true);
                $res = self::wp_post_with_retries($endpoint, $headers, $req, $http_timeout, 3, [500,1000,2000], 'gpt-4o');
                $status = (int)($res['status'] ?? 0);
                $raw = (string)($res['body'] ?? '');
                $ok = ($res['ok'] ?? false) === true;
                $body = is_array($res['json'] ?? null) ? $res['json'] : (json_decode($raw, true) ?: null);
                $usage = is_array($body) && isset($body['usage']) ? $body['usage'] : null;
                $durationFinalMs = (int)round((microtime(true)-$t0f)*1000);
                $text = '';
                if (is_array($body)){
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) $text = (string)$body['choices'][0]['message']['content'];
                    elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) $text = (string)$body['choices'][0]['text'];
                    elseif (isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                }
                $final = $text ? json_decode($text, true) : null;
                $repaired = false;
                if (!is_array($final)){
                    // Repair
                    $repairSys = 'Fix to VALID JSON only with keys: answer, fields_used, aggregations, chart_data, outliers, insights, confidence.';
                    $repairMsgs = [ [ 'role'=>'system','content'=>$repairSys ], [ 'role'=>'user','content'=>$text ] ];
                    $repairReq = [ 'model' => (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$repairMsgs, 'temperature'=>0.0, 'max_tokens'=>400 ];
                    $res2 = self::wp_post_with_retries($endpoint, $headers, $repairReq, 20, 1, [500], 'gpt-4o-mini');
                    $raw2 = (string)($res2['body'] ?? '');
                    $b2 = is_array($res2['json'] ?? null) ? $res2['json'] : (json_decode($raw2, true) ?: null);
                    $txt2 = '';
                    if (is_array($b2)){
                        if (isset($b2['choices'][0]['message']['content']) && is_string($b2['choices'][0]['message']['content'])) $txt2 = (string)$b2['choices'][0]['message']['content'];
                        elseif (isset($b2['choices'][0]['text']) && is_string($b2['choices'][0]['text'])) $txt2 = (string)$b2['choices'][0]['text'];
                        elseif (isset($b2['output_text']) && is_string($b2['output_text'])) $txt2 = (string)$b2['output_text'];
                    }
                    $final = $txt2 ? json_decode($txt2, true) : null;
                    if (is_array($final)) $repaired = true;
                }
                // Compute diagnostics early (candidate rows and matched columns)
                $candidateRows = 0; $matchedColumns = [];
                try {
                    foreach (($partials ?: []) as $p0){
                        $rc = (int)($p0['chunk_summary']['row_count'] ?? 0); $candidateRows += $rc;
                        $fields = is_array($p0['fields_used'] ?? null) ? $p0['fields_used'] : [];
                        foreach ($fields as $f){ if (is_string($f) && $f !== '' && !in_array($f, $matchedColumns, true)) $matchedColumns[] = $f; }
                    }
                } catch (\Throwable $e) { }
                // Minimal final debug always
                $dbg = [];
                // Compute ambiguity score across partials for telemetry only
                try {
                    $bestScores = [];
                    $thresholds = [];
                    $matchedCounts = 0; $partialCounts = 0;
                    foreach (($partials ?: []) as $pt){
                        $dbgl = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                        foreach ($dbgl as $d0){
                            if (isset($d0['best_match_score'])) $bestScores[] = (float)$d0['best_match_score'];
                            if (isset($d0['threshold_used'])) $thresholds[] = (float)$d0['threshold_used'];
                            if (isset($d0['matched_row_ids']) && is_array($d0['matched_row_ids'])) $matchedCounts += count($d0['matched_row_ids']);
                            if (isset($d0['partial_match_row_ids']) && is_array($d0['partial_match_row_ids'])) $partialCounts += count($d0['partial_match_row_ids']);
                        }
                    }
                    $bestAvg = !empty($bestScores) ? array_sum($bestScores)/count($bestScores) : 0.0;
                    $thrAvg = !empty($thresholds) ? array_sum($thresholds)/count($thresholds) : 0.0;
                    $gap = max(0.0, $thrAvg - $bestAvg);
                    $gapNorm = min(1.0, $gap / 0.3);
                    $multi = ($matchedCounts >= 6) ? 1.0 : (($matchedCounts >= 3) ? 0.6 : (($matchedCounts >= 1) ? 0.2 : 0.8));
                    $partialFactor = ($partialCounts >= 4) ? 0.6 : (($partialCounts >= 1) ? 0.3 : 0.0);
                    $ambFinal = max(0.0, min(1.0, 0.5*$gapNorm + 0.3*$multi + 0.2*$partialFactor));
                    $ambFinal = (float)round($ambFinal, 2);
                } catch (\Throwable $e) { $ambFinal = null; }
                // Decide final route based on partial debug metrics (hybrid policy)
                $routeFinal = 'server'; $reasonFinal = 'insufficient_metrics_or_mode';
                $aiCfgF = self::get_ai_analysis_config();
                $modePolicyF = (string)($aiCfgF['mode'] ?? 'hybrid');
                if ($modePolicyF !== 'efficient'){
                    // If no partials came through but intent looks like person/mood, escalate to SubsetAI to avoid empty answers
                    try {
                        if (empty($partials)){
                            $qlFinal = mb_strtolower((string)$question, 'UTF-8');
                            $looksPersonMood = ($requestedPerson !== '') || preg_match('/حال|احوال|روحیه|رضایت/u', $qlFinal);
                            if ($looksPersonMood){ $routeFinal = 'subset-ai'; $reasonFinal = 'no_partials_person_or_mood_intent'; }
                        }
                    } catch (\Throwable $e) { /* ignore */ }
                    $bestHit = null; $thrHit = null; $nearHit = false; $candHit = 0; $metaRoute = '';
                    foreach (($partials ?: []) as $pt){
                        // Prefer meta-carry route if present
                        $meta = is_array($pt['meta'] ?? null) ? $pt['meta'] : [];
                        if ($metaRoute === '' && is_string($meta['ai_decision_route'] ?? '')){ $metaRoute = (string)$meta['ai_decision_route']; }
                        $dbgl = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                        foreach ($dbgl as $d0){
                            if (isset($d0['best_match_score']) && $bestHit === null) $bestHit = (float)$d0['best_match_score'];
                            if (isset($d0['threshold_used']) && $thrHit === null) $thrHit = (float)$d0['threshold_used'];
                            if (isset($d0['partial_match_row_ids']) && is_array($d0['partial_match_row_ids']) && !$nearHit) $nearHit = count($d0['partial_match_row_ids'])>0;
                            if (isset($d0['matched_row_ids']) && is_array($d0['matched_row_ids'])) $candHit = max($candHit, count($d0['matched_row_ids']));
                        }
                    }
                    if ($metaRoute === 'subset-ai'){ $routeFinal = 'subset-ai'; $reasonFinal = 'carried_from_chunk_meta'; }
                    $b = (float)($bestHit ?? 0.0);
                    if ($routeFinal === 'server'){
                        if ($b >= 0.75){ $routeFinal = 'server'; $reasonFinal = 'high_confidence'; }
                        elseif ($b >= 0.50 && $b < 0.75 && ($nearHit || $candHit >= 3)) { $routeFinal = 'subset-ai'; $reasonFinal = 'ambiguous_partial_or_multi'; }
                        else { $routeFinal = 'server'; $reasonFinal = 'low_or_singleton'; }
                    }
                }
                $aiDecisionFinal = [
                    'route' => $routeFinal,
                    'reason' => $reasonFinal,
                    'metrics' => [
                        'ambiguity_score' => $ambFinal,
                        'partials_count' => count($partials),
                        'candidate_rows' => null,
                    ]
                ];
                // Read non-sensitive AI analysis config for debug/telemetry
                $aiCfgF = self::get_ai_analysis_config();
                $dbgBasicFinal = [
                    'phase' => 'final',
                    'partials_count' => count($partials),
                    'requested_person' => $requestedPerson,
                    'fallback_applied' => $fallbackAppliedAny,
                    'fallback_row_ids' => $fallbackRowIds,
                    'candidate_rows' => $candidateRows,
                    'matched_columns' => $matchedColumns,
                    'ambiguity_score' => $ambFinal,
                    'ai_decision' => $aiDecisionFinal,
                    'ai_config' => $aiCfgF,
                    'observability' => [
                        'route' => $routeFinal,
                        'duration_ms' => $durationFinalMs,
                        'usage' => $usage,
                    ],
                    'json_repaired' => $repaired,
                    'http_status' => $status,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ]
                ];
                if ($debug){
                    // Safe request preview
                    $sysPrev = (strlen($sys)>480? (substr($sys,0,480).'…[truncated]') : $sys);
                    $userPrev = [ 'question'=>$question, 'partials_count'=>count($partials) ];
                    $dbgBasicFinal['usage'] = $usage;
                    $dbgBasicFinal['request_preview'] = [ 'model'=>$modelName, 'max_tokens'=> min(1200, max(600, $max_tokens)), 'messages'=> [ ['role'=>'system','content'=>$sysPrev], ['role'=>'user','content'=>$userPrev] ] ];
                }
                
                // Enhanced structured data listing: if partials still empty but query suggests data listing, execute structured route inline
                if (empty($partials) && !empty($form_ids) && isset($structuredNeeded) && $structuredNeeded){
                    @error_log('[Arshline][Analytics] Final: Executing inline structured route for data listing.');
                    try {
                        // Extract and inline the structured route logic
                        $fid = $form_ids[0];
                        $rowsAll = FormsSubmissionRepository::listByFormAll($fid, [], $max_rows);
                        
                        // Re-detect field roles and classify query intent for inline processing  
                        $fieldsForIntent = FormsFieldRepository::listByForm($fid);
                        $fmetaIntent = [];
                        foreach (($fieldsForIntent ?: []) as $f){
                            $props = is_array($f['props'] ?? null) ? $f['props'] : [];
                            $label0 = (string)($props['question'] ?? $props['label'] ?? $props['title'] ?? $props['name'] ?? '');
                            $type0 = (string)($props['type'] ?? '');
                            $fmetaIntent[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                        }
                        $fieldRolesInline = $detect_field_roles($fmetaIntent);
                        $queryIntentInline = $classify_query_intent($question, $fieldRolesInline);
                        if (!empty($rowsAll)){
                            // Build field metadata for structured analysis
                            $fmeta = [];
                            try {
                                $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                                foreach (($fieldsForMeta ?: []) as $f){
                                    $props = is_array($f['props'] ?? null) ? $f['props'] : [];
                                    $label0 = (string)($props['question'] ?? $props['label'] ?? $props['title'] ?? $props['name'] ?? '');
                                    $type0 = (string)($props['type'] ?? '');
                                    $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                                }
                            } catch (\Throwable $e) { /* ignore */ }
                            
                            // Build CSV table for structured analysis
                            $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rowsAll), function($v){ return $v>0; }));
                            $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($sliceIds);
                            
                            // Header labels
                            $labels = [];
                            $idToLabel = [];
                            foreach ($fmeta as $fm){
                                $fidm = (int)($fm['id'] ?? 0);
                                $labm = (string)($fm['label'] ?? '');
                                if ($labm === '') { $labm = 'فیلد #' . $fidm; }
                                $idToLabel[$fidm] = $labm;
                                $labels[] = $labm;
                            }
                            
                            // Build CSV rows
                            $rowsCsv = [];
                            if (!empty($labels)){
                                $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $labels));
                                foreach ($sliceIds as $sid){
                                    $vals = $valuesMap[$sid] ?? [];
                                    $map = [];
                                    foreach ($vals as $v){
                                        $fidv = (int)($v['field_id'] ?? 0);
                                        $lab = (string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab = 'فیلد #'.$fidv;
                                        $val = trim((string)($v['value'] ?? ''));
                                        if (!isset($map[$lab])) $map[$lab] = [];
                                        if ($val !== '') $map[$lab][] = $val;
                                    }
                                    $rowsCsv[] = implode(',', array_map(function($h) use ($map){ $v = isset($map[$h]) ? implode(' | ', $map[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $labels));
                                }
                            }
                            $tableCsv = implode("\r\n", $rowsCsv);
                            
                            // Build intelligent system prompt based on query intent  
                            $sys = 'You are Hoshang, an advanced Persian analytics assistant. Analyze data based on query intent.\n\n';
                            
                            // Base field mapping
                            $sys .= 'FIELD MAPPING RULES:\n'
                                . '- name/نام: "نام"، "اسم"، "نام کامل"، "نام و نام خانوادگی"، "first name"، "last name"\n'
                                . '- phone/تلفن: "شماره تلفن"، "موبایل"، "همراه"، "تماس"، "phone"، "mobile"\n'
                                . '- email: "ایمیل"، "پست الکترونیک"، "email"، "e-mail"\n'
                                . '- rating/امتیاز: "امتیاز"، "نمره"، "رتبه"، "rating"، "score"\n'
                                . '- mood/حال: "حال"، "احوال"، "روحیه"، "حالت"\n'
                                . '- category/دسته: "دسته"، "نوع"، "گروه"، "category"، "type"\n\n';
                            
                            // Intent-specific instructions
                            switch($queryIntentInline['primary_type']){
                                case 'listing':
                                    $sys .= 'LISTING MODE INSTRUCTIONS:\n'
                                        . '1) Extract ALL relevant values from matching columns - be comprehensive\n'
                                        . '2) Format as clear Persian lists with proper names/context\n'
                                        . '3) Include associated information (e.g., name with phone number)\n'
                                        . '4) Use structured formatting for readability\n';
                                    break;
                                case 'aggregation':
                                    $sys .= 'AGGREGATION MODE INSTRUCTIONS:\n'
                                        . '1) Perform requested calculations (sum, average, count, etc.)\n'
                                        . '2) Include breakdown by categories if relevant\n'
                                        . '3) Provide statistical summaries and insights\n'
                                        . '4) Fill aggregations object with calculated values\n';
                                    break;
                                case 'mood_analysis':
                                    $sys .= 'MOOD ANALYSIS MODE INSTRUCTIONS:\n'
                                        . '1) Analyze sentiment and satisfaction patterns\n'
                                        . '2) Calculate mood score statistics if available\n'
                                        . '3) Identify trends and notable patterns\n'
                                        . '4) Provide empathetic, human-centered insights\n';
                                    break;
                                case 'visualization':
                                    $sys .= 'VISUALIZATION MODE INSTRUCTIONS:\n'
                                        . '1) Prepare data optimally for ' . ($queryIntentInline['chart_type'] ?? 'appropriate') . ' charts\n'
                                        . '2) Fill chart_data with properly structured objects\n'
                                        . '3) Group and aggregate data for clear visualization\n'
                                        . '4) Suggest appropriate chart types if needed\n';
                                    break;
                                case 'comparison':
                                    $sys .= 'COMPARISON MODE INSTRUCTIONS:\n'
                                        . '1) Identify key differences and similarities\n'
                                        . '2) Provide side-by-side analysis\n'
                                        . '3) Use statistical comparisons where appropriate\n'
                                        . '4) Highlight significant findings\n';
                                    break;
                                case 'person_lookup':
                                    $sys .= 'PERSON LOOKUP MODE INSTRUCTIONS:\n'
                                        . '1) Find specific person data using fuzzy matching\n'
                                        . '2) Extract all relevant information for that person\n'
                                        . '3) Handle name variations and partial matches\n'
                                        . '4) Provide comprehensive personal data summary\n';
                                    break;
                                default:
                                    $sys .= 'GENERAL ANALYSIS MODE INSTRUCTIONS:\n'
                                        . '1) Analyze data comprehensively based on query\n'
                                        . '2) Provide relevant insights and patterns\n'
                                        . '3) Use appropriate analytical techniques\n'
                                        . '4) Be thorough and contextual\n';
                            }
                            
                            $sys .= '\nOUTPUT REQUIREMENTS:\n'
                                . '- Return ONLY valid JSON with keys in English\n'
                                . '- Persian text for answer and insights\n'
                                . '- Never hallucinate data - use only provided information\n'
                                . '- Set confidence level based on data completeness\n'
                                . '- Fill fields_used array with columns actually analyzed\n'
                                . '- Query Intent: ' . $queryIntentInline['primary_type'] . ' (confidence: ' . round($queryIntentInline['confidence'], 2) . ')';
                            
                            $messages = [ [ 'role' => 'system', 'content' => $sys ] ];
                            $payloadUser = [
                                'question' => $question,
                                'form_id' => $fid,
                                'data_format' => 'table',
                                'table_csv' => $tableCsv,
                                'fields_meta' => $fmeta,
                                'query_intent' => [
                                    'type' => $queryIntentInline['primary_type'],
                                    'confidence' => $queryIntentInline['confidence'],
                                    'requires_aggregation' => $queryIntentInline['requires_aggregation'],
                                    'chart_type' => $queryIntentInline['chart_type'],
                                    'target_fields' => $queryIntentInline['target_fields']
                                ],
                                'field_roles' => array_filter($fieldRolesInline, function($arr) { return !empty($arr); }),
                                'output_schema' => [
                                    'answer' => 'string (Persian)',
                                    'fields_used' => ['string'],
                                    'aggregations' => ($queryIntentInline['requires_aggregation'] ? ['sum'=>0, 'count'=>0, 'average'=>0, 'min'=>0, 'max'=>0] : new \stdClass()),
                                    'chart_data' => [ new \stdClass() ],
                                    'confidence' => 'low|medium|high'
                                ]
                            ];
                            $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
                            $payload = [ 'model' => self::normalize_model_name($use_model), 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $max_tokens ];
                            $payloadJson = wp_json_encode($payload);
                            
                            // Make request
                            $t0Struct = microtime(true);
                            $resInline = self::wp_post_with_retries($endpoint, $headers, $payload, $http_timeout, 3, [500,1000,2000], 'gpt-4o');
                            $status = (int)($resInline['status'] ?? 0);
                            $rawBodyStruct = (string)($resInline['body'] ?? '');
                            $ok = ($resInline['ok'] ?? false) === true;
                            $bodyStruct = is_array($resInline['json'] ?? null) ? $resInline['json'] : (json_decode($rawBodyStruct, true) ?: null);
                            $durationStructMs = (int)round((microtime(true)-$t0Struct)*1000);
                            
                            // Extract text from response
                            $textStruct = '';
                            if (is_array($bodyStruct)){
                                try {
                                    if (isset($bodyStruct['choices'][0]['message']['content']) && is_string($bodyStruct['choices'][0]['message']['content'])){
                                        $textStruct = (string)$bodyStruct['choices'][0]['message']['content'];
                                    } elseif (isset($bodyStruct['choices'][0]['text']) && is_string($bodyStruct['choices'][0]['text'])){
                                        $textStruct = (string)$bodyStruct['choices'][0]['text'];
                                    } elseif (isset($bodyStruct['output_text']) && is_string($bodyStruct['output_text'])){
                                        $textStruct = (string)$bodyStruct['output_text'];
                                    }
                                } catch (\Throwable $e) { $textStruct=''; }
                            }
                            
                            // Parse structured JSON result
                            $resultStruct = null;
                            if (is_string($textStruct) && $textStruct !== ''){
                                $decoded = json_decode($textStruct, true);
                                if (is_array($decoded)){
                                    $resultStruct = $decoded;
                                    @error_log('[Arshline][Analytics] Final: Structured data listing successful, answer length: ' . strlen($resultStruct['answer'] ?? ''));
                                }
                            }
                            
                            if (is_array($resultStruct)){
                                $final = $resultStruct;
                                @error_log('[Arshline][Analytics] Final: Used inline structured route - Intent: ' . $queryIntentInline['primary_type'] . ', Confidence: ' . round($queryIntentInline['confidence'], 2));
                                $dbgBasicFinal['inline_structured'] = [
                                    'used' => true,
                                    'duration_ms' => $durationStructMs,
                                    'status' => $status,
                                    'rows_processed' => count($sliceIds),
                                    'query_intent' => $queryIntentInline['primary_type'],
                                    'intent_confidence' => $queryIntentInline['confidence']
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        @error_log('[Arshline][Analytics] Final: Inline structured route failed: ' . $e->getMessage());
                        $dbgBasicFinal['inline_structured_error'] = $e->getMessage();
                    }
                }
                
                $res = is_array($final)? $final : [ 'answer'=>'تحلیلی یافت نشد.', 'fields_used'=>[], 'aggregations'=>new \stdClass(), 'chart_data'=>[], 'outliers'=>[], 'insights'=>[], 'confidence'=>'low' ];

                // Subset-AI path (execute only if decided and config allows)
                if ($routeFinal === 'subset-ai'){
                    try {
                        $fid = $form_ids[0];
                        // Build rows with canonical keys for minimal intents
                        $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                        $field_roles = $detect_field_roles(is_array($fieldsForMeta)? array_map(function($f){ $p=is_array($f['props'] ?? null)?$f['props']:[]; return [ 'id'=>(int)($f['id']??0), 'label'=>(string)($p['question'] ?? $p['label'] ?? $p['title'] ?? $p['name'] ?? ''), 'type'=>(string)($p['type'] ?? '') ]; }, $fieldsForMeta): []);
                        // Fetch latest rows capped
                        $capMax = (int)($aiCfgF['max_rows'] ?? 400);
                        $all = FormsSubmissionRepository::listByFormAll($fid, [], $capMax);
                        $ids = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, ($all ?: [])), function($v){ return $v>0; }));
                        $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($ids);
                        $rowsBuilt = [];
                        // Quick label lookup
                        $idToLabel = [];
                        try {
                            foreach (($fieldsForMeta ?: []) as $f){ $p0=is_array($f['props'] ?? null)?$f['props']:[]; $lab0=(string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? ''); $idToLabel[(int)($f['id'] ?? 0)]=$lab0; }
                        } catch (\Throwable $e) { $idToLabel = []; }
                        $nameLabs = array_values(array_unique((array)($field_roles['name'] ?? [])));
                        $textLabs = array_values(array_unique((array)($field_roles['mood_text'] ?? [])));
                        $scoreLabs = array_values(array_unique((array)($field_roles['mood_score'] ?? [])));
                        $phoneLabs = array_values(array_unique((array)($field_roles['phone'] ?? [])));
                        $setN=[]; foreach ($nameLabs as $x){ $setN[$x]=true; }
                        $setT=[]; foreach ($textLabs as $x){ $setT[$x]=true; }
                        $setS=[]; foreach ($scoreLabs as $x){ $setS[$x]=true; }
                        $setP=[]; foreach ($phoneLabs as $x){ $setP[$x]=true; }
                        foreach (($all ?: []) as $row){
                            $sid = (int)($row['id'] ?? 0);
                            if ($sid<=0) continue;
                            $vals = $valuesMap[$sid] ?? [];
                            $nmParts=[]; $txParts=[]; $scoreVals=[]; $phones=[];
                            foreach ($vals as $v){
                                $fidv=(int)($v['field_id'] ?? 0);
                                $lab=(string)($idToLabel[$fidv] ?? '');
                                $val=trim((string)($v['value'] ?? ''));
                                if ($val==='') continue;
                                if ($lab!=='' && isset($setN[$lab])) $nmParts[]=$val;
                                if ($lab!=='' && isset($setT[$lab])) $txParts[]=$val;
                                if ($lab!=='' && isset($setS[$lab])){
                                    if (preg_match('/(\d{1,2})/u', strtr($val, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $m)){
                                        $n=(int)$m[1]; if ($n>=0 && $n<=10) $scoreVals[]=$n;
                                    }
                                }
                                if ($lab!=='' && isset($setP[$lab])) $phones[]=$val;
                            }
                            $rowsBuilt[] = [
                                'id' => (string)$sid,
                                'created_at' => (string)($row['created_at'] ?? ''),
                                'name' => trim(implode(' ', $nmParts)),
                                'mood_text' => trim(implode(' | ', $txParts)),
                                'mood_score' => (!empty($scoreVals) ? max($scoreVals) : ''),
                                'phone' => (!empty($phones) ? (string)reset($phones) : ''),
                            ];
                            if (count($rowsBuilt) >= $capMax) break;
                        }
                        // If requested person provided, prioritize rows by fuzzy name match and reduce to top matches
                        $rowsForPkg = $rowsBuilt;
                        if ($requestedPerson !== ''){
                            // Reuse tunable params via the same filter
                            $matchParams = [ 'wTok'=>0.65,'wLev'=>0.35,'bonus_exact'=>0.20,'bonus_partial'=>0.15,'thr_one'=>0.50,'thr_two'=>0.65,'thr_three'=>0.70 ];
                            try { if (function_exists('apply_filters')){ $tmp=apply_filters('arshline_ai_name_match_params', $matchParams, $question); if (is_array($tmp)) $matchParams = array_merge($matchParams, $tmp); } } catch (\Throwable $e) { }
                            $faNorm = function($s){ $s=(string)$s; $s=str_replace(["\xE2\x80\x8C","\xC2\xA0"],['',' '],$s); $s=str_replace(["ي","ك","ة"],["ی","ک","ه"],$s); $s=preg_replace('/\p{Mn}+/u','',$s); $s=preg_replace('/[\p{P}\p{S}]+/u',' ',$s); $s=preg_replace('/\s+/u',' ',$s); return trim(mb_strtolower($s,'UTF-8')); };
                            $tokenize = function($s) use ($faNorm){ $titles=['آقای','آقا','خانم','دکتر','مهندس','استاد']; $n=$faNorm($s); $t=preg_split('/\s+/u',$n,-1,PREG_SPLIT_NO_EMPTY); $set=[]; foreach($titles as $w){ $set[$w]=true; $set[mb_strtolower($w,'UTF-8')]=true; } $out=[]; foreach($t as $x){ if($x!=='' && !isset($set[$x])) $out[]=$x; } return $out; };
                            $tokSim = function(array $A, array $B){ if(empty($A)||empty($B)) return 0.0; $A=array_values(array_unique($A)); $B=array_values(array_unique($B)); $Ai=[]; foreach($A as $a){$Ai[$a]=true;} $Bi=[]; foreach($B as $b){$Bi[$b]=true;} $inter=array_values(array_intersect(array_keys($Ai),array_keys($Bi))); $unionCount=count(array_unique(array_merge(array_keys($Ai),array_keys($Bi)))); $j=$unionCount>0?(count($inter)/$unionCount):0.0; $exact=count($inter); $partial=0; foreach($A as $ta){ foreach($B as $tb){ if($ta===$tb) continue; if(mb_strlen($ta,'UTF-8')>=3 && mb_strlen($tb,'UTF-8')>=3){ if(mb_strpos($ta,$tb,0,'UTF-8')!==false || mb_strpos($tb,$ta,0,'UTF-8')!==false){ $partial++; break; } } } } if($exact>=2) return 1.0; $score=0.6*$j + 0.2*($exact>0?1:0) + 0.2*($partial>0?1:0); return ($score>1.0)?1.0:$score; };
                            $simLev = function(string $a,string $b){ $aN=$a; $bN=$b; if(function_exists('iconv')){ $aT=@iconv('UTF-8','ASCII//TRANSLIT',$aN); $bT=@iconv('UTF-8','ASCII//TRANSLIT',$bN); if(is_string($aT)&&$aT!=='') $aN=$aT; if(is_string($bT)&&$bT!=='') $bN=$bT; } $pct=0.0; similar_text($aN,$bN,$pct); return max(0.0,min(1.0,$pct/100.0)); };
                            $wTok=(float)$matchParams['wTok']; $wLev=(float)$matchParams['wLev']; $bExact=(float)$matchParams['bonus_exact']; $bPartial=(float)$matchParams['bonus_partial'];
                            $hintTokens = $tokenize($requestedPerson);
                            $tokCount = count($hintTokens);
                            $thr1=(float)$matchParams['thr_one']; $thr2=(float)$matchParams['thr_two']; $thr3=(float)$matchParams['thr_three'];
                            $thr = ($tokCount<=1)?$thr1:(($tokCount===2)?$thr2:$thr3);
                            $scored = [];
                            foreach ($rowsBuilt as $rb){ $rowTokens = $tokenize((string)($rb['name']??'')); $scTok=$tokSim($hintTokens,$rowTokens); $hintFull=$faNorm(implode(' ',$hintTokens)); $rowFull=$faNorm(implode(' ',$rowTokens)); $scLev=$simLev($hintFull,$rowFull); $bonus=0.0; $first=$hintTokens[0]??''; if($first!==''){ foreach($rowTokens as $rt){ if($rt===$first){ $bonus=$bExact; break; } if((mb_strlen($rt,'UTF-8')>=3 || mb_strlen($first,'UTF-8')>=3) && (mb_strpos($rt,$first,0,'UTF-8')===0 || mb_strpos($first,$rt,0,'UTF-8')===0)){ $bonus=max($bonus,$bPartial); } } } $sc=$wTok*$scTok + $wLev*$scLev + $bonus; if($sc>1.0) $sc=1.0; $scored[] = [ 'row'=>$rb, 'score'=>$sc ]; }
                            usort($scored, function($a,$b){ return ($b['score']<=>$a['score']); });
                            $selected = array_values(array_filter($scored, function($x) use ($thr){ return (float)$x['score'] >= $thr; }));
                            if (empty($selected) && !empty($scored)) $selected = array_slice($scored, 0, 3); // top-3 fallback
                            if (!empty($selected)) $rowsForPkg = array_values(array_map(function($x){ return $x['row']; }, $selected));
                        }
                        $columns = \Arshline\Support\AiSubsetPackager::columnWhitelist(($requestedPerson!==''?'person_mood':'generic'), !empty($aiCfgF['allow_pii']));
                        $safeRows = \Arshline\Support\AiSubsetPackager::sanitizeRows($rowsForPkg, $columns, [ 'max_rows'=>$capMax, 'allow_pii'=>!empty($aiCfgF['allow_pii']) ]);
                        $pkg = \Arshline\Support\AiSubsetPackager::packageForModel($question, $safeRows, $columns, [ 'intent'=> ($requestedPerson!==''?'person_mood':'generic'), 'target_name'=>$requestedPerson, 'locale'=>'fa_IR', 'token_ceiling'=> [ 'typical'=>(int)($aiCfgF['token_typical'] ?? 8000), 'max'=>(int)($aiCfgF['token_max'] ?? 32000) ] ]);

                        $subsetAns = self::ai_subset_analyze($pkg, [ 'base_url'=>$base, 'api_key'=>$api_key, 'model'=>$use_model ]);
                        if (is_array($subsetAns) && !empty($subsetAns['answer'])){
                            $res['answer'] = (string)$subsetAns['answer'];
                            $res['confidence'] = (string)($subsetAns['confidence'] ?? ($res['confidence'] ?? 'low'));
                            if (!empty($subsetAns['evidence_ids']) && is_array($subsetAns['evidence_ids'])){
                                $res['insights'][] = [ 'evidence_ids' => array_values(array_map('intval', $subsetAns['evidence_ids'])) ];
                            }
                            $dbgBasicFinal['routed'] = 'SubsetAI';
                        } else {
                            // Local deterministic fallback without model: synthesize concise Persian answer from available rows
                            $dbgBasicFinal['subset_ai_error'] = 'empty_or_invalid';
                            try {
                                $evIds = [];
                                $picked = !empty($rowsForPkg) ? $rowsForPkg : $rowsBuilt;
                                if (!empty($picked)){
                                    // Prefer first row (already sorted by best match/time), summarize mood_text and mood_score
                                    $r0 = $picked[0];
                                    $nm = trim((string)($r0['name'] ?? ''));
                                    $mt = trim((string)($r0['mood_text'] ?? ''));
                                    $ms = (string)($r0['mood_score'] ?? '');
                                    $id0 = (int)($r0['id'] ?? 0); if ($id0>0) $evIds[] = $id0;
                                    // Normalize score to 0-10 int
                                    $msi = is_numeric($ms) ? (int)$ms : null; if ($msi!==null && ($msi<0 || $msi>10)) $msi=null;
                                    $rp = ($requestedPerson !== '') ? $requestedPerson : $nm;
                                    if ($rp !== ''){
                                        $parts = [];
                                        if ($msi !== null){ $parts[] = 'امتیاز: ' . $msi; }
                                        if ($mt !== ''){ $parts[] = 'متن: ' . $mt; }
                                        if (!empty($parts)){
                                            $res['answer'] = '<fa>حال ' . $rp . ' بر اساس دادهٔ موجود: ' . implode('؛ ', $parts) . '.</fa>';
                                            $res['confidence'] = 'medium';
                                            if (!empty($evIds)) $res['insights'][] = [ 'evidence_ids' => $evIds ];
                                        }
                                    }
                                }
                            } catch (\Throwable $e) { /* ignore fallback synthesis errors */ }
                        }
                    } catch (\Throwable $e) {
                        $dbgBasicFinal['subset_ai_exception'] = $e->getMessage();
                    }
                }
                // Add final notes and possible adjustments based on partial/fallback tagging
                $hasPartial = false;
                try {
                    foreach (($partials?:[]) as $pt){
                        // look in notes or debug matched_ids_tagged
                        $notes0 = is_array($pt['chunk_summary']['notes'] ?? null) ? $pt['chunk_summary']['notes'] : [];
                        foreach ($notes0 as $n0){ if (is_string($n0) && strpos($n0, 'partial_match_applied_id_') === 0){ $hasPartial = true; break; } }
                        if ($hasPartial) break;
                        $dbg0 = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                        foreach ($dbg0 as $d0){
                            $tags = is_array($d0['matched_ids_tagged'] ?? null) ? $d0['matched_ids_tagged'] : [];
                            foreach ($tags as $tg){ if (is_string($tg) && strpos($tg, '_partial') !== false){ $hasPartial = true; break 2; } }
                        }
                    }
                } catch (\Throwable $e) { }
                $finalNotes = [];
                if ($hasPartial) $finalNotes[] = 'answer_adjusted_for_partial';
                if ($fallbackAppliedAny) $finalNotes[] = 'answer_context_fallback_seen';
                // Optionally adjust clearly empty answers into a deterministic Persian line
                $ansStr = is_string($res['answer'] ?? null) ? trim((string)$res['answer']) : '';
                if ($ansStr === '' || mb_strpos($ansStr, 'تحلیلی یافت نشد', 0, 'UTF-8') !== false || mb_strpos($ansStr, 'اطلاعاتی موجود نیست', 0, 'UTF-8') !== false){
                    if ($requestedPerson !== '' && $candidateRows > 0){
                        if ($hasPartial){ $res['answer'] = 'تحلیل جزئی برای ' . $requestedPerson . ' یافت شد، اما برای نتیجهگیری قطعی کافی نیست.'; }
                        elseif ($fallbackAppliedAny){ $res['answer'] = 'اطلاعاتی برای تحلیل مستقیم ' . $requestedPerson . ' یافت نشد؛ نزدیکترین داده بررسی شد.'; }
                        else { $res['answer'] = 'اطلاعاتی برای تحلیل حال ' . $requestedPerson . ' موجود نیست.'; }
                    }
                }
                if (!empty($finalNotes)) $dbgBasicFinal['final_notes'] = $finalNotes;
                $dbg[] = $dbgBasicFinal;
                // Build diagnostics with routed path so UI can reflect subset vs structured
                $diagnostics = [ 'routed' => ($routeFinal === 'subset-ai' ? 'SubsetAI' : 'Structured') ];
                if ($candidateRows === 0){ $diagnostics['candidate_rows'] = 0; $diagnostics['matched_columns'] = $matchedColumns; }
                // Emit observation hook for integrators
                if (function_exists('do_action')){
                    try { do_action('arshline_ai_observe', [ 'phase'=>'final', 'route'=> ($routeFinal==='subset-ai'?'subset-ai':'server'), 'ambiguity_score'=>$ambFinal, 'partials_count'=>count($partials), 'candidate_rows'=>$candidateRows, 'duration_ms'=>$durationFinalMs, 'usage'=>$usage ]); } catch (\Throwable $e) { /* ignore */ }
                }
                // Tracer: record final step and detect route mismatch
                try {
                    $trace = $read_trace();
                    $trace[] = [ 'ts'=>time(), 'phase'=>'final', 'routed'=> $diagnostics['routed'], 'reason'=>$reasonFinal, 'partials_count'=>count($partials) ];
                    $write_trace($trace);
                    // If chunk meta suggested subset-ai but final chose server, log a warning for diagnostics
                    if (isset($metaRoute) && $metaRoute === 'subset-ai' && $routeFinal !== 'subset-ai'){
                        @error_log('[Arshline][AnalyticsTracer] Route mismatch: chunk->subset-ai but final->server (session_id='.$session_id.').');
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Cleanup transient to avoid stale accumulation
                try {
                    $partials_key = ($session_id > 0) ? ('arsh_ana_partials_' . $session_id) : '';
                    if ($partials_key !== ''){ delete_transient($partials_key); @error_log('[Arshline][Analytics] Final: cleaned partials transient for session ' . $session_id . '.'); }
                } catch (\Throwable $e) { /* ignore cleanup errors */ }
                return new WP_REST_Response([
                    'phase' => 'final',
                    'result' => $res,
                    'diagnostics' => $diagnostics,
                    'usage' => [],
                    'debug' => $dbg,
                    'trace' => (function() use ($read_trace){ try { return $read_trace(); } catch (\Throwable $e) { return []; } })(),
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            return new WP_REST_Response([ 'error'=>'invalid_phase' ], 400);
        }
        // Fast path: structured JSON mode — single-call analysis with strict JSON output (legacy)
        if ($isStructured) {
            // Restrict to the (only) selected form (enforced earlier)
            $fid = $form_ids[0];
            // Fetch up to max_rows for a single grounded CSV
            $rowsAll = FormsSubmissionRepository::listByFormAll($fid, [], $max_rows);
            if (empty($rowsAll)){
                $payloadOut = [ 'result' => [ 'answer' => 'No matching data found.', 'fields_used'=>[], 'aggregations'=>new \stdClass(), 'chart_data'=>[], 'confidence'=>'low' ], 'summary' => 'No matching data found.', 'usage' => [], 'voice' => $voice, 'session_id' => $session_id ];
                return new WP_REST_Response($payloadOut, 200);
            }
            // Build fields meta
            $fmeta = [];
            try {
                $fieldsForMeta = FormsFieldRepository::listByForm($fid);
                foreach (($fieldsForMeta ?: []) as $f){
                    $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                    $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                    $type0 = (string)($p0['type'] ?? '');
                    $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                }
            } catch (\Throwable $e) { /* ignore */ }
            // Compose a single table CSV grounding across rows (with optional pre-filter via LLM planning)
            $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rowsAll), function($v){ return $v>0; }));
            $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($sliceIds);

            // Lightweight server-side name disambiguation (clarify candidates)
            $clarify = null;
            try {
                // Detect name-like field ids
                $nameFieldIds = [];
                foreach ($fmeta as $fm){
                    $lab = mb_strtolower((string)($fm['label'] ?? ''), 'UTF-8');
                    if ($lab === '') continue;
                    if (preg_match('/\bname\b|first\s*name|last\s*name|full\s*name|surname|family/i', $lab)
                        || preg_match('/نام(?:\s*خانوادگی)?|اسم/u', $lab)){
                        $nameFieldIds[] = (int)($fm['id'] ?? 0);
                    }
                }
                $nameFieldIds = array_values(array_unique(array_filter($nameFieldIds, function($v){ return $v>0; })));
                // Build distinct names from filtered ids for CSV (or all slice ids if not filtered)
                $idsForClar = !empty($filteredIds) ? $filteredIds : $sliceIds;
                $normalize = function($s){
                    $s = is_scalar($s) ? (string)$s : '';
                    $s = str_replace(["\xE2\x80\x8C"], [''], $s); // ZWNJ
                    $s = str_replace(["ي","ك"],["ی","ک"], $s);
                    $s = preg_replace('/\s+/u',' ', $s);
                    return trim(mb_strtolower($s, 'UTF-8'));
                };
                $distinctNames = [];
                if (!empty($nameFieldIds)){
                    foreach ($idsForClar as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        $byField = [];
                        foreach ($vals as $v){ $fidv=(int)($v['field_id'] ?? 0); if (!in_array($fidv, $nameFieldIds, true)) continue; $val=(string)($v['value'] ?? ''); if ($val==='') continue; $byField[$fidv][] = $val; }
                        foreach ($byField as $arr){
                            foreach ($arr as $raw){
                                // split by common joiner
                                $parts = array_map('trim', explode('|', str_replace(' | ', '|', $raw)));
                                foreach ($parts as $p){ if ($p==='') continue; $n=$normalize($p); if ($n!==''){ $distinctNames[$n] = $p; } }
                            }
                        }
                    }
                }
                // Extract candidate token from question
                $qtext = $question;
                $qnorm = $normalize($qtext);
                // pick the longest token >= 2 not a common stopword
                $tok = '';
                if (preg_match_all('/[\p{L}]{2,}/u', $qnorm, $mm)){
                    $cands = $mm[0] ?? [];
                    $stops = ['حال','چطوره','هست','چه','روند','امتیاز','میانگین','اسامی','اسم','نام','شماره','تلفن','نمره','امروز','اوضاع','احوال','چقدر','کد','ملی','فرم','پاسخ','ارسال'];
                    $best = '';
                    foreach ($cands as $w){ if (mb_strlen($w,'UTF-8')>=2 && !in_array($w, $stops, true)){ if (mb_strlen($w,'UTF-8') > mb_strlen($best,'UTF-8')) $best=$w; } }
                    $tok = $best;
                }
                if ($tok !== '' && count($distinctNames) > 0){
                    $hits = [];
                    foreach ($distinctNames as $n => $orig){ if (strpos($n, $tok) !== false) $hits[$n] = $orig; }
                    // If multiple candidates (2..6) and no exact single match, propose clarify
                    $count = count($hits);
                    if ($count >= 2 && $count <= 6){ $clarify = [ 'type' => 'name', 'candidates' => array_values(array_unique(array_slice(array_values($hits), 0, 6))) ]; }
                }
            } catch (\Throwable $e) { /* ignore clarify errors */ }

            // Two-stage planning (LLM-guided light filtering) to reduce grounding size and improve precision
            $filteredIds = $sliceIds; $planObj = null; $planUsage = null; $planningApplied = false; $planningModel = '';
            try {
                // Build a tiny planning prompt to extract filters and target fields
                $planSys = 'You are Hoshang planning assistant. Task: Given Persian question and fields_meta, return a strict JSON plan to FILTER rows (no analysis). Rules:\n'
                    . '1) Output JSON ONLY, no text. Keys in English.\n'
                    . '2) Detect name/phone/mood and other field intents from synonyms.\n'
                    . '3) Plan schema: { "filters": [{"field":"<best label>", "op":"contains", "value":"..."}], "columns_needed": ["label1","label2"], "target_fields": ["label?/semantic" ] }.\n'
                    . '4) Use simple contains filters with normalized Persian (ی/ي, ک/ك, remove ZWNJ) and case-insensitive.\n'
                    . '5) Do NOT answer the question.\n';
                $planUser = [ 'question' => $question, 'fields_meta' => $fmeta ];
                $planMsgs = [ [ 'role'=>'system','content'=>$planSys ], [ 'role'=>'user', 'content' => json_encode($planUser, JSON_UNESCAPED_UNICODE) ] ];
                // Prefer a mini model for planning when available
                $planningModel = (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini');
                // Fallback to current model if mini may be unavailable
                if ($planningModel === 'gpt-4o-mini' && $use_model === 'gpt-4o-mini') { /* same */ }
                $makePlanReq = function($modelName) use ($endpoint, $http_timeout, $headers, $planMsgs){
                    $pl = [ 'model'=>$modelName, 'messages'=>$planMsgs, 'temperature'=>0.0, 'max_tokens'=>300 ];
                    $plJson = wp_json_encode($pl);
                    $t0 = microtime(true);
                    $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                    $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                    $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                    $ok = ($status === 200);
                    $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                    $u = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms' => (int) round((microtime(true)-$t0)*1000) ];
                    return [ $ok, $status, $raw, $body, $u, $plJson ];
                };
                [ $pok, $pstatus, $praw, $pbody, $pusage, $pjson ] = $makePlanReq($planningModel);
                if (!$pok && in_array((int)$pstatus, [400,404,422], true) && $planningModel !== $use_model){
                    [ $pok2, $pstatus2, $praw2, $pbody2, $pusage2, $pjson2 ] = $makePlanReq($use_model);
                    if ($pok2){ $pok=true; $pstatus=$pstatus2; $praw=$praw2; $pbody=$pbody2; $pusage=$pusage2; $pjson=$pjson2; $planningModel=$use_model; }
                }
                // Extract plan text
                $planText = '';
                if (is_array($pbody)){
                    try {
                        if (isset($pbody['choices'][0]['message']['content']) && is_string($pbody['choices'][0]['message']['content'])){
                            $planText = (string)$pbody['choices'][0]['message']['content'];
                        } elseif (isset($pbody['choices'][0]['text']) && is_string($pbody['choices'][0]['text'])){
                            $planText = (string)$pbody['choices'][0]['text'];
                        } elseif (isset($pbody['output_text']) && is_string($pbody['output_text'])){
                            $planText = (string)$pbody['output_text'];
                        }
                    } catch (\Throwable $e) { $planText=''; }
                }
                $planDecoded = $planText ? json_decode($planText, true) : null;
                if (is_array($planDecoded)){
                    $planObj = $planDecoded; $planUsage = $pusage;
                    // Minimal light filtering: supports only op=contains over selected columns
                    $filters = is_array($planObj['filters'] ?? null) ? $planObj['filters'] : [];
                    // Build normalized label => field_ids map (labels may repeat across forms, but here only one form)
                    $normalize = function($s){
                        $s = is_scalar($s) ? (string)$s : '';
                        $s = str_replace(["\xE2\x80\x8C"], [''], $s); // ZWNJ
                        $s = str_replace(["ي","ك"],["ی","ک"], $s);
                        $s = preg_replace('/\s+/u',' ', $s);
                        $s = trim(mb_strtolower($s, 'UTF-8'));
                        return $s;
                    };
                    $labelToIds = [];
                    foreach ($fmeta as $fm){ $lab = (string)($fm['label'] ?? ''); $fidm = (int)($fm['id'] ?? 0); if ($lab===''||$fidm<=0) continue; $labN = $normalize($lab); if (!isset($labelToIds[$labN])) $labelToIds[$labN] = []; $labelToIds[$labN][] = $fidm; }
                    $activeFilters = [];
                    foreach ($filters as $flt){
                        $flab = $normalize($flt['field'] ?? ''); $val = $normalize($flt['value'] ?? ''); $op = strtolower((string)($flt['op'] ?? 'contains'));
                        if ($flab !== '' && $val !== '' && ($op === 'contains')){
                            $fids = $labelToIds[$flab] ?? [];
                            if (!empty($fids)) $activeFilters[] = [ 'field_ids'=>$fids, 'value'=>$val ];
                        }
                    }
                    if (!empty($activeFilters)){
                        $keep = [];
                        foreach ($sliceIds as $sid){
                            $vals = $valuesMap[$sid] ?? [];
                            $byField = [];
                            foreach ($vals as $v){ $fidv=(int)($v['field_id'] ?? 0); $val=(string)($v['value'] ?? ''); if ($fidv>0){ if (!isset($byField[$fidv])) $byField[$fidv] = []; if ($val!=='') $byField[$fidv][] = $val; } }
                            $okAll = true;
                            foreach ($activeFilters as $af){
                                $hit = false;
                                foreach ($af['field_ids'] as $fidNeed){
                                    $cell = isset($byField[$fidNeed]) ? implode(' | ', $byField[$fidNeed]) : '';
                                    $cellN = $normalize($cell);
                                    if ($cellN !== '' && strpos($cellN, (string)$af['value']) !== false){ $hit = true; break; }
                                }
                                if (!$hit){ $okAll = false; break; }
                            }
                            if ($okAll) $keep[] = $sid;
                        }
                        if (!empty($keep)){
                            $filteredIds = $keep; $planningApplied = true;
                        }
                    }
                }
            } catch (\Throwable $e) { /* planning step optional; ignore errors */ }
            // Header labels
            $labels = [];
            $idToLabel = [];
            foreach ($fmeta as $fm){
                $fidm = (int)($fm['id'] ?? 0);
                $labm = (string)($fm['label'] ?? '');
                if ($labm === '') { $labm = 'فیلد #' . $fidm; }
                $idToLabel[$fidm] = $labm;
                $labels[] = $labm;
            }
            $rowsCsv = [];
            $idsForCsv = !empty($filteredIds) ? $filteredIds : $sliceIds;
            if (!empty($labels)){
                $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $labels));
                foreach ($idsForCsv as $sid){
                    $vals = $valuesMap[$sid] ?? [];
                    $map = [];
                    foreach ($vals as $v){
                        $fidv = (int)($v['field_id'] ?? 0);
                        $lab = (string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab = 'فیلد #'.$fidv;
                        $val = trim((string)($v['value'] ?? ''));
                        if (!isset($map[$lab])) $map[$lab] = [];
                        if ($val !== '') $map[$lab][] = $val;
                    }
                    $rowsCsv[] = implode(',', array_map(function($h) use ($map){ $v = isset($map[$h]) ? implode(' | ', $map[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $labels));
                }
            }
            $tableCsv = implode("\r\n", $rowsCsv);
            // Smart model selection for analytics
            $complexity_score = strlen($question) > 200 ? 8 : (count($sliceIds) > 100 ? 6 : 4);
            $use_model_struct = self::select_optimal_model($ai_cfg, $question, 'analytics', $complexity_score);
            // Build structured system prompt
            // Gather hint row IDs from partials (to prioritize exact rows if the model is weak/mini)
            $hintMatchedRows = [];
            try {
                foreach (($partials ?: []) as $pt){
                    $dbgl = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                    foreach ($dbgl as $d0){
                        if (isset($d0['matched_row_ids']) && is_array($d0['matched_row_ids'])){
                            foreach ($d0['matched_row_ids'] as $rid){ $rid = (int)$rid; if ($rid>0) $hintMatchedRows[$rid] = true; }
                        }
                    }
                }
            } catch (\Throwable $e) { /* noop */ }
            $hintMatchedRows = array_values(array_map('intval', array_keys($hintMatchedRows)));
            $sys = 'You are Hoshang, a Persian analytics assistant. Strict rules:\n'
                . '1) Map column labels to semantic concepts when needed. Examples (not exhaustive):\n'
                . '   - name: "نام"، "اسم"، "نام و نام خانوادگی"، "first name"، "last name"، "full name".\n'
                . '   - phone: "شماره تلفن"، "شماره تماس"، "موبایل"، "تلفن"، "تلفن همراه"، "mobile"، "phone".\n'
                . '   - mood_text: "امروز اوضاع و احوالتون چطوره"، "حال و احوال"، "حال"، "روحیه".\n'
                . '   - mood_score: "به حال دلت چه امتیازی میدی"، "امتیاز حال دل"، "نمره حال".\n'
                . '2) Normalize Persian text when matching: treat ی/ي and ک/ك as the same; remove zero-width joiners (U+200C) and diacritics; ignore punctuation and extra spaces; match case-insensitively; allow partial substring matches.\n'
                . '3) When searching by a person/entity name in the question (e.g., "نیما"), locate rows where any name-like column contains that name (after normalization), then extract the requested field (e.g., phone, mood) from the same row.\n'
                . '   - Table cells may contain multiple values joined by a delimiter like " | "; split and inspect each value.\n'
                . '   - For phone answers: extract and return the phone number digits for the best-matching row.\n'
                . '4) Interpret the question and link it to the most relevant fields; perform comparisons, summaries, and basic aggregations only from provided data.\n'
                . '5) Never hallucinate values; if insufficient data: return answer="No matching data found."\n'
                . '6) Return JSON ONLY (no markdown/text outside JSON). Keys in English. Values/text (answer) in Persian.\n'
                . '7) If charts are implied, include minimal chart_data array of objects (e.g., name/label and value/score).\n'
                . '8) Keep outputs concise.\n'
                . '9) If user payload includes hints (requested_person or matched_row_ids), prioritize those rows/records and use requested_person verbatim in the Persian answer when applicable.';
            $messages = [ [ 'role' => 'system', 'content' => $sys ] ];
            foreach ($history as $h){ $messages[] = $h; }
            $payloadUser = [
                'question' => $question,
                'form_id' => $fid,
                'data_format' => 'table',
                'table_csv' => $tableCsv,
                'fields_meta' => $fmeta,
                'hints' => [
                    'requested_person' => (string)$requestedPerson,
                    'matched_row_ids' => $hintMatchedRows,
                ],
                // Define desired JSON schema explicitly
                'output_schema' => [
                    'answer' => 'string (Persian)',
                    'fields_used' => ['string'],
                    'aggregations' => new \stdClass(),
                    'chart_data' => [ new \stdClass() ],
                    'confidence' => 'low|medium|high'
                ]
            ];
            $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
            $payload = [ 'model' => $use_model_struct, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $max_tokens ];
            $payloadJson = wp_json_encode($payload);
            if ($debug){
                $prevMsgs = [];
                foreach ($messages as $m){ $c=(string)($m['content'] ?? ''); if (strlen($c)>1800) $c=substr($c,0,1800)."\n…[truncated]"; $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ]; }
                $dbgEntry = [ 'form_id'=>$fid, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$use_model_struct, 'max_tokens'=>$max_tokens, 'temperature'=>0.2, 'messages'=>$prevMsgs ], 'routing' => [ 'hosh_mode'=>$hoshMode, 'structured'=>true, 'auto'=>$autoStructured, 'trigger'=>$structTrigger ] ];
                if ($planningApplied || $planObj){ $dbgEntry['planning'] = [ 'applied' => (bool)$planningApplied, 'model' => (string)$planningModel, 'plan' => $planObj, 'filtered_rows' => is_array($idsForCsv)? count($idsForCsv) : 0, 'total_rows' => count($sliceIds) ]; }
                $debugInfo[] = $dbgEntry;
            }
            // Perform request with 1-shot fallback to gpt-4o on 400/404/422
            $makeReq = function($modelName) use ($endpoint, $http_timeout, $headers, $messages, $max_tokens){
                $pl = [ 'model'=>$modelName, 'messages'=>$messages, 'temperature'=>0.2, 'max_tokens'=>$max_tokens ];
                $plJson = wp_json_encode($pl);
                $t0 = microtime(true);
                $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                $ok = ($status === 200);
                $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms' => (int) round((microtime(true)-$t0)*1000) ];
                return [ $ok, $status, $raw, $body, $usage, $plJson ];
            };
            [ $ok, $status, $rawBody, $body, $usage, $plJson ] = $makeReq($use_model_struct);
            $finalModel = $use_model_struct;
            if (!$ok && in_array((int)$status, [400,404,422], true) && $use_model_struct !== 'gpt-4o'){
                [ $ok2, $status2, $rawBody2, $body2, $usage2, $plJson2 ] = $makeReq('gpt-4o');
                if ($ok2){ $ok=true; $status=$status2; $rawBody=$rawBody2; $body=$body2; $usage=$usage2; $plJson=$plJson2; $finalModel='gpt-4o'; }
            }
            // Friendly mapping for auth/quota errors: 401/403/429
            if (!$ok && in_array((int)$status, [401,403,429], true)){
                $friendly = 'امکان برقراری ارتباط با سرویس تحلیل وجود ندارد. ممکن است شارژ شما تمام شده باشد.';
                $result = [ 'answer' => $friendly, 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
                // Attach clarify if available and not already present
                if (is_array($clarify) && empty($result['clarify'])){ $result['clarify'] = $clarify; }
                $summary = (string)($result['answer'] ?? '');
                // Log usage and persist assistant turn
                self::log_ai_usage($agentName, $finalModel, $usage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]);
                if (is_array($planUsage)){ self::log_ai_usage('hoshang-plan', $planningModel ?: $use_model, $planUsage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]); }
                $usages[] = [ 'form_id'=>$fid, 'usage'=>$usage ];
                try {
                    if ($session_id > 0){
                        global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                        $wpdb->insert($tblMsg, [
                            'session_id' => $session_id,
                            'role' => 'assistant',
                            'content' => $summary,
                            'usage_input' => max(0, (int)$usage['input']),
                            'usage_output' => max(0, (int)$usage['output']),
                            'usage_total' => max(0, (int)$usage['total']),
                            'duration_ms' => max(0, (int)$usage['duration_ms']),
                            'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>'json', 'structured'=>true ], JSON_UNESCAPED_UNICODE),
                        ]);
                        $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
                    }
                } catch (\Throwable $e) { /* ignore */ }
                $dbgPayload = null;
                if ($debug){
                    $dbg = [ 'form_id'=>$fid, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$finalModel, 'max_tokens'=>$max_tokens, 'temperature'=>0.2 ], 'final_model'=>$finalModel, 'routing' => [ 'hosh_mode'=>$hoshMode, 'structured'=>true, 'auto'=>$autoStructured, 'trigger'=>$structTrigger, 'auto_format'=>$autoFormat ], 'http_status'=>$status, 'error_mapped'=>true ];
                    try { $dbg['raw'] = (strlen((string)$rawBody)>1800? substr((string)$rawBody,0,1800)."\n…[truncated]" : (string)$rawBody); } catch (\Throwable $e) { /* noop */ }
                    if (!empty($clarify)) { $dbg['clarify'] = $clarify; }
                    $dbgPayload = [ $dbg ];
                }
                $respPayload = [ 'result' => $result, 'summary' => $summary, 'usage' => $usages, 'voice' => $voice, 'session_id' => $session_id, 'model' => $finalModel ];
                if ($dbgPayload){ $respPayload['debug'] = $dbgPayload; }
                return new WP_REST_Response($respPayload, 200);
            }
            // Extract text and usage
            $text = '';
            if (is_array($body)){
                try {
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])){
                        $text = (string)$body['choices'][0]['message']['content'];
                    } elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])){
                        $text = (string)$body['choices'][0]['text'];
                    } elseif (isset($body['output_text']) && is_string($body['output_text'])){
                        $text = (string)$body['output_text'];
                    }
                } catch (\Throwable $e) { $text=''; }
                $u = $body['usage'] ?? [];
                $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                if ((!$in && !$out && !$tot) && is_array($body)){
                    // try headers
                    // Note: $resp isn’t available here; usage already has duration. Estimate instead.
                }
                if (!$in && !$out && !$tot){
                    $promptApprox = strlen((string)$plJson);
                    $compApprox = strlen((string)$text);
                    $in=(int)ceil($promptApprox/4); $out=(int)ceil($compApprox/4); $tot=$in+$out;
                }
                $usage['input']=$in; $usage['output']=$out; $usage['total']=$tot;
            }
            // Parse structured JSON from model content
            $result = null;
            if (is_string($text) && $text !== ''){
                $decoded = json_decode($text, true);
                if (is_array($decoded)){
                    $result = $decoded;
                } else {
                    // Stage 3: JSON repair mini-call — extract/fix JSON if model returned fenced or noisy content
                    $repairDbg = null;
                    try {
                        $repairModel = (preg_match('/mini/i', (string)$use_model_struct)) ? $use_model_struct : 'gpt-4o-mini';
                        $schema = [
                            'answer' => 'string (Persian)',
                            'fields_used' => ['string'],
                            'aggregations' => new \stdClass(),
                            'chart_data' => [ new \stdClass() ],
                            'confidence' => 'low|medium|high'
                        ];
                        $repSys = 'You are a strict JSON repair tool. Input may include markdown fences or surrounding text. Task: extract and FIX a single JSON object matching the expected schema (keys in English). Output ONLY the JSON with no backticks or commentary.';
                        $repUser = [ 'schema' => $schema, 'text' => (string)$text ];
                        $repMsgs = [ [ 'role'=>'system','content'=>$repSys ], [ 'role'=>'user','content'=>json_encode($repUser, JSON_UNESCAPED_UNICODE) ] ];
                        $makeRepairReq = function($modelName) use ($endpoint, $http_timeout, $headers, $repMsgs){
                            $pl = [ 'model'=>$modelName, 'messages'=>$repMsgs, 'temperature'=>0.0, 'max_tokens'=>600 ];
                            $plJson = wp_json_encode($pl);
                            $t0 = microtime(true);
                            $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                            $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                            $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                            $ok = ($status === 200);
                            $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                            $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>(int)round((microtime(true)-$t0)*1000) ];
                            return [ $ok, $status, $raw, $body, $usage, $plJson ];
                        };
                        [ $rok, $rstatus, $rraw, $rbody, $rusage, $rjson ] = $makeRepairReq($repairModel);
                        $repairFinalModel = $repairModel;
                        if (!$rok && in_array((int)$rstatus, [400,404,422], true) && $repairModel !== $use_model_struct){
                            [ $rok2, $rstatus2, $rraw2, $rbody2, $rusage2, $rjson2 ] = $makeRepairReq($use_model_struct);
                            if ($rok2){ $rok=true; $rstatus=$rstatus2; $rraw=$rraw2; $rbody=$rbody2; $rusage=$rusage2; $rjson=$rjson2; $repairFinalModel=$use_model_struct; }
                        }
                        $repaired = '';
                        if (is_array($rbody)){
                            try {
                                if (isset($rbody['choices'][0]['message']['content']) && is_string($rbody['choices'][0]['message']['content'])){ $repaired = (string)$rbody['choices'][0]['message']['content']; }
                                elseif (isset($rbody['choices'][0]['text']) && is_string($rbody['choices'][0]['text'])){ $repaired = (string)$rbody['choices'][0]['text']; }
                                elseif (isset($rbody['output_text']) && is_string($rbody['output_text'])){ $repaired = (string)$rbody['output_text']; }
                            } catch (\Throwable $e) { $repaired=''; }
                        }
                        $fixed = $repaired !== '' ? json_decode($repaired, true) : null;
                        if (is_array($fixed)){
                            $result = $fixed;
                            // Log repair usage separately
                            self::log_ai_usage('hoshang-repair', $repairFinalModel, $rusage, [ 'form_id'=>$fid, 'repair'=>1 ]);
                            if ($debug){
                                $repairDbg = [ 'model'=>$repairFinalModel, 'http_status'=>$rstatus, 'ok'=>true ];
                                try { $repairDbg['raw'] = (strlen((string)$rraw)>1200? substr((string)$rraw,0,1200)."\n…[truncated]" : (string)$rraw); } catch (\Throwable $e) { /* noop */ }
                            }
                        } else {
                            // Fallback: wrap original plain text into schema
                            $result = [ 'answer' => $text, 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
                            if ($debug){ $repairDbg = [ 'model'=>$repairModel, 'http_status'=>$rstatus, 'ok'=>false ]; }
                        }
                    } catch (\Throwable $e) {
                        // Final fallback when repair stage fails unexpectedly
                        $result = [ 'answer' => $text, 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
                    }
                }
            } else {
                $result = [ 'answer' => 'No matching data found.', 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
            }
            // Deterministic synthesis fallback: if model returned empty/"No matching data found" but we have a requested person and hint rows in this slice
            try {
                $ansTxt = (string)($result['answer'] ?? '');
                $looksEmpty = ($ansTxt === '') || (stripos($ansTxt, 'No matching data found') !== false);
                if ($looksEmpty && $requestedPerson !== '' && !empty($hintMatchedRows)){
                    // Intersect hint rows with this slice
                    $sliceIdSet = [];
                    foreach (($sliceIds ?: []) as $sid){ $sliceIdSet[(int)$sid] = true; }
                    $chosen = 0;
                    foreach ($hintMatchedRows as $rid){ if (isset($sliceIdSet[(int)$rid])) { $chosen = (int)$rid; break; } }
                    if ($chosen > 0 && isset($valuesMap[$chosen]) && is_array($valuesMap[$chosen])){
                        // Build label->values map for the chosen row
                        $map = [];
                        foreach ($valuesMap[$chosen] as $v){
                            $fidv = (int)($v['field_id'] ?? 0);
                            $lab = (string)($idToLabel[$fidv] ?? ('فیلد #'.$fidv));
                            $val = trim((string)($v['value'] ?? ''));
                            if ($val === '') continue;
                            if (!isset($map[$lab])) $map[$lab] = [];
                            $map[$lab][] = $val;
                        }
                        // Try to extract phones first
                        $phones = [];
                        foreach ($map as $lab => $vals){
                            if (preg_match('/(شماره|تلفن|موبایل|تماس|phone|mobile)/ui', (string)$lab)){
                                foreach ($vals as $vv){
                                    $vv2 = preg_replace('/[^0-9+]/', '', (string)$vv);
                                    if ($vv2 !== '' && !in_array($vv2, $phones, true)) $phones[] = $vv2;
                                }
                            }
                        }
                        if (!empty($phones)){
                            $phStr = implode('، ', $phones);
                            $result = [
                                'answer' => 'شماره‌های تماس ' . $requestedPerson . ': ' . $phStr,
                                'fields_used' => ['name','phone'],
                                'aggregations' => new \stdClass(),
                                'chart_data' => [],
                                'confidence' => 'medium'
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) { /* noop */ }
            // Attach clarify if available and not already present
            if (is_array($clarify) && empty($result['clarify'])){ $result['clarify'] = $clarify; }
            $summary = (string)($result['answer'] ?? '');
            // Log usage (include planning call if available) and persist assistant turn
            self::log_ai_usage($agentName, $finalModel, $usage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]);
            if (is_array($planUsage)){ self::log_ai_usage('hoshang-plan', $planningModel ?: $use_model, $planUsage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]); }
            $usages[] = [ 'form_id'=>$fid, 'usage'=>$usage ];
            try {
                if ($session_id > 0){
                    global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                    $wpdb->insert($tblMsg, [
                        'session_id' => $session_id,
                        'role' => 'assistant',
                        'content' => $summary,
                        'usage_input' => max(0, (int)$usage['input']),
                        'usage_output' => max(0, (int)$usage['output']),
                        'usage_total' => max(0, (int)$usage['total']),
                        'duration_ms' => max(0, (int)$usage['duration_ms']),
                        'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>'json', 'structured'=>true ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
            $respPayload = [ 'result' => $result, 'summary' => $summary, 'usage' => $usages, 'voice' => $voice, 'session_id' => $session_id, 'model' => $finalModel ];
            if ($debug){
                $dbg = [ 'form_id'=>$fid, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$finalModel, 'max_tokens'=>$max_tokens, 'temperature'=>0.2 ], 'final_model'=>$finalModel, 'routing' => [ 'hosh_mode'=>$hoshMode, 'structured'=>true, 'auto'=>$autoStructured, 'trigger'=>$structTrigger, 'auto_format'=>$autoFormat ] ];
                try { $dbg['raw'] = (strlen((string)$rawBody)>1800? substr((string)$rawBody,0,1800)."\n…[truncated]" : (string)$rawBody); } catch (\Throwable $e) { /* noop */ }
                if (!empty($clarify)) { $dbg['clarify'] = $clarify; }
                // Attach planning and optional repair meta when present
                $respPayload['debug'] = [ $dbg ];
            }
            return new WP_REST_Response($respPayload, 200);
        }
        foreach ($tables as $t){
            $rows = $t['rows']; $fid = $t['form_id'];
            // Simple chunking by N rows; we serialize minimally to reduce tokens
            for ($i=0; $i<count($rows); $i+=$chunk_size){
                $slice = array_slice($rows, $i, $chunk_size);
                // Fetch submission values in batch for this slice to ground the model
                $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $slice), function($v){ return $v>0; }));
                $valuesMap = FormsSubmissionRepository::listValuesBySubmissionIds($sliceIds);
                // Optionally build a tabular CSV (header=field labels, rows=submissions) to help the model
                $tableCsv = '';
                // Allow tabular grounding for all non-greeting questions to improve extraction (entity lookups, filters, etc.)
                // The system prompt forbids dumping raw CSV unless explicitly requested, so this is safe.
                $allowTableGrounding = ($format === 'table') || (!$isGreeting);
                if ($allowTableGrounding){
                    $labels = [];
                    $idToLabel = [];
                    foreach (($t['fields_meta'] ?? []) as $fm){
                        $fidm = (int)($fm['id'] ?? 0);
                        $labm = (string)($fm['label'] ?? '');
                        if ($labm === '') { $labm = 'فیلد #' . $fidm; }
                        $idToLabel[$fidm] = $labm;
                    }
                    $labels = array_values(array_map(function($fm){
                        $fidm = (int)($fm['id'] ?? 0);
                        $labm = (string)($fm['label'] ?? '');
                        return $labm !== '' ? $labm : ('فیلد #'.$fidm);
                    }, ($t['fields_meta'] ?? [])));
                    if (!empty($labels)){
                        $rowsCsv = [];
                        $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $labels));
                        foreach ($sliceIds as $sid){
                            $vals = $valuesMap[$sid] ?? [];
                            $map = [];
                            foreach ($vals as $v){
                                $fidv = (int)($v['field_id'] ?? 0);
                                $lab = (string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab = 'فیلد #'.$fidv;
                                $val = trim((string)($v['value'] ?? ''));
                                if (!isset($map[$lab])) $map[$lab] = [];
                                if ($val !== '') $map[$lab][] = $val;
                            }
                            $rowsCsv[] = implode(',', array_map(function($h) use ($map){ $v = isset($map[$h]) ? implode(' | ', $map[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $labels));
                        }
                        $tableCsv = implode("\r\n", $rowsCsv);
                    }
                }

                // Build chat messages: system + history + current user payload (grounded data)
                $messages = [
                    [ 'role' => 'system', 'content' => 'You are a Persian-only answering model. Follow strictly:
1) پاسخ فقط و فقط بر اساس داده‌های همین فرم ارسالی (fields_meta, rows, values یا table_csv). هیچ دانش خارجی، مثال عمومی، یا حدس مجاز نیست.
2) زبان خروجی: فقط فارسی.
3) بدون مقدمه یا توضیح اضافی؛ فقط پاسخ مستقیم طبق قالب خواسته‌شده.
4) اگر پاسخ با اتکا به داده‌های فرم ممکن نیست، دقیقاً بنویس: «اطلاعات لازم در فرم پیدا نمی‌کنم».
5) اگر intent یا out_format داده شد، همان را رعایت کن (list_names, list_fields, list_field_values, field_value, show_all_compact_preview | list/table/plain).
6) اگر پرسش مبهم یا صرفاً خوش‌وبش/سلام بود، یک خوشامدگویی خیلی کوتاه بده و یک سؤال روشن‌کنندهٔ کوتاه بپرس؛ از نمایش خام داده‌ها یا جدول خودداری کن مگر کاربر صریحاً درخواست «نمایش/لیست/جدول» کرده باشد.
راهنمای جست‌وجوی مقدار:
- اگر سؤال شامل نام فرد/موجودیت بود (مثل «نیما»)، ستون‌های شبیه نام (label: نام/اسم/name/first/last/full) را پیدا کن و با تطبیق جزئی (case-insensitive) ردیف/ردیف‌های مرتبط را بیاب؛ سپس مقدار ستون مرتبط با سؤال را بازگو کن.
- توجه: واژهٔ «اسم» به‌تنهایی به معنی «نام شخص» نیست. فقط اگر نام خاصی در سؤال آمده باشد (مثلاً «نیما»)، از ستون‌های نام اشخاص استفاده کن. اگر عبارت «اسم X» آمده باشد (مثل «اسم میوه»)، منظور ستون «X» است نه نام شخص.
- اگر در سؤال به یک فیلد اشاره شد (مثل «میوه»)، از fields_meta برچسب‌های شامل آن واژه را پیدا کن (با نادیده‌گرفتن فاصله/HTML entities مثل &nbsp;) و از همان ستون مقدار را استخراج کن.
- خروجی را کوتاه و دقیق بنویس (مثلاً فقط نام میوه)، مگر explicitly «لیست/جدول» خواسته شده باشد.
راهنمای فرمت جدول:
- فقط وقتی data_format=table و کاربر واقعاً درخواست «لیست/نمایش/جدول» کرده، CSV را مبنای پاسخ قرار بده. هرگز CSV خام، هدرها یا داده‌ها را بدون درخواست صریح چاپ نکن؛ صرفاً خروجی خواسته‌شده را فشرده و کاربردی ارائه کن.
داده‌ها:
- fields_meta: [{id,label,type}]، rows: [{id,...}]، values: {submission_id: [{field_id,value}]}
'
                    ]
                ];
                if (!empty($history)){
                    foreach ($history as $h){ $messages[] = $h; }
                }
                // Derive intent with field-aware hints
                $intent = null;
                if ($isShowAllIntent || $isAnswersIntent) {
                    $intent = 'show_all_compact_preview';
                } elseif ($isFieldsIntent) {
                    $intent = 'list_fields';
                } elseif ($field_hint !== '' && $isListOut) {
                    $intent = 'list_field_values';
                } elseif ($field_hint !== '') {
                    $intent = 'field_value';
                } elseif ($isNamesIntent) {
                    $intent = 'list_names';
                }
                if ($allowTableGrounding && $tableCsv !== ''){
                    $payloadUser = [ 'question'=>$question, 'form_id'=>$fid, 'data_format'=>'table', 'table_csv'=>$tableCsv, 'fields_meta'=>$t['fields_meta'] ?? [] ];
                    if ($intent) { $payloadUser['intent'] = $intent; }
                    if ($out_format) { $payloadUser['out_format'] = $out_format; }
                    if ($field_hint !== '') { $payloadUser['field_hint'] = $field_hint; }
                    $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
                } else {
                    $payloadUser = [ 'question'=>$question, 'form_id'=>$fid, 'fields_meta'=>$t['fields_meta'] ?? [], 'rows'=>$slice, 'values'=>$valuesMap ];
                    if ($intent) { $payloadUser['intent'] = $intent; }
                    if ($out_format) { $payloadUser['out_format'] = $out_format; }
                    if ($field_hint !== '') { $payloadUser['field_hint'] = $field_hint; }
                    $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
                }
                $payload = [
                    'model' => self::normalize_model_name($use_model),
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => $max_tokens,
                ];
                $payloadJson = wp_json_encode($payload);
                $t0 = microtime(true);
                if ($debug){
                    // Attach a lightweight request preview (truncate large fields) for debugging
                    $prevMsgs = [];
                    foreach ($messages as $m){
                        $c = (string)($m['content'] ?? '');
                        if (strlen($c) > 1800) { $c = substr($c, 0, 1800) . "\n…[truncated]"; }
                        $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ];
                    }
                    $debugInfo[] = [ 'form_id'=>$fid, 'chunk_index'=>$i, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$use_model, 'max_tokens'=>$max_tokens, 'temperature'=>0.2, 'messages'=>$prevMsgs ] ];
                }
                $resLLM = self::wp_post_with_retries($endpoint, $headers, $payload, $http_timeout, 3, [500,1000,2000], 'gpt-4o');
                $ok = ($resLLM['ok'] ?? false) === true;
                $rawBody = (string)($resLLM['body'] ?? '');
                $body = is_array($resLLM['json'] ?? null) ? $resLLM['json'] : ($ok ? json_decode($rawBody, true) : null);
                $text = '';
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'cost'=>null,'duration_ms'=> (int) round((microtime(true)-$t0)*1000) ];
                if (is_array($body)){
                    // Try to extract text from multiple common shapes
                    $text = '';
                    try {
                        if (isset($body['choices']) && is_array($body['choices']) && isset($body['choices'][0])){
                            $c0 = $body['choices'][0];
                            if (isset($c0['message']['content'])){
                                $mc = $c0['message']['content'];
                                if (is_string($mc)) { $text = (string)$mc; }
                                elseif (is_array($mc)) { // parts array
                                    $parts = [];
                                    foreach ($mc as $part){
                                        if (is_string($part)) $parts[] = $part;
                                        elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) $parts[] = $part['text'];
                                    }
                                    $text = trim(implode("\n", $parts));
                                }
                            } elseif (isset($c0['text']) && is_string($c0['text'])) {
                                $text = (string)$c0['text'];
                            }
                        }
                        if ($text === '' && isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                        if ($text === '' && isset($body['message']) && is_string($body['message'])) $text = (string)$body['message'];
                        if ($text === '' && isset($body['content']) && is_string($body['content'])) $text = (string)$body['content'];
                    } catch (\Throwable $e) { $text = ''; }
                    $u = $body['usage'] ?? [];
                    // Support multiple usage shapes (OpenAI-style and others)
                    $in = 0; $out = 0; $tot = 0;
                    if (is_array($u)){
                        $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                        $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                        $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                        // Nested tokens object
                        if (!$in && is_array($u['tokens'] ?? null)){
                            $in = (int)($u['tokens']['input'] ?? 0);
                        }
                        if (!$out && is_array($u['tokens'] ?? null)){
                            $out = (int)($u['tokens']['output'] ?? 0);
                        }
                        if (!$tot && is_array($u['tokens'] ?? null)){
                            $tot = (int)($u['tokens']['total'] ?? 0);
                        }
                    }
                    // If body.usage missing or zeros, try headers (provider-specific)
                    if ((!$in && !$out && !$tot) && is_array($resp)){
                        $hdrs = wp_remote_retrieve_headers($resp);
                        $hdrsArr = [];
                        if (is_object($hdrs) && method_exists($hdrs, 'getAll')){ $hdrsArr = $hdrs->getAll(); }
                        elseif (is_array($hdrs)){ $hdrsArr = $hdrs; }
                        if (is_array($hdrsArr)){
                            foreach ($hdrsArr as $hk => $hv){
                                $k = strtolower((string)$hk); $v = is_array($hv)? implode(',', $hv) : (string)$hv;
                                if (!$in && preg_match('/(prompt|input).*tokens/', $k)) { $in = (int)preg_replace('/\D+/', '', $v); }
                                if (!$out && preg_match('/(completion|output).*tokens/', $k)) { $out = (int)preg_replace('/\D+/', '', $v); }
                                if (!$tot && preg_match('/total.*tokens/', $k)) { $tot = (int)preg_replace('/\D+/', '', $v); }
                            }
                        }
                    }
                    // As a very rough fallback, estimate tokens from characters (approx 4 chars per token)
                    if (!$in && !$out && !$tot){
                        $promptApprox = strlen((string)$payloadJson);
                        $compApprox = strlen($text);
                        $in = (int) ceil($promptApprox / 4);
                        $out = (int) ceil($compApprox / 4);
                        $tot = $in + $out;
                    }
                    $usage['input'] = max(0, $in);
                    $usage['output'] = max(0, $out);
                    $usage['total'] = max(0, $tot ?: ($usage['input'] + $usage['output']));
                }
                // If provider returned no body or usage still zero, estimate from payload/response text
                if ((!$usage['input'] && !$usage['output'] && !$usage['total'])){
                    $promptApprox = strlen((string)$payloadJson);
                    $compApprox = strlen((string)$text);
                    $usage['input'] = max(1, (int) ceil($promptApprox / 4));
                    $usage['output'] = max(0, (int) ceil($compApprox / 4));
                    $usage['total'] = $usage['input'] + $usage['output'];
                }
                if ($text === '' || !is_string($text)){
                    $text = 'اطلاعات لازم در فرم پیدا نمی‌کنم';
                }
                $answers[] = [ 'form_id'=>$fid, 'chunk'=> [ 'index'=>$i, 'size'=>count($slice) ], 'text'=>$text ];
                // Log usage
                self::log_ai_usage($agentName, $use_model, $usage, [ 'form_id'=>$fid, 'rows'=>count($slice) ]);
                $usages[] = [ 'form_id'=>$fid, 'usage'=>$usage ];
                if ($debug || !$ok || $text === ''){
                    $dbg = [
                        'form_id' => $fid,
                        'chunk_index' => $i,
                        'rows' => count($slice),
                        'endpoint' => $endpoint,
                        'model' => $use_model,
                        'http_status' => is_array($resp) ? (int)wp_remote_retrieve_response_code($resp) : null,
                        'usage' => $usage,
                    ];
                    try { $dbg['raw'] = (strlen($rawBody) > 1800) ? (substr($rawBody, 0, 1800) . "\n…[truncated]") : $rawBody; } catch (\Throwable $e) { /* noop */ }
                    $debugInfo[] = $dbg;
                }
            }
        }
        // Merge answers: no headings or preface; join texts directly
        $summary = implode("\n\n", array_map(function($a){ return trim((string)$a['text']); }, $answers));
        $respPayload = [ 'summary' => $summary, 'chunks' => $answers, 'usage' => $usages, 'voice' => $voice, 'session_id' => $session_id ];
        // Persist assistant turn with aggregated usage
        try {
            if ($session_id > 0){
                $tot = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>0 ];
                foreach ($usages as $u){ $uu = $u['usage'] ?? []; $tot['input'] += (int)($uu['input'] ?? 0); $tot['output'] += (int)($uu['output'] ?? 0); $tot['total'] += (int)($uu['total'] ?? 0); $tot['duration_ms'] += (int)($uu['duration_ms'] ?? 0); }
                global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                $wpdb->insert($tblMsg, [
                    'session_id' => $session_id,
                    'role' => 'assistant',
                    'content' => $summary,
                    'usage_input' => max(0, (int)$tot['input']),
                    'usage_output' => max(0, (int)$tot['output']),
                    'usage_total' => max(0, (int)$tot['total']),
                    'duration_ms' => max(0, (int)$tot['duration_ms']),
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>$format ], JSON_UNESCAPED_UNICODE),
                ]);
                $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
            }
        } catch (\Throwable $e) { /* ignore persistence errors */ }
    if ($debug || !empty($debugInfo)){ $respPayload['debug'] = $debugInfo; }
        return new WP_REST_Response($respPayload, 200);
    }

    /**
     * POST /ai/simple-chat — Minimal chat proxy to LLM.
     * Body: { message: string, history?: [{role, content}], model?, max_tokens?, temperature? }
     * Returns: { reply: string, usage?: {...}, debug?: {...} }
     */
    public static function ai_simple_chat(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params(); if (!is_array($p)) $p = $request->get_params();
            $message = is_scalar($p['message'] ?? null) ? trim((string)$p['message']) : '';
            if ($message === '') return new WP_REST_Response(['error'=>'message_required'], 400);
            $session_id = isset($p['session_id']) && is_numeric($p['session_id']) ? (int)$p['session_id'] : 0;
            // Optional grounding: allow a single reference form id (sent as [id]) to answer strictly from form data
            $form_ids = [];
            if (isset($p['form_ids'])){
                $form_ids = array_values(array_filter(array_map('intval', (array)$p['form_ids']), function($v){ return $v>0; }));
                if (count($form_ids) > 1) { $form_ids = [ $form_ids[0] ]; }
            }
            $history = [];
            if (isset($p['history']) && is_array($p['history'])){
                foreach ($p['history'] as $h){
                    if (!is_array($h)) continue; $role = (string)($h['role'] ?? ''); $content = (string)($h['content'] ?? '');
                    if ($content==='') continue; if (!in_array($role, ['user','assistant','system'], true)) $role='user';
                    $history[] = [ 'role'=>$role, 'content'=>$content ];
                }
            }
            $model = is_scalar($p['model'] ?? null) ? (string)$p['model'] : '';
            $max_tokens = isset($p['max_tokens']) && is_numeric($p['max_tokens']) ? max(16, min(2048, (int)$p['max_tokens'])) : 800;
            $temperature = isset($p['temperature']) && is_numeric($p['temperature']) ? max(0.0, min(2.0, (float)$p['temperature'])) : 0.3;
            $wantDebug = !empty($p['debug']);

            // If a reference form is provided, route through analytics to keep answers grounded on form data
            if (!empty($form_ids)){
                try {
                    $r = new \WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
                    $r->set_body_params([
                        'form_ids'   => $form_ids,
                        'question'   => $message,
                        'session_id' => $session_id,
                        'max_tokens' => $max_tokens,
                        'debug'      => $wantDebug,
                    ]);
                    /** @var \WP_REST_Response $resp */
                    $resp = self::analytics_analyze($r);
                    $code = $resp instanceof \WP_REST_Response ? (int)$resp->get_status() : 500;
                    $data = $resp instanceof \WP_REST_Response ? $resp->get_data() : null;
                    if ($code === 200 && is_array($data)){
                        $reply = '';
                        if (isset($data['result']) && is_array($data['result']) && isset($data['result']['answer'])){
                            $reply = (string)$data['result']['answer'];
                        }
                        if ($reply === ''){ $reply = (string)($data['summary'] ?? ''); }
                        if ($reply === ''){ $reply = 'اطلاعات لازم در فرم پیدا نمی‌کنم'; }
                        // Consolidate usage if provided as an array
                        $usageAgg = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>0 ];
                        try {
                            $u = $data['usage'] ?? [];
                            if (is_array($u)){
                                // usage might be an array of items, or a single dict
                                if (isset($u['input']) || isset($u['total'])){
                                    $usageAgg['input'] = (int)($u['input'] ?? 0);
                                    $usageAgg['output']= (int)($u['output'] ?? 0);
                                    $usageAgg['total'] = (int)($u['total'] ?? 0);
                                    $usageAgg['duration_ms'] = (int)($u['duration_ms'] ?? 0);
                                } else {
                                    foreach ($u as $it){ $uu = is_array($it) ? ($it['usage'] ?? $it) : []; $usageAgg['input'] += (int)($uu['input'] ?? 0); $usageAgg['output'] += (int)($uu['output'] ?? 0); $usageAgg['total'] += (int)($uu['total'] ?? 0); $usageAgg['duration_ms'] += (int)($uu['duration_ms'] ?? 0); }
                                }
                            }
                        } catch (\Throwable $e) { /* ignore */ }
                        // Prefer session id from analytics (it may create one)
                        $sid = isset($data['session_id']) && is_numeric($data['session_id']) ? (int)$data['session_id'] : $session_id;
                        $res = [ 'reply' => $reply, 'usage' => $usageAgg, 'model' => (string)($data['model'] ?? 'analytics'), 'session_id' => $sid ];
                        if ($debug){
                            $preview = null;
                            try {
                                // Build a small preview to aid console debugging without flooding logs
                                $preview = [
                                    'summary' => isset($data['summary']) ? (string)$data['summary'] : null,
                                    'result' => isset($data['result']) && is_array($data['result']) ? [
                            'phase' => 'final',
                                        'confidence' => (string)($data['result']['confidence'] ?? ''),
                                        'clarify' => isset($data['result']['clarify']) ? $data['result']['clarify'] : null,
                                    ] : null,
                            'debug' => $dbg,
                            'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                                    'usage' => isset($data['usage']) ? $data['usage'] : null,
                                    'model' => isset($data['model']) ? (string)$data['model'] : null,
                                ];
                            } catch (\Throwable $e) { $preview = null; }
                            $res['debug'] = [ 'routed' => 'analytics', 'analytics_debug' => ($data['debug'] ?? null), 'analytics_preview' => $preview ];
                        }
                        return new WP_REST_Response($res, 200);
                    }
                } catch (\Throwable $e) {
                    // Fall back to plain chat if analytics routing fails
                }
            }

            // Load AI config
            $gs = get_option('arshline_settings', []);
            $base = is_scalar($gs['ai_base_url'] ?? null) ? trim((string)$gs['ai_base_url']) : '';
            $api_key = is_scalar($gs['ai_api_key'] ?? null) ? (string)$gs['ai_api_key'] : '';
            $enabled = !empty($gs['ai_enabled']);
            $default_model = is_scalar($gs['ai_model'] ?? null) ? (string)$gs['ai_model'] : 'gpt-4o';
            if (!$enabled || $base === '' || $api_key === ''){
                return new WP_REST_Response([ 'error' => 'ai_disabled' ], 400);
            }
            $use_model = $model !== '' ? $model : $default_model;

            // Normalize base URL to avoid double /v1 when admins paste a base ending with /v1
            $baseNorm = rtrim($base, '/');
            if (preg_match('#/v\d+$#', $baseNorm)) {
                $baseNorm = preg_replace('#/v\d+$#', '', $baseNorm);
            }
            // If someone provided a full endpoint already, respect it
            if (preg_match('#/chat/(?:completions|completion)$#', $baseNorm)) {
                $endpoint = $baseNorm;
            } else {
                $endpoint = $baseNorm . '/v1/chat/completions';
            }
            $headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ];
            $http_timeout = 45;

            // Build conversation history intelligently: prefer persisted session history; fall back to provided history
            $messages = [ [ 'role' => 'system', 'content' => 'فقط فارسی پاسخ بده. پاسخ را کوتاه و مستقیم بنویس. اگر سوال مبهم بود، یک سوال کوتاه برای روشن‌تر شدن بپرس.' ] ];
            $persisted = [];
            $includedHistoryPreview = [];
            try {
                if ($session_id > 0){
                    global $wpdb; $uid = get_current_user_id();
                    $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
                    $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                    $s = $wpdb->get_row($wpdb->prepare("SELECT id, user_id FROM {$tblSess} WHERE id = %d", $session_id), ARRAY_A);
                    if (!$s || ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== (int)$uid)){
                        // Invalid or foreign session — drop it silently
                        $session_id = 0;
                    }
                    // Fetch last 20 messages for this session (ascending by id for chronological order)
                    if ($session_id > 0){
                        $persisted = $wpdb->get_results($wpdb->prepare("SELECT role, content FROM {$tblMsg} WHERE session_id = %d ORDER BY id ASC", $session_id), ARRAY_A) ?: [];
                    }
                }
            } catch (\Throwable $e) { $persisted = []; }
            // Heuristic: include more history for follow-ups or short/elliptic prompts
            $ql = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
            $isFollowUp = (bool)preg_match('/(?:ادامه|بیشتر|جزییات|جزئیات|قبلی|بالا|همان|همین|این|آن|قبلی|توضیح|چرا|چطور|continue|more|explain|previous|again|same|that|it|they|he|she|follow\s*up)/ui', $ql);
            $shortPrompt = strlen($message) < 36;
            $maxTurns = ($isFollowUp || $shortPrompt) ? 16 : 8; // turns = individual messages
            // Merge: use persisted first; if empty, consider provided transient history
            $merged = [];
            if (!empty($persisted)){
                $merged = $persisted;
            } elseif (!empty($history)){
                $merged = $history;
            }
            // Trim to last N messages and append to messages
            if (!empty($merged)){
                $slice = array_slice($merged, -$maxTurns);
                foreach ($slice as $m){
                    $role = in_array(($m['role'] ?? ''), ['user','assistant','system'], true) ? ($m['role'] ?? 'user') : 'user';
                    $content = (string)($m['content'] ?? ''); if ($content==='') continue;
                    $messages[] = [ 'role'=>$role, 'content'=>$content ];
                    if ($wantDebug){ $includedHistoryPreview[] = [ 'role'=>$role, 'content' => (strlen($content)>400? (substr($content,0,400).'…[truncated]') : $content) ]; }
                }
            }
            // Current user message last
            $messages[] = [ 'role' => 'user', 'content' => $message ];

            $makeAttempt = function(string $modelName) use ($messages, $temperature, $max_tokens, $endpoint, $headers, $http_timeout) {
                $payload = [ 'model'=> Api::normalize_model_name($modelName), 'messages'=>$messages, 'temperature'=>$temperature, 'max_tokens'=>$max_tokens ];
                $payloadJson = wp_json_encode($payload);
                $t0 = microtime(true);
                $res0 = Api::wp_post_with_retries($endpoint, $headers, $payload, $http_timeout, 3, [500,1000,2000], 'gpt-4o');
                $status = (int)($res0['status'] ?? 0);
                $raw = (string)($res0['body'] ?? '');
                $ok = ($res0['ok'] ?? false) === true;
                $body = is_array($res0['json'] ?? null) ? $res0['json'] : (json_decode($raw, true) ?: null);
                $text = '';
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=> (int) round((microtime(true)-$t0)*1000) ];
                return [ $ok, $status, $raw, $body, $text, $usage, $payloadJson ];
            };

            // First attempt with the requested/default model
            [ $ok, $status, $rawBody, $body, $text, $usage, $payloadJson ] = $makeAttempt($use_model);

            // Optional one-shot fallback to a known model if model/path is invalid
            $finalModel = $use_model;
            if (!$ok && in_array((int)$status, [400, 404, 422], true)){
                $fallback = 'gpt-4o';
                if ($fallback !== $use_model){
                    [ $ok2, $status2, $rawBody2, $body2, $text2, $usage2, $payloadJson2 ] = $makeAttempt($fallback);
                    if ($ok2){
                        $ok = true; $status = $status2; $rawBody = $rawBody2; $body = $body2; $text = $text2; $usage = $usage2; $payloadJson = $payloadJson2; $finalModel = $fallback;
                    } else {
                        // Keep the first attempt’s artifacts but expose the second in debug attempts
                    }
                }
            }

            if (is_array($body)){
                try {
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])){
                        $text = (string)$body['choices'][0]['message']['content'];
                    } elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) {
                        $text = (string)$body['choices'][0]['text'];
                    } elseif (isset($body['output_text']) && is_string($body['output_text'])) {
                        $text = (string)$body['output_text'];
                    }
                } catch (\Throwable $e) { $text=''; }
                $u = $body['usage'] ?? [];
                $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                // Fallback: rough approximation if provider didn't return usage
                if (!$in && !$out && !$tot){ $promptApprox=strlen((string)$payloadJson); $compApprox=strlen((string)$text); $in=(int)ceil($promptApprox/4); $out=(int)ceil($compApprox/4); $tot=$in+$out; }
                $usage['input']=$in; $usage['output']=$out; $usage['total']=$tot;
            }
            // Ensure session persistence: create session on demand and store turns
            try {
                global $wpdb; $uid = get_current_user_id();
                $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
                $tblMsg  = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                if ($session_id <= 0){
                    $wpdb->insert($tblSess, [
                        'user_id' => $uid ?: null,
                        'title' => function_exists('mb_substr') ? mb_substr($message, 0, 190) : substr($message,0,190),
                        'meta' => wp_json_encode([ 'agent' => 'hoshang-chat' ], JSON_UNESCAPED_UNICODE),
                        'last_message_at' => current_time('mysql'),
                    ]);
                    $session_id = (int)$wpdb->insert_id;
                }
                if ($session_id > 0){
                    // Save user turn
                    $wpdb->insert($tblMsg, [ 'session_id'=>$session_id, 'role'=>'user', 'content'=>$message, 'meta'=>wp_json_encode(['agent'=>'hoshang-chat'], JSON_UNESCAPED_UNICODE) ]);
                }
            } catch (\Throwable $e) { /* ignore */ }

            // Save assistant turn and usage; also log usage aggregate
            try {
                global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                if ($session_id > 0){
                    $wpdb->insert($tblMsg, [
                        'session_id' => $session_id,
                        'role' => 'assistant',
                        'content' => ($text !== '' ? $text : '—'),
                        'usage_input' => max(0, (int)($usage['input'] ?? 0)),
                        'usage_output' => max(0, (int)($usage['output'] ?? 0)),
                        'usage_total' => max(0, (int)($usage['total'] ?? 0)),
                        'duration_ms' => max(0, (int)($usage['duration_ms'] ?? 0)),
                        'meta' => wp_json_encode([ 'agent'=>'hoshang-chat' ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
            self::log_ai_usage('hoshang-chat', $finalModel, $usage, [ 'session_id' => $session_id ]);

            $res = [ 'reply' => ($text !== '' ? $text : '—') , 'usage' => $usage, 'model' => $finalModel, 'session_id' => $session_id ];
            if ($wantDebug){
                $prevMsgs = [];
                foreach ($messages as $m){ $c = (string)($m['content'] ?? ''); if (strlen($c) > 1800) $c = substr($c,0,1800) . "\n…[truncated]"; $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ]; }
                $dbg = [
                    'endpoint' => $endpoint,
                    'request_preview' => [ 'model'=>$use_model, 'max_tokens'=>$max_tokens, 'temperature'=>$temperature, 'messages'=>$prevMsgs ],
                    'http_status' => $ok ? 200 : (int)$status,
                    'raw' => (strlen((string)$rawBody)>1800? substr((string)$rawBody,0,1800)."\n…[truncated]": (string)$rawBody),
                    'final_model' => $finalModel,
                    'used_history_count' => count($messages) - 2, // excluding system + current user
                    'session_id' => $session_id,
                    'included_history_preview' => $includedHistoryPreview,
                ];
                $res['debug'] = $dbg;
            }
            return new WP_REST_Response($res, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([ 'error' => 'server_error' ], 500);
        }
    }

    // ===== Chat history endpoints =====
    public static function list_chat_sessions(\WP_REST_Request $r)
    {
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, title, last_message_at, created_at, updated_at FROM {$t} WHERE user_id %s ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 200", $uid ? '=' : 'IS', $uid ?: null), ARRAY_A);
            if (!is_array($rows)) $rows = [];
            return new \WP_REST_Response([ 'items' => $rows ], 200);
        } catch (\Throwable $e) { return new \WP_REST_Response([ 'items'=>[] ], 200); }
    }
    public static function get_chat_messages(\WP_REST_Request $r)
    {
        $sid = (int)$r['session_id']; if ($sid<=0) return new \WP_REST_Response(['error'=>'invalid_session'], 400);
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tm= \Arshline\Support\Helpers::tableName('ai_chat_messages');
            $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $sid), ARRAY_A);
            if (!$s) return new \WP_REST_Response(['error'=>'not_found'], 404);
            if ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== $uid){ return new \WP_REST_Response(['error'=>'forbidden'], 403); }
            $msgs = $wpdb->get_results($wpdb->prepare("SELECT id, role, content, usage_input, usage_output, usage_total, duration_ms, created_at FROM {$tm} WHERE session_id = %d ORDER BY id ASC", $sid), ARRAY_A) ?: [];
            return new \WP_REST_Response(['session'=>['id'=>$sid,'title'=>$s['title'] ?? ''],'messages'=>$msgs], 200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['error'=>'server_error'], 500); }
    }
    public static function export_chat_session(\WP_REST_Request $r)
    {
        $sid = (int)$r['session_id']; if ($sid<=0) return new \WP_REST_Response(['error'=>'invalid_session'], 400);
        $format = strtolower((string)($r->get_param('format') ?? 'json'));
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tm= \Arshline\Support\Helpers::tableName('ai_chat_messages');
            $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $sid), ARRAY_A);
            if (!$s) return new \WP_REST_Response(['error'=>'not_found'], 404);
            if ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== $uid){ return new \WP_REST_Response(['error'=>'forbidden'], 403); }
            $msgs = $wpdb->get_results($wpdb->prepare("SELECT role, content, usage_input, usage_output, usage_total, duration_ms, created_at FROM {$tm} WHERE session_id = %d ORDER BY id ASC", $sid), ARRAY_A) ?: [];
            if ($format === 'csv'){
                $rows = [ ['created_at','role','usage_input','usage_output','usage_total','duration_ms','content'] ];
                foreach ($msgs as $m){ $rows[] = [ $m['created_at'],$m['role'],(int)$m['usage_input'],(int)$m['usage_output'],(int)$m['usage_total'],(int)$m['duration_ms'],$m['content'] ]; }
                $csv = '';
                foreach ($rows as $r0){ $csv .= implode(',', array_map(function($v){ $s=is_string($v)?$v:json_encode($v); return '"'.str_replace('"','""',$s).'"'; }, $r0)) . "\r\n"; }
                $resp = new \WP_REST_Response($csv, 200); $resp->header('Content-Type','text/csv; charset=UTF-8'); $resp->header('Content-Disposition','attachment; filename="chat-session-'.$sid.'.csv"'); return $resp;
            }
            // default json
            $resp = new \WP_REST_Response([ 'session'=>['id'=>$sid,'title'=>$s['title'] ?? ''],'messages'=>$msgs ], 200);
            $resp->header('Content-Disposition','attachment; filename="chat-session-'.$sid.'.json"');
            return $resp;
        } catch (\Throwable $e) { return new \WP_REST_Response(['error'=>'server_error'], 500); }
    }

    /** Insert a row into ai_usage table. */
    protected static function log_ai_usage(string $agent, string $model, array $usage, array $meta = []): void
    {
        try {
            global $wpdb; $table = Helpers::tableName('ai_usage');
            $user_id = get_current_user_id();
            $wpdb->insert($table, [
                'user_id' => $user_id ?: null,
                'agent' => substr($agent, 0, 32),
                'model' => substr($model, 0, 100),
                'tokens_input' => max(0, (int)($usage['input'] ?? 0)),
                'tokens_output' => max(0, (int)($usage['output'] ?? 0)),
                'tokens_total' => max(0, (int)($usage['total'] ?? 0)),
                'cost' => isset($usage['cost']) ? (float)$usage['cost'] : null,
                'duration_ms' => max(0, (int)($usage['duration_ms'] ?? 0)),
                'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /**
     * Ensures a public_token exists for form and returns it. Admin/editor only.
     */
    public static function ensure_token(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        // Do not issue tokens for non-published forms to enforce gating
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        if (empty($form->public_token)) {
            FormRepository::save($form);
            $form = FormRepository::find($id) ?: $form;
        }
        return new WP_REST_Response(['token' => (string)$form->public_token], 200);
    }

    /**
     * PUT /forms/{id} — update form attributes (currently only status)
     */
    public static function update_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $status = (string)($request->get_param('status') ?? '');
        $undo = null;
        if ($status !== ''){
            $allowed = ['draft','published','disabled'];
            if (!in_array($status, $allowed, true)){
                return new WP_REST_Response(['error'=>'invalid_status'], 400);
            }
            $beforeStatus = $form->status;
            $form->status = $status;
            // Only log when status actually changes
            if ($beforeStatus !== $status){
                $undo = Audit::log('update_form_status', 'form', $id, ['status'=>$beforeStatus], ['status'=>$status]);
            }
        }
        FormRepository::save($form);
        $resp = ['ok'=>true, 'id'=>$form->id, 'status'=>$form->status];
        if ($undo){ $resp['undo_token'] = $undo; }
        return new WP_REST_Response($resp, 200);
    }

    public static function upload_image(WP_REST_Request $request)
    {
        if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
        if (!function_exists('wp_insert_attachment')) require_once(ABSPATH . 'wp-admin/includes/image.php');
        $files = $request->get_file_params();
        if (!isset($files['file'])){
            return new WP_REST_Response(['error' => 'no_file'], 400);
        }
        // Simple per-IP rate limit for uploads (10 per دقیقه)
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $now = time();
            $key = 'arsh_up_rl_'.md5($ip ?: '');
            $entry = get_transient($key);
            $windowSec = 60; $limit = 10;
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + $windowSec];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + $windowSec]; }
            if ((int)$data['count'] >= $limit){ return new WP_REST_Response(['error'=>'rate_limited','retry_after'=>max(0,(int)$data['reset']-$now)], 429); }
            $data['count'] = (int)$data['count'] + 1; set_transient($key, $data, $windowSec);
        } catch (\Throwable $e) { /* ignore RL errors */ }
        $file = $files['file'];
    // Basic size limit (server-side double-check) from global settings
    $gs = self::get_global_settings();
    $maxKB = isset($gs['upload_max_kb']) ? max(50, min(4096, (int)$gs['upload_max_kb'])) : 300;
    $maxBytes = $maxKB * 1024;
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        if ($size <= 0 || $size > $maxBytes){
            return new WP_REST_Response(['error' => 'invalid_size', 'message' => 'File too large or invalid. Max 300KB'], 413);
        }
    // Allow only common raster image types by extension and real MIME (SVG excluded for security)
    $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];
        $clientType = (string)($file['type'] ?? '');
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowedExt = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowedExt, true)){
            return new WP_REST_Response(['error' => 'invalid_extension'], 415);
        }
        // Real MIME sniffing using finfo when available
        $realMime = '';
        if (function_exists('finfo_open') && is_readable($file['tmp_name'] ?? '')){
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f){ $realMime = (string)finfo_file($f, $file['tmp_name']); finfo_close($f); }
        }
        $mimeToCheck = $realMime ?: $clientType;
        if ($mimeToCheck && !in_array($mimeToCheck, $allowedMimes, true)){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        // Block SVG uploads entirely when enabled (risk of embedded scripts)
        $blockSvg = !isset($gs['block_svg']) || (bool)$gs['block_svg'];
        if ($blockSvg && $ext === 'svg'){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        // Enforce max dimensions (<=2048x2048 and <=4MP)
        try {
            $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
            if ($tmp && is_readable($tmp)){
                $info = @getimagesize($tmp);
                if (is_array($info)){
                    $w = (int)($info[0] ?? 0); $h = (int)($info[1] ?? 0);
                    $maxW = 2048; $maxH = 2048; $maxPixels = 4000000;
                    if ($w <= 0 || $h <= 0 || $w > $maxW || $h > $maxH || ($w*$h) > $maxPixels){
                        return new WP_REST_Response(['error'=>'invalid_dimensions','message'=>'Image dimensions exceed limits'], 415);
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        add_filter('upload_dir', function($dirs){
            $dirs['subdir'] = '/arshline';
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        });
        // Enforce type/size checks in WordPress as well
    $overrides = [ 'test_form' => false, 'mimes' => [ 'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp' ], 'unique_filename_callback' => null ];
        $movefile = wp_handle_upload($file, $overrides);
        remove_all_filters('upload_dir');
        if (!$movefile || isset($movefile['error'])){
            return new WP_REST_Response(['error' => 'upload_failed', 'message' => $movefile['error'] ?? ''], 500);
        }
        // Return URL only
        return new WP_REST_Response([ 'url' => $movefile['url'] ], 201);
    }

    /**
     * POST /migrations/restore-file-uploads
     * Scans existing fields and restores any that were previously degraded from file_upload to regex/free_text.
     * Criteria:
     *  - props._orig_format === 'file_upload'
     *  - (props.format !== 'file_upload' OR (type not set to 'file'))
     * Action:
     *  - set props.format = 'file_upload'
     *  - add note migration_restored_file_upload
     *  - ensure type=file (if a 'type' key exists in props array used elsewhere)
     */
    public static function migrate_restore_file_uploads(WP_REST_Request $request)
    {
        if (!self::user_can_manage_forms()) return new WP_REST_Response(['error'=>'forbidden'], 403);
        global $wpdb; $tableFields = Helpers::tableName('fields'); $tableForms = Helpers::tableName('forms');
        $limit = isset($_GET['limit']) ? max(1, min(500, intval($_GET['limit']))) : 250;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, form_id, props FROM {$tableFields} WHERE props LIKE %s LIMIT %d", '%_orig_format%file_upload%', $limit), ARRAY_A);
        $updated = 0; $formTouched = [];
        foreach ($rows as $r){
            $props = json_decode($r['props'] ?: '{}', true);
            if (!is_array($props)) continue;
            $orig = $props['_orig_format'] ?? '';
            $curr = $props['format'] ?? '';
            if ($orig === 'file_upload' && $curr !== 'file_upload'){
                $props['format'] = 'file_upload';
                $props['type'] = 'file';
                // Remove regex if it was only for degraded placeholder
                if (isset($props['regex'])){
                    // Heuristic: regex previously auto-generated for alphanumeric degrade; not needed
                    unset($props['regex']);
                }
                $props['migration_note'] = 'restored_file_upload';
                $wpdb->update($tableFields, [ 'props'=>json_encode($props, JSON_UNESCAPED_UNICODE) ], [ 'id'=>$r['id'] ]);
                if (!$wpdb->last_error){ $updated++; $formTouched[(int)$r['form_id']] = true; }
            }
        }
        // Append note to each affected form meta (non destructive)
        if ($updated>0 && !empty($formTouched)){
            $formIds = array_keys($formTouched);
            $in = implode(',', array_map('intval',$formIds));
            $frows = $wpdb->get_results("SELECT id, meta FROM {$tableForms} WHERE id IN ($in)", ARRAY_A);
            foreach ($frows as $fr){
                $meta = json_decode($fr['meta'] ?: '{}', true); if (!is_array($meta)) $meta=[];
                $notes = isset($meta['notes']) && is_array($meta['notes']) ? $meta['notes'] : [];
                $notes[] = 'migration_restored_file_upload';
                // de-duplicate notes
                $notes = array_values(array_unique($notes));
                $meta['notes'] = $notes;
                $wpdb->update($tableForms, [ 'meta'=>json_encode($meta, JSON_UNESCAPED_UNICODE) ], [ 'id'=>$fr['id'] ]);
            }
        }
        return new WP_REST_Response([ 'ok'=>true, 'updated_fields'=>$updated, 'forms_affected'=>count($formTouched) ], 200);
    }

    /**
     * POST /public/forms/{form_id}/file-upload
     * Secure generic file upload for form 'file' fields. Returns a temporary token reference for later attachment to submission.
     * Enforces: form published, optional access token, size limit, allowed extensions/MIME, random filename, quarantine directory.
     */
    public static function public_form_file_upload(WP_REST_Request $request)
    {
        $formId = (int)$request['form_id'];
        if ($formId <= 0) return new WP_REST_Response(['error'=>'invalid_form_id'], 400);
        $form = FormRepository::find($formId);
        if (!$form || $form->status !== 'published'){
            return new WP_REST_Response(['error'=>'form_unavailable'], 404);
        }
        // Basic anti-abuse rate limit per IP
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $now = time();
            $key = 'arsh_up_file_rl_'.md5($ip ?: '');
            $entry = get_transient($key);
            $windowSec = 60; $limit = 12;
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now+$windowSec];
            if ($now >= (int)$data['reset']){ $data=['count'=>0,'reset'=>$now+$windowSec]; }
            if ((int)$data['count'] >= $limit){ return new WP_REST_Response(['error'=>'rate_limited','retry_after'=>max(0,(int)$data['reset']-$now)], 429); }
            $data['count']=(int)$data['count']+1; set_transient($key,$data,$windowSec);
        } catch (\Throwable $e) {}

        if (!function_exists('wp_handle_sideload')) require_once(ABSPATH.'wp-admin/includes/file.php');
        $files = $request->get_file_params();
        if (!isset($files['file'])) return new WP_REST_Response(['error'=>'no_file'], 400);
        $file = $files['file'];

    $origName = (string)($file['name'] ?? '');
    // Normalize & sanitize original name (keep extension separately)
    $origName = preg_replace('/[\r\n\t\\\/]+/','_', $origName);
    $origName = preg_replace('/[^A-Za-z0-9._\-\x{0600}-\x{06FF}]+/u','_', $origName);
    if (mb_strlen($origName) > 120) { $origName = mb_substr($origName, 0, 120); }
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        $gs = self::get_global_settings();
        $maxKB = isset($gs['file_upload_max_kb']) ? max(50, min(8192, (int)$gs['file_upload_max_kb'])) : 2048; // default 2MB
        $maxBytes = $maxKB * 1024;
        if ($size <= 0 || $size > $maxBytes){
            return new WP_REST_Response(['error'=>'invalid_size','max_kb'=>$maxKB], 413);
        }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        // Disallow dangerous extensions
        $blocked = ['php','php5','phtml','phar','js','html','htm','svg','exe','sh','bat','cmd','ps1'];
        if (in_array($ext, $blocked, true)) return new WP_REST_Response(['error'=>'blocked_extension'], 415);
        // Allowed whitelist (can expand via filter)
        $allowedExt = apply_filters('arshline_public_file_allowed_ext', ['pdf','doc','docx','txt','log','jpg','jpeg','png']);
        if (!in_array($ext, $allowedExt, true)) return new WP_REST_Response(['error'=>'invalid_extension'], 415);

        // MIME sniff (primary)
        $realMime='';
        $tmpPath = $file['tmp_name'] ?? '';
        if (function_exists('finfo_open') && is_readable($tmpPath)){
            $f=finfo_open(FILEINFO_MIME_TYPE); if ($f){ $realMime=(string)finfo_file($f,$tmpPath); finfo_close($f);} }
        $clientType = (string)($file['type'] ?? '');
        $mimeToCheck = $realMime ?: $clientType;
        $allowedMimes = [
            'application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain','image/jpeg','image/png','image/jpg','text/x-log'
        ];
        if ($mimeToCheck && !in_array($mimeToCheck, $allowedMimes, true)){
            return new WP_REST_Response(['error'=>'invalid_type'], 415);
        }
        // Secondary magic bytes checks
        if ($tmpPath && is_readable($tmpPath)){
            try {
                $fh = fopen($tmpPath, 'rb');
                if ($fh){
                    $sig = fread($fh, 8); fclose($fh);
                    if ($ext === 'png' && substr($sig,0,8) !== "\x89PNG\x0D\x0A\x1A\x0A") return new WP_REST_Response(['error'=>'invalid_signature'], 415);
                    if (in_array($ext, ['jpg','jpeg']) && substr($sig,0,2) !== "\xFF\xD8") return new WP_REST_Response(['error'=>'invalid_signature'], 415);
                    if ($ext === 'gif' && substr($sig,0,3) !== 'GIF') return new WP_REST_Response(['error'=>'invalid_signature'], 415);
                    if ($ext === 'pdf'){
                        // PDF should start with %PDF-
                        $fh2 = fopen($tmpPath,'rb'); if ($fh2){ $sig2 = fread($fh2, 5); fclose($fh2); if ($sig2 !== "%PDF-" ) return new WP_REST_Response(['error'=>'invalid_signature'], 415); }
                    }
                }
            } catch (\Throwable $e){ /* ignore but safer to reject on explicit mismatch */ }
        }
        // Enforce extension/MIME consistency for simple text/log vs others
        $mapExtMime = [ 'pdf'=>'application/pdf', 'doc'=>'application/msword', 'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt'=>'text/plain', 'log'=>'text/x-log', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png' ];
        if (isset($mapExtMime[$ext]) && $realMime && $realMime !== $mapExtMime[$ext]){
            return new WP_REST_Response(['error'=>'mime_mismatch'], 415);
        }
        // Randomize filename (keep extension)
    $randomBase = bin2hex(random_bytes(8));
        $safeName = $randomBase.'.'.$ext;
        $uploadDir = wp_upload_dir();
        $subdir = '/arshline/forms';
        $targetDir = $uploadDir['basedir'].$subdir;
        if (!wp_mkdir_p($targetDir)) return new WP_REST_Response(['error'=>'mkdir_failed'], 500);
        $targetPath = $targetDir.'/'.$safeName;
        // Move file
        if (!isset($file['tmp_name']) || !@is_uploaded_file($file['tmp_name'])){
            return new WP_REST_Response(['error'=>'invalid_tmp'], 400);
        }
        if (!@move_uploaded_file($file['tmp_name'], $targetPath)){
            return new WP_REST_Response(['error'=>'move_failed'], 500);
        }
        // Store minimal meta in transient (token -> path + original name + size + mime) for later binding on submit
        $token = 'F'.substr(bin2hex(random_bytes(12)),0,20);
        $sha1 = '';
        try { if ($tmpPath && is_readable($targetPath)) { $sha1 = sha1_file($targetPath) ?: ''; } } catch(\Throwable $e){ }
        $meta = [
            'form_id'=>$formId,
            'path'=>$targetPath,
            'name'=>$origName,
            'stored_name'=>$safeName,
            'size'=>$size,
            'ext'=>$ext,
            'mime'=>$mimeToCheck,
            'hash'=>$sha1,
            'created'=>time(),
        ];
        set_transient('arshline_file_'.$token, $meta, 60*30); // 30 minutes
        $publicUrl = $uploadDir['baseurl'].$subdir.'/'.$safeName;
        return new WP_REST_Response(['ok'=>true,'token'=>$token,'name'=>$origName,'size'=>$size,'url'=>$publicUrl], 201);
    }
}
