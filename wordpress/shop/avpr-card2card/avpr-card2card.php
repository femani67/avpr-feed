<?php
/**
 * Plugin Name: AVPR کارت به کارت
 * Description: پرداخت کارت به کارت برای ووکامرس: نمایش شماره کارت به مشتری، فرم ارسال رسید (شماره پیگیری، ۴ رقم آخر کارت، مبلغ، تاریخ، تصویر رسید)، بررسی خودکار رسید و تطبیق با پیامک واریز بانک، و تایید یک‌کلیکی سفارش.
 * Version: 1.3.0
 * Author: AVPR
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;

define('AVPR_C2C_VER', '1.3.0');

/* ================= helpers ================= */
function avpr_c2c_en($s) { return strtr((string)$s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>',','،'=>',']); }
function avpr_c2c_fa($s) { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function avpr_c2c_digits($s) { return preg_replace('/\D+/', '', avpr_c2c_en($s)); }
function avpr_c2c_opt($k, $d = '') { $o = get_option('woocommerce_avpr_c2c_settings', []); return isset($o[$k]) && $o[$k] !== '' ? $o[$k] : $d; }
/** order total in Rial (store currency may be IRT = Toman or IRR = Rial) */
function avpr_c2c_order_rial($order) {
	$t = (float) $order->get_total();
	$cur = $order->get_currency();
	return (int) round(in_array($cur, ['IRT', 'IRHT', 'TOMAN'], true) || get_woocommerce_currency_symbol($cur) === 'تومان' ? $t * 10 : $t);
}
function avpr_c2c_card_fmt($n) { $n = avpr_c2c_digits($n); return trim(chunk_split($n, 4, ' ')); }

/* ================= gateway ================= */
add_action('plugins_loaded', function () {
	if (!class_exists('WC_Payment_Gateway')) return;

	class WC_Gateway_AVPR_C2C extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'avpr_c2c';
			$this->method_title = 'کارت به کارت (AVPR)';
			$this->method_description = 'مشتری مبلغ را کارت به کارت می‌کند و رسید را در سایت ثبت می‌کند. افزونه رسید را بررسی می‌کند و با پیامک واریز بانک تطبیق می‌دهد.';
			$this->has_fields = false;
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		}
		public function init_form_fields() {
			$this->form_fields = [
				'enabled' => ['title' => 'فعال', 'type' => 'checkbox', 'label' => 'فعال کردن پرداخت کارت به کارت', 'default' => 'yes'],
				'title' => ['title' => 'عنوان در صفحهٔ پرداخت', 'type' => 'text', 'default' => 'کارت به کارت'],
				'description' => ['title' => 'توضیح در صفحهٔ پرداخت', 'type' => 'textarea', 'default' => 'بعد از ثبت سفارش، شماره کارت و مبلغ را می‌بینید. مبلغ را با اپ بانک یا اپ‌های پرداخت (مثل همراه کارت یا آپ) کارت به کارت کنید و رسید را همان‌جا ثبت کنید.'],
				'card_number' => ['title' => 'شماره کارت مقصد', 'type' => 'text', 'description' => '۱۶ رقم، بدون فاصله یا با فاصله.', 'default' => ''],
				'card_holder' => ['title' => 'نام صاحب کارت', 'type' => 'text', 'default' => ''],
				'bank_name' => ['title' => 'نام بانک', 'type' => 'text', 'default' => ''],
				'sheba' => ['title' => 'شماره شبا (اختیاری)', 'type' => 'text', 'default' => ''],
				'phone' => ['title' => 'شمارهٔ تماس برای هماهنگی پرداخت', 'type' => 'text', 'default' => ''],
				'sms_token' => ['title' => 'کلید دریافت پیامک بانک', 'type' => 'text', 'description' => 'برای تطبیق خودکار با پیامک واریز. آدرس دریافت: <code>' . esc_html(rest_url('avpr-c2c/v1/sms')) . '?token=این‌کلید</code>', 'default' => wp_generate_password(24, false)],
				'notify_emails' => ['title' => 'ایمیل‌های اطلاع‌رسانی به مدیر', 'type' => 'text', 'description' => 'هر سفارش و هر رسید تازه به این ایمیل‌ها هم فرستاده می‌شود (با کاما جدا کنید).', 'default' => 'mfakharmanesh@gmail.com'],
				'instant_digital' => ['title' => 'تحویل فوری فایل', 'type' => 'checkbox', 'label' => 'اگر سفارش فقط فایل دانلودی است و رسید ثبت‌شده درست است (مبلغ درست، شماره پیگیری تکراری نیست، تصویر رسید دارد)، لینک دانلود همان لحظه فعال شود؛ تطبیق با پیامک بانک بعداً انجام می‌شود', 'default' => 'yes'],
				'auto_confirm' => ['title' => 'تایید خودکار', 'type' => 'checkbox', 'label' => 'اگر رسید با یک پیامک واریز بانک (همان مبلغ، بعد از زمان سفارش) جور شد، سفارش خودکار تایید شود', 'default' => 'yes'],
			];
		}
		public function process_payment($order_id) {
			$order = wc_get_order($order_id);
			$order->update_status('on-hold', 'در انتظار کارت به کارت و ثبت رسید.');
			wc_reduce_stock_levels($order_id);
			WC()->cart->empty_cart();
			return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
		}
	}
	add_filter('woocommerce_payment_gateways', function ($g) { $g[] = 'WC_Gateway_AVPR_C2C'; return $g; });
});

