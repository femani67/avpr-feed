<?php
/**
 * Plugin Name: AVPR Site Kit
 * Description: سفارشی‌سازی‌های avpr.ir که نباید با به‌روزرسانی پوسته پاک شوند: آیکون و شمارندهٔ سبد خرید فروشگاه، تیتر کوتاه اسلایدر، بخش «گفت‌وگو و یادداشت» صفحهٔ اول، پنهان‌سازی کادر نویسنده، فونت فرهنگ برای کل سایت، لینکدین و دکمه‌های ارتباط با ادمین، صفحهٔ تبلیغ در فاخته. هر بخش فقط وقتی فعال می‌شود که پوسته خودش آن را نداشته باشد (بعد از به‌روزرسانی پوسته).
 * Version: 1.3.0
 * Author: AVPR
 * Requires at least: 6.0
 */
if (!defined('ABSPATH')) exit;

final class AVPR_Site_Kit {
    const CART_URL = 'https://avpr.ir/shop/cart/';
    const IV_CAT = 294;
    private static $slider_post = 0;

    static function init() {
        add_action('wp_head', [__CLASS__, 'verification'], 1);
        add_action('wp_head', [__CLASS__, 'css'], 99);
        add_action('wp_head', [__CLASS__, 'fonts'], 100);
        add_action('wp_footer', [__CLASS__, 'cart'], 20);
        add_action('wp_footer', [__CLASS__, 'interviews'], 21);
        add_action('begin_fetch_post_thumbnail_html', [__CLASS__, 'thumb_begin'], 10, 3);
        add_filter('the_title', [__CLASS__, 'slider_title'], 20, 2);
        add_action('wp_footer', [__CLASS__, 'social'], 22);
        add_action('wp_head', [__CLASS__, 'ads_css'], 101);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_shortcode('avpr_admin_contact', [__CLASS__, 'sc_admin']);
    }

