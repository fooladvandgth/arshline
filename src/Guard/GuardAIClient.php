<?php
namespace Arshline\Guard;

use Arshline\Hoosha\Pipeline\OpenAIModelClient;

/**
 * GuardAIClient: lightweight wrapper around existing OpenAIModelClient for AI-assist phases.
 * Provides: analyzeIntents, reasonConflicts, validateSchema
 * Returns normalized arrays; never throws (returns ['ok'=>false,'error'=>...]) on failure.
 */
class GuardAIClient
{
    protected OpenAIModelClient $client;
    protected float $timeoutSec;

    public function __construct(OpenAIModelClient $client, float $timeoutSec = 12.0)
    {
        $this->client = $client; $this->timeoutSec = $timeoutSec;
    }

    public function analyzeIntents(array $questions, array $capabilities): array
    {
        $payload = [ 'questions'=>$questions, 'capabilities'=>[ 'types'=>$capabilities['types']??[], 'formats'=>$capabilities['formats']??[] ] ];
    $system = "You are an intent analyzer for Persian form questions. Keep labels in Persian; treat Arabic variants (ك/ک, ي/ی, ة/ه, ئ/ی) and Persian/Arabic digits as equivalent. Return ONLY compact JSON array. Each item: {label,intent,suggested_type?,suggested_format?,props?,confidence}. Use only provided types/formats. confidence 0..1. Do NOT add fields beyond questions.";
        $user = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $r = $this->client->complete([
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ], ['temperature'=>0]);
        if (empty($r['ok']) || empty($r['text'])) return ['ok'=>false,'error'=>'no_response'];
        $raw = trim($r['text']); $raw = preg_replace('/```json|```/u','',$raw);
        $data = json_decode($raw,true);
        if (!is_array($data)) return ['ok'=>false,'error'=>'json_decode_fail'];
        // Normalize rows
        $out=[]; foreach($data as $row){ if(!is_array($row)) continue; $lbl=$row['label']??null; if(!$lbl) continue; $out[]=[
            'label'=>$lbl,
            'intent'=>$row['intent']??null,
            'suggested_type'=>$row['suggested_type']??null,
            'suggested_format'=>$row['suggested_format']??null,
            'props'=> (isset($row['props']) && is_array($row['props']))? $row['props']:[],
            'confidence'=> isset($row['confidence'])? (float)$row['confidence']:null
        ]; }
        return ['ok'=>true,'items'=>$out];
    }

    public function reasonConflicts(array $baselineLabels, array $currentFields): array
    {
        $payload = [ 'baseline_labels'=>$baselineLabels, 'current_labels'=>array_map(fn($f)=>$f['label']??'', $currentFields) ];
    $system = "You are a conflict resolver for Persian form fields. Consider Persian normalization equivalences (digits, ك->ک, ي->ی, tatweel removal, diacritics ignored). Output JSON array [{action, target_label, reason, confidence}]. Actions: replace|merge|drop|keep. No prose. Do not hallucinate new labels.";
        $user = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $r = $this->client->complete([
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ], ['temperature'=>0]);
        if (empty($r['ok']) || empty($r['text'])) return ['ok'=>false,'error'=>'no_response'];
        $raw = trim($r['text']); $raw = preg_replace('/```json|```/u','',$raw);
        $data = json_decode($raw,true); if(!is_array($data)) return ['ok'=>false,'error'=>'json_decode_fail'];
        $out=[]; foreach($data as $row){ if(!is_array($row)) continue; $act=$row['action']??null; $lab=$row['target_label']??($row['target_field']??null); if(!$act||!$lab) continue; $out[]=[
            'action'=>$act,
            'target_label'=>$lab,
            'reason'=>$row['reason']??null,
            'confidence'=> isset($row['confidence'])? (float)$row['confidence']:null
        ]; }
        return ['ok'=>true,'items'=>$out];
    }

    public function validateSchema(array $finalSchema, array $questions): array
    {
        $payload = [ 'questions'=>$questions, 'final_fields'=>array_map(fn($f)=>$f['label']??'', $finalSchema['fields']??[]) ];
    $system = "You are a final schema validator for Persian forms. Apply normalization equivalences (ك=ک, ي=ی, Persian/Arabic digits unified). Return JSON {approved:bool, issues:[], confidence:float}. No extra keys. Do NOT suggest additions beyond provided final fields.";
        $user = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $r = $this->client->complete([
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ], ['temperature'=>0]);
        if (empty($r['ok']) || empty($r['text'])) return ['ok'=>false,'error'=>'no_response'];
        $raw = trim($r['text']); $raw = preg_replace('/```json|```/u','',$raw);
        $data = json_decode($raw,true); if(!is_array($data)) return ['ok'=>false,'error'=>'json_decode_fail'];
        return [ 'ok'=>true, 'approved'=>(bool)($data['approved']??false), 'issues'=> (isset($data['issues'])&&is_array($data['issues']))?$data['issues']:[], 'confidence'=> isset($data['confidence'])?(float)$data['confidence']:null ];
    }
}
