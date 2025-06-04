<?php
/*
Plugin Name:  POST MIGRATION
Description:  This plugin allows you to export post data along with associated media, revisions, and comments into a ZIP file and port it to another WordPress site.
Requires at least: 6.4
Requires PHP:      8.2
Version:      1.0.1
Author:       Web Creator ITmaroon
Author URI:   https://itmaroon.net
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  post-migration
Domain Path:  /languages
*/

if (! defined('ABSPATH')) exit;

require_once __DIR__ . '/vendor/itmar/loader-package/src/register_autoloader.php';

//プログレスオーバーレイのインスタンスを取得しておく
\Itmar\BlockClassPackage\ItmarProgressClass::get_instance();

//CSS等の読込
function itmar_post_tranfer_script_init($hook)
{
  // 'post-migration_page' を含む管理画面でのみスクリプトを読み込む
  if (strpos($hook, 'post-migration_page') === false) {
    return;
  }
  //独自CSSの読み込み
  $css_path = plugin_dir_path(__FILE__) . 'css/transfer.css';
  wp_enqueue_style('transfer_handle', plugins_url('/css/transfer.css', __FILE__), array(), filemtime($css_path), 'all');

  // zip-js ライブラリを読み込む
  wp_enqueue_script(
    'zip-js',
    plugin_dir_url(__FILE__) . 'assets/js/jszip.min.js',
    array(), // 依存関係なし
    '3.10.1', // バージョン
    true // フッターで読み込む
  );

  // FileSaver
  wp_enqueue_script(
    'file-saver',
    plugin_dir_url(__FILE__) . 'assets/js/FileSaver.min.js',
    array(),
    '2.0.5',
    true
  );

  // WordPress コアの api-fetch
  wp_enqueue_script('wp-api-fetch');

  //このプラグイン専用のJSをエンキュー
  $script_path = plugin_dir_path(__FILE__) . 'assets/js/post-mi-script.js';
  wp_enqueue_script('post-mi-handle', plugin_dir_url(__FILE__) . 'assets/js/post-mi-script.js', array('jquery'), filemtime($script_path), true);
  //JSの翻訳ファイルの読み込み
  $lang_path = plugin_dir_path(__FILE__) . 'languages';
  wp_set_script_translations(
    'post-mi-handle',
    'post-migration',
    $lang_path
  );
  //JS用のパラメータを読み込む
  wp_localize_script('post-mi-handle', 'itmar_vars', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('itmar-ajax-nonce'),
  ]);
}
add_action('admin_enqueue_scripts', 'itmar_post_tranfer_script_init');



/**
 * 「ツール」にメニューを追加
 */
function itmar_post_tranfer_add_admin_menu()
{
  // 親メニュー（ツールメニューの下に追加）
  add_menu_page(
    'POST MIGRATION', // 設定画面のページタイトル.
    'POST MIGRATION', // 管理画面メニューに表示される名前.
    'manage_options',
    'itmar_post_tranfer_menu', // メニューのスラッグ.
    '', //コールバックは空
    'dashicons-admin-tools',  // アイコン
    75                        // メニューの位置
  );

  // 「インポート」サブメニュー
  add_submenu_page(
    'itmar_post_tranfer_menu',        // 親メニューのスラッグ
    __('Import', 'post-migration'),      // ページタイトル
    __('import', 'post-migration'),             // メニュータイトル
    'manage_options',         // 権限
    'itmar_post_tranfer_import',       // スラッグ
    'itmar_post_tranfer_import_page'   // コールバック関数
  );

  // 「エクスポート」サブメニュー
  add_submenu_page(
    'itmar_post_tranfer_menu',        // 親メニューのスラッグ
    __('Export', 'post-migration'),      // ページタイトル
    __('export', 'post-migration'),             // メニュータイトル
    'manage_options',         // 権限
    'itmar_post_tranfer_export',       // スラッグ
    'itmar_post_tranfer_export_page'   // コールバック関数
  );

  // サブメニューを削除
  remove_submenu_page('itmar_post_tranfer_menu', 'itmar_post_tranfer_menu');
}
add_action('admin_menu', 'itmar_post_tranfer_add_admin_menu');

