<?php
/*
Plugin Name:  POST MIGRATION
Description:  This plugin allows you to export post data along with associated media, revisions, and comments into a ZIP file and port it to another WordPress site.
Version:      0.1.0
Author:       Web Creator ITmaroon
Author URI:   https://itmaroon.net
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  post-migration
Domain Path:  /languages
*/

if (! defined('ABSPATH')) exit;

//翻訳ファイルの読み込み
function itmar_post_mi_textdomain()
{
  load_plugin_textdomain('post-migration', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('init', 'itmar_post_mi_textdomain');


//投稿タイプを取得する関数
function itmar_get_post_type_label($post_type)
{
  $post_type_object = get_post_type_object($post_type);
  return $post_type_object ? $post_type_object->label : '未登録の投稿タイプ';
}

//WordPress のメディアライブラリからファイルのメディア ID を取得する関数
function itmar_get_attachment_id_by_file_path($file_path)
{
  global $wpdb;

  // WordPressのアップロードディレクトリの情報を取得
  $upload_dir = wp_upload_dir();

  // アップロードディレクトリを削除して相対パスを取得
  $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);

  // `_wp_attached_file` でメディアIDを取得（完全一致検索）
  $attachment_id = $wpdb->get_var($wpdb->prepare(
    "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
    '%' . $relative_path . '%'
  ));

  return $attachment_id ? intval($attachment_id) : false;
}

//CSS等の読込
function itmar_post_tranfer_script_init()
{
  $css_path = plugin_dir_path(__FILE__) . 'css/transfer.css';
  wp_enqueue_style('transfer_handle', plugins_url('/css/transfer.css', __FILE__), array(), filemtime($css_path), 'all');
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
 * インポートの処理
 */
function itmar_post_tranfer_import_page()
{

  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(_e('You do not have sufficient permissions to access this page.', 'post-migration'));
  }

?>
  <div class="wrap">
    <h1><?php echo esc_html__("Post Data Import", "post-migration"); ?></h1>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('custom_import_action', 'custom_import_nonce'); ?>

      <!-- ZIP ファイル選択 -->
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
              <input type="radio" name="import_mode" value="update" checked> <?php echo esc_html__("Override by ID", "post-migration"); ?>
            </label><br>
            <label>
              <input type="radio" name="import_mode" value="create"> <?php echo esc_html__("Add new record", "post-migration"); ?>
            </label>
          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" name="submit_import" class="button button-primary" value="<?php echo esc_attr__("Start Import", "post-migration"); ?>">
      </p>
    </form>
    <?php itmar_post_tranfer_import_json(); ?>
  </div>
<?php
}


/**
 * エクスポートの処理
 */

function itmar_post_tranfer_export_page()
{

  // 権限チェック.
  if (! current_user_can('manage_options')) {
    wp_die(_e('You do not have sufficient permissions to access this page.', 'post-migration'));
  }

?>
  <div class="wrap">

    <div class="form-container">
      <form method="post" action="">
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
            echo "<h2>{$post_type->label}</h2>";
            // すべてのレコードを選択 チェックボックスを追加
            echo "<label class='select-all-posts'><input type='checkbox' name='export_types[]' value='{$post_type->name}'>" . __(' Select all records', 'post-migration') . "</label>";
            // 投稿タイプに紐づくタクソノミーを取得（ヘッダー用）
            $taxonomies = get_object_taxonomies($post_type->name, 'objects');
            // **post_format を配列から削除**
            unset($taxonomies['post_format']);
            //投稿のテーブル
            echo "<table class='widefat striped'>";
            echo "<thead><tr><th><input type='checkbox' id='select-all-{$post_type->name}'></th><th>" . __('Title', 'post-migration') . "</th><th>" . __('Featured', 'post-migration') . "</th>";
            // タクソノミーごとにヘッダーを追加
            foreach ($taxonomies as $taxonomy) {
              echo "<th>{$taxonomy->label}</th>";
            }
            echo "<th>" . __('Updated on', 'post-migration') . "</th></tr></thead>";
            echo "<tbody>";

            foreach ($posts as $post) {
              // アイキャッチ画像を取得
              $thumbnail = get_the_post_thumbnail($post->ID, 'thumbnail');
              // 変更日
              $modified_date = get_the_modified_date('Y-m-d', $post->ID);

              echo "<tr>";
              echo "<td><input type='checkbox' name='export_posts[]' value='{$post->ID}'></td>";
              echo "<td>{$post->post_title}</td>";
              echo "<td>" . ($thumbnail ?: __('None', 'post-migration')) . "</td>";
              // タクソノミーごとのタームを取得し、カンマ区切りで表示
              foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy->name);
                if (!empty($terms) && !is_wp_error($terms)) {
                  $term_list = implode(', ', wp_list_pluck($terms, 'name'));
                } else {
                  $term_list = '-';
                }
                echo "<td>{$term_list}</td>";
              }

              echo "<td>{$modified_date}</td>";
              echo "</tr>";
            }

            echo "</tbody></table>";
            // ページネーションの表示
            if ($total_pages > 1) {
              echo "<div class='tablenav'>";
              echo "<div class='tablenav-pages'>";

              // 前のページ
              if ($current_page > 1) {
                echo '<a class="button" href="?page=' . esc_attr($_GET['page']) . '&paged_' . $post_type->name . '=' . ($current_page - 1) . '">« ' . __('Before', 'post-migration') . '</a>';
              }

              echo __('Page', 'post-migration') . " {$current_page} / {$total_pages} ";

              // 次のページ
              if ($current_page < $total_pages) {
                echo '<a class="button" href="?page=' . esc_attr($_GET['page']) . '&paged_' . $post_type->name . '=' . ($current_page + 1) . '">' . __('Next', 'post-migration') . '»</a>';
              }

              echo "</div></div>";
            }
          }
        }
        //他のページで選択された投稿IDも含めて格納するinput要素
        echo "<input type='hidden' name='all_export_posts'>"
        ?>

        <p class='footer_exec'><input type="submit" name="export_selected" class="button button-primary" value="選択した記事をエクスポート"></p>
      </form>
    </div>
    <script>
      let isNavigatingWithinPlugin = false; // 「前へ」「次へ」ボタンでの遷移かどうかを判定

      document.addEventListener("DOMContentLoaded", function() {
        const storageKey = "itmar_selected_posts";
        let selectedPosts = []; //選択されたセレクトボックスをためる配列
        //実行ボタンのアニメーション関数
        const exec_animation = () => {
          const exec_button = document.querySelector(".footer_exec");
          if (selectedPosts.length > 0) {
            setTimeout(() => {
              exec_button.classList.add("appear");
            }, 100); // 100ms後にクラスを追加
          } else {
            setTimeout(() => {
              exec_button.classList.remove("appear");
            }, 100); // 100ms後にクラスを削除
          }
        }

        // **ローディング画像のHTMLを追加**
        let loadingOverlay = document.createElement("div");
        loadingOverlay.id = "loading-overlay";
        loadingOverlay.innerHTML = `<img src="<?php echo plugin_dir_url(__FILE__) . 'img/transloading.gif'; ?>" alt="Loading...">`;
        document.querySelector("form").appendChild(loadingOverlay);

        // ローディング画像を非表示にする関数
        function hideLoadingOverlay() {
          loadingOverlay.style.display = "none";
        }

        // ローディング画像を表示する関数
        function showLoadingOverlay() {
          loadingOverlay.style.display = "flex";
        }

        // **初期状態ではローディング画像を非表示**
        hideLoadingOverlay();


        //行見出しのチェックボックスを押したときに、そのテーブル内のチェックボックスが変更される処理
        document.querySelectorAll("input[id^='select-all-']").forEach(function(checkbox) {
          checkbox.addEventListener("change", function() {
            let table = this.closest("table");
            table.querySelectorAll("input[name='export_posts[]']").forEach(function(cb) {
              cb.checked = checkbox.checked;
              // **change イベントを手動で発生させる**
              cb.dispatchEvent(new Event("change", {
                bubbles: true
              }));
            });
          });
        });


        function restoreSelectedPosts() {
          selectedPosts = JSON.parse(sessionStorage.getItem(storageKey)) || [];
          // チェックボックスの状態を復元
          document.querySelectorAll("input[name='export_posts[]']").forEach(function(checkbox) {
            if (selectedPosts.includes(checkbox.value)) {
              checkbox.checked = true;
            }
          });
          //選択済みの投稿IDをinput-hiddenに確保
          if (selectedPosts) {
            document.querySelector("input[name='all_export_posts']").value = selectedPosts.join(",");
          }

          //実行ボタンのアニメーション
          exec_animation();
        }

        // イベントリスナーを設定（チェックボックスが変更されたら配列を更新）
        document.querySelectorAll("input[name='export_posts[]'], input[name='export_types[]']").forEach(function(checkbox) {
          checkbox.addEventListener("change", function() {
            if (this.checked) {
              // 選択された場合、配列に追加（重複を防ぐ）
              if (!selectedPosts.includes(this.value)) {
                selectedPosts.push(this.value);
              }
            } else {
              // 解除された場合、配列から削除
              selectedPosts = selectedPosts.filter(id => id !== this.value);
            }
            //選択済みの投稿IDをinput-hiddenに確保
            if (selectedPosts) {
              document.querySelector("input[name='all_export_posts']").value = selectedPosts.join(",");
            }
            //実行ボタンのアニメーション
            exec_animation();

          });
        });

        // 「前へ」「次へ」のリンクがクリックされたときにデータを保存
        document.querySelectorAll(".tablenav-pages a").forEach(function(link) {
          link.addEventListener("click", function(event) {
            isNavigatingWithinPlugin = true; // フラグを立てる
            // **フォームを使用不可にする**
            document.querySelector("form").style.pointerEvents = "none";
            document.querySelector("form").style.opacity = "0.5";

            // **ローディング画像を表示**
            showLoadingOverlay();
            sessionStorage.setItem(storageKey, JSON.stringify(selectedPosts)); // クリック時にデータを保存
          });
        });

        // ページ読み込み時にデータを復元
        restoreSelectedPosts();
      });
      // **このページから離脱するときに `sessionStorage` をクリア**
      window.addEventListener("beforeunload", function() {
        if (window.location.search.includes("page=itmar_post_tranfer_export") && !isNavigatingWithinPlugin) {
          sessionStorage.removeItem("itmar_selected_posts");
        }
      });
    </script>
  </div>

