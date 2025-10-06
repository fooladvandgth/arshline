<?php
namespace Arshline\Hosha2;

interface Hosha2OpenAIClientInterface
{
    /** Send generate/edit style request, returns model raw array */
    public function sendGenerate(array $envelope): array;
    /** Send validation style request, returns model raw array */
    public function sendValidate(array $envelope): array;
}
?>