/* ================= customer box: card info + receipt form ================= */
function avpr_c2c_box($order) {
	if (!$order || $order->get_payment_method() !== 'avpr_c2c') return;
	$card = avpr_c2c_opt('card_number'); $holder = avpr_c2c_opt('card_holder'); $bank = avpr_c2c_opt('bank_name');
	$sheba = avpr_c2c_opt('sheba'); $phone = avpr_c2c_opt('phone');
	$rial = avpr_c2c_order_rial($order); $toman = (int) round($rial / 10);
	$r = $order->get_meta('_avpr_c2c_receipt');
	$st = $order->get_status();
	echo '<section class="avpr-c2c" style="border:2px solid #2a0d86;border-radius:16px;padding:20px;margin:24px 0;background:#faf7f2">';
	echo '<h3 style="margin-top:0;color:#2a0d86">پرداخت کارت به کارت</h3>';
	if (in_array($st, ['processing', 'completed'], true)) {
		echo '<p style="color:#147a2e;font-weight:700">✔ پرداخت شما تایید شد و سفارش در حال آماده‌سازی است.</p></section>'; return;
	}
	echo '<p>مبلغ <strong style="font-size:1.2em">' . esc_html(avpr_c2c_fa(number_format($toman))) . ' تومان</strong> را به کارت زیر واریز کنید:</p>';
	if ($card) {
		echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0"><span dir="ltr" style="font-size:1.35em;font-weight:800;letter-spacing:1px;background:#fff;border:1px solid #ddd;border-radius:10px;padding:8px 14px">' . esc_html(avpr_c2c_card_fmt($card)) . '</span>'
			. '<button type="button" class="button" onclick="navigator.clipboard.writeText(\'' . esc_js(avpr_c2c_digits($card)) . '\');this.textContent=\'کپی شد\'">کپی شماره کارت</button></div>';
	} else {
		echo '<p style="color:#c8102e">شماره کارت هنوز در تنظیمات ثبت نشده است.</p>';
	}
	$who = trim($holder . ($bank ? ' — بانک ' . $bank : ''));
	if ($who) echo '<p>به نام: <strong>' . esc_html($who) . '</strong></p>';
	if ($sheba) echo '<p>شبا: <span dir="ltr">' . esc_html($sheba) . '</span></p>';
	echo '<p style="font-size:.95em;color:#555">انتقال را با اپ همراه‌بانک خودتان یا اپ‌های پرداخت مثل <a href="https://cafebazaar.ir/app/com.adpdigital.mbs.ayande" target="_blank" rel="noopener">همراه کارت</a> انجام دهید.'
		. ($phone ? ' برای هماهنگی پرداخت: <a href="tel:' . esc_attr(avpr_c2c_digits($phone)) . '" dir="ltr">' . esc_html($phone) . '</a>' : '') . '</p>';

	if ($r) {
		echo '<div style="background:#fff;border-radius:10px;padding:12px;margin-top:12px"><strong>رسید شما ثبت شد</strong> (شماره پیگیری ' . esc_html(avpr_c2c_fa($r['ref'])) . '). پس از تایید، سفارش ارسال می‌شود و ایمیل تایید برایتان می‌آید.</div></section>';
		return;
	}
	$url = esc_url(add_query_arg([]));
	?>
	<form method="post" enctype="multipart/form-data" action="<?php echo $url; ?>" style="margin-top:16px;display:grid;gap:10px;max-width:520px">
		<h4 style="margin:0">ثبت رسید پرداخت</h4>
		<?php wp_nonce_field('avpr_c2c_receipt_' . $order->get_id(), 'avpr_c2c_nonce'); ?>
		<input type="hidden" name="avpr_c2c_order" value="<?php echo (int) $order->get_id(); ?>">
		<input type="hidden" name="avpr_c2c_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
		<label>شماره پیگیری / شماره مرجع تراکنش *<input type="text" name="ref" required inputmode="numeric" style="width:100%"></label>
		<label>۴ رقم آخر کارتی که از آن پرداخت کردید *<input type="text" name="last4" required maxlength="4" inputmode="numeric" style="width:100%"></label>
		<label>مبلغ واریزی (تومان) *<input type="text" name="amount" required inputmode="numeric" value="<?php echo esc_attr($toman); ?>" style="width:100%"></label>
		<label>تاریخ و ساعت واریز<input type="text" name="paid_at" placeholder="مثلاً ۱۴۰۵/۰۹/۰۶ ساعت ۱۸:۳۰" style="width:100%"></label>
		<label>تصویر رسید (عکس یا اسکرین‌شات)<input type="file" name="receipt" accept="image/*,.pdf"></label>
		<button type="submit" class="button alt" style="background:#2a0d86;color:#fff">ثبت رسید</button>
	</form></section>
	<?php
}
add_action('woocommerce_thankyou_avpr_c2c', function ($id) { avpr_c2c_box(wc_get_order($id)); }, 5);
add_action('woocommerce_order_details_before_order_table', function ($order) {
	if (is_wc_endpoint_url('order-received')) return; // thank-you page already shows it
	avpr_c2c_box($order);
});