<?php
}

//ZIP から画像をアップロードする関数
function itmar_import_thumbnail_from_zip($zip, $file_path, $post_id)
{
  $upload_dir = wp_upload_dir();
  $extract_path = trailingslashit($upload_dir['path']); // アップロードフォルダのパスを取得
  $dest_path = $extract_path . basename($file_path);

  // すでに同じファイルがある場合は処理を終了
  if (file_exists($dest_path)) {
    $attachment_id = itmar_get_attachment_id_by_file_path($dest_path);
    if ($attachment_id) {
      return new WP_Error('file_exists', __("Processing stopped due to existing file found (media ID:", "post-migration") . $attachment_id . ")");
    }
  }

  // ZIP 内のファイルを展開
  if ($zip->locateName($file_path) !== false) {
    // ZIPから抽出
    $zip->extractTo($extract_path, $file_path);
    // ZIP内のフォルダ構成がある場合、正しいパスに移動
    $extracted_file = $extract_path . $file_path;
    if (!file_exists($extracted_file)) {
      return new WP_Error('extract_failed', __("Failed to extract the file from ZIP", "post-migration"));
    }
    rename($extracted_file, $dest_path);
  } else {
    return new WP_Error('file_not_found', __("File not found in ZIP", "post-migration"));
  }
  // Windows のパス区切りを `/` に統一
  $dest_path = str_replace('\\', '/', $dest_path);

  // 展開されたファイルがあるかチェック
  if (!file_exists($dest_path)) {

    return new WP_Error('file_not_found', __("Unpacked image not found:", "post-migration") . $dest_path);
  }

  // 登録データを生成
  $filetype = wp_check_filetype($dest_path);
  if (!$filetype['type']) {
    return new WP_Error('invalid_file_type', __("Invalid file type:", "post-migration") . $dest_path);
  }
  $attachment = array(
    'post_mime_type' => $filetype['type'],
    'post_title'     => sanitize_file_name(basename($dest_path)),
    'post_content'   => '',
    'post_status'    => 'inherit'
  );

  // メディアライブラリに登録
  $attachment_id = wp_insert_attachment($attachment, $dest_path, $post_id);
  if (is_wp_error($attachment_id)) {
    return $attachment_id;
  }

  // メタデータの生成
  require_once(ABSPATH . 'wp-admin/includes/image.php');
  $attachment_data = wp_generate_attachment_metadata($attachment_id, $dest_path);
  // メタデータ更新前にファイルが存在するか確認
  if (!file_exists($dest_path)) {
    return new WP_Error('file_not_found', __("File was deleted before metadata was updated:", "post-migration") . $dest_path);
  }


  wp_update_attachment_metadata($attachment_id, $attachment_data);

  return $attachment_id;
}


