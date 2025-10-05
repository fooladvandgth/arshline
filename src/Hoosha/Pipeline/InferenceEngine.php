<?php
namespace Arshline\Hoosha\Pipeline;

use Arshline\Hoosha\HooshaBaselineInferer;

class InferenceEngine
{
    public function baseline(string $text): array
    {
        $schema = HooshaBaselineInferer::infer($text);
        // Add lightweight type/format inference annotations (hotfix path)
        foreach (($schema['fields'] ?? []) as &$f) {
            if (!is_array($f)) continue;
            $lbl = mb_strtolower((string)($f['label'] ?? ''), 'UTF-8');
            $type = $f['type'] ?? 'short_text';
            $props = $f['props'] ?? [];
            // Mobile IR
            if (!isset($props['format']) && preg_match('/شماره.*(موبایل|تلفن).*ایران/u',$lbl)) { $props['format']='mobile_ir'; }
            // Date (greg vs jalali heuristics)
            if (!isset($props['format']) && preg_match('/تولد|تاریخ/u',$lbl)) { $props['format'] = (mb_strpos($lbl,'جلالی')!==false? 'date_jalali':'date_greg'); }
            // Long text triggers
            if ($type==='short_text' && preg_match('/مفصل|توضیح بده|شرح بده|علتش|علت را/u',$lbl)) { $type='long_text'; $props['rows']=4; }
            // Informal yes/no
            if ($type==='short_text' && (preg_match('/یا\s+نه(\s|$)/u',$lbl) || preg_match('/^می(?:ای|خوای|ری)\b/u',$lbl)) ){
                $type='multiple_choice';
                $props['options']=['بله','خیر'];
                $props['multiple']=false;
                $props['source']='yesno_infer_informal_baseline';
            }
            $f['type']=$type; $f['props']=$props;
        }
        unset($f);
        return $schema;
    }
}