    /* ---------- 5) Contact links (Settings → General → «راه‌های ارتباطی AVPR») ---------- */
    static $defaults = [
        'avpr_linkedin'       => ['لینکدین AVPR', 'https://www.linkedin.com/company/aviation-public-relations'],
        'avpr_admin_bale'     => ['بلهٔ ادمین', 'https://ble.ir/ma_fakharmanesh'],
        'avpr_admin_telegram' => ['تلگرام ادمین', 'https://t.me/ma_fakharmanesh'],
    ];
    static function link($k) {
        $v = trim((string) get_option($k, ''));
        return $v !== '' ? $v : self::$defaults[$k][1];
    }
    static function settings() {
        add_settings_section('avpr_contacts', 'راه‌های ارتباطی AVPR', function () {
            echo '<p>تلگرام، اینستاگرام و بلهٔ کانال از «تنظیمات پوسته ← شبکه‌های اجتماعی» می‌آیند. این‌ها لینکدین و دکمه‌های «پیام به ادمین» (فوتر، تماس با ما، صفحهٔ تبلیغ در فاخته) هستند. نشانی کامل بنویسید.</p>';
        }, 'general');
        foreach (self::$defaults as $k => $d) {
            register_setting('general', $k, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
            add_settings_field($k, $d[0], function () use ($k, $d) {
                printf('<input type="url" class="regular-text ltr" dir="ltr" name="%s" value="%s" placeholder="%s">', esc_attr($k), esc_attr(get_option($k, '')), esc_attr($d[1]));
            }, 'general', 'avpr_contacts');
        }
    }
    static function sc_admin() {
        return '<div class="avpr-admin-btns"><a class="avpr-btn bale" href="' . esc_url(self::link('avpr_admin_bale')) . '" target="_blank" rel="noopener"><i class="efa efa-bale"></i>پیام در بله</a>'
             . '<a class="avpr-btn tg" href="' . esc_url(self::link('avpr_admin_telegram')) . '" target="_blank" rel="noopener"><i class="bi bi-telegram"></i>پیام در تلگرام</a></div>';
    }
    static function social() {
        if (is_admin()) return;
        $li = esc_url(self::link('avpr_linkedin'));
        $admin = self::sc_admin();
        ?>
<script id="avpr-kit-social">(function(){
var LI='<?php echo $li; ?>';
function icon(a){var i=a.querySelector('i');if(i)i.className='bi bi-linkedin';}
function run(){
  var hs=document.querySelector('.header__comfort--social ul.social');
  if(hs&&!hs.querySelector('.avpr-li')){var src=hs.querySelector('li');if(src){var li=src.cloneNode(true);li.classList.add('avpr-li');var a=li.querySelector('a');a.href=LI;a.className='';a.target='_blank';a.rel='noopener';a.setAttribute('aria-label','لینکدین');icon(a);hs.appendChild(li);}}
  document.querySelectorAll('footer .social .body').forEach(function(b){
    if(b.querySelector('.linkedin'))return;var src=b.querySelector('a');if(!src)return;
    var a=src.cloneNode(true);a.className='linkedin';a.href=LI;a.target='_blank';a.rel='noopener';a.setAttribute('aria-label','لینکدین');icon(a);a.style.background='#0a66c2';b.appendChild(a);
    if(!b.parentNode.querySelector('.avpr-foot-admin')){var d=document.createElement('div');d.className='avpr-foot-admin';d.innerHTML='<span>ارتباط با ادمین</span>'+<?php echo wp_json_encode($admin); ?>;b.parentNode.appendChild(d);}
  });
  document.querySelectorAll('ul.social a, footer .social .body a').forEach(function(a){if(/^https?:/.test(a.getAttribute('href')||'')){a.target='_blank';a.rel='noopener';}});
}
if(document.readyState!=='loading')run();else document.addEventListener('DOMContentLoaded',run);
})();</script>
        <?php
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
           . ".avpr-admin-btns{display:flex;flex-wrap:wrap;gap:10px;margin:6px 0 18px}.avpr-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:12px;color:#fff!important;text-decoration:none!important;font-weight:700!important;font-size:15px;line-height:1.4;transition:transform .15s,box-shadow .15s}.avpr-btn:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(0,0,0,.15)}.avpr-btn i{font-size:18px}.avpr-btn.bale{background:#1fb68c}.avpr-btn.tg{background:#229ed9}"
           . ".avpr-foot-admin{margin-top:16px}.avpr-foot-admin>span{display:block;margin-bottom:8px;opacity:.85;font-size:14px}.avpr-foot-admin .avpr-admin-btns{margin:0}.avpr-foot-admin .avpr-btn{padding:8px 14px;font-size:13.5px;border-radius:10px}"
           . "</style>\n";
    }



    /* ---------- 6) «تبلیغ در فاخته» landing page styles (page slug faakhte-ads) ---------- */
    static function ads_css() {
        if (!is_page('faakhte-ads')) return;
        echo '<style id="avpr-kit-ads">'
        . '.avpr-ads{--l:#1F3563;--g:#C9962B;--p:#F3E9DA;--c:#FBF6EE;--i:#2A2320;--m:#6B5F51;color:var(--i);font-size:16px;line-height:2}'
        . '.avpr-ads *{box-sizing:border-box}.avpr-ads h2{font-size:24px!important;font-weight:900!important;color:var(--l)!important;margin:0 0 14px!important;line-height:1.6!important}'
        . '.avpr-ads section{margin:0 0 34px}'
        . '.ads-hero{display:grid;grid-template-columns:1.25fr 1fr;gap:28px;align-items:center;background:linear-gradient(135deg,#1F3563,#2d4d8a);color:#F6EEDD;border-radius:22px;padding:34px 34px 0;overflow:hidden}'
        . '.ads-hero .k{display:inline-block;background:rgba(227,180,81,.18);color:#E9C46A;border-radius:20px;padding:2px 14px;font-size:13.5px;font-weight:700;margin-bottom:10px}'
        . '.ads-hero h1{color:#fff!important;font-size:30px!important;line-height:1.6!important;margin:0 0 12px!important}.ads-hero p{color:#e9e2d4!important;margin:0 0 18px!important;font-size:16px!important}'
        . '.ads-hero .ph{align-self:end;text-align:center}.ads-hero .ph img{width:100%;max-width:300px;border-radius:26px 26px 0 0;box-shadow:0 -6px 30px rgba(0,0,0,.35);display:inline-block}'
        . '.ads-hero .avpr-admin-btns{margin-bottom:30px}'
        . '.ads-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}'
        . '.ads-card{background:var(--c);border:1px solid #E0D0B8;border-radius:18px;padding:20px 22px}.ads-card h3{font-size:18.5px!important;font-weight:900!important;color:var(--l)!important;margin:0 0 6px!important}'
        . '.ads-card p{margin:0 0 8px!important;font-size:15px!important;line-height:1.95!important}.ads-card small{display:block;color:var(--m);font-size:13.5px;font-weight:500!important}'
        . '.ads-card .n{display:inline-flex;width:34px;height:34px;border-radius:10px;background:var(--l);color:#E9C46A;align-items:center;justify-content:center;font-weight:900;margin-bottom:8px}'
        . '.ads-chips{display:flex;flex-wrap:wrap;gap:10px}.ads-chips span{background:#F5E6BF;color:#8A6414;border-radius:22px;padding:6px 16px;font-weight:700;font-size:14.5px}'
        . '.ads-steps{counter-reset:s;display:grid;grid-template-columns:repeat(4,1fr);gap:14px;list-style:none;padding:0!important;margin:0!important}'
        . '.ads-steps li{counter-increment:s;background:var(--c);border:1px solid #E0D0B8;border-radius:16px;padding:16px;font-size:15px;line-height:1.9!important;margin:0!important}'
        . '.ads-steps li:before{content:counter(s,persian);display:block;font-size:26px;font-weight:900;color:var(--g);line-height:1.3}'
        . '.ads-cta{background:var(--p);border-radius:20px;padding:26px;text-align:center}.ads-cta .avpr-admin-btns{justify-content:center;margin:12px 0 0}'
        . '.ads-print{display:grid;grid-template-columns:1fr 1.3fr;gap:20px;align-items:center}.ads-print img{width:100%;border-radius:16px}'
        . '@media(max-width:767px){.ads-hero{grid-template-columns:1fr;padding:24px 20px 0}.ads-hero h1{font-size:24px!important}.ads-grid,.ads-print{grid-template-columns:1fr}.ads-steps{grid-template-columns:1fr 1fr}}'
        . '</style>' . "\n";
    }

    /* ---------- 0) Site-ownership verification meta tags ---------- */
    static function verification() {
        if (!is_front_page()) return;
        echo '<meta name="platform-verification" content="acgmkvfytq" />' . "\n";
    }

    /* ---------- 4) Farhang typeface + article spacing ---------- */
    static function fonts() {
        if (is_admin()) return;
        $u = plugins_url('fonts/', __FILE__); $v = '1.2.0';
        $w = ['Thin'=>100,'ExtraLight'=>200,'Light'=>300,'Regular'=>400,'Medium'=>500,'DemiBold'=>600,'Bold'=>700,'ExtraBold'=>800,'Black'=>900];
        echo '<link rel="preload" href="' . esc_url($u . 'Farhang-Regular.woff2?v=' . $v) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        echo '<link rel="preload" href="' . esc_url($u . 'Farhang-Black.woff2?v=' . $v) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        $css = '';
        foreach ($w as $n => $wt) {
            $css .= "@font-face{font-family:'Farhang';src:url('" . esc_url($u . "Farhang-$n.woff2?v=$v") . "') format('woff2');font-weight:$wt;font-style:normal;font-display:swap}";
        }
        $F = "'Farhang',Tahoma,sans-serif";
        $not = ':not(i):not([class*="icon"]):not([class^="bi"]):not([class*=" bi-"]):not([class*="fa-"]):not(.ab-icon):not(.dashicons)';
        $css .= "body,body :where(h1,h2,h3,h4,h5,h6,p,a,span,li,div,section,header,footer,nav,article,label,input,textarea,select,button,td,th,strong,b,em,small,blockquote,figcaption,time,cite)$not{font-family:$F!important}";
        // base text: Regular
        $css .= "body{font-weight:400}";
        // headings & titles: Black / ExtraBold / Bold
        $css .= "h1,.single h1,.posts_title h1{font-weight:900!important;line-height:1.6!important}";
        $css .= ".posts_header h3,.posts_header h2,.widget h3,.widget_title,.widget-title{font-weight:900!important}";
        $css .= "h2,h3,h4,h5,h6,.slider_main h3,.latest_news_inner h5{font-weight:700!important;line-height:1.7!important}";
        $css .= ".slider_main h3,.latest_news_inner h5,.lastest_news h5,.lastest_news h4,.lastest_news h3{font-weight:900!important}";
        $css .= "strong,b{font-weight:700!important}";
        $css .= "body.single h1{font-size:24px!important;line-height:1.65!important;margin-bottom:12px!important}@media(max-width:767px){body.single h1{font-size:21px!important}}";
        // menus, labels, meta: Medium / Light
        $css .= "nav a,.menu a,.main_menu a,.latest_news_inner span,.slider_main span,.ribbon,.badge{font-weight:500!important}";
        $css .= "time,.date,.post_date,.meta,.posts_meta,.breadcrumb,small,figcaption{font-weight:300!important}";
        $css .= "p.lead{font-weight:500!important;font-size:16.5px!important;line-height:2!important;margin-bottom:22px!important}";
        // article body spacing
        $css .= ".posts_desc{font-size:16px!important;line-height:2.05!important}";
        $css .= ".posts_desc p{font-size:16px!important;line-height:2.05!important;margin:0 0 1.15em!important;font-weight:400!important}";
        $css .= ".posts_desc h2{color:#000!important;font-size:21px!important;font-weight:900!important;line-height:1.7!important;margin:1.9em 0 .6em!important}";
        $css .= ".posts_desc h3{color:#000!important;font-size:18px!important;font-weight:800!important;line-height:1.7!important;margin:1.6em 0 .5em!important}";
        $css .= ".posts_desc h4{color:#000!important;font-size:16.5px!important;font-weight:700!important;margin:1.4em 0 .45em!important}";
        $css .= ".posts_desc>:first-child{margin-top:0!important}";
        $css .= ".posts_desc ul,.posts_desc ol{margin:0 0 1.15em!important;padding-inline-start:1.4em}.posts_desc li{line-height:2!important;margin-bottom:.35em}";
        $css .= ".posts_desc blockquote{margin:1.4em 0!important;line-height:2!important}";
        $css .= ".posts_desc p:empty{display:none}";
        echo '<style id="avpr-kit-fonts">' . $css . "</style>\n";
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
