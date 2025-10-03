<?php
namespace Arshline\Core;

class TokenCostCalculator
{
    /**
     * قیمت هر توکن برای مدل‌های مختلف (دلار)
     * قیمت‌ها بر اساس آخرین تعرفه OpenAI
     */
    public static $prices = [
        'gpt-3.5-turbo'   => [ 'input' => 0.0005, 'output' => 0.0015 ], // per 1K tokens
        'gpt-4o-mini'     => [ 'input' => 0.00015, 'output' => 0.00015 ],
        'gpt-4o'          => [ 'input' => 0.0025, 'output' => 0.005 ],
        'gpt-4-turbo'     => [ 'input' => 0.01, 'output' => 0.03 ],
        'o1-mini'         => [ 'input' => 0.003, 'output' => 0.003 ],
        'gpt-4'           => [ 'input' => 0.03, 'output' => 0.06 ],
    ];

    /**
     * محاسبه هزینه بر اساس مدل و تعداد توکن
     */
    public static function calculate($model, $inputTokens, $outputTokens)
    {
        $model = strtolower($model);
        $price = self::$prices[$model] ?? self::$prices['gpt-3.5-turbo'];
        $inputCost = ($inputTokens / 1000) * $price['input'];
        $outputCost = ($outputTokens / 1000) * $price['output'];
        $totalCost = $inputCost + $outputCost;
        return [
            'input_cost' => round($inputCost, 6),
            'output_cost' => round($outputCost, 6),
            'total_cost' => round($totalCost, 6)
        ];
    }
}
