# Hoosha Modular Components

This directory contains modularized building blocks for the Persian smart form ("Hoosha") pipeline.

## Current Modules

### HooshaBaselineInferer
Reflective wrapper delegating to existing internal baseline inference logic (`Api::hoosha_local_infer_from_text_v2`).

Purpose:
- Allow future extraction / rewrite without touching massive `Api.php`.
- Enable isolated unit tests without requiring full WordPress bootstrap.

Planned Enhancements:
- Pure implementation (no reflection) copied & reduced from `Api`.
- Pluggable heuristics (options extraction, rating detection, yes/no mapping, enumeration parsing).
- Strategy interface for field-type scoring & duplication collapse.

## Public Facade
```php
use Arshline\Hoosha\HooshaBaselineInferer;
$result = HooshaBaselineInferer::infer($text); // ['fields'=>[ ... ]]
```

Return Shape:
```json
{
  "fields": [
    { "type": "short_text", "label": "نام و نام خانوادگی", "required": false, "props": {"format": "fa_letters"} }
  ]
}
```

## Migration Path
1. Mirror core inference logic into a dedicated service class.
2. Add deterministic unit tests (enumeration, yes/no, rating, duplicate merging, baseline preservation).
3. Replace reflection bridging with direct code.
4. Gradually remove protected method from `Api` after stability window.

## Testing
Add tests under `tests/Unit/Hoosha*` that import only this module (no Brain Monkey when possible).

### Scenario Matrix (Automated)
`HooshaScenarioTest` exercises multiple questionnaire patterns:
1. Baseline simple (national id, date, email, name, mobile)
2. Informal yes/no detection (محاوره‌ای بدون «آیا»)
3. Enumerated checklist with numeric prefixes (1) 2) ...)
4. Rating extraction ("از 1 تا 10")
5. Hallucination / noise pruning (خط جعلی با کاراکترهای غیرمعنادار)

To extend, add another entry to `$scenarios` in `HooshaScenarioTest`:
```php
'my_case' => [
  'input' => "خط۱\nخط۲",
  'assert' => function($r){ /* custom assertions */ }
]
```
This enables rapid detection of heuristic regressions before API/model calls.
