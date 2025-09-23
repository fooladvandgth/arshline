<?php
namespace Arshline\Core;

use WP_REST_Request;
use WP_REST_Response;
use Arshline\Support\Helpers;
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\Submission;
use Arshline\Modules\Forms\SubmissionRepository;
use Arshline\Modules\Forms\SubmissionValueRepository;
use Arshline\Modules\Forms\FormValidator;
use Arshline\Modules\Forms\FieldRepository;

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
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/fields', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_fields'],
            'permission_callback' => function() { return current_user_can('edit_posts') || current_user_can('manage_options'); },
        ]);
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_form'],
            'permission_callback' => function() { return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'args' => [
                'title' => [ 'type' => 'string', 'required' => false ],
            ],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'delete_form'],
            'permission_callback' => function() { return current_user_can('edit_posts') || current_user_can('manage_options'); },
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/submissions', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_submissions'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create_submission'],
                'permission_callback' => '__return_true',
            ]
        ]);
        // Upload image (for admins/editors)
        register_rest_route('arshline/v1', '/upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'upload_image'],
            'permission_callback' => function() { return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'args' => [
                'file' => [ 'required' => false ],
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

    public static function get_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $fields = FieldRepository::listByForm($id);
        return new WP_REST_Response([
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ], 200);
    }

    public static function update_fields(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $fields = $request->get_param('fields');
        if (!is_array($fields)) $fields = [];
        FieldRepository::replaceAll($id, $fields);
        return new WP_REST_Response(['ok'=>true], 200);
    }

    public static function get_submissions(WP_REST_Request $request)
    {
        global $wpdb;
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response([], 200);
        $table = Helpers::tableName('submissions');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, status, meta, created_at FROM {$table} WHERE form_id=%d ORDER BY id DESC LIMIT 100", $form_id), ARRAY_A);
        $data = array_map(function ($r) {
            $meta = json_decode($r['meta'] ?: '{}', true);
            return [
                'id' => (int)$r['id'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
                'summary' => $meta['summary'] ?? null,
            ];
        }, $rows ?: []);
        return new WP_REST_Response($data, 200);
    }

    public static function create_submission(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response(['error' => 'invalid_form_id'], 400);
        $values = $request->get_param('values');
        if (!is_array($values)) $values = [];
        // Load schema for validation
        $fields = FieldRepository::listByForm($form_id);
        $valErrors = FormValidator::validateSubmission($fields, $values);
        if (!empty($valErrors)) {
            return new WP_REST_Response(['error' => 'validation_failed', 'messages' => $valErrors], 422);
        }
        $submissionData = [
            'form_id' => $form_id,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'pending',
            'meta' => [ 'summary' => 'ایجاد از REST' ],
            'values' => $values,
        ];
        $submission = new Submission($submissionData);
        $id = SubmissionRepository::save($submission);
        foreach ($values as $idx => $entry) {
            $field_id = (int)($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? '';
            if ($field_id > 0) {
                SubmissionValueRepository::save($id, $field_id, $value, $idx);
            }
        }
        return new WP_REST_Response([ 'id' => $id, 'form_id' => $form_id, 'status' => 'pending' ], 201);
    }

    public static function delete_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $ok = FormRepository::delete($id);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok ? 200 : 404);
    }

    public static function upload_image(WP_REST_Request $request)
    {
        if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
        if (!function_exists('wp_insert_attachment')) require_once(ABSPATH . 'wp-admin/includes/image.php');
        $files = $request->get_file_params();
        if (!isset($files['file'])){
            return new WP_REST_Response(['error' => 'no_file'], 400);
        }
        $file = $files['file'];
        // Allow only images
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        $type = $file['type'] ?? '';
        if ($type && !in_array($type, $allowed, true)){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        add_filter('upload_dir', function($dirs){
            $dirs['subdir'] = '/arshline';
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        });
        $overrides = [ 'test_form' => false ];
        $movefile = wp_handle_upload($file, $overrides);
        remove_all_filters('upload_dir');
        if (!$movefile || isset($movefile['error'])){
            return new WP_REST_Response(['error' => 'upload_failed', 'message' => $movefile['error'] ?? ''], 500);
        }
        // Return URL only
        return new WP_REST_Response([ 'url' => $movefile['url'] ], 201);
    }
}
