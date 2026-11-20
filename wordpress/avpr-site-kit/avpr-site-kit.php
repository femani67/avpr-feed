<?php
/**
 * Plugin Name: AVPR Site Kit
 * Description: سفارشی‌سازی‌های avpr.ir که نباید با به‌روزرسانی پوسته پاک شوند: آیکون و شمارندهٔ سبد خرید فروشگاه، تیتر کوتاه اسلایدر، بخش «گفت‌وگو و یادداشت» صفحهٔ اول، پنهان‌سازی کادر نویسنده. هر بخش فقط وقتی فعال می‌شود که پوسته خودش آن را نداشته باشد (بعد از به‌روزرسانی پوسته).
 * Version: 1.0.0
 * Author: AVPR
 * Requires at least: 6.0
 */
if (!defined('ABSPATH')) exit;

final class AVPR_Site_Kit {
    const CART_URL = 'https://avpr.ir/shop/cart/';
    const IV_CAT = 294;
    private static $slider_post = 0;

    static function init() {
        add_action('wp_head', [__CLASS__, 'css'], 99);
        add_action('wp_footer', [__CLASS__, 'cart'], 20);
        add_action('wp_footer', [__CLASS__, 'interviews'], 21);
        add_action('begin_fetch_post_thumbnail_html', [__CLASS__, 'thumb_begin'], 10, 3);
        add_filter('the_title', [__CLASS__, 'slider_title'], 20, 2);
    }

    /** ?avpr_kit_force=1 (admins only) simulates an unpatched theme, for testing. */
    private static function forced() {
        return isset($_GET['avpr_kit_force']) && current_user_can('manage_options');
    }

    /** true if the theme file still contains our edit ($needle). */
    private static function theme_has($file, $needle) {
        static $cache = [];
        if (self::forced()) return false;
        $k = $file . '|' . $needle;
        if (!isset($cache[$k])) {
            $p = get_template_directory() . '/' . $file;
            $cache[$k] = is_readable($p) && strpos((string) file_get_contents($p), $needle) !== false;
        }
        return $cache[$k];
    }

    static function css() {
        echo "<style id=\"avpr-kit-css\">.author_box{display:none!important}"
           . ".avpr-cart{position:relative}.avpr-cart-count{position:absolute;top:-6px;left:-6px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:#e31e24;color:#fff;font-size:11px;font-weight:700;line-height:19px;text-align:center;box-shadow:0 0 0 2px #fff;display:none}.avpr-cart-count.on{display:block}"
           . "</style>\n";
    }

