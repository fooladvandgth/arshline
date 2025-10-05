<?php
namespace Arshline\Hoosha\Pipeline;

interface ModelClientInterface
{
    /**
     * Perform a model completion returning raw text output.
     * @param array $messages chat-style messages
     * @param array $options  model options (model, max_tokens, temperature, etc)
     */
    public function complete(array $messages, array $options = []): array; // ['ok'=>bool,'text'=>'','usage'=>[]]

    /**
     * Refine a baseline schema with model assistance. Returns schema-like array OR empty array on no-delta.
     * @param array $baseline existing schema (fields[])
     * @param string $userText original user text
     * @param array $options model options
     * @return array ['fields'=>[...]] | []
     */
    public function refine(array $baseline, string $userText, array $options = []): array;
}
