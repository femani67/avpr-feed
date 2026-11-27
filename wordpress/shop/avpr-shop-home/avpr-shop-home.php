<?php
/**
 * Plugin Name: AVPR صفحهٔ اول فروشگاه
 * Description: اسلایدر قابل مدیریت صفحهٔ اول فروشگاه (منوی «اسلایدهای فروشگاه»)، و نمایش جداگانهٔ قیمت هر نوع نسخه (مثلاً PDF و چاپی) به‌جای بازهٔ قیمت.
 * Version: 1.0.2
 * Author: AVPR
 */
if (!defined('ABSPATH')) exit;

/* ---------- Slides: custom post type managed from wp-admin ---------- */
add_action('init', function () {
	register_post_type('avpr_slide', [
		'labels' => ['name' => 'اسلایدهای فروشگاه', 'singular_name' => 'اسلاید', 'add_new' => 'افزودن اسلاید', 'add_new_item' => 'افزودن اسلاید تازه', 'edit_item' => 'ویرایش اسلاید', 'all_items' => 'همهٔ اسلایدها', 'featured_image' => 'تصویر اسلاید', 'set_featured_image' => 'انتخاب تصویر اسلاید'],
		'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_position' => 56, 'menu_icon' => 'dashicons-images-alt2',
		'supports' => ['title', 'excerpt', 'thumbnail', 'page-attributes', 'custom-fields'], 'show_in_rest' => true,
	]);
	foreach (['_avpr_slide_link', '_avpr_slide_button', '_avpr_slide_badge', '_avpr_slide_bg'] as $k)
		register_post_meta('avpr_slide', $k, ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => function () { return current_user_can('edit_posts'); }]);
});
add_action('add_meta_boxes', function () {
	add_meta_box('avpr_slide_box', 'تنظیمات اسلاید', function ($post) {
		wp_nonce_field('avpr_slide', 'avpr_slide_nonce');
		$f = function ($k, $label, $ph = '') use ($post) {
			echo '<p><label><strong>' . esc_html($label) . '</strong><br><input type="text" style="width:100%" name="' . esc_attr($k) . '" value="' . esc_attr(get_post_meta($post->ID, $k, true)) . '" placeholder="' . esc_attr($ph) . '"></label></p>';
		};
		echo '<p>عنوان اسلاید = عنوان بالای صفحه. متن کوتاه = «چکیده». تصویر = «تصویر اسلاید» در ستون کناری. ترتیب نمایش = «ترتیب» در کادر «ویژگی‌های صفحه».</p>';
		$f('_avpr_slide_badge', 'برچسب کوچک بالای عنوان', 'مثلاً: پیش‌فروش');
		$f('_avpr_slide_link', 'لینک دکمه', 'مثلاً: /shop/faakhte/');
		$f('_avpr_slide_button', 'متن دکمه', 'مثلاً: مشاهده و خرید');
		$f('_avpr_slide_bg', 'رنگ زمینه (اختیاری)', '#f2e9dc');
	}, 'avpr_slide', 'normal', 'high');
});
add_action('save_post_avpr_slide', function ($id) {
	if (!isset($_POST['avpr_slide_nonce']) || !wp_verify_nonce($_POST['avpr_slide_nonce'], 'avpr_slide') || !current_user_can('edit_post', $id)) return;
	foreach (['_avpr_slide_link', '_avpr_slide_button', '_avpr_slide_badge', '_avpr_slide_bg'] as $k)
		if (isset($_POST[$k])) update_post_meta($id, $k, sanitize_text_field(wp_unslash($_POST[$k])));
});

