<?php
namespace Arshline\Hosha2;

/**
 * Builds capabilities map for Hosha2 OpenAI envelopes.
 * Placeholder: static structure now; future dynamic scan of plugin field registrations.
 */
class Hosha2CapabilitiesBuilder
{
    protected string $cacheKey = 'hosha2_capabilities_v1';
    protected int $ttl = 600; // 10 min

    public function build(bool $force = false): array
    {
        $logger = function_exists('Arshline\\Hosha2\\hoosha2_logger') ? hoosha2_logger() : null;
        if ($logger) $logger->phase('capabilities_build_start');

        if (!$force && function_exists('get_transient')) {
            $cached = get_transient($this->cacheKey);
            if (is_array($cached)) {
                if ($logger) $logger->log('capabilities_cache_hit', ['count' => count($cached['fields'] ?? [])]);
                return $cached;
            }
        }

        $cap = $this->baseline();
        // Allow external filters (future): apply_filters('arshline_hosha2_capabilities', $cap)
        if (function_exists('set_transient')) {
            set_transient($this->cacheKey, $cap, $this->ttl);
        }

        if ($logger) $logger->phase('capabilities_build_end', ['fields' => count($cap['fields'])]);
        return $cap;
    }

    protected function baseline(): array
    {
        return [
            'schema_version' => '1.0',
            'form_types' => ['quiz','questionnaire','survey','registration','feedback','poll'],
            'fields' => [
                ['type'=>'text','validation'=>['required','pattern','minLength','maxLength']],
                ['type'=>'email','validation'=>['required','email','maxLength']],
                ['type'=>'number','validation'=>['required','min','max','step']],
                ['type'=>'radio','options'=>['static','dynamic']],
                ['type'=>'checkbox','options'=>['static']],
                ['type'=>'select','options'=>['multiple','searchable']],
                ['type'=>'date','variants'=>['gregorian','jalali']],
                ['type'=>'file','constraints'=>['mime','size','count']],
                ['type'=>'rating','scale'=>['min'=>1,'max'=>5]],
                ['type'=>'html','raw_allowed'=>false],
                ['type'=>'section','ui'=>['collapsible'=>true]]
            ],
            'limits' => ['maxFields'=>200,'maxPages'=>20],
            'i18n' => ['defaultLocale'=>'fa_IR','locales'=>['fa_IR','en_US']],
        ];
    }
}
?>