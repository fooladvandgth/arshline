<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2DiffValidator,Hosha2OpenAIClientInterface,Hosha2LoggerInterface,Hosha2VersionRepository};

class Hosha2DiffShaTest extends TestCase
{
    public array $logs = [];
    public array $snapshots = [];

    protected function loggerStub(): Hosha2LoggerInterface
    {
        $outer = $this; return new class($outer) implements Hosha2LoggerInterface { private $o; public function __construct($o){$this->o=$o;} public function log(string $event, array $payload = [], string $level = 'INFO'): void { $this->o->logs[]=['event'=>$event,'payload'=>$payload,'level'=>$level]; } public function phase(string $phase, array $extra = [], string $level = 'INFO'): void { $this->log('phase',['phase'=>$phase]+$extra,$level);} public function summary(array $metrics, array $issues = [], array $notes = []): void { $this->log('summary',['metrics'=>$metrics,'issues'=>$issues,'notes'=>$notes]); } public function setContext(array $context): void {} public function rotateIfNeeded(): void {} };
    }

    protected function versionRepoStub(): Hosha2VersionRepository
    {
        $outer=$this; return new class($outer) extends Hosha2VersionRepository { private $o; public function __construct($o){ $this->o=$o; parent::__construct(); } public function saveSnapshot(int $formId, array $config, array $metadata = [], ?string $diffSha = null): int { $id = count($this->o->snapshots)+1; $this->o->snapshots[$id] = ['form_id'=>$formId,'config'=>$config,'metadata'=>$metadata,'diff_sha'=>$diffSha]; return $id; } };
    }

    protected function makeServiceWithClientReturning(array $result): Hosha2GenerateService
    {
        $client = new class($result) implements Hosha2OpenAIClientInterface { private $r; public function __construct($r){$this->r=$r;} public function sendGenerate(array $envelope): array { return $this->r; } public function sendValidate(array $envelope): array { return $this->r; } };
        return new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator(),
            $this->loggerStub(),
            null,
            $this->versionRepoStub()
        );
    }

    public function testValidDiffComputesSha()
    {
        $diff = [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1']] ];
        $result = ['final_form'=>['fields'=>[['id'=>'f1']]],'diff'=>$diff,'token_usage'=>['total'=>10]];
        $service = $this->makeServiceWithClientReturning($result);
        $res = $service->generate(['prompt'=>'x','form_id'=>1]);
        $expected = sha1(json_encode($diff));
        $this->assertEquals($expected, $res['diff_sha']);
        $summary = $this->findLog('summary');
        $this->assertEquals($expected, $summary['payload']['metrics']['diff_sha']);
    }

    public function testEmptyDiffProducesNullSha()
    {
        $result = ['final_form'=>['fields'=>[]],'diff'=>[],'token_usage'=>['total'=>0]];
        $service = $this->makeServiceWithClientReturning($result);
        $res = $service->generate(['prompt'=>'empty','form_id'=>2]);
        $this->assertNull($res['diff_sha']);
    }

    public function testInvalidDiffProducesNullSha()
    {
        $result = ['final_form'=>['fields'=>[]],'diff'=>'not-array','token_usage'=>['total'=>0]];
        $service = $this->makeServiceWithClientReturning($result);
        $res = $service->generate(['prompt'=>'invalid','form_id'=>3]);
        $this->assertNull($res['diff_sha']);
    }

    public function testSnapshotStoresDiffSha()
    {
        $diff = [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'fZ']] ];
        $result = ['final_form'=>['fields'=>[['id'=>'fZ']]],'diff'=>$diff,'token_usage'=>['total'=>5]];
        $service = $this->makeServiceWithClientReturning($result);
        $service->generate(['prompt'=>'snap','form_id'=>5]);
        $this->assertNotEmpty($this->snapshots);
        $first = reset($this->snapshots);
        $this->assertEquals(sha1(json_encode($diff)), $first['diff_sha']);
    }

    public function testSameDiffYieldsSameSha()
    {
        $diff = [ ['op'=>'replace','path'=>'/fields/0/id','value'=>'fX'] ];
        $result = ['final_form'=>['fields'=>[['id'=>'fX']]],'diff'=>$diff,'token_usage'=>['total'=>2]];
        $service = $this->makeServiceWithClientReturning($result);
        $a = $service->generate(['prompt'=>'a','form_id'=>7]);
        $b = $service->generate(['prompt'=>'b','form_id'=>7]);
        $this->assertEquals($a['diff_sha'],$b['diff_sha']);
    }

    public function testDifferentDiffYieldDifferentSha()
    {
        $diff1 = [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1']] ];
        $diff2 = [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f2']] ];
        $service1 = $this->makeServiceWithClientReturning(['final_form'=>['fields'=>[['id'=>'f1']]],'diff'=>$diff1,'token_usage'=>['total'=>1]]);
        $service2 = $this->makeServiceWithClientReturning(['final_form'=>['fields'=>[['id'=>'f2']]],'diff'=>$diff2,'token_usage'=>['total'=>1]]);
        $a = $service1->generate(['prompt'=>'p1','form_id'=>9]);
        $b = $service2->generate(['prompt'=>'p2','form_id'=>9]);
        $this->assertNotEquals($a['diff_sha'],$b['diff_sha']);
    }

    public function testOpenAILogContainsDiffSha()
    {
        $diff = [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1']] ];
        $result = ['final_form'=>['fields'=>[['id'=>'f1']]],'diff'=>$diff,'token_usage'=>['total'=>3]];
        $service = $this->makeServiceWithClientReturning($result);
        $service->generate(['prompt'=>'log','form_id'=>11]);
        $target = sha1(json_encode($diff));
        $found=false; foreach($this->logs as $l){
            if (isset($l['payload']['diff_sha']) && $l['payload']['diff_sha']===$target) { $found=true; break; }
            if ($l['event']==='summary' && isset($l['payload']['metrics']['diff_sha']) && $l['payload']['metrics']['diff_sha']===$target) { $found=true; break; }
        }
        $this->assertTrue($found,'Expected diff_sha present in at least one log entry');
    }

    private function findLog(string $event)
    {
        foreach ($this->logs as $l) if ($l['event']===$event) return $l;
        return null;
    }
}
?>