add_shortcode('avpr_slider', function () {
	$q = get_posts(['post_type' => 'avpr_slide', 'numberposts' => 10, 'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'], 'post_status' => 'publish']);
	if (!$q) return '';
	ob_start(); ?>
	<div class="avpr-sl" dir="rtl">
		<div class="avpr-sl-track">
		<?php foreach ($q as $i => $s):
			$img = get_the_post_thumbnail_url($s, 'large'); $link = get_post_meta($s->ID, '_avpr_slide_link', true);
			$btn = get_post_meta($s->ID, '_avpr_slide_button', true) ?: 'مشاهده و خرید'; $badge = get_post_meta($s->ID, '_avpr_slide_badge', true);
			$bg = get_post_meta($s->ID, '_avpr_slide_bg', true) ?: '#f2e9dc'; ?>
			<div class="avpr-sl-item<?php echo $i ? '' : ' on'; ?>" style="background:<?php echo esc_attr($bg); ?>">
				<div class="t">
					<?php if ($badge) echo '<span class="b">' . esc_html($badge) . '</span>'; ?>
					<h2><?php echo esc_html(get_the_title($s)); ?></h2>
					<?php if ($s->post_excerpt) echo '<p>' . esc_html($s->post_excerpt) . '</p>'; ?>
					<?php if ($link) echo '<a class="btn" href="' . esc_url($link) . '">' . esc_html($btn) . '</a>'; ?>
				</div>
				<?php if ($img) echo '<a class="i" href="' . esc_url($link ?: '#') . '"><img src="' . esc_url($img) . '" alt="' . esc_attr(get_the_title($s)) . '" loading="' . ($i ? 'lazy' : 'eager') . '"></a>'; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php if (count($q) > 1): ?><div class="avpr-sl-dots"><?php foreach ($q as $i => $s) echo '<button type="button" aria-label="اسلاید ' . ($i + 1) . '"' . ($i ? '' : ' class="on"') . '></button>'; ?></div><?php endif; ?>
	</div>
	<style>
	.avpr-sl{position:relative;margin:10px 0 40px}.avpr-sl-item{display:none;border-radius:28px;padding:40px;gap:32px;align-items:center;min-height:420px}.avpr-sl-item.on{display:flex;animation:avprf .6s}
	@keyframes avprf{from{opacity:.2}to{opacity:1}}
	.avpr-sl-item .t{flex:1 1 50%}.avpr-sl-item .i{flex:1 1 50%;text-align:center}.avpr-sl-item img{max-width:100%;max-height:420px;border-radius:18px;box-shadow:0 18px 40px rgba(20,20,60,.18)}
	.avpr-sl h2{font-size:34px;line-height:1.5;color:#1c1a5e;margin:12px 0}.avpr-sl p{font-size:17px;line-height:2;color:#4a4f60}
	.avpr-sl .b{display:inline-block;background:#c8102e;color:#fff;border-radius:8px;padding:3px 12px;font-size:13px;font-weight:700}
	.avpr-sl .btn{display:inline-block;margin-top:14px;background:#1c1a8e;color:#fff!important;border-radius:12px;padding:12px 28px;font-weight:700}
	.avpr-sl-dots{display:flex;gap:8px;justify-content:center;margin-top:14px}.avpr-sl-dots button{width:10px!important;height:10px!important;min-height:0!important;line-height:0!important;border-radius:50%!important;border:0!important;background:#c9c9d8!important;padding:0!important}.avpr-sl-dots button.on{background:#1c1a8e!important;width:26px!important;border-radius:6px!important}
	@media(max-width:767px){.avpr-sl-item.on{flex-direction:column-reverse;padding:24px;min-height:0}.avpr-sl h2{font-size:24px}}
	</style>
	<script>(function(){var r=document.currentScript.previousElementSibling.previousElementSibling;if(!r||!r.classList.contains('avpr-sl'))r=document.querySelector('.avpr-sl');var it=r.querySelectorAll('.avpr-sl-item'),dt=r.querySelectorAll('.avpr-sl-dots button'),c=0,t;function go(n){it[c].classList.remove('on');dt[c]&&dt[c].classList.remove('on');c=(n+it.length)%it.length;it[c].classList.add('on');dt[c]&&dt[c].classList.add('on')}function auto(){clearInterval(t);t=setInterval(function(){go(c+1)},6000)}dt.forEach(function(b,i){b.onclick=function(){go(i);auto()}});if(it.length>1)auto()})();</script>
	<?php return ob_get_clean();
});

/* ---------- Show each edition's price instead of a price range ---------- */
add_filter('woocommerce_variable_price_html', function ($html, $product) {
	$vars = $product->get_available_variations('objects');
	if (!$vars || count($vars) > 4) return $html;
	$parts = [];
	foreach ($vars as $v) {
		$label = implode(' ', array_map(function ($val, $key) use ($v) {
			$tax = str_replace('attribute_', '', $key);
			if (taxonomy_exists($tax)) { $t = get_term_by('slug', $val, $tax); return $t ? $t->name : $val; }
			return $val;
		}, $v->get_attributes(), array_keys($v->get_attributes())));
		$parts[] = '<span class="avpr-ed"><span class="avpr-ed-l">' . esc_html($label) . ':</span> ' . $v->get_price_html() . '</span>';
	}
	return '<span class="avpr-eds">' . implode('<span class="avpr-ed-sep"> | </span>', $parts) . '</span>';
}, 20, 2);
add_action('wp_head', function () {
	echo '<style>.avpr-eds{display:inline-flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;justify-content:center}.avpr-ed-l{font-size:.85em;color:#555;font-weight:500}.avpr-ed-sep{color:#bbb}</style>';
});

/* ---------- Branded sender for WordPress system emails (password reset, new account) ---------- */
add_filter('wp_mail_from_name', function ($n) { return ($n === 'WordPress' || $n === '') ? 'فروشگاه AVPR' : $n; });
add_filter('wp_mail_from', function ($e) { return (strpos($e, 'wordpress@') === 0) ? 'info@avpr.ir' : $e; });
