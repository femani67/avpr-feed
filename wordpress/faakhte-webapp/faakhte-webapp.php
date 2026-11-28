<?php
/**
 * Plugin Name: فاخته — وب‌اپ
 * Description: نسخهٔ وب و آیفون تقویم فاخته در نشانی avpr.ir/faakhte (قابل نصب روی صفحهٔ اصلی گوشی، کار بدون اینترنت، یادآور در تقویم گوشی). فایل‌ها هنگام فعال‌سازی در پوشهٔ faakhte سایت کپی می‌شوند؛ از «ابزارها ← وب‌اپ فاخته» هم می‌شود دوباره نصبشان کرد.
 * Version: 1.0.0
 * Author: AVPR
 */
if (!defined('ABSPATH')) exit;
define('FAAKHTE_WEBAPP_SRC', __DIR__ . '/webapp');
define('FAAKHTE_WEBAPP_DST', ABSPATH . 'faakhte');

function faakhte_webapp_deploy() {
	$n = 0; $err = [];
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FAAKHTE_WEBAPP_SRC, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	if (!is_dir(FAAKHTE_WEBAPP_DST)) wp_mkdir_p(FAAKHTE_WEBAPP_DST);
	foreach ($it as $f) {
		$rel = substr($f->getPathname(), strlen(FAAKHTE_WEBAPP_SRC) + 1);
		$to = FAAKHTE_WEBAPP_DST . '/' . $rel;
		if ($f->isDir()) { if (!is_dir($to)) wp_mkdir_p($to); continue; }
		if (@copy($f->getPathname(), $to)) $n++; else $err[] = $rel;
	}
	// correct types for the manifest/service worker
	$ht = FAAKHTE_WEBAPP_DST . '/.htaccess'; $mark = '# faakhte-webapp';
	$cur = is_file($ht) ? file_get_contents($ht) : '';
	if (strpos($cur, $mark) === false) {
		@file_put_contents($ht, $cur . "\n$mark\nAddType application/manifest+json .webmanifest\nAddType application/javascript .js\nDirectoryIndex index.html\n<IfModule mod_headers.c>\n<FilesMatch \"^(sw\\.js|index\\.html)$\">\nHeader set Cache-Control \"no-cache\"\n</FilesMatch>\n</IfModule>\n");
	}
	update_option('faakhte_webapp_deployed', ['t' => time(), 'n' => $n, 'err' => $err], false);
	return [$n, $err];
}
register_activation_hook(__FILE__, 'faakhte_webapp_deploy');
add_action('upgrader_process_complete', function ($up, $o) {
	if (($o['type'] ?? '') === 'plugin' && is_dir(FAAKHTE_WEBAPP_SRC)) faakhte_webapp_deploy();
}, 10, 2);

add_action('admin_menu', function () {
	add_management_page('وب‌اپ فاخته', 'وب‌اپ فاخته', 'manage_options', 'faakhte-webapp', function () {
		$msg = '';
		if (isset($_POST['faakhte_deploy']) && check_admin_referer('faakhte_deploy')) { [$n, $e] = faakhte_webapp_deploy(); $msg = $n . ' فایل نصب شد.' . ($e ? ' خطا در: ' . implode('، ', $e) : ''); }
		$d = get_option('faakhte_webapp_deployed');
		echo '<div class="wrap"><h1>وب‌اپ فاخته</h1>';
		if ($msg) echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
		echo '<p>نشانی وب‌اپ: <a href="' . esc_url(home_url('/faakhte/')) . '" target="_blank">' . esc_html(home_url('/faakhte/')) . '</a></p>';
		echo '<p>آخرین نصب: ' . ($d ? esc_html(wp_date('Y-m-d H:i', $d['t']) . ' — ' . $d['n'] . ' فایل') : 'هنوز نصب نشده') . '</p>';
		echo '<form method="post">'; wp_nonce_field('faakhte_deploy');
		echo '<p><button class="button button-primary" name="faakhte_deploy" value="1">نصب / به‌روزرسانی فایل‌های وب‌اپ</button></p></form>';
		echo '<p>مناسبت‌ها، تبلیغ‌ها و تصویر ماه‌ها مثل اپ اندروید از افزونهٔ «فاخته – مدیریت تقویم هوانوردی» می‌آیند و نیازی به نصب دوباره ندارند.</p></div>';
	});
});