/**
 * インポートのフロントエンド処理
 */
function itmar_post_tranfer_import_page()
{

  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'post-migration'));
  }

?>
  <div class="wrap">
    <h1><?php echo esc_html__("Post Data Import", "post-migration"); ?></h1>
    <form id="inportForm">

      <table class="form-table">
        <tr>
          <th><label for="import_file"><?php echo esc_html__("ZIP file to import", "post-migration"); ?></label></th>
          <td>
            <input type="file" name="import_file" id="import_file" accept=".zip" required>
          </td>
        </tr>

        <!-- インポート方法選択 -->
        <tr>
          <th><?php echo esc_html__("How to import", "post-migration"); ?></th>
          <td>
            <label>
              <input type="radio" name="import_mode" value="create" checked> <?php echo esc_html__("Add new record", "post-migration"); ?>
            </label><br>
            <label>
              <input type="radio" name="import_mode" value="update"> <?php echo esc_html__("Override by ID", "post-migration"); ?>
            </label>

          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" name="submit_import" class="button button-primary" value="<?php echo esc_attr__("Start Import", "post-migration"); ?>">
      </p>
    </form>

    <div class='inport_result' style="display: none;">
      <h2><?php echo esc_html__("Import Result", "post-migration") ?></h2>
      <table class="widefat">
        <thead>
          <tr>
            <th>ID</th>
            <th><?php echo esc_html__("Title", "post-migration") ?></th>
            <th><?php echo esc_html__("Post Type", "post-migration") ?></th>
            <th><?php echo esc_html__("Result", "post-migration") ?></th>
          </tr>
        </thead>
        <tbody class="post_trns_tbody">
        </tbody>
      </table>
    </div>

  </div>
<?php
}

//インポートメインデータの逐次処理（非同期）
function itmar_post_data_fetch()
{
  // WordPress の nonce チェック（セキュリティ対策）
  check_ajax_referer('itmar-ajax-nonce', 'nonce');
  // **キャンセルフラグチェック**
  $cancel_flag = get_option('start_cancel', false);
  if ($cancel_flag) {
    wp_send_json(["result" => "cancel", "message" => __("Processing has been aborted", "post-migration")]);
    exit;
  }
  $db_obj = new \Itmar\WpsettingClassPackage\ItmarDbAction();

  // **JSON をデコード**
  $post_data = [];

  if (isset($_POST['post_data'])) {
    $raw_json = wp_unslash($_POST['post_data']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $decoded  = json_decode($raw_json, true);

    if (is_array($decoded)) {
      $post_data = $decoded;
    }
  }

  // **デコードエラーチェック**
  if (!is_array($post_data) || empty($post_data)) {
    wp_send_json_error(["message" => __("Incorrect data", "post-migration")]);
    exit;
  }
  //インポートモード
  $import_mode = isset($_POST['import_mode']) ? sanitize_text_field(wp_unslash($_POST['import_mode'])) : "update";

  //メディアファイル
  // 📌 `media_files` を取得

  if (
    isset($_FILES['media_files']) &&
    is_array($_FILES['media_files']) &&
    isset($_FILES['media_files']['name']) &&
    is_array($_FILES['media_files']['name'])
  ) {
    $file_count = count($_FILES['media_files']['name']);

    for ($i = 0; $i < $file_count; $i++) {
      // 各フィールドの存在をチェックしてから処理
      $name      = isset($_FILES['media_files']['name'][$i]) ? sanitize_file_name(wp_unslash($_FILES['media_files']['name'][$i])) : '';
      $type      = isset($_FILES['media_files']['type'][$i]) ? sanitize_mime_type(wp_unslash($_FILES['media_files']['type'][$i])) : '';
      $tmp_name = isset($_FILES['media_files']['tmp_name'][$i])
        ? $_FILES['media_files']['tmp_name'][$i] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        : '';
      $error     = isset($_FILES['media_files']['error'][$i]) ? (int) $_FILES['media_files']['error'][$i] : 1; // デフォルトをエラー扱いに
      $size      = isset($_FILES['media_files']['size'][$i]) ? absint($_FILES['media_files']['size'][$i]) : 0;
      // ファイル構造再現用に full_path を取得。保存/表示目的で使用。
      $full_path = isset($_FILES['media_files']['full_path'][$i])
        ? $_FILES['media_files']['full_path'][$i] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        : '';

      // エラーファイルはスキップしてもよい
      if ($error === 0 && $name && $tmp_name) {
        $sanitized_files[] = [
          'name'       => $name,
          'type'       => $type,
          'tmp_name'   => $tmp_name,
          'error'      => $error,
          'size'       => $size,
          'full_path'  => $full_path,
        ];
      }
    }
  }


  $result = $db_obj->json_import_data($post_data, $sanitized_files, $import_mode);
  wp_send_json($result);
}

add_action('wp_ajax_post_data_fetch', 'itmar_post_data_fetch');
add_action('wp_ajax_nopriv_post_data_fetch', 'itmar_post_data_fetch');




/**
 * エクスポートのフロントエンド処理
 */

function itmar_post_tranfer_export_page()
{

  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'post-migration'));
  }