/* ================= handle receipt submission ================= */
add_action('template_redirect', function () {
	if (empty($_POST['avpr_c2c_order'])) return;
	$oid = (int) $_POST['avpr_c2c_order'];
	$order = wc_get_order($oid);
	if (!$order || $order->get_payment_method() !== 'avpr_c2c') return;
	if (!wp_verify_nonce($_POST['avpr_c2c_nonce'] ?? '', 'avpr_c2c_receipt_' . $oid)) return;
	if (!hash_equals($order->get_order_key(), (string) ($_POST['avpr_c2c_key'] ?? ''))) return;
	if ($order->get_meta('_avpr_c2c_receipt')) return;

	$r = [
		'ref' => substr(avpr_c2c_digits($_POST['ref'] ?? ''), 0, 30),
		'last4' => substr(avpr_c2c_digits($_POST['last4'] ?? ''), -4),
		'amount_toman' => (int) avpr_c2c_digits($_POST['amount'] ?? ''),
		'paid_at' => sanitize_text_field(avpr_c2c_en($_POST['paid_at'] ?? '')),
		'submitted' => time(),
		'file' => '',
	];
	if ($r['ref'] === '' || strlen($r['last4']) !== 4 || !$r['amount_toman']) {
		wc_add_notice('لطفاً شماره پیگیری، ۴ رقم آخر کارت و مبلغ را کامل وارد کنید.', 'error'); return;
	}
	if (!empty($_FILES['receipt']['name']) && empty($_FILES['receipt']['error'])) {
		$ok = wp_check_filetype_and_ext($_FILES['receipt']['tmp_name'], $_FILES['receipt']['name']);
		if ($ok['ext'] && in_array($ok['ext'], ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true) && $_FILES['receipt']['size'] < 8 * 1048576) {
			$up = wp_upload_dir(); $dir = $up['basedir'] . '/avpr-receipts';
			if (!is_dir($dir)) { wp_mkdir_p($dir); file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); file_put_contents($dir . '/index.html', ''); }
			$name = $oid . '-' . wp_generate_password(12, false) . '.' . $ok['ext'];
			if (move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . '/' . $name)) $r['file'] = $name;
		}
	}
	$order->update_meta_data('_avpr_c2c_receipt', $r);
	$checks = avpr_c2c_check($order, $r);
	$order->update_meta_data('_avpr_c2c_checks', $checks);
	$order->save();
	$order->add_order_note('رسید کارت به کارت ثبت شد: پیگیری ' . $r['ref'] . '، کارت ****' . $r['last4'] . '، مبلغ ' . number_format($r['amount_toman']) . ' تومان. ' . avpr_c2c_checks_text($checks));
	$matched = avpr_c2c_try_sms_match($order);
	$order = wc_get_order($oid);
	$ok = $checks['amount'] === 'ok' && $checks['dup'] === 'ok' && $checks['file'] === 'ok';
	if (!$matched && $ok && $order->has_status('on-hold') && avpr_c2c_opt('instant_digital', 'yes') === 'yes' && avpr_c2c_only_digital($order)) {
		$order->update_meta_data('_avpr_c2c_provisional', 1); $order->save();
		$order->update_status('processing', 'تایید موقت بر اساس رسید (سفارش فایل): لینک دانلود فعال شد. پیامک واریز بانک هنوز تطبیق داده نشده؛ واریز را در حساب بررسی کنید.');
	}
	$state = $order->has_status('processing') ? ($order->get_meta('_avpr_c2c_provisional') ? 'تایید موقت (فایل تحویل شد؛ واریز را در حساب بررسی کنید)' : 'تایید شد') : 'در انتظار تایید شما';
	avpr_c2c_mail_later(avpr_c2c_admin_emails(), 'رسید کارت به کارت — سفارش #' . $order->get_order_number() . ' — ' . $state,
		'مشتری رسید پرداخت را ثبت کرد.' . "\n\nوضعیت: " . $state . "\nبررسی خودکار: " . avpr_c2c_checks_text($checks) . "\nشماره پیگیری: " . $r['ref'] . ' | کارت ****' . $r['last4'] . ' | ' . number_format($r['amount_toman']) . " تومان\n\nمدیریت سفارش:\n" . $order->get_edit_order_url());
	wc_add_notice('رسید شما ثبت شد. پس از تایید، سفارش ارسال می‌شود.', 'success');
	wp_safe_redirect(add_query_arg([], wp_get_referer() ?: $order->get_view_order_url())); exit;
});

