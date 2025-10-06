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
}
