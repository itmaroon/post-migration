<?php
/*
Plugin Name:  POST MIGRATION
Description:  This plugin allows you to export post data along with associated media, revisions, and comments into a ZIP file and port it to another WordPress site.
Requires at least: 6.4
Requires PHP:      8.2
Version:      0.1.0
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
function itmar_post_tranfer_script_init()
{
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

  //JS用のパラメータを読み込む
  wp_localize_script('wp-api-fetch', 'itmar_vars', [
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



    <script>
      document.addEventListener("DOMContentLoaded", function() {
        //Ajax送信先URL
        let ajaxUrl = ' <?php echo esc_url(admin_url('admin-ajax.php', __FILE__)); ?>';

        // ZIP 内のファイルを保存するグローバル変数
        let zipFiles = {};
        //ZIPファイルのリーダー

        document.getElementById("inportForm").addEventListener("submit", async function(event) {
          event.preventDefault();

          // **オーバーレイを表示**
          await ProgressOverlay.show("<?php echo esc_js(esc_html__("Parsing import file...", "post-migration")) ?>");

          // `inport_result` を取得
          const inportResult = document.querySelector(".inport_result");
          const tbody = document.querySelector(".post_trns_tbody");
          // **開始時に `inport_result` を表示 & tbody を空にする**
          inportResult.style.display = "block"; // 表示
          tbody.innerHTML = ""; // tbody の内容をリセット
          //インポートモード
          let import_mode = document.querySelector('input[name="import_mode" ]:checked').value;
          //ファイル名
          let fileInput = document.getElementById("import_file");
          if (fileInput.files.length === 0) {
            alert("<?php echo esc_js(esc_html__("Select the ZIP file.", "post-migration")) ?>");
            ProgressOverlay.cancel();
            return;
          }
          let file = fileInput.files[0];

          const zip = new JSZip();
          const zipData = await file.arrayBuffer(); // ZIPデータを取得
          const unzipped = await zip.loadAsync(zipData); // ZIP解凍
          zipFiles = unzipped.files; //グローバル変数に渡す
          // "export_data.json" を探す
          const jsonFile = unzipped.file("export_data.json");
          if (!jsonFile) {
            alert("<?php echo esc_js(esc_html__("export_data.json not found.", "post-migration")) ?>");
            ProgressOverlay.cancel();
            return;
          }
          const jsonText = await jsonFile.async("text");
          // JSONデータを解析
          const jsonDataArray = JSON.parse(jsonText);
          //本体データとリビジョンデータをまとめてjson配列を再構成
          const groupedData = [];
          let tempGroup = [];

          for (let i = 0; i < jsonDataArray.length; i++) {
            const item = jsonDataArray[i];

            // 最初の1件を追加
            if (tempGroup.length === 0) {
              tempGroup.push(item);
              continue;
            }

            // post_typeが"revision"なら現在のグループに追加
            if (item.post_type === "revision") {
              tempGroup.push(item);
            } else {
              // それ以外なら、現在のグループを保存して新しいグループを作成
              groupedData.push([...tempGroup]);
              tempGroup = [item]; // 新しいグループの開始
            }
          }

          // 最後のグループを追加
          if (tempGroup.length > 0) {
            groupedData.push([...tempGroup]);
          }

          // **結果ログを格納する配列**
          const result_log = [];

          const totalItems = groupedData.length; // **合計アイテム数**
          let processedItems = 0; // **処理済みカウント**

          //最初の１件が終了してからプログレスの形状を変えるためのフラグ
          let first_flg = true;

          //jsonDataを順次サーバーに送る
          for (const jsonData of groupedData) {
            //メディアファイルの収集
            const mediaData = [];

            for (const postData of jsonData) {
              //サムネイルのメディアファイルデータを取得
              if (postData.thumbnail_path) {
                const file = await extractMediaFile(postData.thumbnail_path);

                // すでに同じファイル名が存在するかをチェック
                const isDuplicate = mediaData.some(existingFile => existingFile.name === file.name);
                if (!isDuplicate) {
                  mediaData.push(file);
                }
              }
              //投稿本文内のメディアファイルデータを取得
              const content_medias = [];
              if (postData.content) { //投稿本文からファイルのpathを取得
                const regex = /exported_media\/(.+?\.[a-zA-Z0-9]+)/gu; // "g" (global) と "u" (Unicode)
                const matches = [...postData.content.matchAll(regex)]; // すべての一致を取得

                // matches[0] 相当の結果を取得（完全一致した部分を取得）
                const contentMediaPaths = matches.map(match => match[0]);
                //メディアデータを取得
                for (const media_path of contentMediaPaths) {
                  if (media_path) {
                    const file = await extractMediaFile(media_path);
                    if (file !== null) {
                      // すでに同じファイル名が存在するかをチェック
                      const isDuplicate = mediaData.some(existingFile => existingFile.name === file.name);
                      if (!isDuplicate) {
                        mediaData.push(file);
                      }
                    } else {
                      console.error("Not exist:", media_path);
                    }
                  }
                }
              }
              //acfメディアデータの取得
              const acf_medias = [];
              const regex = /exported_media\/(.+?\.[a-zA-Z0-9]+)/u; // "u" (Unicode)

              if (postData.acf_fields) {
                // Object.entries() を使って key-value をループ
                for (const [key, value] of Object.entries(postData.acf_fields)) {
                  if (regex.test(value)) { // 正規表現でマッチするかチェック
                    const file = await extractMediaFile(value);
                    // すでに同じファイル名が存在するかをチェック
                    const isDuplicate = mediaData.some(existingFile => existingFile.name === file.name);
                    if (!isDuplicate) {
                      mediaData.push(file);
                    }
                  }
                }
              }
            }

            try {
              const resultObj = await sendFetchData(jsonData, mediaData, import_mode);


              if (first_flg) {
                // **解析完了後の処理**
                ProgressOverlay.showChange();
                first_flg = false; //フラグをおろす
              }

              //プログレスバーの更新関数
              processedItems++;
              ProgressOverlay.changeProgress(totalItems, processedItems);

              // `result` からデータを取得 (サーバーのレスポンス構造に応じて修正)
              const {
                id,
                title,
                result,
                log,
                message
              } = resultObj;
              //キャンセルが検出されたら終了(ループから抜ける)
              if (result === "cancel") break;

              //テーブルに結果出力
              const line_class = result === 'error' ?
                'skip_line' :
                'data_line';


              // `tr` 要素を作成
              const tr = document.createElement("tr");
              tr.classList.add(line_class); // クラスを追加

              tr.innerHTML = `
                  <td>${id}</td>
                  <td>${title}</td>
                  <td>${result}</td>
                  <td>${message}</td>
              `;

              // テーブルに追加
              tbody.appendChild(tr);
              //ログの集積
              log.push(""); // 空白行
              result_log.push(...log);

            } catch (error) {
              console.error("送信エラー:", error);
            }
          }

          // **完了時にオーバーレイを非表示**
          ProgressOverlay.hide();

          // エラーログがある場合、ファイルを作成してダウンロード
          if (result_log.length != 0) {
            // **ログファイルを作成して保存**
            createLogFile(result_log);
          }
        });

        /**
         * result_log を HTML 文書として生成し、ファイルを保存
         */
        function createLogFile(result_log) {
          // HTML ドキュメントの作成
          let logHtml = `<!DOCTYPE html>
            <html lang="ja">
            <head>
                <meta charset="UTF-8">
                <title>Import Log</title>
            </head>
            <body>
                <h2>Import Log</h2>
                <pre>${result_log.join("\n")}</pre>
            </body>
            </html>`;

          // Blob 生成
          const blob = new Blob([logHtml], {
            type: "text/html"
          });
          const url = URL.createObjectURL(blob);

          // **ダウンロードリンクの作成**
          let logLink = document.createElement("a");
          logLink.href = url;
          logLink.download = "import_log.html";
          logLink.textContent = "<?php echo esc_js(esc_html__("Download the import log", "post-migration")); ?>";
          logLink.style.display = "block";
          logLink.style.marginTop = "10px";

          // `inport_result` の後に挿入
          document.querySelector(".inport_result").after(logLink);
        }

        async function extractMediaFile(mediaPath) {
          if (!zipFiles || Object.keys(zipFiles).length === 0) {
            return null;
          }
          //ファイルの存在確認
          const matchingFile = Object.keys(zipFiles).find((fileName) =>
            fileName.includes(mediaPath)
          );
          if (!matchingFile) {
            return null;
          }
          // ファイルのバイナリデータを取得
          const fileData = await zipFiles[matchingFile].async("arraybuffer");

          const file = new File([fileData], matchingFile, {
            type: "application/octet-stream"
          });
          // ✅ `File` オブジェクトに `mediaPath` を追加
          return file;
        }


        async function sendFetchData(postData, mediaData, import_mode) {

          const formData = new FormData();
          formData.append('action', 'post_data_fetch');
          formData.append('nonce', itmar_vars.nonce);
          formData.append('post_data', JSON.stringify(postData)); // JSON化して送信
          formData.append('import_mode', import_mode);
          // ✅ mediaData の各ファイルを FormData に追加
          mediaData.forEach((file, index) => {
            formData.append(`media_files[${index}]`, file);
          });

          // サーバーに送信
          try {
            const response = await fetch(ajaxUrl, {
              method: 'POST',
              body: formData,
            });

            if (!response.ok) {
              throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json(); // ✅ **PHP からの戻り値を受け取る**

            return data;

          } catch (error) {
            console.error('Fetch error:', error);
            return data;
          }
        }

      });
    </script>

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
  $db_obj = new \Itmar\WpSettingClassPackage\ItmarDbAction();

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
    <script>
      let isNavigatingWithinPlugin = false; // 「前へ」「次へ」ボタンでの遷移かどうかを判定
      const storageKey = "itmar_selected_posts";
      //jQuery.post を async/await に対応させる（ラップ関数を作る）
      function postAsync(url, data) {
        return new Promise((resolve, reject) => {
          jQuery.post(url, data, function(response) {
            if (response.success) {
              resolve(response);
            } else {
              reject(response);
            }
          }).fail((jqXHR, textStatus, errorThrown) => {
            reject({
              jqXHR,
              textStatus,
              errorThrown
            });
          });
        });
      }


      document.addEventListener("DOMContentLoaded", function() {
        //エクスポート処理の開始
        const form = document.getElementById("exportForm");

        if (form) {
          form.addEventListener("submit", async function(event) {
            event.preventDefault(); // ページリロードを止める
            ProgressOverlay.show(); // オーバーレイを表示
            ProgressOverlay.showChange();

            const formData = jQuery(this).serializeArray(); // ← export_posts[] 含む
            formData.push({
              name: 'nonce',
              value: itmar_vars.nonce
            });


            // Step1: selectedPosts を取得
            const getIdsPrm = [...formData, {
              name: 'action',
              value: 'itmar_export_ids'
            }];
            const idsResponse = await postAsync(itmar_vars.ajaxurl, getIdsPrm);
            const selectedPosts = idsResponse.data.selected_posts;
            const total = selectedPosts.length;

            // エクスポート先のzipを定義
            const zip = new JSZip();
            const allPostsData = []; // 投稿データを格納
            const mediaUrlSet = new Set(); // ← ここにURLを蓄積

            for (let index = 0; index < total; index++) {
              const post_id = selectedPosts[index];

              // Step2: 個別IDを使って export_json を送信
              const exportPrm = [
                ...formData,
                {
                  name: 'action',
                  value: 'itmar_export_json'
                },
                {
                  name: 'post_id',
                  value: post_id
                }
              ];

              try {
                //サーバーからデータ取得
                const response = await postAsync(itmar_vars.ajaxurl, exportPrm);
                ProgressOverlay.changeProgress(total, index + 1);

                if (response.success) {
                  // 1件分の投稿データを配列に集積
                  const postJson = response.data.json;
                  allPostsData.push(postJson);
                  //メディアのURLを集積
                  const mediaUrls = response.data.media_urls || []; // 各投稿が返すメディアURL配列
                  mediaUrls.forEach(url => mediaUrlSet.add(url)); // ← Set に追加（重複無視）
                }
              } catch (error) {
                console.warn('Export failed for post ID:', post_id, error);
                // 失敗時も進めるならここでcontinue相当
                ProgressOverlay.changeProgress(total, index + 1);
              }
            }

            // JSON配列として1ファイルにまとめてZIPに追加
            const jsonString = JSON.stringify(allPostsData, null, 2); // JSON配列形式に整形
            zip.file('export_data.json', jsonString);

            // すべての投稿の処理が終わったあとにメディア一括処理
            const media_total = mediaUrlSet.size;
            let media_count = 0;

            for (const mediaUrl of mediaUrlSet) {
              ProgressOverlay.changeProgress(media_total, media_count + 1);
              if (mediaUrl) {

                const filename = mediaUrl.split('/').pop();
                try {

                  const blob = await fetch(mediaUrl).then(res => res.blob());
                  zip.file(`exported_media/${filename}`, blob);
                } catch (err) {
                  console.warn(`Failed to fetch media: ${mediaUrl}`, err);
                }
              }
              media_count++;

            }

            // ZIPファイルを生成して保存
            zip.generateAsync({
              type: 'blob'
            }).then((content) => {
              saveAs(content, 'exported_data.zip'); // ZIPファイルをダウンロード
              ProgressOverlay.hide();
            });

          });
        }

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
          document.querySelectorAll("input[name='export_types[]']").forEach(function(checkbox) {
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
            ProgressOverlay.show(); // "オーバーレイを表示"

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

  $db_obj = new \Itmar\WpSettingClassPackage\ItmarDbAction();

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