//インポートの実行処理
function itmar_process_import_data($decoded_data, $zip_path, $import_mode)
{
  echo '<h2>' . __("Import Result", "post-migration") . '</h2>';
  echo '<table class="widefat">';
  echo '<thead><tr><th>#</th><th>' . __("Title", "post-migration") . '</th><th>' . __("Post Type", "post-migration") . '</th><th>' . __("Result", "post-migration") . '</th></tr></thead>';
  echo '<tbody class="post_trns_tbody">';

  // ZIP ファイルを開く
  $zip = new ZipArchive;
  if ($zip->open($zip_path) !== true) {
    echo '<div class="error"><p>' . __("Failed to extract ZIP file.", "post-migration") . '</p></div>';
    return;
  }
  //インポートしたデータのIDを保存しておく変数
  $parent_id = 0;

  //エラーログ
  $error_logs = [];

  //インポートのループ
  foreach ($decoded_data as $index => $entry) {
    //JSONのデコード結果から情報を取り出し
    $post_id = isset($entry['ID']) ? intval($entry['ID']) : 0;
    $post_title = isset($entry['title']) ? esc_html($entry['title']) : '';
    $post_type = isset($entry['post_type']) ? esc_html($entry['post_type']) : '';
    $post_status = isset($entry['post_status']) ? esc_html($entry['post_status']) : '';
    $post_date = isset($entry['date']) ? $entry['date'] : current_time('mysql');
    $post_modified = isset($entry['modified']) ? $entry['modified'] : current_time('mysql');
    $post_author = isset($entry['author']) ? get_user_by('login', $entry['author'])->ID ?? 1 : 1;
    $post_parent = isset($entry['post_parent']) ? intval($entry['ID']) : 0;
    $post_name = isset($entry['post_name']) ? esc_html($entry['post_name']) : '';
    $thumbnail_path = $entry['thumbnail_path'] ?? null;

    $error_logs[] = "==={$post_title}(ID:{$post_id} TYPE:{$post_type})===";

    // 投稿タイプが登録されていない場合はスキップ
    if (!post_type_exists($post_type)) {
      echo "<tr class='skip_line'><td>" . ($index + 1) . "</td><td>{$post_title}</td><td>{$post_type}</td><td>" . __("Skip (unregistered post type)", "post-migration") . "</td></tr>";
    }

    //ID上書きのリビジョンデータはスキップ
    if ($post_id > 0 && get_post($post_id) && $import_mode === "update" && $post_type === "revision") {
      echo "<tr class='skip_line'><td>" . ($index + 1) . "</td><td>{$post_title}</td><td>{$post_type}</td><td>" . __("Skip (Existing data available)", "post-migration") . "</td></tr>";
      continue;
    }

    //投稿本文内のメディアファイルのパスを配列にする
    $post_content = $entry['content'] ?? '';
    $content_mediaURLs = [];
    if (isset($post_content)) {
      $matches = [];
      preg_match_all('/exported_media\/(.+?\.[a-zA-Z0-9]+)/u', $post_content, $matches);
      $content_mediaURLs = $matches[0] ?? []; // `matches[0]` にフルパス名が格納される
    }

    // 本文内画像のインポートと本文の修正
    foreach ($content_mediaURLs as $media_url) {
      $attachment_id = itmar_import_thumbnail_from_zip($zip, $media_url, 0);
      //本文内の$media_urlとアタッチメントIDで取得したURLを置換
      if (!is_wp_error($attachment_id)) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        $post_content = str_replace($media_url, $attachment_url, $post_content);
      } else {
        $error_logs[] = $attachment_id->get_error_message();
      }
    }

    // 投稿データ
    $post_data = array(
      'post_title'   => $post_title,
      'post_content' => wp_slash($post_content),
      'post_excerpt' => $entry['excerpt'] ?? '',
      'post_status'  => $post_status,
      'post_type'    => $post_type,
      'post_date'     => $post_date,
      'post_modified' => $post_modified,
      'post_author'   => $post_author,
    );
    //revisionレコードの場合
    if ($parent_id != 0 && $post_type === "revision") {
      $post_data["post_parent"] = $parent_id;
      $post_data['post_name'] = "{$parent_id}-revision-v1"; // 一意なリビジョン名
    } else {
      $post_data['post_name'] = $post_name;
    }



    // インポートモードがupdateで、既存投稿があれば上書き、なければ新規追加
    if ($post_id > 0 && get_post($post_id) && $import_mode === "update") {
      $post_data['ID'] = $post_id;
      $updated_post_id = wp_update_post($post_data, true);
      if (is_wp_error($updated_post_id)) {
        $result = __("Error (update failed)", "post-migration");
        $error_logs[] = "ID " . $post_id . ": " . $updated_post_id->get_error_message();
      } else {
        $result = __("Overwrite successful", "post-migration");
        $new_post_id = $updated_post_id;
      }
    } else {
      $new_post_id = wp_insert_post($post_data, true);
      if (is_wp_error($new_post_id)) {
        $result = __("Error (addition failed)", "post-migration");
        $error_logs[] = "ID " . $post_id . ": " . $new_post_id->get_error_message();
      } else {
        $result = __("Addition successful", "post-migration");
      }
    }

    //親データとしてIDをキープ
    if ($post_status != "inherit") {
      $parent_id = $new_post_id;
    }


    //投稿データのインポート終了後
    if ($new_post_id && !is_wp_error($new_post_id)) {
      // **ターム（カテゴリー・タグ・カスタム分類）を登録**
      foreach ($entry['terms'] as $taxonomy => $terms) {
        $tax_result = wp_set_object_terms($new_post_id, $terms, $taxonomy);
        //エラーの場合はエラーを記録
        if (is_wp_error($tax_result)) {
          $error_logs[] = "ID " . $new_post_id . ": " . $tax_result->get_error_message() . " (タクソノミー: {$taxonomy})";
        }
      }
      if (!empty($thumbnail_path)) {
        // アイキャッチ画像のインポートと設定
        $attachment_id = itmar_import_thumbnail_from_zip($zip, $thumbnail_path, $new_post_id);
        if (!is_wp_error($attachment_id)) {
          set_post_thumbnail($new_post_id, $attachment_id);
        } else {
          $error_logs[] = $attachment_id->get_error_message();
        }
      }

      //カスタムフィールドのインポート
      if (isset($entry['custom_fields'])) {
        foreach ($entry['custom_fields'] as $field => $value) {
          update_post_meta($new_post_id, $field, $value);
        }
      }
      //acfフィールドのインポート
      if (isset($entry['acf_fields'])) {
        if (itmar_is_acf_active()) { //acfのインストールチェック
          $acf_fields = $entry['acf_fields'];

          //メディアフィールドを探索し、メディアをアップロードしてフィールド値をidで置き換え
          foreach ($acf_fields as $key => $value) {
            if (preg_match('/exported_media\/(.+?\.[a-zA-Z0-9]+)/u', $value, $matches)) { //メディアフィールド
              $attachment_id = itmar_import_thumbnail_from_zip($zip, $matches[0], $new_post_id);
              //本文内の$media_urlとアタッチメントIDで取得したURLを置換
              if (!is_wp_error($attachment_id)) {
                $acf_fields[$key] = $attachment_id;
              } else {
                $error_logs[] = $attachment_id->get_error_message();
              }
            }
          }

          $group_fields = []; // グループフィールドを格納する配列

          foreach ($acf_fields as $key => $value) {
            // グループのプレフィックスを探す
            if ($value === '_group') {
              $group_prefix = $key . '_'; // グループのプレフィックス
              $group_fields[$key] = []; // グループフィールドの配列を初期化

              // グループ要素を抽出
              foreach ($acf_fields as $sub_key => $sub_value) {
                if (strpos($sub_key, $group_prefix) === 0) {
                  $sub_field_key = str_replace($group_prefix, '', $sub_key);
                  $group_fields[$key][$sub_field_key] = $sub_value;
                }
              }
            }
          }

          // 通常のフィールドを更新
          foreach ($acf_fields as $key => $value) {
            if ($value === '_group') {
              continue; // グループ要素はここでは処理しない
            }
            update_field($key, $value, $new_post_id);
          }

          // グループフィールドを更新
          foreach ($group_fields as $group_key => $group_value) {
            update_field($group_key, $group_value, $new_post_id);
          }
        } else {
          $error_logs[] = "ID " . $new_post_id . ": ACFまたはSCFがインストールされていません。";
        }
      }
      //コメントのインポート
      if (isset($entry['comments'])) {
        itmar_insert_comments_with_meta($entry['comments'], $new_post_id, $import_mode === "update");
      }
    }

    $line_class = $post_type === 'revision' ? 'rev_line' : 'data_line';
    echo "<tr class='{$line_class}'><td>" . ($index + 1) . "</td><td>{$post_title}</td><td>{$post_type}</td><td>{$result}</td></tr>";
  }

  echo '</tbody>';
  echo '</table>';

  // エラーログがある場合、ファイルを作成してダウンロード
  if (!empty($error_logs)) {
    $filename = pathinfo($zip_path, PATHINFO_FILENAME);
    $log_file_path = wp_upload_dir()['path'] . "/error_log_{$filename}.html";

    // **HTMLヘッダーを追加**
    $html_content = "<!DOCTYPE html>
    <html lang='ja'>
    <head>
        <meta charset='UTF-8'>
        <title>" . esc_html__("Post Data Import", "post-migration") . "</title>
    </head>
    <body>
    <pre>" . implode("\n", $error_logs) . "</pre>
    </body>
    </html>";

    // UTF-8 に変換して保存
    file_put_contents($log_file_path, mb_convert_encoding($html_content, "UTF-8", "auto"));

    // ダウンロード用のURLを取得
    $log_file_url = wp_upload_dir()['url'] . "/error_log_{$filename}.html";
    //エラーログ表示のリンク
    echo wp_kses_post("<div class='post_trns_link'><a href='{$log_file_url}' target='_blank'>" . esc_html__("Viewing Error Logs", "post-migration") . "</a></div>");
  }

  $zip->close();
}

