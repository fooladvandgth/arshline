<?php
namespace ArshlineTests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Guard\SemanticTools;

class SemanticToolsTest extends TestCase
{
    public function test_normalize_label_removes_polite_tokens()
    {
        $in = ' لطفا   سن   شما چند  سال است؟ ';
        $out = SemanticTools::normalize_label($in);
        $this->assertStringNotContainsString('لطفا', $out);
        $this->assertStringContainsString('سن', $out);
    }

    public function test_similarity_high_for_variants()
    {
        $a = 'سن شما چند سال است';
        $b = 'چند سالته';
        $sim = SemanticTools::similarity($a,$b);
        $this->assertGreaterThan(0.4, $sim, 'Expected moderate similarity');
    }

    public function test_cluster_labels_respects_override_threshold()
    {
        SemanticTools::setOverrideThreshold(0.2);
        $labels = ['سن شما چند سال است','چند سالته','کد ملی','شماره ملی'];
        $clusters = SemanticTools::cluster_labels($labels, 0.8); // override -> 0.2
        $this->assertLessThan(count($labels), count($clusters), 'Override should merge some labels');
        SemanticTools::setOverrideThreshold(null);
    }
}
