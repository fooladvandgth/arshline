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
        // Basic prompt instructing model to output JSON {"fields":[...]} according to improvements.
        $system = 'You are a form refinement engine. Return ONLY minified JSON with fields array.';
        $user = "BASELINE:\n".json_encode($baseline, JSON_UNESCAPED_UNICODE)."\nUSER_TEXT:\n".$userText."\nTASK: Improve labels (formal Persian), infer better types (multiple_choice yes/no, date, rating), DO NOT add unrelated fields. Output {\"fields\":[...]}";
        $r = $this->complete([
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user]
        ], $options);
        if (!$r['ok']) return [];
        $txt = trim($r['text']);
        // Attempt to decode JSON
        $json = null; @($json = json_decode($txt, true));
        if (is_array($json) && !empty($json['fields']) && is_array($json['fields'])) return $json;
        return [];
    }
}