// JSONインポート処理
function itmar_post_tranfer_import_json()
{
  if (!isset($_POST['submit_import']) || !check_admin_referer('custom_import_action', 'custom_import_nonce')) {
    return;
  }
  //インポートモードの取得
  if (isset($_POST['import_mode'])) {
    $import_mode = $_POST['import_mode'];
  }

  // ファイルが選択されていない場合はエラー
  if (empty($_FILES['import_file']['name'])) {
    echo '<div class="error"><p>' . esc_html__("Select the ZIP file.", "post-migration") . ' </p></div>';
    return;
  }

  // アップロードされたZIPファイルの処理
  $file = $_FILES['import_file'];
  $upload_dir = wp_upload_dir();
  $zip_path = $upload_dir['path'] . '/' . basename($file['name']);

  // ZIPファイルを一時ディレクトリに保存
  if (!move_uploaded_file($file['tmp_name'], $zip_path)) {
    echo '<div class="error"><p>' . esc_html__("File upload failed.", "post-migration") . '</p></div>';
    return;
  }

  // ZIPを展開
  $zip = new ZipArchive;
  if ($zip->open($zip_path) === true) {
    $json_filename = 'export_data.json';
    $json_path = $upload_dir['path'] . '/' . $json_filename;

    // JSONファイルを展開
    if ($zip->locateName($json_filename) !== false) {
      $zip->extractTo($upload_dir['path'], $json_filename);
    } else {
      echo '<div class="error"><p>' . esc_html__("The ZIP file does not contain \"export_data.json\"", "post-migration") . '</p></div>';
      $zip->close();
      unlink($zip_path); // ZIP削除
      return;
    }

    $zip->close();

    // JSONファイルの読み込み
    if (file_exists($json_path)) {
      $json_data = file_get_contents($json_path);
      $decoded_data = json_decode($json_data, true);

      if (!empty($decoded_data) && is_array($decoded_data)) {
        itmar_process_import_data($decoded_data, $zip_path, $import_mode);
      } else {
        echo '<div class="error"><p>' . esc_html__("Failed to parse JSON data.", "post-migration") . '</p></div>';
      }

      unlink($json_path); // JSON削除
    } else {
      echo '<div class="error"><p>' . esc_html__("I can't find the extracted JSON file.", "post-migration") . '</p></div>';
    }
  } else {
    echo '<div class="error"><p>' . esc_html__("Failed to extract ZIP file.", "post-migration") . '</p></div>';
  }

  unlink($zip_path); // ZIP削除
}