/** queue an email to be sent after the response has been delivered to the customer */
function avpr_c2c_mail_later($to, $subject, $body) {
	static $q = null;
	if ($q === null) {
		$q = [];
		add_action('shutdown', function () use (&$q) {
			if (!$q) return;
			if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
			foreach ($q as $m) wp_mail($m[0], $m[1], $m[2]);
		}, 20);
	}
	$q[] = [$to, $subject, $body];
}
/** digital-only orders are finished once paid; physical ones still need shipping */
function avpr_c2c_final_status($order) { return avpr_c2c_only_digital($order) ? 'completed' : 'processing'; }
function avpr_c2c_admin_emails() {
	$list = array_filter(array_map('trim', explode(',', (string) avpr_c2c_opt('notify_emails', 'mfakharmanesh@gmail.com'))));
	$list[] = get_option('admin_email');
	return array_values(array_unique(array_filter($list, 'is_email')));
}
function avpr_c2c_only_digital($order) {
	foreach ($order->get_items() as $it) { $p = $it->get_product(); if (!$p || !($p->is_downloadable() || $p->is_virtual())) return false; }
	return count($order->get_items()) > 0;
}
/* new c2c order → notify admins */
add_action('woocommerce_order_status_pending_to_on-hold', function ($id) {
	$o = wc_get_order($id); if (!$o || $o->get_payment_method() !== 'avpr_c2c') return;
	$lines = []; foreach ($o->get_items() as $it) $lines[] = '- ' . $it->get_name() . ' × ' . $it->get_quantity();
	avpr_c2c_mail_later(avpr_c2c_admin_emails(), 'سفارش تازه #' . $o->get_order_number() . ' — فروشگاه AVPR',
		"سفارش تازه با پرداخت کارت به کارت ثبت شد.\n\nمشتری: " . $o->get_formatted_billing_full_name() . ' — ' . $o->get_billing_phone() . ' — ' . $o->get_billing_email() . "\nمبلغ: " . number_format((float) $o->get_total()) . " تومان\n" . implode("\n", $lines) . "\n\nمدیریت سفارش:\n" . $o->get_edit_order_url());
});

