<?php
/**
 * Plugin Name: AVPR Author Byline
 * Description: نویسنده وردپرسی هر مطلب واردشده از AVPR Feed را بر اساس خط «نویسنده: …» در انتهای متن تعیین می‌کند (محمدعلی فخارمنش / فاطمه ایمانی)، تا کادر نویسنده زیر مطلب هم همین نام را نشان دهد. مطالب قبلی هم هنگام فعال‌سازی اصلاح می‌شوند. به افزونه AVPR Feed Importer دست نمی‌زند.
 * Version: 1.1
 * Author: AVPR
 */

if (!defined('ABSPATH')) exit;

const AVPR_AUTHORS = [
    'محمدعلی فخارمنش' => 'm-fakharmanesh',
    'فاطمه ایمانی'     => 'f-emani',
];

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/** Normalise Persian text so small differences (ZWNJ, Arabic ye/kaf, spaces) do not break matching. */
function avpr_norm($s) {
    $s = wp_strip_all_tags((string) $s);
    $s = str_replace(["\u{200C}", "\u{200F}", "\u{200E}", "\u{00A0}"], ' ', $s);
    $s = str_replace(['ي', 'ك'], ['ی', 'ک'], $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** The byline name written at the end of the post text, or '' if there is none / it is unknown. */
function avpr_byline_name($content) {
    if (!preg_match('/class=["\']?avpr-author["\']?[^>]*>(.*?)<\/p>/su', (string) $content, $m)) return '';
    $text = avpr_norm($m[1]);
    foreach (array_keys(AVPR_AUTHORS) as $name) {
        if (mb_strpos($text, avpr_norm($name)) !== false) return $name;
    }
    return '';
}

/** Find (or create) the WordPress user for an AVPR author name. */
function avpr_find_author($name, $create = true) {
    $login = AVPR_AUTHORS[$name] ?? '';
    if (!$login) return 0;
    $u = get_user_by('login', $login);
    if ($u) return (int) $u->ID;
    $users = get_users(['search' => $name, 'search_columns' => ['display_name'], 'number' => 1, 'fields' => 'ID']);
    if ($users) return (int) $users[0];
    if (!$create) return 0;
    $parts = explode(' ', $name, 2);
    $id = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => wp_generate_password(24),
        'display_name' => $name,
        'nickname'     => $name,
        'first_name'   => $parts[0],
        'last_name'    => $parts[1] ?? '',
        'role'         => 'author',
    ]);
    return is_wp_error($id) ? 0 : (int) $id;
}

/** Set the post author from the byline. Returns true if the post was changed. */
function avpr_fix_post_author($post) {
    $post = get_post($post);
    if (!$post || $post->post_type !== 'post') return false;
    if (get_post_meta($post->ID, '_avpr_author_manual', true)) return false;   // a person chose the author by hand
    $name = avpr_byline_name($post->post_content);
    if (!$name) return false;
    $uid = avpr_find_author($name);
    if (!$uid || (int) $post->post_author === $uid) return false;
    remove_action('wp_after_insert_post', 'avpr_set_byline_author', 99);
    wp_update_post(['ID' => $post->ID, 'post_author' => $uid]);
    add_action('wp_after_insert_post', 'avpr_set_byline_author', 99, 2);
    clean_post_cache($post->ID);
    return true;
}

/** Fix every existing post that has a byline. */
function avpr_backfill_authors() {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('publish','future','draft','pending','private') AND post_content LIKE '%avpr-author%'");
    $n = 0;
    foreach ($ids as $id) if (avpr_fix_post_author((int) $id)) $n++;
    update_option('avpr_author_backfill', ['time' => time(), 'checked' => count($ids), 'fixed' => $n], false);
    return $n;
}

/** True when a person is saving the post by hand in the editor (their choice must stick). */
function avpr_is_manual_edit() {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return false;
    if (!get_current_user_id()) return false;
    if (isset($_POST['action']) && $_POST['action'] === 'editpost') return true;      // classic editor
    if (defined('REST_REQUEST') && REST_REQUEST && isset($_SERVER['REQUEST_URI'])
        && strpos($_SERVER['REQUEST_URI'], '/wp/v2/posts') !== false) return true;   // block editor
    return false;
}

/* ---------------------------------------------------------------------------
 * Hooks
 * ------------------------------------------------------------------------- */

/** On activation: create the two author accounts and fix all existing posts. */
register_activation_hook(__FILE__, function () {
    foreach (array_keys(AVPR_AUTHORS) as $name) avpr_find_author($name);
    avpr_backfill_authors();
});

/**
 * Every time the importer saves a post (late priority, after anything the importer does),
 * set the author from the byline. Manual saves in the editor are left alone.
 */
add_action('wp_after_insert_post', 'avpr_set_byline_author', 99, 2);
function avpr_set_byline_author($post_id, $post) {
    if (wp_is_post_revision($post_id)) return;
    if (avpr_is_manual_edit()) {
        // Remember a hand-picked author that differs from the byline, so the fix never overrides it.
        $name = avpr_byline_name($post->post_content);
        $uid  = $name ? avpr_find_author($name, false) : 0;
        if ($uid && (int) $post->post_author !== $uid) update_post_meta($post_id, '_avpr_author_manual', 1);
        else delete_post_meta($post_id, '_avpr_author_manual');
        return;
    }
    avpr_fix_post_author($post);
}

/** Safety net: if the importer changes the author afterwards, fix it again on the next cron tick. */
add_action('avpr_author_recheck', 'avpr_backfill_authors');
add_action('init', function () {
    if (!wp_next_scheduled('avpr_author_recheck')) wp_schedule_event(time() + 300, 'hourly', 'avpr_author_recheck');
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('avpr_author_recheck'); });

/**
 * Display safety net: the author box and "by …" lines show the byline name
 * even before the post author has been corrected.
 */
function avpr_display_name_filter($name) {
    if (is_admin() || (!in_the_loop() && !is_singular('post'))) return $name;
    $post = get_post();
    if (!$post || $post->post_type !== 'post' || get_post_meta($post->ID, '_avpr_author_manual', true)) return $name;
    $byline = avpr_byline_name($post->post_content);
    return $byline ?: $name;
}
add_filter('the_author', 'avpr_display_name_filter', 20);
add_filter('get_the_author_display_name', 'avpr_display_name_filter', 20);

/** Tools → «نویسنده مطالب AVPR»: button to re-run the fix by hand. */
add_action('admin_menu', function () {
    add_management_page('نویسنده مطالب AVPR', 'نویسنده مطالب AVPR', 'edit_others_posts', 'avpr-author-byline', function () {
        if (isset($_POST['avpr_fix']) && check_admin_referer('avpr_fix_authors')) {
            $n = avpr_backfill_authors();
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf('نویسنده %d مطلب اصلاح شد.', $n)) . '</p></div>';
        }
        $last = get_option('avpr_author_backfill');
        echo '<div class="wrap"><h1>نویسنده مطالب AVPR</h1>';
        echo '<p>نویسنده هر مطلب را از روی خط «نویسنده: …» در انتهای متن تعیین می‌کند.</p>';
        if ($last) echo '<p>' . esc_html(sprintf('آخرین اجرا: %s، %d مطلب بررسی و %d مطلب اصلاح شد.', wp_date('Y-m-d H:i', $last['time']), $last['checked'], $last['fixed'])) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('avpr_fix_authors');
        echo '<p><button class="button button-primary" name="avpr_fix" value="1">اصلاح نویسنده همه مطالب</button></p></form></div>';
    });
});