//ダウンロード関数
function itmar_download_image($image_url, $save_folder)
{
  // 画像のURLからファイル名を取得
  $parse_url = parse_url($image_url, PHP_URL_PATH);
  if (!$parse_url) { //ファイル名がパースできない場合
    return false;
  }
  $image_filename = basename(parse_url($image_url, PHP_URL_PATH));

  // 保存先のパスを決定
  $image_path = $save_folder . $image_filename;

  // 既にファイルが存在する場合はダウンロードしない
  if (file_exists($image_path)) {
    return $image_path;
  }

  //ローカルサーバーか否かの判定
  $is_local_environment = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local';

  $response = wp_remote_get($image_url, [
    'sslverify' => !$is_local_environment, // ローカルサーバーではSSL 検証を無効化
    'timeout'   => 20, // タイムアウト設定
  ]);

  if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    return false; // 取得失敗
  }

  $image_data = wp_remote_retrieve_body($response);
  if (!$image_data) {
    return false;
  }

  // ファイルを保存
  if (file_put_contents($image_path, $image_data) !== false) {
    return $image_path; // 成功したらファイル名を返す
  }

  return false; // 失敗した場合
}

//コンテンツからメディアURLを抜き出す関数
function itmar_extract_media_urls($content)
{
  $media_urls = [];

  // 画像・メディアURLを正規表現で抽出
  preg_match_all('/https?:\/\/[^\"\'\s]+(?:jpg|jpeg|png|gif|mp4|mp3|pdf)/i', $content, $matches);

  if (!empty($matches[0])) {
    $media_urls = array_unique($matches[0]); // 重複を除外
  }

  return $media_urls;
}