/* ================= automatic checks ================= */
function avpr_c2c_check($order, $r) {
	$c = [];
	$need = (int) round(avpr_c2c_order_rial($order) / 10);
	$c['amount'] = ($r['amount_toman'] === $need) ? 'ok' : ($r['amount_toman'] > $need ? 'more' : 'less');
	// duplicate tracking number on other orders
	$dup = 0;
	foreach (wc_get_orders(['limit' => 300, 'payment_method' => 'avpr_c2c', 'exclude' => [$order->get_id()]]) as $o2) {
		if ((string) $o2->get_meta('_avpr_c2c_ref') === (string) $r['ref']) { $dup = $o2->get_id(); break; }
	}
	$c['dup'] = $dup ? 'dup:' . $dup : 'ok';
	$order->update_meta_data('_avpr_c2c_ref', $r['ref']);
	$c['ref_len'] = (strlen($r['ref']) >= 6) ? 'ok' : 'short';
	$c['file'] = $r['file'] ? 'ok' : 'none';
	$c['sms'] = 'waiting';
	return $c;
}
function avpr_c2c_checks_text($c) {
	$t = [];
	$t[] = $c['amount'] === 'ok' ? '✔ مبلغ با سفارش یکی است' : ($c['amount'] === 'more' ? '⚠ مبلغ بیشتر از سفارش است' : '✖ مبلغ کمتر از سفارش است');
	$t[] = $c['dup'] === 'ok' ? '✔ شماره پیگیری تکراری نیست' : '✖ این شماره پیگیری قبلاً در سفارش #' . substr($c['dup'], 4) . ' ثبت شده';
	if ($c['ref_len'] !== 'ok') $t[] = '⚠ شماره پیگیری کوتاه است';
	$t[] = $c['file'] === 'ok' ? '✔ تصویر رسید پیوست شده' : '⚠ تصویر رسید ندارد';
	$t[] = ['waiting' => '… در انتظار پیامک واریز بانک', 'matched' => '✔ با پیامک واریز بانک تطبیق داده شد', 'none' => '—'][$c['sms']] ?? '';
	return implode(' | ', array_filter($t));
}

