<?php
namespace ArshlineTests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Guard\GuardService;
use Arshline\Guard\SemanticTools;

class GuardServiceSemanticTest extends TestCase
{
    protected function makeGuardService(): GuardService
    {
        // Null model client (not used for semantic clustering portion)
        return new GuardService(null);
    }

    public function test_semantic_merge_reduces_duplicates_when_threshold_low()
    {
        // Lower threshold via override to force merge
        SemanticTools::setOverrideThreshold(0.2);
        $guard = $this->makeGuardService();
        $baseline = [ 'fields'=> [
            ['label'=>'سن','type'=>'short_text','props'=>[]],
            ['label'=>'کد ملی','type'=>'short_text','props'=>[]]
        ]];
        $schema = [ 'fields'=> [
            ['label'=>'سن شما چند سال است','type'=>'short_text','props'=>[]],
            ['label'=>'چند سالته','type'=>'short_text','props'=>[]],
            ['label'=>'کد ملی','type'=>'short_text','props'=>[]]
        ]];
        $notes = [];
        $res = $guard->evaluate($baseline, $schema, "سن شما چند سال است\nکد ملی", $notes);
        SemanticTools::setOverrideThreshold(null);
        $this->assertNotEmpty($res['issues']);
        // Expect duplicates collapsed metric to be >=1 after merge
        $diag = $res['diagnostics'] ?? [];
        $collapsed = ($diag['duplicates_collapsed'] ?? 0) + ($diag['semantic_clusters'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $collapsed, 'Expected at least one semantic duplicate collapsed');
    }
}
