<?php
namespace Arshline\Guard;

/**
 * IntentRules: declarative intent metadata (phase 1 minimal)
 * Each rule: id, trigger_keywords (array), default_schema (optional), auto_inject (bool)
 */
class IntentRules
{
    public static function all(): array
    {
        return [
            [
                'id' => 'contact_preference',
                'trigger_keywords' => ['تماس','ارتباط','شیوه تماس','نحوه تماس','روش تماس'],
                'default_schema' => [
                    'type' => 'multiple_choice',
                    'label' => 'ترجیح شما برای شیوه تماس؟',
                    'props' => [ 'options' => ['ایمیل','تلفن','موبایل'] ]
                ],
                'auto_inject' => false
            ]
        ];
    }

    public static function detect(string $text): array
    {
        $hits = [];
        $norm = mb_strtolower($text,'UTF-8');
        foreach (self::all() as $rule){
            foreach ($rule['trigger_keywords'] as $kw){
                if (mb_strpos($norm, mb_strtolower($kw,'UTF-8')) !== false){ $hits[$rule['id']] = $rule; break; }
            }
        }
        return array_values($hits);
    }
}
