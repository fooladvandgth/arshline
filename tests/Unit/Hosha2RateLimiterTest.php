<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2RateLimiter,Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator,Hosha2LoggerInterface};

class Hosha2RateLimiterTest extends TestCase
{
    public array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTransientShim();
        $this->logs = [];
    }

    protected function ensureTransientShim(): void
    {
        if (!function_exists('get_transient')) {
            if (!defined('HOSHA2_TRANSIENT_SHIM')) {
                define('HOSHA2_TRANSIENT_SHIM', true);
                global $hosha2_test_transients; $hosha2_test_transients = [];
                eval('function set_transient($k,$v,$t){ global $hosha2_test_transients; $hosha2_test_transients[$k]=["v"=>$v,"exp"=>time()+$t]; return true; }');
                eval('function get_transient($k){ global $hosha2_test_transients; if(!isset($hosha2_test_transients[$k]))return false; if($hosha2_test_transients[$k]["exp"]<time())return false; return $hosha2_test_transients[$k]["v"]; }');
            }
        }
    }

    protected function loggerStub(): Hosha2LoggerInterface
    {
        $outer = $this; // capture by reference
        return new class($outer) implements Hosha2LoggerInterface {
            private $outer; private array $ctx=[]; public function __construct($outer){ $this->outer=$outer; }
            public function log(string $event, array $payload = [], string $level = 'INFO'): void { $this->outer->logs[] = ['event'=>$event,'payload'=>$payload,'level'=>$level]; }
            public function phase(string $phase, array $extra = [], string $level = 'INFO'): void { $this->log('phase',['phase'=>$phase]+$extra,$level); }
            public function summary(array $metrics, array $issues = [], array $notes = []): void { $this->log('summary',['metrics'=>$metrics,'issues'=>$issues,'notes'=>$notes]); }
            public function setContext(array $context): void { $this->ctx = $context; }
            public function rotateIfNeeded(): void {}
        };
    }

    public function testFirstRequestAllowed()
    {
        $rl = new Hosha2RateLimiter($this->loggerStub(), ['max_requests'=>10,'window'=>60]);
        $allowed = $rl->isAllowed('req_first');
        $this->assertTrue($allowed);
        $log = end($this->logs); // last log maybe rate_limit_check or rate_limit_exceeded
        $this->assertEquals('rate_limit_check', $log['event']);
        $this->assertTrue($log['payload']['allowed']);
        $this->assertEquals(0, $log['payload']['current_count']);
    }

    public function testWithinLimit()
    {
        // Pre-populate 5 entries
        set_transient('hosha2_rate_limit_window', [
            ['timestamp'=>time(),'req_id'=>'a'],
            ['timestamp'=>time(),'req_id'=>'b'],
            ['timestamp'=>time(),'req_id'=>'c'],
            ['timestamp'=>time(),'req_id'=>'d'],
            ['timestamp'=>time(),'req_id'=>'e'],
        ], 120);
        $rl = new Hosha2RateLimiter($this->loggerStub(), ['max_requests'=>10,'window'=>60]);
        $allowed = $rl->isAllowed('req_sixth');
        $this->assertTrue($allowed);
        $log = end($this->logs); $this->assertEquals('rate_limit_check',$log['event']);
        $this->assertEquals(5, $log['payload']['current_count']);
    }

    public function testAtExactLimitBlocked()
    {
        $entries=[]; for($i=0;$i<10;$i++){ $entries[]=['timestamp'=>time(),'req_id'=>'r'.$i]; }
        set_transient('hosha2_rate_limit_window', $entries, 120);
        $rl = new Hosha2RateLimiter($this->loggerStub(), ['max_requests'=>10,'window'=>60]);
        $allowed = $rl->isAllowed('req_over');
        $this->assertFalse($allowed);
        // last two logs could include rate_limit_exceeded; find check log
        $found=false; foreach($this->logs as $l){ if($l['event']==='rate_limit_check') { $found=$l; } }
        $this->assertNotFalse($found);
        $this->assertFalse($found['payload']['allowed']);
        $this->assertEquals(10, $found['payload']['current_count']);
    }

    public function testCleanupOldRequests()
    {
        $now = time();
        $old = $now - 120; // older than 60s window
        $entries=[]; for($i=0;$i<10;$i++){ $entries[]=['timestamp'=>$old,'req_id'=>'old'.$i]; }
        for($i=0;$i<5;$i++){ $entries[]=['timestamp'=>$now,'req_id'=>'new'.$i]; }
        set_transient('hosha2_rate_limit_window', $entries, 300);
        // subclass to control now()
        $rl = new class($this->loggerStub()) extends Hosha2RateLimiter { protected function now(): int { return time(); } };
        $allowed = $rl->isAllowed('req_new');
        $this->assertTrue($allowed);
        $log = end($this->logs); $this->assertEquals('rate_limit_check',$log['event']);
        $this->assertEquals(5, $log['payload']['current_count']);
    }

    public function testRecordRequestAppends()
    {
        set_transient('hosha2_rate_limit_window', [], 120);
        $rl = new Hosha2RateLimiter($this->loggerStub(), ['max_requests'=>10,'window'=>60]);
        $this->assertTrue($rl->isAllowed('reqX'));
        $rl->recordRequest('reqX');
        $stored = get_transient('hosha2_rate_limit_window');
        $this->assertCount(1, $stored);
        $this->assertEquals('reqX', $stored[0]['req_id']);
        $this->assertTrue(abs(time() - $stored[0]['timestamp']) < 3);
    }

    public function testIntegrationGenerateServiceRateLimited()
    {
        // Fill limit
        $entries=[]; for($i=0;$i<10;$i++){ $entries[]=['timestamp'=>time(),'req_id'=>'full'.$i]; }
        set_transient('hosha2_rate_limit_window', $entries, 120);
        $logger = $this->loggerStub();
        $rl = new Hosha2RateLimiter($logger, ['max_requests'=>10,'window'=>60]);
        $service = new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            new Hosha2OpenAIClientStub(),
            new Hosha2DiffValidator(),
            $logger,
            $rl
        );
        $caught=false; try { $service->generate(['req_id'=>'blocked1','prompt'=>'x']); } catch(\RuntimeException $e) { $caught=true; $this->assertStringContainsString('Rate limit exceeded',$e->getMessage()); }
        $this->assertTrue($caught, 'Expected RuntimeException for rate limit');
        $foundExceeded=false; foreach($this->logs as $l){ if($l['event']==='rate_limit_exceeded'){ $foundExceeded=true; }}
        $this->assertTrue($foundExceeded, 'Expected rate_limit_exceeded log');
    }
}
?>