/* ================= bank SMS matching ================= */
/** Parse an Iranian bank deposit SMS: returns ['rial'=>int,'deposit'=>bool] or null */
function avpr_c2c_parse_sms($text) {
	$t = avpr_c2c_en($text);
	$deposit = (bool) preg_match('/واریز|\+\s*[\d,]+|انتقال\s*به|افزایش|deposit|credit/u', $t) && !preg_match('/برداشت|خرید|(?<![\d])-\s*[\d,]{4,}/u', $t);
	if (!preg_match_all('/(\+?\s*[\d,]{4,})/u', $t, $m)) return null;
	$nums = array_map(function ($x) { return (int) preg_replace('/\D/', '', $x); }, $m[1]);
	// first number after واریز/+ is usually the amount; balance (مانده) comes later
	$amount = 0;
	if (preg_match('/(?:واریز|\+|مبلغ)\D{0,15}([\d,]{4,})/u', $t, $mm)) $amount = (int) preg_replace('/\D/', '', $mm[1]);
	if (!$amount) $amount = $nums[0];
	$unit = preg_match('/ریال/u', $t) ? 'rial' : (preg_match('/تومان/u', $t) ? 'toman' : 'unknown');
	return ['rial' => $unit === 'toman' ? $amount * 10 : $amount, 'deposit' => $deposit, 'unit' => $unit];
}
function avpr_c2c_store_sms($text, $source = 'api') {
	$p = avpr_c2c_parse_sms($text);
	$list = get_option('avpr_c2c_sms', []);
	$list[] = ['t' => time(), 'text' => mb_substr(wp_strip_all_tags($text), 0, 500), 'rial' => $p['rial'] ?? 0, 'deposit' => $p['deposit'] ?? false, 'unit' => $p['unit'] ?? 'unknown', 'used' => 0, 'src' => $source];
	$list = array_slice($list, -300);
	update_option('avpr_c2c_sms', $list, false);
	// try to match every waiting order
	$orders = wc_get_orders(['status' => ['on-hold', 'processing'], 'payment_method' => 'avpr_c2c', 'limit' => 80]);
	foreach ($orders as $o) avpr_c2c_try_sms_match($o);
	return $p;
}
function avpr_c2c_try_sms_match($order) {
	$r = $order->get_meta('_avpr_c2c_receipt'); if (!$r) return false;
	$checks = $order->get_meta('_avpr_c2c_checks') ?: [];
	if (($checks['sms'] ?? '') === 'matched') return true;
	// orders already confirmed by hand (not provisional) must not consume a new SMS
	if (!$order->has_status('on-hold') && !$order->get_meta('_avpr_c2c_provisional')) return false;
	$need = avpr_c2c_order_rial($order);
	$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
	$list = get_option('avpr_c2c_sms', []);
	foreach ($list as $i => $s) {
		$amt_ok = (int) $s['rial'] === $need || (($s['unit'] ?? 'unknown') === 'unknown' && (int) $s['rial'] * 10 === $need);
		if ($s['used'] || !$s['deposit'] || !$amt_ok) continue;
		if ($s['t'] < $created - 600 || $s['t'] > $created + 7 * DAY_IN_SECONDS) continue;
		$list[$i]['used'] = $order->get_id();
		update_option('avpr_c2c_sms', $list, false);
		$checks['sms'] = 'matched';
		$order->update_meta_data('_avpr_c2c_checks', $checks);
		$order->update_meta_data('_avpr_c2c_sms', $s);
		$order->save();
		$order->add_order_note('پیامک واریز بانک با این سفارش جور شد (' . number_format($s['rial']) . ' ریال، ' . wp_date('Y-m-d H:i', $s['t']) . ').');
		if ($order->get_meta('_avpr_c2c_provisional')) {
			$order->delete_meta_data('_avpr_c2c_provisional'); $order->save();
			$fs = avpr_c2c_final_status($order);
			if ($fs === 'completed') $order->update_status('completed', 'واریز با پیامک بانک تأیید شد؛ سفارش فایل تکمیل شد.');
			else $order->add_order_note('تایید موقت قطعی شد: واریز با پیامک بانک تأیید شد.');
			return true;
		}
		$ok = ($checks['amount'] ?? '') === 'ok' && ($checks['dup'] ?? '') === 'ok';
		if ($ok && $order->has_status('on-hold') && avpr_c2c_opt('auto_confirm', 'yes') === 'yes') {
			$order->update_status(avpr_c2c_final_status($order), 'پرداخت کارت به کارت به‌صورت خودکار تایید شد (رسید + پیامک بانک).');
		}
		return true;
	}
	return false;
}
add_action('rest_api_init', function () {
	register_rest_route('avpr-c2c/v1', '/sms', [
		'methods' => ['POST', 'GET'],
		'permission_callback' => '__return_true',
		'callback' => function (WP_REST_Request $req) {
			$token = avpr_c2c_opt('sms_token');
			$text = (string) ($req->get_param('text') ?? $req->get_param('message') ?? $req->get_param('msg') ?? $req->get_param('body') ?? '');
			if ($text === '') { $j = $req->get_json_params(); $text = (string) ($j['text'] ?? $j['message'] ?? $j['body'] ?? ''); }
			if ($text === '') { $raw = (string) $req->get_body(); if ($raw !== '' && strpos($raw, '=') === false) $text = $raw; }
			$tok_ok = $token && hash_equals($token, (string) $req->get_param('token'));
			$hits = get_option('avpr_c2c_hits', []); $hits[] = ['t' => time(), 'method' => $req->get_method(), 'token' => $tok_ok ? 'ok' : 'wrong', 'len' => mb_strlen($text), 'ct' => (string) $req->get_header('content_type'), 'keys' => implode(',', array_keys((array) $req->get_params()))];
			update_option('avpr_c2c_hits', array_slice($hits, -20), false);
			if (!$tok_ok) return new WP_REST_Response(['ok' => false], 403);
			if ($text === '') return new WP_REST_Response(['ok' => false, 'error' => 'empty'], 400);
			$p = avpr_c2c_store_sms($text, 'api');
			return ['ok' => true, 'parsed' => $p];
		},
	]);
});

