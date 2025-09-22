<?php
namespace Arshline\Core;

use WP_REST_Request;
use WP_REST_Response;
use Arshline\Support\Helpers;
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;

class Api
{
    public static function boot()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes()
    {
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_forms'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_form'],
            'permission_callback' => '__return_true',
            'args' => [
                'title' => [ 'type' => 'string', 'required' => false ],
            ],
        ]);
    }

    public static function get_forms(WP_REST_Request $request)
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $rows = $wpdb->get_results("SELECT id, status, meta, created_at FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        $data = array_map(function ($r) {
            $meta = json_decode($r['meta'] ?: '{}', true);
            return [
                'id' => (int)$r['id'],
                'title' => $meta['title'] ?? 'بدون عنوان',
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows ?: []);
        return new WP_REST_Response($data, 200);
    }

    public static function create_form(WP_REST_Request $request)
    {
        $title = trim((string)($request->get_param('title') ?? 'فرم بدون عنوان'));
        $formData = [
            'schema_version' => '1.0.0',
            'owner_id' => get_current_user_id(),
            'status' => 'draft',
            'meta' => [ 'title' => $title ],
        ];
        $form = new Form($formData);
        $id = FormRepository::save($form);
        return new WP_REST_Response([ 'id' => $id, 'title' => $title, 'status' => 'draft' ], 201);
    }
}
