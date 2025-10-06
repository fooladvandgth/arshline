<?php
namespace Arshline\Hoosha\Pipeline;

use Arshline\Core\Api; // Assuming Api contains openai_chat_json + model normalization helpers

class OpenAIModelClient implements ModelClientInterface
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini', string $baseUrl = 'https://api.openai.com')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl;
    }

    public function complete(array $messages, array $options = []): array
    {
        // Convert messages into simple system+user concatenation for now
        $system = '';
        $user = '';
        foreach ($messages as $m){
            if (!is_array($m)) continue; $role=$m['role']??''; $content=$m['content']??'';
            if ($role==='system'){ $system .= $content."\n"; }
            elseif ($role==='user'){ $user .= $content."\n"; }
            elseif ($role==='assistant'){ $user .= "(assistant previous) ".$content."\n"; }
        }
        try {
            $resp = Api::openai_chat_json_wrapper($this->baseUrl, $this->apiKey, $this->model, trim($system), trim($user));
            return [ 'ok'=>($resp['ok']??false), 'text'=>($resp['text']??''), 'usage'=>($resp['usage']??[]) ];
        } catch (\Throwable $e){
            return [ 'ok'=>false, 'text'=>'', 'error'=>$e->getMessage(), 'usage'=>[] ];
        }
    }

    public function refine(array $baseline, string $userText, array $options = []): array
    {
    // Constraint-driven root-cause prompt (Examples are symptomatic only, not the targets of the fix.)
    $system = <<<PROMPT
You are a strict Persian form field mapper.
Rules:
1. Input consists of user questions (informal Persian) plus a baseline draft.
2. Produce JSON ONLY: {"fields":[{label,type,props?}],"meta":{"input_count":N,"output_count":N,"added":0,"removed":0,"semantic_merges":X}}.
3. EXACTLY one field per original user question (output_count == input_count). Do NOT add new concepts. Do NOT invent fields.
4. If two questions are semantically identical (different phrasing, same meaning) merge them into one canonical field BUT still keep output_count == input_count by merging meaning (never duplicate).
5. Allowed types: short_text, multiple_choice, number, date, national_id, phone.
6. Derive props.format when clear: date_greg, national_id_ir, mobile_ir, email.
7. Yes/No intent: starts with "آیا" or explicit binary → type=multiple_choice with options ["بله","خیر"].
8. Age questions (contains "سن" or "چند سال") → type=number.
9. National ID → type=short_text + props.format=national_id_ir.
10. Date of birth → type=short_text + props.format=date_greg unless explicit Jalali.
11. Phone / mobile labels → type=short_text + props.format=mobile_ir.
12. NEVER hallucinate general form fields (e.g. contact preference) not present in user input.
13. Labels concise; reuse user wording where unambiguous; unify spacing.
14. meta.added must be 0 and meta.output_count must equal meta.input_count.
15. Return ONLY valid UTF-8 JSON; no markdown, code fences or explanations.
Reject any optimization that only fits the examples—solution must generalize to unseen input patterns.
PROMPT;
    $user = "BASELINE:\n".json_encode($baseline, JSON_UNESCAPED_UNICODE)."\nUSER_TEXT:\n".$userText."\nTASK: Produce deterministic constrained JSON per rules.";
        $r = $this->complete([
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ], $options);
        if (!$r['ok']) return [];
        $txt = trim($r['text']);
        // Attempt to decode JSON
        $json = null; @($json = json_decode($txt, true));
        if (is_array($json) && !empty($json['fields']) && is_array($json['fields'])) {
            // Meta enforcement patch: derive input_count from userText heuristic (question splits)
            $questions = $this->extractUserQuestions($userText);
            $inputCount = count($questions);
            $outputCount = count($json['fields']);
            if (!isset($json['meta']) || !is_array($json['meta'])) $json['meta'] = [];
            $json['meta']['input_count'] = $json['meta']['input_count'] ?? $inputCount;
            $json['meta']['output_count'] = $outputCount;
            // added = max(0, output - input) but we expect 0 per contract; if mismatch mark violation
            $json['meta']['added'] = ($outputCount > $inputCount) ? ($outputCount - $inputCount) : 0;
            if ($json['meta']['added'] > 0) {
                $json['meta']['violation'] = ($json['meta']['violation'] ?? []);
                $json['meta']['violation'][] = 'added_positive('.$json['meta']['added'].')';
            }
            if ($json['meta']['input_count'] !== $outputCount) {
                $json['meta']['violation'] = ($json['meta']['violation'] ?? []);
                $json['meta']['violation'][] = 'count_mismatch(input='.$json['meta']['input_count'].',output='.$outputCount.')';
            }
            return $json;
        }
        return [];
    }

    /** Light user question splitter mirroring Api::extract_user_questions (copied to avoid coupling). */
    protected function extractUserQuestions(string $userText): array
    {
        $normalized = str_replace("\r", "\n", $userText);
        $normalized = preg_replace('/([؟?])\s*/u', "$1\n", (string)$normalized);
        $parts = preg_split('/\n+/u', (string)$normalized, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p){
            $p = trim($p);
            if ($p==='') continue; if (preg_match('/^(system:|note:|meta:)/i',$p)) continue;
            $p = preg_replace('/[؟?]+$/u','',$p); if($p==='') continue;
            if (mb_strlen($p,'UTF-8')>200) $p = mb_substr($p,0,200,'UTF-8');
            $out[]=$p;
        }
        if (!$out){ $t=trim($userText); if($t!=='') $out[]=mb_substr($t,0,200,'UTF-8'); }
        return $out;
    }
}
