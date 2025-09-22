<?php
// بارگذاری قالب اختصاصی داشبورد افزونه برای صفحه داشبورد
add_filter('template_include', function ($template) {
	$dashboard_id = (int) get_option('arshline_dashboard_page_id');
	$use_plugin_template = false;

	// 1) بر اساس ID ذخیره‌شده
	if ($dashboard_id && is_page($dashboard_id)) {
		$use_plugin_template = true;
	}

	// 2) بر اساس اسلاگ یا وجود شورت‌کد
	if (!$use_plugin_template && is_page()) {
		$queried_id = get_queried_object_id();
		$slug = $queried_id ? get_post_field('post_name', $queried_id) : '';
		if ($slug === 'arshline-dashboard') {
			$use_plugin_template = true;
		} else if ($queried_id) {
			$content = get_post_field('post_content', $queried_id);
			if (function_exists('has_shortcode') && has_shortcode($content, 'arshline_dashboard')) {
				$use_plugin_template = true;
			}
		}
	}

	if ($use_plugin_template) {
		$plugin_template = __DIR__ . '/src/Dashboard/dashboard-template.php';
		if (file_exists($plugin_template)) {
			return $plugin_template;
		}
	}
	return $template;
});
use Arshline\Dashboard\DashboardPageInstaller;
use Arshline\Dashboard\Dashboard;
// ثبت فلگ برای نصب صفحه داشبورد در init پس از فعال‌سازی
register_activation_hook(__FILE__, function () {
	update_option('arshline_do_page_install', 1);
});

// ثبت شورت‌کد داشبورد مدرن
add_action('init', function () {
	DashboardPageInstaller::register_shortcode();
	// اگر فلگ نصب صفحه فعال است، الان صفحه را بساز و به عنوان صفحه نخست تنظیم کن
	if (get_option('arshline_do_page_install')) {
		DashboardPageInstaller::install_dashboard_page();
		if (function_exists('update_option')) {
			DashboardPageInstaller::ensure_front_page();
		}
		delete_option('arshline_do_page_install');
	}
	// اگر قبلاً افزونه فعال بوده و صفحه نصب شده اما صفحه نخست تنظیم نشده، یک‌بار تنظیم کن
	if (!get_option('arshline_frontpage_migrated')) {
		$dashboard_id = get_option('arshline_dashboard_page_id');
		if ($dashboard_id) {
			DashboardPageInstaller::ensure_front_page();
			update_option('arshline_frontpage_migrated', 1);
		}
	}
});

// ریدایرکت صفحه اصلی سایت به صفحه داشبورد (موقت)
add_action('template_redirect', function () {
	if (is_front_page()) {
		$dashboard_id = get_option('arshline_dashboard_page_id');
		if ($dashboard_id) {
			$url = get_permalink($dashboard_id);
			if ($url && !is_page($dashboard_id)) {
				wp_redirect($url);
				exit;
			}
		}
	}
});
// بوت داشبورد اختصاصی افزونه (نسخه‌دار)
add_action('plugins_loaded', function () {
	Dashboard::boot();
});
/*
Plugin Name: استارت افزونه عرشلاین (Arshline Starter)
Plugin URI: https://example.com/
Description: این فایل استارت افزونه عرشلاین است و نقطه شروع توسعه می‌باشد.
Version: 1.0.3
Author: Your Name
Author URI: https://example.com/
License: GPL2
Text Domain: arshline
*/
use Arshline\Modules\Submission;
use Arshline\Modules\SubmissionRepository;
use Arshline\Modules\SubmissionValueRepository;
// نمونه ثبت پاسخ فرم (Submission) برای تست اولیه
add_action('init', function () {
	if (isset($_GET['arshline_test_submission'])) {
		$submissionData = [
			'form_id' => 1,
			'user_id' => get_current_user_id(),
			'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
			'status' => 'pending',
			'meta' => ['desc' => 'پاسخ تست MVP'],
			'values' => [
				['field_id' => 1, 'value' => 'علی'],
				['field_id' => 2, 'value' => 'ali@example.com']
			]
		];
		$submission = new Submission($submissionData);
		$submission_id = SubmissionRepository::save($submission);
		foreach ($submission->values as $val) {
			SubmissionValueRepository::save($submission_id, $val['field_id'], $val['value']);
		}
		echo "<div style='direction:rtl'>پاسخ با موفقیت ذخیره شد. ID: $submission_id</div>";
		exit;
	}
});


// این فایل استارت افزونه عرشلاین است.
// Autoload (Composer-like)
spl_autoload_register(function ($class) {
	$prefix = 'Arshline\\';
	$base_dir = __DIR__ . '/src/';
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}
	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
	if (file_exists($file)) {
		require_once $file;
	}
});

// بوت اولیه ServiceContainer و FormsModule
use Arshline\Core\ServiceContainer;
use Arshline\Modules\FormsModule;

add_action('plugins_loaded', function () {
	$container = new ServiceContainer();
	// ثبت ماژول‌ها
	FormsModule::boot();
	// سایر ماژول‌ها بعداً اضافه می‌شوند
});

// نمونه ثبت فرم ساده (MVP Core) برای تست اولیه
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\FormValidator;

add_action('init', function () {
	if (isset($_GET['arshline_test_form'])) {
		$formData = [
			'schema_version' => '1.0.0',
			'owner_id' => get_current_user_id(),
			'status' => 'draft',
			'meta' => ['title' => 'فرم تست MVP'],
			'fields' => [
				['type' => 'text', 'label' => 'نام'],
				['type' => 'email', 'label' => 'ایمیل']
			]
		];
		$form = new Form($formData);
		$errors = FormValidator::validate($form);
		if (empty($errors)) {
			$id = FormRepository::save($form);
			echo "<div style='direction:rtl'>فرم با موفقیت ذخیره شد. ID: $id</div>";
		} else {
			echo "<div style='direction:rtl;color:red'>خطا: ".implode('<br>', $errors)."</div>";
		}
		exit;
	}
});
