<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2VersionController,Hosha2VersionRepository,Hosha2LoggerInterface};
use Arshline\Hosha2\Storage\InMemoryVersionStorage;

class Hosha2VersionControllerTest extends TestCase
{
    private function skipIfNoWP(): void
    {
        if (!class_exists('WP_REST_Request')) {
            $this->markTestSkipped('WP REST classes not loaded');
        }
    }

    private function loggerStub(): Hosha2LoggerInterface
    {
        return new class implements Hosha2LoggerInterface {
            public array $logs = []; public function log(string $event, array $payload = [], string $level = 'INFO'): void { $this->logs[] = [$event,$payload,$level]; }
            public function phase(string $phase, array $extra = [], string $level = 'INFO'): void {}
            public function summary(array $metrics, array $issues = [], array $notes = []): void {}
            public function setContext(array $context): void {}
            public function rotateIfNeeded(): void {}
        };
    }

    private function makeControllerWithData(int $formId, int $count): Hosha2VersionController
    {
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger); // uses InMemory fallback
        // Seed versions
        for ($i=0; $i<$count; $i++) {
            $repo->saveSnapshot($formId, ['fields'=>[['id'=>'f'.$i]]], ['user_prompt'=>'p'.$i,'tokens_used'=>$i], null);
        }
        return new Hosha2VersionController($repo, $logger);
    }

    public function testBasicList()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(10, 3);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/10/versions');
        $req->set_param('form_id', 10);
        $resp = $c->listFormVersions($req);
        $this->assertEquals(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertTrue($data['success']);
        $this->assertCount(3, $data['data']['versions']);
        $this->assertArrayHasKey('version_id', $data['data']['versions'][0]);
    }

    public function testEmptyList()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(55, 0);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/55/versions');
        $req->set_param('form_id', 55);
        $resp = $c->listFormVersions($req);
        $this->assertEquals(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertEquals(0, $data['data']['total']);
        $this->assertCount(0, $data['data']['versions']);
    }

    public function testPagination()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(77, 7);
        $req1 = new WP_REST_Request('GET','/hosha2/v1/forms/77/versions');
        $req1->set_param('form_id', 77); $req1->set_param('limit',3); $req1->set_param('offset',0);
        $r1 = $c->listFormVersions($req1)->get_data();
        $req2 = new WP_REST_Request('GET','/hosha2/v1/forms/77/versions');
        $req2->set_param('form_id', 77); $req2->set_param('limit',3); $req2->set_param('offset',3);
        $r2 = $c->listFormVersions($req2)->get_data();
        $this->assertCount(3, $r1['data']['versions']);
        $this->assertCount(3, $r2['data']['versions']);
        $ids1 = array_column($r1['data']['versions'],'version_id');
        $ids2 = array_column($r2['data']['versions'],'version_id');
        $this->assertEmpty(array_intersect($ids1,$ids2));
    }

    public function testInvalidLimit()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(90, 1);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/90/versions');
        $req->set_param('form_id', 90); $req->set_param('limit', 0);
        $resp = $c->listFormVersions($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_limit', $resp->get_data()['error']['code']);
    }

    public function testInvalidOffset()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(91, 1);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/91/versions');
        $req->set_param('form_id', 91); $req->set_param('offset', -5);
        $resp = $c->listFormVersions($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_offset', $resp->get_data()['error']['code']);
    }

    public function testInvalidFormId()
    {
        $this->skipIfNoWP();
        $c = $this->makeControllerWithData(1, 0);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/0/versions');
        $req->set_param('form_id', 0);
        $resp = $c->listFormVersions($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_form_id', $resp->get_data()['error']['code']);
    }

    // --- Single version retrieval tests (F6-2) ---
    public function testGetVersionSuccess()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger); // in-memory
        $vid = $repo->saveSnapshot(123, ['fields'=>[['id'=>'a']]], ['user_prompt'=>'hello'], null);
        $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('GET', '/hosha2/v1/forms/123/versions/'.$vid);
        $req->set_param('form_id', 123);
        $req->set_param('version_id', $vid);
        $resp = $controller->getVersion($req);
        $this->assertEquals(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals($vid, $data['data']['version_id']);
        $this->assertEquals(123, $data['data']['form_id']);
        $this->assertArrayHasKey('snapshot', $data['data']);
        $this->assertNotEmpty($data['data']['created_at']);
        // Check ISO8601 format basic pattern (YYYY-MM-DDTHH:MM:SSZ)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['data']['created_at']);
    }

    public function testGetVersionNotFound()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger);
        $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/50/versions/999');
        $req->set_param('form_id', 50); $req->set_param('version_id', 999);
        $resp = $controller->getVersion($req);
        $this->assertEquals(404, $resp->get_status());
        $this->assertEquals('version_not_found', $resp->get_data()['error']['code']);
    }

    public function testGetVersionFormMismatch()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger);
        $vid = $repo->saveSnapshot(10, ['x'=>1], ['user_prompt'=>'x'], null);
        $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/11/versions/'.$vid);
        $req->set_param('form_id', 11); $req->set_param('version_id', $vid);
        $resp = $controller->getVersion($req);
        $this->assertEquals(404, $resp->get_status());
        $this->assertEquals('version_not_found', $resp->get_data()['error']['code']);
    }

    public function testGetVersionInvalidVersionId()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger);
        $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/5/versions/0');
        $req->set_param('form_id', 5); $req->set_param('version_id', 0);
        $resp = $controller->getVersion($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_version_id', $resp->get_data()['error']['code']);
    }

    public function testGetVersionInvalidFormId()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger);
        $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('GET','/hosha2/v1/forms/0/versions/1');
        $req->set_param('form_id', 0); $req->set_param('version_id', 1);
        $resp = $controller->getVersion($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_form_id', $resp->get_data()['error']['code']);
    }

    // --- Apply Diff Tests (F7) ---
    private function makeBaseVersion(int $formId, array $config): array
    {
        $logger = $this->loggerStub();
        $repo = new Hosha2VersionRepository($logger);
        $vid = $repo->saveSnapshot($formId, $config, ['user_prompt'=>'seed'], null);
        $controller = new Hosha2VersionController($repo, $logger);
        return [$controller,$repo,$logger,$vid];
    }

    public function testApplyDiffReplaceFieldLabel()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(300, ['fields'=>[['label'=>'Old','type'=>'short_text']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/300/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',300); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'replace','path'=>'/fields/0/label','value'=>'New']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals('New', $data['data']['snapshot']['fields'][0]['label']);
        $this->assertNotNull($data['data']['new_version_id']);
    }

    public function testApplyDiffAddField()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(301, ['fields'=>[]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/301/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',301); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'add','path'=>'/fields/0','value'=>['label'=>'A','type'=>'short_text']]
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(200, $resp->get_status());
        $this->assertEquals(1, count($resp->get_data()['data']['snapshot']['fields']));
    }

    public function testApplyDiffRemoveField()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(302, ['fields'=>[['label'=>'X'],['label'=>'Y']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/302/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',302); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'remove','path'=>'/fields/0']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(200, $resp->get_status());
        $fields = $resp->get_data()['data']['snapshot']['fields'];
        $this->assertEquals('Y', $fields[0]['label']);
    }

    public function testApplyDiffDryRun()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(303, ['fields'=>[['label'=>'One']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/303/versions/'.$vid.'/apply-diff?dry_run=1');
        $req->set_param('form_id',303); $req->set_param('version_id',$vid); $req->set_param('dry_run','1');
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'replace','path'=>'/fields/0/label','value'=>'Preview']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(200, $resp->get_status());
        $d = $resp->get_data();
        $this->assertTrue($d['data']['dry_run']);
        $this->assertNull($d['data']['new_version_id']);
        $this->assertEquals('Preview',$d['data']['snapshot']['fields'][0]['label']);
    }

    public function testApplyDiffUnsupportedOp()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(304, ['fields'=>[['label'=>'A']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/304/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',304); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'move','path'=>'/fields/0','from'=>'/fields/1']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('unsupported_operation', $resp->get_data()['error']['code']);
    }

    public function testApplyDiffInvalidPath()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(305, ['fields'=>[['label'=>'A']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/305/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',305); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'replace','path'=>'fields/0/label','value'=>'NoSlash']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(400, $resp->get_status());
        $this->assertEquals('invalid_diff',$resp->get_data()['error']['code']);
    }

    public function testApplyDiffVersionNotFound()
    {
        $this->skipIfNoWP();
        $logger = $this->loggerStub(); $repo = new Hosha2VersionRepository($logger); $controller = new Hosha2VersionController($repo, $logger);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/310/versions/999/apply-diff');
        $req->set_param('form_id',310); $req->set_param('version_id',999);
        $req->set_body(json_encode(['diff'=>[['op'=>'replace','path'=>'/fields/0','value'=>[]]]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(404, $resp->get_status());
        $this->assertEquals('version_not_found', $resp->get_data()['error']['code']);
    }

    public function testApplyDiffOwnershipMismatch()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(320, ['fields'=>[['label'=>'A']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/321/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',321); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'replace','path'=>'/fields/0/label','value'=>'X']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(404, $resp->get_status());
        $this->assertEquals('version_not_found', $resp->get_data()['error']['code']);
    }

    public function testApplyDiffInvalidFormId()
    {
        $this->skipIfNoWP();
        $logger=$this->loggerStub(); $repo=new Hosha2VersionRepository($logger); $controller=new Hosha2VersionController($repo,$logger);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/0/versions/1/apply-diff');
        $req->set_param('form_id',0); $req->set_param('version_id',1);
        $req->set_body(json_encode(['diff'=>[['op'=>'add','path'=>'/x','value'=>1]]]));
        $req->set_header('Content-Type','application/json');
        $resp=$controller->applyDiff($req);
        $this->assertEquals(400,$resp->get_status());
        $this->assertEquals('invalid_form_id',$resp->get_data()['error']['code']);
    }

    public function testApplyDiffInvalidVersionId()
    {
        $this->skipIfNoWP();
        $logger=$this->loggerStub(); $repo=new Hosha2VersionRepository($logger); $controller=new Hosha2VersionController($repo,$logger);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/10/versions/0/apply-diff');
        $req->set_param('form_id',10); $req->set_param('version_id',0);
        $req->set_body(json_encode(['diff'=>[['op'=>'add','path'=>'/x','value'=>1]]]));
        $req->set_header('Content-Type','application/json');
        $resp=$controller->applyDiff($req);
        $this->assertEquals(400,$resp->get_status());
        $this->assertEquals('invalid_version_id',$resp->get_data()['error']['code']);
    }

    public function testApplyDiffEmptyDiff()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(330, ['fields'=>[]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/330/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',330); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[]]));
        $req->set_header('Content-Type','application/json');
        $resp=$controller->applyDiff($req);
        $this->assertEquals(400,$resp->get_status());
        $this->assertEquals('empty_diff',$resp->get_data()['error']['code']);
    }

    public function testApplyDiffMetadataPersisted()
    {
        $this->skipIfNoWP();
        [$controller,$repo,$logger,$vid] = $this->makeBaseVersion(340, ['fields'=>[['label'=>'A']]]);
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/340/versions/'.$vid.'/apply-diff');
        $req->set_param('form_id',340); $req->set_param('version_id',$vid);
        $req->set_body(json_encode(['diff'=>[
            ['op'=>'replace','path'=>'/fields/0/label','value'=>'B']
        ]]));
        $req->set_header('Content-Type','application/json');
        $resp = $controller->applyDiff($req);
        $this->assertEquals(200,$resp->get_status());
        $newVid = $resp->get_data()['data']['new_version_id'];
        $snap = $repo->getSnapshot($newVid);
        $this->assertEquals(1, $snap['metadata']['_hosha2_diff_applied']);
    }
}
