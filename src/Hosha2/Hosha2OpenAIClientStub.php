<?php
namespace Arshline\Hosha2;

use DateTimeImmutable;

class Hosha2OpenAIClientStub implements Hosha2OpenAIClientInterface
{
    public function sendGenerate(array $envelope): array
    {
        $logger = function_exists('Arshline\\Hosha2\\hoosha2_logger') ? hoosha2_logger() : null;
        $start = microtime(true);
        if ($logger) $logger->log('openai_request_start', ['intent'=>'generate','lvl'=>'INFO']);
        // Simulated latency
        usleep(20000); // 20ms
        $fields = [
            ['id'=>'f1','type'=>'text','label'=>'نام کامل','required'=>true],
            ['id'=>'f2','type'=>'email','label'=>'ایمیل','required'=>true],
        ];
        $response = [
            'intent' => 'generate',
            'diagnostics' => ['notes'=>[],'riskFlags'=>[]],
            'final_form' => [
                'version' => 'arshline_form@v1',
                'fields' => $fields,
                'layout' => [],
                'meta' => ['generated_at'=>(new DateTimeImmutable())->format('c')]
            ],
            'diff' => [
                ['op'=>'add','path'=>'/fields/0','value'=>$fields[0]],
                ['op'=>'add','path'=>'/fields/1','value'=>$fields[1]],
            ],
            'ui_hints' => ['previewTips'=>[],'emptyStates'=>[]],
            'token_usage' => ['prompt'=>120,'completion'=>85,'total'=>205]
        ];
        if ($logger) $logger->log('openai_request_end', [
            'intent'=>'generate',
            'latency_ms'=> round((microtime(true)-$start)*1000,2),
            'tokens_total'=>$response['token_usage']['total']
        ]);
        return $response;
    }

    public function sendValidate(array $envelope): array
    {
        $logger = function_exists('Arshline\\Hosha2\\hoosha2_logger') ? hoosha2_logger() : null;
        $start = microtime(true);
        if ($logger) $logger->log('openai_request_start', ['intent'=>'validate']);
        usleep(10000);
        $response = [
            'intent' => 'validate',
            'diagnostics' => ['notes'=>['validation_passed'],'riskFlags'=>[]],
            'final_form' => $envelope['current_form'] ?? [],
            'diff' => [],
            'token_usage' => ['prompt'=>40,'completion'=>15,'total'=>55]
        ];
        if ($logger) $logger->log('openai_request_end', [
            'intent'=>'validate',
            'latency_ms'=> round((microtime(true)-$start)*1000,2),
            'tokens_total'=>$response['token_usage']['total']
        ]);
        return $response;
    }
}
?>