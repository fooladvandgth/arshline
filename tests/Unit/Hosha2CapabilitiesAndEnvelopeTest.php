<?php
namespace Arshline\Tests\Unit;

use Arshline\Hosha2\Hosha2CapabilitiesBuilder;
use Arshline\Hosha2\Hosha2OpenAIEnvelopeFactory;
use PHPUnit\Framework\TestCase;

class Hosha2CapabilitiesAndEnvelopeTest extends TestCase
{
    public function testBuildCapabilitiesAndEnvelope()
    {
        $builder = new Hosha2CapabilitiesBuilder();
        $cap = $builder->build(true); // force rebuild
        $this->assertArrayHasKey('fields', $cap);
        $this->assertGreaterThan(5, count($cap['fields']));
        $factory = new Hosha2OpenAIEnvelopeFactory();
        $env = $factory->createGenerate(['prompt' => 'یک فرم ثبت نام ساده بساز'], $cap, ['locale'=>'fa_IR']);
        $this->assertEquals('generate', $env['meta']['intent']);
        $this->assertArrayHasKey('capabilities', $env);
        $this->assertArrayHasKey('user_input', $env);
    }
}
?>