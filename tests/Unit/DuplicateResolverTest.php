<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\Pipeline\DuplicateResolver;
use Arshline\Hoosha\Pipeline\Normalizer;

class DuplicateResolverTest extends TestCase
{
    public function testSemanticDuplicateCollapse(): void
    {
        $schema = [ 'fields' => [
            ['label'=>'شماره موبایل شما','type'=>'short_text','props'=>[]],
            ['label'=>'شماره تلفن همراه','type'=>'short_text','props'=>[]],
            ['label'=>'نام کامل','type'=>'short_text','props'=>[]],
        ]];
        // Baseline only contained first and third, meaning second is candidate duplicate to first
        $baseline = [ 'fields' => [
            ['label'=>'شماره موبایل شما','type'=>'short_text','props'=>[]],
            ['label'=>'نام کامل','type'=>'short_text','props'=>[]],
        ]];
        $baselineCanonMap = [];
        foreach ($baseline['fields'] as $bf){ $baselineCanonMap[Normalizer::canonLabel($bf['label'])]=true; }
        $notes = [];
        $resolver = new DuplicateResolver();
    $resolver->collapse($schema, $baselineCanonMap, $notes, 0.2); // lower threshold for test to ensure match
        $this->assertCount(2, $schema['fields'], 'One duplicate should be removed');
        $labels = array_map(fn($f)=>$f['label'],$schema['fields']);
        $this->assertContains('شماره موبایل شما',$labels);
        $this->assertContains('نام کامل',$labels);
        $this->assertStringContainsString('heur:semantic_duplicate', implode(',', $notes));
    }
}