//acfがアクティブかどうかを判定する関数
function itmar_is_acf_active()
{
  return function_exists('get_field') && function_exists('get_field_object');
}

//コメントデータの取得（metaデータを含む）
function itmar_get_comments_with_meta($post_id)
{
  $args = array(
    'post_id' => $post_id,
    'status'  => 'approve',
    'orderby' => 'comment_date',
    'order'   => 'ASC'
  );

  $comments = get_comments($args);
  $formatted_comments = array();

  foreach ($comments as $comment) {
    // メタデータを取得
    $meta_data = get_comment_meta($comment->comment_ID);
    $meta_formatted = array();

    // メタデータを整形（配列をそのまま使うとJSONで不便なので平坦化）
    foreach ($meta_data as $key => $value) {
      $meta_formatted[$key] = is_array($value) ? $value[0] : $value; // 配列なら最初の値だけ取得
    }

    // コメントデータをフォーマット（メタデータを "meta" キーに格納）
    $formatted_comments[] = array(
      'comment_ID'         => strval($comment->comment_ID),
      'comment_post_ID'    => strval($comment->comment_post_ID),
      'comment_author'     => $comment->comment_author,
      'comment_author_email' => $comment->comment_author_email,
      'comment_date'       => $comment->comment_date,
      'comment_date_gmt'   => $comment->comment_date_gmt,
      'comment_content'    => $comment->comment_content,
      'comment_karma'      => strval($comment->comment_karma),
      'comment_approved'   => strval($comment->comment_approved),
      'comment_type'       => $comment->comment_type,
      'comment_parent'     => strval($comment->comment_parent),
      'user_id'            => strval($comment->user_id),
      'meta'               => $meta_formatted // メタデータを "meta" に格納
    );
  }

  return $formatted_comments;
}

//コメントをメタデータとともにインサートする関数
function itmar_insert_comments_with_meta($comments_data, $post_id, $override_flg)
{
  global $wpdb;


  $comment_id_map = []; // 旧コメントID → 新コメントID のマッピング用配列
  $pending_comments = []; // 親コメントが未登録のコメントを一時保存

  // まず親コメントを登録（`comment_parent` が 0 のもの）
  foreach ($comments_data as $comment_data) {
    $existing_comment = false; //上書きの判断フラグを初期化
    if ($override_flg) {
      // 既存のコメントがあるか確認
      $existing_comment = $wpdb->get_var($wpdb->prepare(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID = %d",
        $comment_data['comment_ID']
      ));
    }
    if ($comment_data['comment_parent'] == 0) {
      $new_comment_id = itmar_post_single_comment($comment_data, $post_id, $existing_comment);
      if ($new_comment_id) {
        $comment_id_map[$comment_data['comment_ID']] = $new_comment_id;
      }
    } else {
      // 親コメントがまだ登録されていないので後で処理する
      $pending_comments[] = $comment_data;
    }
  }

  // 子コメントを登録（`comment_parent` が 0 以外のもの）
  foreach ($pending_comments as $comment_data) {
    $old_parent_id = $comment_data['comment_parent'];

    // マッピングが存在すれば、新しいIDに変換
    if (isset($comment_id_map[$old_parent_id])) {
      $comment_data['comment_parent'] = $comment_id_map[$old_parent_id];
    } else {
      // 親コメントが見つからない場合は 0 にする
      $comment_data['comment_parent'] = 0;
    }

    // 子コメントを挿入
    $new_comment_id = itmar_post_single_comment($comment_data, $post_id, $existing_comment);
    if ($new_comment_id) {
      $comment_id_map[$comment_data['comment_ID']] = $new_comment_id;
    }
  }
}

