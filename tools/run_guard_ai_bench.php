<?php
// Benchmark stub: compare rule-only vs AI-assist modes on synthetic scenarios.
// Usage: php tools/run_guard_ai_bench.php [--ai]

require_once __DIR__.'/../vendor/autoload.php';

use Arshline\Guard\GuardUnit;

$withAi = in_array('--ai', $argv, true);
if ($withAi) { define('HOOSHA_GUARD_AI_ASSIST', true); }

$scenarios = [
    [
        'name'=>'national_id_intent',
        'questions'=>['اسمت چیست؟','کد ملی خود را وارد کنید','تمایل به همکاری داری؟'],
        'model_schema'=>[
            'fields'=>[
                ['type'=>'short_text','label'=>'اسمت چیست'],
                ['type'=>'short_text','label'=>'کد ملی خود را وارد کنید'],
                ['type'=>'short_text','label'=>'تمایل به همکاری داری']
            ]
        ]
    ],
];

$results = [];
foreach ($scenarios as $sc){
    $gu = new GuardUnit([], [], 'corrective', 'bench');
    $res = $gu->run($sc['questions'], $sc['model_schema']);
    $results[] = [
        'scenario'=>$sc['name'],
        'status'=>$res['status'],
        'issues'=>$res['issues'],
        'metrics'=>$res['metrics']
    ];
}

echo json_encode(['ai_assist'=>$withAi,'results'=>$results], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
// Future: add comparative summary & BENCH_AI_SUMMARY line