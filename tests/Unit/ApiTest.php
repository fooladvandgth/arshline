<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;
use ReflectionMethod;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function testRegistersRoutes()
    {
        $calls = [];
        Functions::when('register_rest_route')->alias(function($ns, $route, $args) use (&$calls) {
            $calls[] = [$ns, $route, $args];
        });
        Functions::when('current_user_can')->justReturn(true);

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
        Functions::when('register_rest_route')->alias(function($ns, $route, $args) use (&$perms) {
            if (is_array($args) && isset($args['permission_callback'])) {
                $perms[] = $args['permission_callback'];
            } elseif (is_array($args) && isset($args[0]['permission_callback'])) {
                $perms[] = $args[0]['permission_callback'];
                $perms[] = $args[1]['permission_callback'];
            }
        });
        Functions::when('current_user_can')->alias(function($cap){
            return in_array($cap, ['manage_options','edit_posts'], true);
        });
        Functions::when('__return_true')->justReturn(true);

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

        Functions::when('current_user_can')->justReturn(false);
        $this->assertFalse($method->invoke(null));

        Functions::when('current_user_can')->alias(function($cap){
            return $cap === 'edit_posts';
        });
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
            public $insert_data;
            public $insert_id = 42;
            public function insert($table, $data) {
                $this->insert_data = [$table, $data];
                return true;
            }
        };

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
        $req = new WP_REST_Request('GET','/');
        $res = Api::get_forms($req);
        $this->assertEquals(200, $res->get_status());
        $data = $res->get_data();
        $this->assertCount(2, $data);
        $this->assertEquals('T1', $data[0]['title']);
        $this->assertEquals('فرم بدون عنوان', $data[1]['title']);
    }
}