/* ================= admin: meta box + confirm/reject ================= */
add_action('add_meta_boxes', function () {
	$screens = ['shop_order', 'woocommerce_page_wc-orders'];
	foreach ($screens as $s) add_meta_box('avpr_c2c_box', 'کارت به کارت', 'avpr_c2c_admin_box', $s, 'side', 'high');
});
function avpr_c2c_admin_box($post_or_order) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
	if (!$order || $order->get_payment_method() !== 'avpr_c2c') { echo '<p>این سفارش کارت به کارت نیست.</p>'; return; }
	$r = $order->get_meta('_avpr_c2c_receipt');
	if (!$r) { echo '<p>مشتری هنوز رسید ثبت نکرده است.</p>'; }
	else {
		$c = $order->get_meta('_avpr_c2c_checks') ?: [];
		echo '<p><b>پیگیری:</b> ' . esc_html($r['ref']) . '<br><b>کارت پرداخت‌کننده:</b> ****' . esc_html($r['last4']) . '<br><b>مبلغ اعلامی:</b> ' . esc_html(number_format($r['amount_toman'])) . ' تومان<br><b>زمان اعلامی:</b> ' . esc_html($r['paid_at'] ?: '—') . '</p>';
		echo '<p style="line-height:1.9">' . str_replace(' | ', '<br>', esc_html(avpr_c2c_checks_text($c))) . '</p>';
		if ($r['file']) echo '<p><a class="button" target="_blank" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=avpr_c2c_file&order=' . $order->get_id()), 'avpr_c2c_file')) . '">دیدن تصویر رسید</a></p>';
	}
	if ($order->has_status('on-hold') || $order->get_meta('_avpr_c2c_provisional')) {
		if ($order->get_meta('_avpr_c2c_provisional')) echo '<p style="color:#b26a00"><b>تایید موقت:</b> فایل تحویل شده ولی پیامک واریز هنوز نرسیده. اگر واریز را در حساب دیدید، تایید کنید.</p>';
		$u = function ($a) use ($order) { return esc_url(wp_nonce_url(admin_url('admin-post.php?action=avpr_c2c_' . $a . '&order=' . $order->get_id()), 'avpr_c2c_act')); };
		echo '<p><a class="button button-primary" href="' . $u('confirm') . '">تایید پرداخت و آماده‌سازی سفارش</a></p><p><a class="button" href="' . $u('reject') . '" onclick="return confirm(\'رسید رد شود؟\')">رد رسید</a></p>';
	}
}
add_action('admin_post_avpr_c2c_file', function () {
	if (!current_user_can('edit_shop_orders') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'avpr_c2c_file')) wp_die('دسترسی ندارید');
	$o = wc_get_order((int) $_GET['order']); $r = $o ? $o->get_meta('_avpr_c2c_receipt') : null;
	if (!$r || !$r['file']) wp_die('فایل نیست');
	$p = wp_upload_dir()['basedir'] . '/avpr-receipts/' . basename($r['file']);
	if (!is_file($p)) wp_die('فایل پیدا نشد');
	$t = wp_check_filetype($p);
	header('Content-Type: ' . ($t['type'] ?: 'application/octet-stream')); header('Content-Length: ' . filesize($p)); readfile($p); exit;
});
foreach (['confirm', 'reject'] as $act) {
	add_action('admin_post_avpr_c2c_' . $act, function () use ($act) {
		if (!current_user_can('edit_shop_orders') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'avpr_c2c_act')) wp_die('دسترسی ندارید');
		$o = wc_get_order((int) $_GET['order']);
		if ($o) {
			if ($act === 'confirm') { $o->delete_meta_data('_avpr_c2c_provisional'); $o->save(); $o->update_status(avpr_c2c_final_status($o), 'پرداخت کارت به کارت دستی تایید شد.'); }
			else { $o->delete_meta_data('_avpr_c2c_receipt'); $o->save(); $o->add_order_note('رسید کارت به کارت رد شد؛ مشتری می‌تواند رسید درست را دوباره ثبت کند.', true); }
		}
		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=shop_order')); exit;
	});
}

