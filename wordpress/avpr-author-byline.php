<?php
/**
 * Plugin Name: AVPR Author Byline
 * Description: نویسنده وردپرسی هر مطلب واردشده از AVPR Feed را بر اساس خط «نویسنده: …» در انتهای متن تعیین می‌کند (محمدعلی فخارمنش / فاطمه ایمانی). به افزونه AVPR Feed Importer دست نمی‌زند.
 * Version: 1.0
 * Author: AVPR
 */

if (!defined('ABSPATH')) exit;

const AVPR_AUTHORS = [
    'محمدعلی فخارمنش' => 'm-fakharmanesh',
    'فاطمه ایمانی'     => 'f-emani',
];

/** On activation: create the two author accounts if they do not exist yet. */
register_activation_hook(__FILE__, function () {
    foreach (AVPR_AUTHORS as $name => $login) {
        if (avpr_find_author($name)) continue;
        $parts = explode(' ', $name, 2);
        wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => wp_generate_password(24),
            'display_name' => $name,
            'nickname'     => $name,
            'first_name'   => $parts[0],
            'last_name'    => $parts[1] ?? '',
            'role'         => 'author',
        ]);
    }
});

function avpr_find_author($name) {
    $users = get_users(['search' => $name, 'search_columns' => ['display_name'], 'number' => 1, 'fields' => 'ID']);
    if ($users) return (int) $users[0];
    $login = AVPR_AUTHORS[$name] ?? '';
    $u = $login ? get_user_by('login', $login) : false;
    return $u ? (int) $u->ID : 0;
}

/**
 * The first time a post's content contains <p class="avpr-author">…نویسنده: NAME…</p>,
 * set the post author to the matching user. Runs once per post, so later manual changes stick.
 */
add_action('wp_after_insert_post', 'avpr_set_byline_author', 20, 2);
function avpr_set_byline_author($post_id, $post) {
    if ($post->post_type !== 'post' || wp_is_post_revision($post_id)) return;
    if (get_post_meta($post_id, '_avpr_author_done', true)) return;
    if (!preg_match('/class="avpr-author"[^>]*>.*?نویسنده:\s*([^<]+)</u', $post->post_content, $m)) return;
    $name = trim($m[1]);
    if (!isset(AVPR_AUTHORS[$name])) return;
    $uid = avpr_find_author($name);
    if (!$uid) return;
    update_post_meta($post_id, '_avpr_author_done', 1);
    if ((int) $post->post_author !== $uid) {
        remove_action('wp_after_insert_post', 'avpr_set_byline_author', 20);
        wp_update_post(['ID' => $post_id, 'post_author' => $uid]);
        add_action('wp_after_insert_post', 'avpr_set_byline_author', 20, 2);
    }
}
