<?php
namespace Arshline\Hoosha\Pipeline;

/** Deterministic dummy model for tests (avoids network). */
class DummyModelClient implements ModelClientInterface
{
    public function complete(array $messages, array $options = []): array
    {
        $lastUser = '';
        for ($i = count($messages)-1; $i>=0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') { $lastUser = (string)$messages[$i]['content']; break; }
        }
        // Simple heuristic: echo back a JSON stub summarizing length for predictability
        $summary = [ 'echo' => mb_substr($lastUser,0,120,'UTF-8'), 'len' => mb_strlen($lastUser,'UTF-8') ];
        return [ 'ok'=>true, 'text'=>json_encode($summary, JSON_UNESCAPED_UNICODE), 'usage'=>['input'=>50,'output'=>10,'total'=>60] ];
    }

    public function refine(array $baseline, string $userText, array $options = []): array
    {
        // Deterministic: return empty (no delta) so tests remain stable unless explicitly overridden.
        return [];
    }
}
