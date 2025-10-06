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
}