// 単一のコメントを `wp_insert_comment()` で挿入
function itmar_post_single_comment($comment_data, $post_id, $override_flg)
{
  $comment_arr = array(
    'comment_post_ID'      => intval($post_id),
    'comment_author'       => $comment_data['comment_author'],
    'comment_author_email' => $comment_data['comment_author_email'],
    'comment_content'      => $comment_data['comment_content'],
    'comment_date'         => $comment_data['comment_date'],
    'comment_date_gmt'     => $comment_data['comment_date_gmt'],
    'comment_karma'        => intval($comment_data['comment_karma']),
    'comment_approved'     => intval($comment_data['comment_approved']),
    'comment_type'         => $comment_data['comment_type'],
    'comment_parent'       => intval($comment_data['comment_parent']), // ここで新しいIDが適用される
    'user_id'              => intval($comment_data['user_id'])
  );
  if ($override_flg) {
    $comment_arr["comment_ID"] = $comment_data['comment_ID'];
    $new_comment_id = wp_update_comment($comment_arr);
    if ($new_comment_id === 1) { //更新成功であれば、メタデータのコメントIDを更新結果に代入
      $new_comment_id = $comment_data['comment_ID'];
    }
  } else {
    $new_comment_id = wp_insert_comment($comment_arr);
  }


  if ($new_comment_id) {
    //メタデータを update_comment_meta() を使うことで、既存のデータを上書き or 追加
    if (!empty($comment_data['meta'])) {
      foreach ($comment_data['meta'] as $meta_key => $meta_value) {
        update_comment_meta($new_comment_id, $meta_key, $meta_value);
      }
    }
  }
  return $new_comment_id;
}