    /* ---------- 1) Shop cart icon + live item count ---------- */
    static function cart() {
        if (is_admin()) return;
        $has_link = self::theme_has('inc/theme/header/comfort.php', 'avpr-cart');
        $has_sync = self::theme_has('inc/theme/header/comfort.php', 'avpr-cart-sync');
        if ($has_link && $has_sync) return;
        $url = esc_url(self::CART_URL);
        $force = self::forced() ? 'true' : 'false';
        ?>
<script id="avpr-kit-cart">(function(){
var FORCE=<?php echo $force; ?>;
function ensure(){
  if(FORCE){document.querySelectorAll('.avpr-cart').forEach(function(a){a.remove()});FORCE=false;}
  if(document.querySelector('.avpr-cart'))return;
  var box=document.querySelector('.header__comfort .leftSide')||document.querySelector('header .leftSide');
  if(!box)return;
  var a=document.createElement('a');a.className='avpr-cart';a.href='<?php echo $url; ?>';a.title='سبد خرید فروشگاه AVPR';a.setAttribute('aria-label','سبد خرید');
  a.innerHTML='<i class="bi bi-cart3"></i>';
  a.style.cssText='flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;background:rgba(255,255,255,.14);color:#fff;font-size:19px;text-decoration:none';
  box.insertBefore(a,box.firstChild);
}
var fa=function(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d]})};
function upd(){
  ensure();
  fetch('/shop/wp-json/wc/store/v1/cart',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null}).then(function(c){if(!c)return;var n=c.items_count||0;
    document.querySelectorAll('.avpr-cart').forEach(function(a){var b=a.querySelector('.avpr-cart-count');if(!b){b=document.createElement('span');b.className='avpr-cart-count';b.setAttribute('aria-live','polite');a.appendChild(b);}
      b.textContent=fa(n);b.classList.toggle('on',n>0);
      var t=c.totals?parseInt(c.totals.total_price,10)/Math.pow(10,c.totals.currency_minor_unit||0):0;
      a.title=n>0?('سبد خرید: '+fa(n)+' کالا — '+fa(t.toLocaleString('en-US'))+' تومان'):'سبد خرید فروشگاه AVPR';});
  }).catch(function(){});
}
<?php if (!$has_sync || self::forced()) : ?>
if(document.readyState!=='loading')upd();else document.addEventListener('DOMContentLoaded',upd);
window.addEventListener('pageshow',upd);document.addEventListener('visibilitychange',function(){if(!document.hidden)upd()});
<?php else : ?>
ensure();
<?php endif; ?>
})();</script>
        <?php
    }

    /* ---------- 2) Short slider titles ---------- */
    static function thumb_begin($post_id, $thumb_id, $size) {
        if ($size === 'slider_image' && !is_admin() && !self::theme_has('inc/theme/index/slider.php', 'avpr_short_title')) {
            self::$slider_post = (int) $post_id;
        }
    }
    static function slider_title($title, $post_id = 0) {
        if (!self::$slider_post || (int) $post_id !== self::$slider_post) return $title;
        self::$slider_post = 0; // only the next title after the slider image
        $short = trim((string) get_post_meta($post_id, 'avpr_short_title', true));
        if ($short === '') {
            $parts = preg_split('/(?<=؟)|؛/u', $title);
            $short = trim($parts[0]);
        }
        return $short !== '' ? $short : $title;
    }

    /* ---------- 3) Homepage «گفت‌وگو و یادداشت» (category 294) ---------- */
    static function interviews() {
        if (!is_front_page() || self::theme_has('index.php', 'avpr_iv')) return;
        $q = new WP_Query(['post_type' => 'post', 'posts_per_page' => 4, 'cat' => self::IV_CAT, 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
        if (!$q->have_posts()) return;
        echo '<template id="avpr-kit-iv"><section class="content mt-3 avpr-kit-iv"><div class="posts">'
           . '<header class="posts_header posts_header2 d-flex justify-content-between align-items-center"><h3>گفت‌وگو و یادداشت</h3>'
           . '<a href="' . esc_url(get_category_link(self::IV_CAT)) . '">مشاهده همه</a></header><div class="lastest_news">';
        while ($q->have_posts()) { $q->the_post();
            $img = has_post_thumbnail() ? get_the_post_thumbnail(null, 'post_image') : '<img src="' . esc_url(get_template_directory_uri() . '/images/no-img.jpg') . '" alt="">';
            $before = function_exists('get_field') ? (string) get_field('beforeTitle') : '';
            $ex = function_exists('limited_excerpt') ? limited_excerpt(get_the_excerpt(), 350) : esc_html(wp_trim_words(get_the_excerpt(), 40));
            echo '<a class="row" href="' . esc_url(get_permalink()) . '">'
               . '<div class="col-xxl-4 col-xl-4 col-lg-4 col-md-4 col-sm-4"><div class="thumbnail video_image">' . $img . '</div></div>'
               . '<div class="col-xxl-8 col-xl-8 col-lg-8 col-md-8 col-sm-8"><div class="latest_news_inner">'
               . '<span>' . esc_html($before) . '</span><h5>' . esc_html(get_the_title()) . '</h5><p>' . $ex . '</p></div></div></a>';
        }
        wp_reset_postdata();
        $force = self::forced() ? 'true' : 'false';
        echo '</div></div></section></template>';
        ?>
<script id="avpr-kit-iv-js">(function(){var t=document.getElementById('avpr-kit-iv');if(!t)return;
var heads=[].slice.call(document.querySelectorAll('section .posts_header h3'));
var old=heads.filter(function(h){return h.textContent.trim()==='گفت‌وگو و یادداشت'&&!h.closest('.avpr-kit-iv')});
if(old.length){if(<?php echo $force; ?>){old.forEach(function(h){h.closest('section').remove()})}else return;}
var pag=document.querySelector('.pagination_');var anchor=pag?pag.closest('section'):null;
if(!anchor){var ln=document.querySelectorAll('section .lastest_news');anchor=ln.length?ln[ln.length-1].closest('section'):null;}
if(!anchor)return;anchor.parentNode.insertBefore(t.content.cloneNode(true),anchor.nextSibling);})();</script>
        <?php
    }
}
AVPR_Site_Kit::init();
