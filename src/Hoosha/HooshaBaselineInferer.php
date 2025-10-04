<?php
namespace Arshline\Hoosha;

/**
 * Lightweight, framework-independent baseline inference for Persian smart form (Hoosha).
 * Extracted from Api::hoosha_local_infer_from_text_v2 to allow isolated unit testing
 * without requiring WordPress / Brain Monkey bootstrap.
 */
class HooshaBaselineInferer
{
    /**
     * Public facade: infer fields from free-form Persian instructions.
     * Returns: [ fields => [ { type,label,required,props:{} } ] ]
     */
    public static function infer(string $text): array
    {
        // Delegate to internal (copied minimal logic). For modularization we can
        // later refactor pieces (tokenization, option extraction, rating detection).
        $api = new \ReflectionClass('Arshline\\Core\\Api');
        if ($api->hasMethod('hoosha_local_infer_from_text_v2')) {
            $m = $api->getMethod('hoosha_local_infer_from_text_v2');
            $m->setAccessible(true);
            return (array)$m->invoke(null, $text);
        }
        return ['fields'=>[]];
    }
}