?>
  <div class="wrap">

    <div class="form-container">
      <form id="exportForm" method="post">
        <?php wp_nonce_field('export_action', 'itmar_export_nonce'); ?>
        <input type="hidden" name="export_action" value="export_json">

        <!-- ヘッダーを固定 -->
        <div class="fixed-header">
          <h1><?php echo esc_html__("Post Data Custom Export", "post-migration") ?></h1>
          <p><?php echo esc_html__("Select the articles you want to export.", "post-migration") ?></p>
          <label>
            <input type="checkbox" id="include_custom_fields" name="include_custom_fields" value="1">
            <?php echo esc_html__("Include custom fields", "post-migration") ?>
          </label>
          <label>
            <input type="checkbox" id="include_revisions" name="include_revisions" value="1">
            <?php echo esc_html__("Include revisions", "post-migration") ?>
          </label>
          <label>
            <input type="checkbox" id="include_comments" name="include_comments" value="1">
            <?php echo esc_html__("Include comments", "post-migration") ?>
          </label>
        </div>

        <?php
        // すべてのカスタム投稿タイプを取得（メディア "attachment" を除外）
        $all_post_types = get_post_types(['public' => true], 'objects');

        // 投稿タイプの順序を変更（投稿 → カスタム投稿 → 固定ページ）
        $ordered_post_types = [];
        if (isset($all_post_types['post'])) {
          $ordered_post_types['post'] = $all_post_types['post']; // 投稿を最初に
          unset($all_post_types['post']);
        }
        if (isset($all_post_types['page'])) {
          $page_type = $all_post_types['page']; // 固定ページを最後に
          unset($all_post_types['page']);
        }

        // カスタム投稿タイプを残りの投稿タイプとして格納
        foreach ($all_post_types as $key => $type) {
          if ($key !== 'attachment') { // メディア（"attachment"）を除外
            $ordered_post_types[$key] = $type;
          }
        }

        // 固定ページを最後に追加
        if (isset($page_type)) {
          $ordered_post_types['page'] = $page_type;
        }

        // 投稿タイプごとに記事一覧を表示
        foreach ($ordered_post_types as $post_type) {
          // This GET param is only used for pagination display, not data processing.
          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
          $current_page = isset($_GET["paged_{$post_type->name}"]) ? max(1, intval($_GET["paged_{$post_type->name}"])) : 1;
          $posts_per_page = 10;
          $offset = ($current_page - 1) * $posts_per_page;

          $query_args = [
            'post_type'      => $post_type->name,
            'posts_per_page' => $posts_per_page,
            'offset'         => $offset,
          ];
          $posts = get_posts($query_args);
          $total_posts = wp_count_posts($post_type->name)->publish;
          $total_pages = ceil($total_posts / $posts_per_page);

          if ($posts) {
            echo '<h2>' . esc_html($post_type->label) . '</h2>';
            // すべてのレコードを選択 チェックボックスを追加
            echo "<label class='select-all-posts'><input type='checkbox' name='export_types[]' value='" . esc_html($post_type->name) . "'>" . esc_html__(' Select all records', 'post-migration') . "</label>";
            // 投稿タイプに紐づくタクソノミーを取得（ヘッダー用）
            $taxonomies = get_object_taxonomies($post_type->name, 'objects');
            // **post_format を配列から削除**
            unset($taxonomies['post_format']);
            //投稿のテーブル
            echo "<table class='widefat striped'>";
            echo "<thead><tr><th><input type='checkbox' id='select-all-" . esc_html($post_type->name) . "'></th><th>" . esc_html__('Title', 'post-migration') . "</th><th>" . esc_html__('Featured', 'post-migration') . "</th>";
            // タクソノミーごとにヘッダーを追加
            foreach ($taxonomies as $taxonomy) {
              echo "<th>" . esc_html($taxonomy->label) . "</th>";
            }
            echo "<th>" . esc_html__('Updated on', 'post-migration') . "</th></tr></thead>";
            echo "<tbody>";

            foreach ($posts as $post) {
              // アイキャッチ画像を取得
              $thumbnail = get_the_post_thumbnail($post->ID, 'thumbnail');
              // 変更日
              $modified_date = get_the_modified_date('Y-m-d', $post->ID);

              echo "<tr>";
              echo "<td><input type='checkbox' name='export_posts[]' value='" . esc_html($post->ID) . "'></td>";
              echo "<td>" . esc_html($post->post_title) . "</td>";
              echo '<td>' . ($thumbnail ? wp_kses($thumbnail, array('img' => array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true))) : esc_html__('None', 'post-migration')) . '</td>';

              // タクソノミーごとのタームを取得し、カンマ区切りで表示
              foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy->name);
                if (!empty($terms) && !is_wp_error($terms)) {
                  $term_list = implode(', ', wp_list_pluck($terms, 'name'));
                } else {
                  $term_list = '-';
                }
                echo "<td>" . esc_html($term_list) . "</td>";
              }

              echo "<td>" . esc_html($modified_date) . "</td>";
              echo "</tr>";
            }

            echo "</tbody></table>";
            // ページネーションの表示
            if ($total_pages > 1) {
              echo "<div class='tablenav'>";
              echo "<div class='tablenav-pages'>";

              // 前のページ
              if ($current_page > 1) {
                // This GET param is only used for pagination display, not data processing.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $page_param     = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
                $post_type_name = esc_attr($post_type->name);
                $prev_page      = $current_page - 1;
                $prev_url       = '?page=' . $page_param . '&paged_' . $post_type_name . '=' . $prev_page;

                echo '<a class="button" href="' . esc_url($prev_url) . '">« ' . esc_html__('Before', 'post-migration') . '</a>';
              }

              // ページ番号表示
              echo esc_html__('Page', 'post-migration') . ' ' . esc_html($current_page) . ' / ' . esc_html($total_pages) . ' ';

              // 次のページ
              if ($current_page < $total_pages) {
                // This GET param is only used for pagination display, not data processing.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $page_param     = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
                $post_type_name = esc_attr($post_type->name);
                $next_page      = $current_page + 1;
                $next_url       = '?page=' . $page_param . '&paged_' . $post_type_name . '=' . $next_page;

                echo '<a class="button" href="' . esc_url($next_url) . '">' . esc_html__('Next', 'post-migration') . ' »</a>';
              }

              echo '</div></div>';
            }
          }
        }
        //他のページで選択された投稿IDも含めて格納するinput要素
        echo "<input type='hidden' name='all_export_posts'>"
        ?>

        <p class='footer_exec'><input type="submit" name="export_selected" class="button button-primary" value="選択した記事をエクスポート"></p>
      </form>
    </div>

  </div>