/* ================= admin page: paste bank SMS manually ================= */
add_action('admin_menu', function () {
	add_submenu_page('woocommerce', 'پیامک‌های بانک', 'پیامک‌های بانک (کارت به کارت)', 'edit_shop_orders', 'avpr-c2c-sms', function () {
		$msg = '';
		if (!empty($_POST['avpr_sms_text']) && check_admin_referer('avpr_c2c_sms')) {
			$p = avpr_c2c_store_sms(wp_unslash($_POST['avpr_sms_text']), 'manual');
			$msg = $p ? 'ثبت شد: ' . number_format($p['rial']) . ' ریال — ' . ($p['deposit'] ? 'واریز' : 'برداشت/نامشخص') : 'مبلغی در متن پیدا نشد.';
		}
		echo '<div class="wrap"><h1>پیامک‌های واریز بانک</h1>';
		if ($msg) echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>';
		echo '<p>متن پیامک واریز را اینجا بچسبانید تا با رسیدهای ثبت‌شده تطبیق داده شود. برای تطبیق خودکار، یک اپ «فوروارد پیامک» روی گوشی‌ای که پیامک بانک را می‌گیرد نصب کنید و پیامک‌های بانک را به این آدرس بفرستد (POST، فیلد text):</p>';
		echo '<p><code dir="ltr">' . esc_html(rest_url('avpr-c2c/v1/sms') . '?token=' . avpr_c2c_opt('sms_token')) . '</code></p>';
		echo '<form method="post">'; wp_nonce_field('avpr_c2c_sms');
		echo '<textarea name="avpr_sms_text" rows="5" style="width:100%;max-width:640px"></textarea><p><button class="button button-primary">ثبت و تطبیق</button></p></form>';
		echo '<h2>آخرین درخواست‌های رسیده از گوشی (برای عیب‌یابی شورتکات)</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>روش</th><th>کلید</th><th>طول متن</th><th>نوع</th><th>فیلدها</th></tr></thead><tbody>';
		foreach (array_reverse(get_option('avpr_c2c_hits', [])) as $x) echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i:s', $x['t'])) . '</td><td>' . esc_html($x['method']) . '</td><td>' . esc_html($x['token']) . '</td><td>' . (int) $x['len'] . '</td><td>' . esc_html($x['ct']) . '</td><td>' . esc_html($x['keys']) . '</td></tr>';
		echo '</tbody></table>';
		echo '<h2>آخرین پیامک‌ها</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>مبلغ (ریال)</th><th>نوع</th><th>سفارش</th><th>متن</th></tr></thead><tbody>';
		foreach (array_reverse(get_option('avpr_c2c_sms', [])) as $s) {
			echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i', $s['t'])) . '</td><td>' . esc_html(number_format($s['rial'])) . '</td><td>' . ($s['deposit'] ? 'واریز' : '—') . '</td><td>' . ($s['used'] ? '<a href="' . esc_url(admin_url('post.php?post=' . $s['used'] . '&action=edit')) . '">#' . (int) $s['used'] . '</a>' : '') . '</td><td style="max-width:420px">' . esc_html($s['text']) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	});
});

/* emails: show card info in the on-hold email */
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain) {
	if ($sent_to_admin || $order->get_payment_method() !== 'avpr_c2c' || !$order->has_status('on-hold')) return;
	$card = avpr_c2c_opt('card_number');
	$t = 'لطفاً مبلغ ' . number_format((int) round(avpr_c2c_order_rial($order) / 10)) . ' تومان را به کارت ' . avpr_c2c_card_fmt($card) . ' (' . avpr_c2c_opt('card_holder') . ') واریز کنید و رسید را از این لینک ثبت کنید: ' . $order->get_checkout_order_received_url();
	echo $plain ? $t . "\n\n" : '<p style="border:2px solid #2a0d86;padding:12px;border-radius:8px">' . esc_html($t) . '</p>';
}, 5, 3);
