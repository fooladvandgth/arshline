<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\Pipeline\HooshaService;

class InformalYesNoTest extends TestCase
{
    public function testInformalYesNoConversion(): void
    {
        // Use service with no model
        $svc = new HooshaService(null);
        $text = "میای شرکت یا نه\nتوضیح مفصل بده";
        $result = $svc->process($text, []);
        $fields = $result['schema']['fields'] ?? [];
        $found = false; $noteFound=false;
        foreach ($fields as $f){
            if (($f['label']??'')==='میای شرکت یا نه' && ($f['type']??'')==='multiple_choice'){
                $found = in_array('بله', $f['props']['options']??[]) && in_array('خیر', $f['props']['options']??[]);
            }
        }
        foreach ($result['notes'] as $n){ if (strpos($n,'heur:yesno_informal')===0) $noteFound=true; }
        $this->assertTrue($found,'Informal yes/no not converted');
        $this->assertTrue($noteFound,'Yes/no inference note missing');
    }
}
