<?php
//リビジョンの制御
add_filter('wp_revisions_to_keep', function ($num, $post) {
    if (!$post) return $num; // 安全のため null チェック

    // 投稿ごとにカスタムフィールドの値を取得
    $custom_revisions = get_post_meta($post->ID, 'custom_revisions_count', true);

    // 値が設定されている場合、その値をリビジョン数として適用
    if (is_numeric($custom_revisions) && $custom_revisions >= 0) {
        return (int) $custom_revisions;
    }

    return $num; // デフォルトのリビジョン数
}, 10, 2);

add_action('add_meta_boxes', function () {
    add_meta_box('custom_revisions_meta', 'リビジョン設定', function ($post) {
        $value = get_post_meta($post->ID, 'custom_revisions_count', true);
        echo '<label>この投稿のリビジョン最大数: </label>';
        echo '<input type="number" name="custom_revisions_count" value="' . esc_attr($value) . '" />';
    }, 'post', 'side', 'high');
});

add_action('save_post', function ($post_id) {
    if (isset($_POST['custom_revisions_count'])) {
        update_post_meta($post_id, 'custom_revisions_count', intval($_POST['custom_revisions_count']));
    }
});