<?php
}

// エクスポートのサーバーサイド処理
//エクスポート対象の投稿IDの取得
add_action('wp_ajax_itmar_export_ids', 'itmar_post_tranfer_export_ids');
function itmar_post_tranfer_export_ids()
{
  check_ajax_referer('itmar-ajax-nonce', 'nonce');
  //最初にexport_data.jsonを削除しておく
  require_once ABSPATH . 'wp-admin/includes/file.php';
  global $wp_filesystem;
  if (! WP_Filesystem()) {
    wp_die('WP_Filesystem の初期化に失敗しました。');
  }
  $upload_dir   = wp_upload_dir();
  $json_path    = $upload_dir['basedir'] . '/export_data.json';

  if ($wp_filesystem->exists($json_path)) {
    $wp_filesystem->delete($json_path);
  }

  $str_post_ids = isset($_POST['all_export_posts']) ? sanitize_text_field(wp_unslash($_POST['all_export_posts'])) : '';
  $post_ids     = explode(',', $str_post_ids);

  $selected_post_types = isset($_POST['export_types']) && is_array($_POST['export_types'])
    ? array_map('sanitize_key', wp_unslash($_POST['export_types']))
    : [];

  $selected_posts = [];

  $all_selected_posts = array_merge(...array_map(function ($post_type) {
    return array_map('strval', get_posts([
      'post_type'      => $post_type,
      'posts_per_page' => -1,
      'fields'         => 'ids',
    ]));
  }, $selected_post_types));

  $selected_posts = array_values(array_unique(array_merge($post_ids, $all_selected_posts)));
  $selected_posts = array_values(array_diff($selected_posts, $selected_post_types));

  //リビジョンをエクスポートに含めるか
  $include_revisions     = isset($_POST['include_revisions']);

  if ($include_revisions) {
    $selected_posts_rev = [];
    foreach ($selected_posts as $post_id) {
      $selected_posts_rev[] = $post_id;
      $rev_ids              = get_posts([
        'post_type'   => 'revision',
        'post_status' => 'any',
        'post_parent' => $post_id,
        'numberposts' => -1,
        'fields'      => 'ids',
      ]);
      if (! empty($rev_ids)) {
        $selected_posts_rev = array_merge($selected_posts_rev, $rev_ids);
      }
    }
    $selected_posts = array_values($selected_posts_rev);
  }

  //データを返す
  wp_send_json_success([
    'selected_posts'     => $selected_posts,
  ]);
}


