<?php
namespace Arshline\Hosha2;

class Hosha2OpenAIEnvelopeFactory
{
    public function createGenerate(array $userInput, array $capabilities, array $meta = []): array
    {
        $logger = function_exists('Arshline\\Hosha2\\hoosha2_logger') ? hoosha2_logger() : null;
        if ($logger) $logger->phase('envelope_create_start', ['intent'=>'generate']);
        $envelope = [
            'meta' => array_merge([
                'intent' => 'generate',
                'locale' => 'fa_IR',
                'model_pref' => 'auto',
                'secondary_validation' => true,
            ], $meta),
            'user_input' => $userInput,
            'capabilities' => $capabilities,
        ];
        if ($logger) $logger->log('envelope_create', ['intent'=>'generate','size'=>strlen(json_encode($envelope))]);
        if ($logger) $logger->phase('envelope_create_end', ['intent'=>'generate']);
        return $envelope;
    }

    public function createEdit(array $userInput, array $currentForm, array $capabilities, array $meta = []): array
    {
        $logger = function_exists('Arshline\\Hosha2\\hoosha2_logger') ? hoosha2_logger() : null;
        if ($logger) $logger->phase('envelope_create_start', ['intent'=>'edit']);
        $envelope = [
            'meta' => array_merge([
                'intent' => 'edit',
                'locale' => 'fa_IR',
                'model_pref' => 'auto',
                'secondary_validation' => true,
            ], $meta),
            'user_input' => $userInput,
            'current_form' => $currentForm,
            'capabilities' => $capabilities,
        ];
        if ($logger) $logger->log('envelope_create', ['intent'=>'edit','size'=>strlen(json_encode($envelope))]);
        if ($logger) $logger->phase('envelope_create_end', ['intent'=>'edit']);
        return $envelope;
    }
}
?>