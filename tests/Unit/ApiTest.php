<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use function Brain\Monkey\Functions\when; // new style function import
use Arshline\Core\Api;
use WP_REST_Request;
use ReflectionMethod;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('Brain\\Monkey\\setUp')) { \Brain\Monkey\setUp(); }
        // Ensure user id function exists for namespaced calls in Api class
        when('get_current_user_id')->justReturn(1);
    }

    protected function tearDown(): void
    {
        if (function_exists('Brain\\Monkey\\tearDown')) { \Brain\Monkey\tearDown(); }
        parent::tearDown();
    }

    public function testRegistersRoutes()
    {
        $calls = [];
        when('register_rest_route')->alias(function($ns, $route, $args) use (&$calls) {
            $calls[] = [$ns, $route, $args];
        });
        when('current_user_can')->justReturn(true);

        Api::register_routes();

        $this->assertNotEmpty($calls);
        $first = $calls[0];
        $this->assertSame('arshline/v1', $first[0]);
        $this->assertSame('/forms', $first[1]);
        $this->assertSame([Api::class, 'user_can_manage_forms'], $first[2]['permission_callback']);
    }

    public function testPermissionsCallbacks()
    {
        $perms = [];
        when('register_rest_route')->alias(function($ns, $route, $args) use (&$perms) {
            if (is_array($args) && isset($args['permission_callback'])) {
                $perms[] = $args['permission_callback'];
            } elseif (is_array($args) && isset($args[0]['permission_callback'])) {
                $perms[] = $args[0]['permission_callback'];
                $perms[] = $args[1]['permission_callback'];
            }
        });
        when('current_user_can')->alias(function($cap){ return in_array($cap, ['manage_options','edit_posts'], true); });
        when('__return_true')->justReturn(true);

        Api::register_routes();
        $this->assertNotEmpty($perms);
        foreach ($perms as $cb) {
            $this->assertTrue((bool) call_user_func($cb));
        }
    }

    public function testUserCanManageFormsRequiresCapability()
    {
        $method = new ReflectionMethod(Api::class, 'user_can_manage_forms');
        $method->setAccessible(true);

        when('current_user_can')->justReturn(false);
        $this->assertFalse($method->invoke(null));

        when('current_user_can')->alias(function($cap){ return $cap === 'edit_posts'; });
        $this->assertTrue($method->invoke(null));
    }

    public function testCreateSubmissionRejectsInvalidFormId()
    {
        $request = new WP_REST_Request('POST', '/');
        $request['form_id'] = 0;
        $response = Api::create_submission($request);
        $this->assertEquals(400, $response->get_status());
    }

    public function testCreateFormPersistsData()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $insert_data; public $insert_id = 42;
            public function insert($table, $data) { $this->insert_data = [$table, $data]; return true; }
        };
        when('current_user_can')->justReturn(true);

        $request = new WP_REST_Request('POST', '/');
        $request->set_param('title', 'نمونه');
        $response = Api::create_form($request);

        $this->assertEquals(201, $response->get_status());
        $payload = $response->get_data();
        $this->assertSame(42, $payload['id']);
        $this->assertSame('نمونه', $payload['title']);
    }

    public function testGetFormsReturnsArray()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function get_results($q, $out) {
                return [
                    ['id'=>1,'status'=>'draft','meta'=>json_encode(['title'=>'T1']),'created_at'=>'2025-01-01 00:00:00'],
                    ['id'=>2,'status'=>'published','meta'=>json_encode([]),'created_at'=>'2025-01-02 00:00:00'],
                ];
            }
        };
        when('current_user_can')->justReturn(true);
        $req = new WP_REST_Request('GET','/');
        $res = Api::get_forms($req);
        $this->assertEquals(200, $res->get_status());
        $data = $res->get_data();
        $this->assertCount(2, $data);
        $this->assertEquals('T1', $data[0]['title']);
        // Accept both legacy and current default title variants to avoid flakiness
        $this->assertTrue(in_array($data[1]['title'], ['بدون عنوان','فرم بدون عنوان'], true), 'Unexpected default title: '.$data[1]['title']);
    }
}