// JSONエクスポート処理
function itmar_post_tranfer_export_json()
{
  if (isset($_POST['export_action']) && $_POST['export_action'] === 'export_json' && isset($_POST['all_export_posts']) && (isset($_POST['export_posts']) || isset($_POST['export_types']))) {
    //
    //個別に選択された投稿のID
    //$post_ids = isset($_POST['export_posts']) ? array_map('intval', $_POST['export_posts']) : [];
    $str_post_ids = isset($_POST['all_export_posts']) ? $_POST['all_export_posts'] : "";
    $post_ids = explode(",", $str_post_ids);
    $selected_post_types = isset($_POST['export_types']) ? $_POST['export_types'] : [];
    // 選択された投稿タイプの全ての投稿 ID を取得し統合
    $all_selected_posts = array_merge(...array_map(function ($post_type) {
      return get_posts([
        'post_type'      => $post_type,
        'posts_per_page' => -1, // 全投稿を取得
        'fields'         => 'ids' // ID のみ取得
      ]);
    }, $selected_post_types));
    //個別選択のIDと統合
    $selected_posts = array_unique(array_merge($post_ids, $all_selected_posts));

    //カスタムフィールドの選択設定
    $include_custom_fields = isset($_POST['include_custom_fields']);
    //リビジョンの選択設定
    $include_revisions = isset($_POST['include_revisions']);
    //コメントの選択設定
    $include_comments = isset($_POST['include_comments']);

    //リビジョンの取得（IDのみ）
    if ($include_revisions) { //チェックを確認
      $selected_posts_rev = array();
      foreach ($selected_posts as $post_id) {
        // 元の投稿IDを追加
        $selected_posts_rev[] = $post_id;
        $args = array(
          'post_type'   => 'revision',
          'post_status' => 'any',
          'post_parent' => $post_id,
          'numberposts' => -1, // すべての投稿を取得
          'fields'         => 'ids' // ID のみ取得
        );
        $rev_ids = get_posts($args);
        // リビジョンIDを追加（空でなければ）
        if (!empty($rev_ids)) {
          $selected_posts_rev = array_merge($selected_posts_rev, $rev_ids);
        }
      }
      //$selected_posts に上書き
      $selected_posts = $selected_posts_rev;
    }

    //エクスポートディレクトリの設定
    //$export_data = [];
    $upload_dir = wp_upload_dir();
    $save_folder = $upload_dir['basedir'] . '/exported_media/'; // 画像保存用ディレクトリ

    // ディレクトリがない場合は作成
    if (!file_exists($save_folder)) {
      wp_mkdir_p($save_folder);
    }

    // ZIP ファイルの保存先
    $zip_filename = $upload_dir['basedir'] . '/exported_data.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      wp_die('ZIP ファイルを作成できませんでした');
    }

    //JSON文字列を直接ファイルに書き込むためファイルを用意
    $json_path = $upload_dir['basedir'] . '/export_data.json';
    // ファイルを開く
    $fp = fopen($json_path, 'w');
    fwrite($fp, "[\n");
    //ファイルの先頭であることを示すフラグ
    $first = true;

    foreach ($selected_posts as $post_id) {
      $post = get_post($post_id);
      if ($post) {
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
          'post_status'     => $post->post_status,
          'post_parent'   => $post->post_parent,
          'thumbnail_url' => get_the_post_thumbnail_url($post->ID, 'full'), // 画像URL
          'thumbnail_path' => null, // 保存後の画像パス
          'terms'         => [] // タクソノミー情報を格納する配列
        ];

        // **投稿タイプに紐づくタクソノミーを取得**
        $taxonomies = get_object_taxonomies($post->post_type, 'names');

        // **タクソノミーごとにタームを取得**
        foreach ($taxonomies as $taxonomy) {
          $terms = get_the_terms($post->ID, $taxonomy);
          if (!empty($terms) && !is_wp_error($terms)) {
            // タームの名前のみ取得して配列に格納
            $post_data['terms'][$taxonomy] = wp_list_pluck($terms, 'name');
          } else {
            $post_data['terms'][$taxonomy] = []; // タームがない場合は空配列
          }
        }

        // カスタムフィールドを含める場合
        if ($include_custom_fields) { //チェックを確認
          //wp_postmetaから取り出す全ての関連データ
          $custom_fields = get_post_meta($post->ID);

          //WordPress の register_post_meta() で登録されたものだけを取得
          $registered_meta_keys = get_registered_meta_keys('post', $post->post_type);

          //カスタムフィールドの処理
          foreach ($custom_fields as $key => $value) {
            //acfがインストールされているときの処理
            if (itmar_is_acf_active()) {
              if (strpos($key, '_') !== 0) { // `_` 付きのフィールドをスキップ
                $field_object = get_field_object($key, $post->ID);
                //ACFフィールドである
                if ($field_object && isset($field_object['type'])) {
                  //フィールドタイプがイメージやファイルのものならダウンロード処理
                  if ($field_object['type'] === 'image' || $field_object['type'] === 'file') {
                    $value = get_field($key, $post->ID);
                    if ($value) { //値がなければ処理しない
                      //値が数値ならurlを取得、配列なら`url` を取得、それ以外はそのまま
                      if (is_numeric($value)) {
                        $media_url = wp_get_attachment_url($value);
                      } elseif (is_array($value) && isset($value['url'])) {
                        $media_url = $value['url'];
                      } else {
                        $media_url = $value;
                      }
                      //ダウンロード処理
                      if ($media_url) {
                        $media_path = itmar_download_image($media_url, $save_folder);
                        if ($media_path) {
                          $relative_path = 'exported_media/' . basename($media_path);
                          $zip->addFile($media_path, $relative_path);
                          $post_data['acf_fields'][$key] = $relative_path;
                        }
                      }
                    }
                  } else if ($field_object['type'] === 'group') {
                    //フィールド種別がグループの時は値を_groupとする
                    $post_data['acf_fields'][$key] = '_group';
                  } else {
                    $post_data['acf_fields'][$key] = maybe_unserialize($value[0]);
                  }
                  //WordPress の register_post_meta() で登録されたもの
                } else if (array_key_exists($key, $registered_meta_keys)) {
                  $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
                }
              }
              //acfがインストールされていないときの処理
            } else {
              $post_data['custom_fields'][$key] = maybe_unserialize($value[0]);
            }
          }
        }
        //コメントを含める場合
        if ($include_comments) { //チェックを確認
          //コメントデータをメタデータを含めて取りだし
          $comments = itmar_get_comments_with_meta($post->ID);
          $post_data['comments'] = maybe_unserialize($comments);
        }

        // アイキャッチ画像のダウンロード処理
        if ($post_data['thumbnail_url']) {
          if ($post_data['thumbnail_url']) {
            // ダウンロードの結果からパス・ファイル名を取得
            $image_path = itmar_download_image($post_data['thumbnail_url'], $save_folder);
            if ($image_path) {
              //ダウンロードが成功したらpost_dataのthumbnail_pathに記録して、zipファイルに追加
              $image_filename = basename($image_path);
              $post_data['thumbnail_path'] = 'exported_media/' . $image_filename; // ZIP 内のパス
              $zip->addFile($image_path, 'exported_media/' . $image_filename);
            }
          }
        }

        // 投稿本文内のメディアURLをダウンロード
        $content_media_urls = itmar_extract_media_urls($post->post_content);
        $modified_content = $post_data['content'];
        foreach ($content_media_urls as $media_url) {
          $media_path = itmar_download_image($media_url, $save_folder);
          if ($media_path) {
            //ダウンロードが成功したらコンテンツ内のファイルパスを書き換え
            $relative_path = 'exported_media/' . basename($media_path);
            $modified_content = str_replace($media_url, $relative_path, $modified_content);
            //zipファイルにメディアファイルを書き込み
            $zip->addFile($media_path, $relative_path);
          }
        }
        $post_data['content'] = $modified_content;

        //$export_data[] = $post_data;
        // JSON に変換
        $json_data = json_encode($post_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // JSON をファイルに追記（カンマ区切り）
        if (!$first) {
          fwrite($fp, ",\n");
        }
        fwrite($fp, $json_data);

        $first = false; // 最初のデータ処理が終わったことを記録
      }
    }
    // JSON 配列の閉じ
    fwrite($fp, "\n]");
    fclose($fp);

    // JSON を ZIP に追加
    //$json_data = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    //$json_path = $upload_dir['basedir'] . '/export_data.json';
    //file_put_contents($json_path, $json_data);
    $zip->addFile($json_path, 'export_data.json');

    // ZIP を閉じる
    $zip->close();

    // ダウンロード用ヘッダー
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="exported_data.zip"');
    header('Content-Length: ' . filesize($zip_filename));

    // ZIP ファイルを出力
    readfile($zip_filename);

    // 一時ファイルを削除
    unlink($json_path);
    unlink($zip_filename);
    exit;
  }
}

add_action('admin_init', 'itmar_post_tranfer_export_json');
