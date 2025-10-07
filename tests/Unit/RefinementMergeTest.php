<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\Pipeline\HooshaService;
use Arshline\Hoosha\Pipeline\ModelClientInterface;

class StubModelClient implements ModelClientInterface
{
    public function __construct(){}
    public function complete(array $messages, array $options = []): array { return ['ok'=>true,'text'=>'']; }
    public function refine(array $baseline, string $userText, array $options = []): array
    {
        // Simulate refinement: modify first label type, add a new field
        $fields = $baseline['fields'];
        if (!empty($fields) && is_array($fields[0])){
            $fields[0]['type']='long_text';
            $fields[0]['props']['rows']=6;
        }
        $fields[]=[ 'type'=>'short_text','label'=>'فیلد جدید آزمایشی','required'=>false,'props'=>[] ];
        return ['fields'=>$fields];
    }
}

class RefinementMergeTest extends TestCase
{
    public function testMergeProducesNotes()
    {
        $baselineText = "نام شما چیست؟\nتوضیح بده";
        $svc = new HooshaService(new StubModelClient());
        $res = $svc->process($baselineText, []);
        $notes = $res['notes'] ?? [];
        $hasModified=false; $hasAdded=false;
        foreach ($notes as $n){
            if (str_starts_with($n,'ai:modified(')) $hasModified=true;
            if (str_starts_with($n,'ai:added(')) $hasAdded=true;
        }
        $this->assertTrue($hasModified,'Expected ai:modified note');
        $this->assertTrue($hasAdded,'Expected ai:added note');
    }
}