//投稿IDごとにZIPファイルにエクスポート
add_action('wp_ajax_itmar_export_json', 'itmar_post_tranfer_export_json');
function itmar_post_tranfer_export_json()
{
  check_ajax_referer('itmar-ajax-nonce', 'nonce');

  // 必須情報取得
  $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
  if (! $post_id) {
    wp_send_json_error(['message' => 'Invalid post_id']);
  }

  $db_obj = new \Itmar\WpsettingClassPackage\ItmarDbAction();

  //オプションのフラグ
  $include_custom_fields = isset($_POST['include_custom_fields']);
  $include_comments      = isset($_POST['include_comments']);
  //メディアURL保存配列
  $media_urls = [];

  $post = get_post($post_id);
  if (! $post) {
    wp_send_json_error(['message' => 'Post not found']);
  }

  $post_data = [
    'ID'            => $post->ID,
    'title'         => $post->post_title,
    'content'       => $post->post_content,
    'excerpt'       => $post->post_excerpt,
    'date'          => $post->post_date,
    'modified'      => $post->post_modified,
    'author'        => get_the_author_meta('display_name', $post->post_author),
    'post_name'     => $post->post_name,
    'post_type'     => $post->post_type,
    'post_status'   => $post->post_status,
    'post_parent'   => $post->post_parent,
    'thumbnail_url' => get_the_post_thumbnail_url($post->ID, 'full'),
    'thumbnail_path' => null,
    'terms'         => [],
  ];
  // タクソノミー
  $taxonomies = get_object_taxonomies($post->post_type, 'names');
  foreach ($taxonomies as $taxonomy) {
    $terms = get_the_terms($post->ID, $taxonomy);
    $post_data['terms'][$taxonomy] = ! is_wp_error($terms) && ! empty($terms) ? wp_list_pluck($terms, 'name') : [];
  }
  // カスタムフィールド
  if ($include_custom_fields) {
    $custom_fields        = get_post_meta($post->ID);
    $registered_meta_keys = get_registered_meta_keys('post', $post->post_type);

    foreach ($custom_fields as $key => $value) {
      if ($db_obj->is_acf_active()) {
        if (strpos($key, '_') !== 0) {
          $field_ID     = $db_obj->get_acf_field_key($key);
          $field_object = get_field_object($field_ID, $post->ID);

          if ($field_object && isset($field_object['type'])) {
            if (in_array($field_object['type'], ['image', 'file'], true)) {
              $val        = get_field($key, $post->ID);
              $media_url  = is_numeric($val) ? wp_get_attachment_url($val) : (is_array($val) && isset($val['url']) ? $val['url'] : $val);
              if ($media_url) {
                $relative_path                    = 'exported_media/' . basename($media_url);
                $post_data['acf_fields'][$key] = $relative_path;
                $media_urls[] = $media_url;
              }
            } elseif ($field_object['type'] === 'group') {
              $post_data['acf_fields'][$key] = '_group';
            } else {
              $post_data['acf_fields'][$key] = maybe_unserialize($value[0]);
            }
          } elseif (array_key_exists($key, $registered_meta_keys)) {
            $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
          }
        }
      } else {
        $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
      }
    }
  }
  // コメント
  if ($include_comments) {
    $comments               = $db_obj->get_comments_with_meta($post->ID);
    $post_data['comments']  = maybe_unserialize($comments);
  }
  // アイキャッチ画像
  if ($post_data['thumbnail_url']) {
    $image_filename              = basename($post_data['thumbnail_url']);
    $post_data['thumbnail_path'] = 'exported_media/' . $image_filename;
    $media_urls[] = $post_data['thumbnail_url'];
  }
  // 本文中のメディア
  $content_media_urls = itmar_extract_media_urls($post->post_content);
  $modified_content   = $post_data['content'];
  foreach ($content_media_urls as $media_url) {
    $relative_path   = 'exported_media/' . basename($media_url);
    $modified_content = str_replace($media_url, $relative_path, $modified_content);
    $media_urls[] = $media_url;
  }

  $post_data['content'] = $modified_content;

  wp_send_json_success(['json' => $post_data, 'media_urls' => $media_urls]);
}

//コンテンツからメディアURLを抜き出す関数
function itmar_extract_media_urls($content)
{
  $media_urls = [];

  // 画像・メディアURLを正規表現で抽出
  preg_match_all('/https?:\/\/[^\"\'\s]+(?:jpg|jpeg|png|gif|mp4|mp3|pdf)/i', $content, $matches);
  // preg_match_all(
  //   '#https?://(?![^"\']*exported_media/)[^"\']*?/([a-zA-Z0-9_\-]+(?:-[0-9]+)*\.(?:jpg|jpeg|png|gif|mp4|mp3|pdf))#i',
  //   $content,
  //   $matches
  // );
  if (!empty($matches[0])) {
    $media_urls = array_unique($matches[0]); // 重複を除外
  }

  return $media_urls;
}
