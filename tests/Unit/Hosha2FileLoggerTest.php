<?php
namespace Arshline\Tests\Unit;

use Arshline\Hosha2\Hosha2FileLogger;
use Arshline\Hosha2\Hosha2LogRedactor;
use PHPUnit\Framework\TestCase;

class Hosha2FileLoggerTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/hosha2_log_test_' . uniqid();
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) @unlink($f);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testWritesNdjsonAndRedacts()
    {
        $logger = new Hosha2FileLogger($this->tmpDir, 'test.log', 2000, new Hosha2LogRedactor());
        $logger->setContext(['run_id' => 'abc123']);
    $logger->log('phase', ['phase' => 'start', 'api_key' => 'sk-THISISASECRETKEYVALUE'], 'DEBUG');
        $logger->summary(['ok' => 1], [], ['note1']);
        $contents = file_get_contents($this->tmpDir . '/test.log');
        $lines = array_filter(explode("\n", trim($contents)));
        $this->assertCount(2, $lines, 'Should have two NDJSON lines');
        $first = json_decode($lines[0], true);
        $this->assertEquals('phase', $first['ev']);
    $this->assertEquals('***', $first['api_key'], 'API key must be redacted');
    $this->assertEquals('DEBUG', $first['lvl']);
        $this->assertEquals('abc123', $first['run_id']);
    }

    public function testRotationOccurs()
    {
        // Force very low max size to trigger rotation
        $logger = new Hosha2FileLogger($this->tmpDir, 'rot.log', 400); // 400 bytes
        // Write enough entries
        for ($i = 0; $i < 50; $i++) {
            $logger->log('debug', ['i' => $i, 'payload' => str_repeat('x', 50)], 'INFO');
        }
        $files = glob($this->tmpDir . '/rot.log*');
        // Expect at least one rotated file besides active
        $this->assertGreaterThanOrEqual(2, count($files), 'Rotation should produce multiple files');
    }
}
?>