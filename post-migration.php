<?php
/*
Plugin Name:  POST MIGRATION
Description:  This plugin allows you to export post data along with associated media, revisions, and comments into a ZIP file and port it to another WordPress site.
Requires at least: 6.3
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
              console.log("Received result:", resultObj);

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


  $result = itmar_json_import_data($post_data, $sanitized_files, $import_mode);
  wp_send_json($result);
}

add_action('wp_ajax_post_data_fetch', 'itmar_post_data_fetch');
add_action('wp_ajax_nopriv_post_data_fetch', 'itmar_post_data_fetch');

//インポートのサーバーサイド実行処理
function itmar_json_import_data($groupArr, $uploaded_medias, $import_mode)
{
  //エラーログ
  $error_logs = [];
  //実行結果
  $result_arr = [];

  //親IDの初期化
  $parent_id = 0;

  //リビジョンの生成を止めてから挿入
  add_filter('wp_save_post_revision_check_for_changes', '__return_false');
  add_filter('wp_revisions_to_keep', '__return_zero', 10, 2);

  foreach ($groupArr as $entry) {
    //JSONのデコード結果から情報を取り出し
    $post_id = isset($entry['ID']) ? intval($entry['ID']) : 0;
    $post_title = isset($entry['title']) ? esc_html($entry['title']) : '';
    $post_type = isset($entry['post_type']) ? esc_html($entry['post_type']) : '';
    $post_status = isset($entry['post_status']) ? esc_html($entry['post_status']) : '';
    $post_date = isset($entry['date']) ? $entry['date'] : current_time('mysql');
    $post_modified = isset($entry['modified']) ? $entry['modified'] : current_time('mysql');
    $post_author = isset($entry['author']) ? get_user_by('login', $entry['author'])->ID ?? 1 : 1;
    $post_name = isset($entry['post_name']) ? esc_html($entry['post_name']) : '';
    $thumbnail_path = $entry['thumbnail_path'] ?? null;

    // 投稿タイプが登録されていない場合はスキップ
    if (!post_type_exists($post_type)) {
      $error_logs[] = __("Skip (unregistered post type)", "post-migration");
      $result_arr = [
        'result' => 'error',
        'id' => null,
        'message' => __("Skip (unregistered post type)", "post-migration"),
        'log' => $error_logs
      ];
      return $result_arr;
    }

    //ID上書きのリビジョンデータはスキップ
    if ($post_id > 0 && get_post($post_id) && $import_mode === "update" && $post_type === "revision") {
      $error_logs[] = __("Skip (Existing revison data available)", "post-migration");
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

    // インポートモードがupdateで、既存投稿があり、ポストタイプが一致すれば上書き、なければ新規追加
    $post_check = get_post($post_id);
    if ($post_id > 0 && get_post($post_id) && $import_mode === "update" && $post_check->post_type === $post_type) {
      $post_data['ID'] = $post_id;
      $updated_post_id = wp_update_post($post_data, true);
      if (is_wp_error($updated_post_id)) {
        $result = __("Error (update failed)", "post-migration");
        $error_logs[] = "ID " . $post_id . ": " . $updated_post_id->get_error_message();
      } else {
        $result = __("Overwrite successful", "post-migration");
        if ($post_type === "revision") {
          $error_logs[] = __("Addition successful", "post-migration");
        }
        $new_post_id = $updated_post_id;
      }
    } else {
      $new_post_id = wp_insert_post($post_data, true);

      if (is_wp_error($new_post_id)) {
        $result = __("Error (addition failed)", "post-migration");
        $error_logs[] = "ID " . $post_id . ": " . $new_post_id->get_error_message();
      } else {
        $result = __("Addition successful", "post-migration");
        if ($post_type === "revision") {
          $error_logs[] = __("Addition successful", "post-migration");
        }
      }
    }

    //親データとしてIDをキープとログの記録
    if ($post_status != "inherit") {
      $parent_id = $new_post_id;
      $error_logs[] = "==={$post_title}(ID:{$new_post_id} TYPE:{$post_type})===";
    } else {
      //ログの記録
      $error_logs[] = "( ID:{$new_post_id} TYPE:{$post_type} Parent ID:{$parent_id})";
    }


    //投稿データのインポート終了後
    if ($new_post_id && !is_wp_error($new_post_id)) {
      // **ターム（カテゴリー・タグ・カスタム分類）を登録**
      foreach ($entry['terms'] as $taxonomy => $terms) {
        $tax_result = wp_set_object_terms($new_post_id, $terms, $taxonomy);
        //エラーの場合はエラーを記録
        if (is_wp_error($tax_result)) {
          $error_logs[] = "ID " . $new_post_id . ": " . $tax_result->get_error_message() . __("Taxonomy: ", "post-migration") . $taxonomy;
        } else {
          $error_logs[] = __("Taxonomy: ", "post-migration") . $taxonomy . "  " . __("has been registered.", "post-migration");
        }
      }

      //カスタムフィールドのインポート
      if (isset($entry['custom_fields'])) {
        foreach ($entry['custom_fields'] as $field => $value) {
          update_post_meta($new_post_id, $field, $value);
          $error_logs[] = __("Custom Field Import:", "post-migration") . $field;
        }
      }
      //acfフィールドのインポート
      if (isset($entry['acf_fields'])) {
        if (itmar_is_acf_active()) { //acfのインストールチェック
          $acf_fields = $entry['acf_fields'];
          $acf_mediaURLs = [];
          //メディアフィールドを探索し、メディアのURLを配列に格納
          foreach ($acf_fields as $key => $value) {
            if (preg_match('/exported_media\/(.+?\.[a-zA-Z0-9]+)/u', $value, $matches)) { //メディアフィールド
              $acf_mediaURLs[] = [
                'key' => $key,
                'value' => $value
              ];
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
            $error_logs[] = __("Custom Field Import(ACF):", "post-migration") . $key;
          }

          // グループフィールドを更新
          foreach ($group_fields as $group_key => $group_value) {
            update_field($group_key, $group_value, $new_post_id);
            $error_logs[] = __("Custom Field Import(ACF GROUP):", "post-migration") . $group_key;
          }
        } else {
          $error_logs[] = "ID " . $new_post_id . __(": ACF or SCF is not installed", "post-migration");
        }
      }
      //コメントのインポート
      if (isset($entry['comments'])) {
        $result_count = itmar_insert_comments_with_meta($entry['comments'], $new_post_id, $import_mode === "update");
        $error_logs[] = $result_count . __("comment item has been registered.", "post-migration");
      }
    }

    //メディアのアップロードとレコードのセット
    //サムネイル
    if ($thumbnail_path) {
      $media_result = itmar_set_media($uploaded_medias, $new_post_id, $thumbnail_path, "thumbnail");
      $error_logs[] = $media_result['message'];
    }
    //コンテンツ内画像
    $updated_content = $post_content; //コンテンツを別の変数に置き換え
    foreach ($content_mediaURLs as $content_path) {
      if ($content_path) {
        $media_result = itmar_set_media($uploaded_medias, $new_post_id, $content_path, "content");
        $updated_content = str_replace($content_path, $media_result['attachment_url'], $updated_content);
        $error_logs[] = $media_result['message'];
      }
    }
    // 投稿を更新
    $update_data = array(
      'ID'           => $new_post_id,
      'post_content' => wp_slash($updated_content),
    );
    wp_update_post($update_data, true);
    //ACF画像
    foreach ($acf_mediaURLs as $acf_path) {
      if ($acf_path) {
        $media_result = itmar_set_media($uploaded_medias, $new_post_id, $acf_path, "acf_field");
        $error_logs[] = $media_result['message'];
      }
    }

    //inherit以外のレコードで結果生成
    if ($post_status != "inherit") {
      $result_arr = [
        'result' => $post_type,
        'id' => $new_post_id,
        'title' => $post_title,
        'parentID' => $parent_id,
        'message' => $result,
      ];
    }
  }
  //リビジョンの生成を戻す
  remove_filter('wp_save_post_revision_check_for_changes', '__return_false');
  remove_filter('wp_revisions_to_keep', '__return_zero', 10);
  //ログは最後に入れる
  $result_arr['log'] = array_map('esc_html', $error_logs);
  return $result_arr;
}


//インポートメディアの処理
function itmar_set_media($media_array, $post_id, $file_path, $media_type)
{
  //acf_fieldのときはオブジェクトが来るのでそれに対応
  if ($media_type === 'acf_field') {
    $file_name = basename($file_path['value']);
    $acf_field = $file_path['key'];
  } else {
    $file_name = basename($file_path);
  }

  // `name` キーに `$file_name` が一致する要素を検索
  $matched_files = array_filter($media_array, function ($file) use ($file_name) {
    return $file['name'] === $file_name;
  });
  // 1つだけ取得
  $file = reset($matched_files) ?: null;
  //取得できなければ終了
  if (is_null($file)) {
    return array(
      "status" => 'error',
      "message" => __("File not found (file name:", "post-migration") . $file_name . ")",
    );
  }

  $upload_dir = wp_upload_dir();
  $dest_path = $upload_dir['path'] . '/' . basename($file['name']);

  if (file_exists($dest_path)) {
    //既に同じ名前のファイルが存在したらアップロードしない
    $attachment_id = itmar_get_attachment_id_by_file_path($dest_path);
    if ($attachment_id) {
      $result = 'success';
      $message = __("Processing stopped due to existing file found (media ID:", "post-migration") . $attachment_id . ")";
    }
  } else {
    // wp_handle_upload の前準備
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($file, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
      $dest_path = $movefile['file'];
      $file_name = isset($file['name']) ? $file['name'] : basename($dest_path);
      $filetype = wp_check_filetype(basename($dest_path), null);

      $attachment = array(
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($file_name),
        'post_content'   => '',
        'post_status'    => 'inherit'
      );

      $attachment_id = wp_insert_attachment($attachment, $dest_path);
      $attach_data = wp_generate_attachment_metadata($attachment_id, $dest_path);
      wp_update_attachment_metadata($attachment_id, $attach_data);

      // 成功時のレスポンス
      $result = 'success';
      $message  = __("File uploaded", "post-migration");
    } else {
      $result = 'error';
      $message  = __("Failed to upload file", "post-migration");
    }
  }

  // 投稿データにメディア情報を反映
  $attachment_url = wp_get_attachment_url($attachment_id);
  if ($attachment_id) {
    if ($media_type === 'thumbnail') {
      set_post_thumbnail($post_id, $attachment_id);
      $message = __('Upload thumbnail: ', "post-migration") . $message;
    } elseif ($media_type === 'content') {
      $message = __('Uploading in-content media: ', "post-migration") . $message;
    } elseif ($media_type === 'acf_field') {
      if (!empty($acf_field)) {
        update_field($acf_field, $attachment_id, $post_id);
        $message = __('Uploading acf media: ', "post-migration") . $message;
      }
    }
  }

  return array(
    "status" => $result,
    "message" => $message,
    "attachment_id" => $attachment_id,
    "attachment_url" => $attachment_url,
  );
}


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
            for (const mediaUrl of mediaUrlSet) {
              if (mediaUrl) {
                const filename = mediaUrl.split('/').pop();
                try {
                  const blob = await fetch(mediaUrl).then(res => res.blob());
                  zip.file(`exported_media/${filename}`, blob);
                } catch (err) {
                  console.warn(`Failed to fetch media: ${mediaUrl}`, err);
                }
              }

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
      if (itmar_is_acf_active()) {
        if (strpos($key, '_') !== 0) {
          $field_ID     = itmar_get_acf_field_key($key);
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
    $comments               = itmar_get_comments_with_meta($post->ID);
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
  $comment_id_map = []; // 旧コメントID → 新コメントID のマッピング用配列
  $pending_comments = []; // 親コメントが未登録のコメントを一時保存
  $ret_count = 0;

  // まず親コメントを登録（`comment_parent` が 0 のもの）
  foreach ($comments_data as $comment_data) {
    $existing_comment = false; //上書きの判断フラグを初期化
    if ($override_flg) {
      // 既存のコメントがあるか確認
      $existing_comment = get_comment($comment_data['comment_ID']);
    }
    if ($comment_data['comment_parent'] == 0) {
      $new_comment_id = itmar_post_single_comment($comment_data, $post_id, $existing_comment);
      if ($new_comment_id) {
        $comment_id_map[$comment_data['comment_ID']] = $new_comment_id;
        //登録コメント数をインクリメント
        $ret_count++;
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
      //登録コメント数をインクリメント
      $ret_count++;
    }
  }
  //登録数を返す
  return $ret_count;
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
    $comment_arr["comment_ID"] = intval($comment_data['comment_ID']);

    $new_comment_id = wp_update_comment($comment_arr);
    if ($new_comment_id === 1 || $new_comment_id === 0) { //更新成功であれば、メタデータのコメントIDを更新結果に代入
      $new_comment_id = intval($comment_data['comment_ID']);
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



//acfがアクティブかどうかを判定する関数
function itmar_is_acf_active()
{
  return function_exists('get_field') && function_exists('get_field_object');
}

//投稿タイプを取得する関数
function itmar_get_post_type_label($post_type)
{
  $post_type_object = get_post_type_object($post_type);
  return $post_type_object ? $post_type_object->label : '未登録の投稿タイプ';
}

//WordPress のメディアライブラリからファイルのメディア ID を取得する関数
function itmar_get_attachment_id_by_file_path($file_path)
{

  // WordPressのアップロードディレクトリの情報を取得
  $upload_dir = wp_upload_dir();

  // アップロードディレクトリを削除して相対パスを取得
  $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);

  // `_wp_attached_file` でメディアIDを取得（完全一致検索）

  $attachments = get_posts([
    'post_type'  => 'attachment',
    'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
      [
        'key'     => '_wp_attached_file',
        'value'   => $relative_path,
        'compare' => '=',
      ],
    ],
    'numberposts' => 1,
    'fields' => 'ids',
  ]);

  $attachment_id = ! empty($attachments) ? $attachments[0] : 0;


  return $attachment_id ? intval($attachment_id) : false;
}

//meta_key から field_XXXXXXX を取得
function itmar_get_acf_field_key($meta_key)
{
  $ret = false;

  // ACFフィールドをすべて取得（投稿オブジェクトで取得）
  $acf_fields = get_posts([
    'post_type'      => 'acf-field',
    'posts_per_page' => -1,
    'post_status'    => 'any',
  ]);

  if (! $acf_fields) {
    return false; // ACF フィールドが見つからない
  }

  $non_group_fields = [];

  // グループ・リピーター・フレキシブル以外のフィールドを抽出
  foreach ($acf_fields as $field) {
    $field_content = maybe_unserialize($field->post_content);

    if (
      ! isset($field_content['type']) ||
      ! in_array($field_content['type'], ['group', 'repeater', 'flexible_content'], true)
    ) {
      $non_group_fields[] = $field;
    }
  }

  // meta_key と post_excerpt の一致をチェック
  foreach ($non_group_fields as $field) {
    if ($field->post_excerpt === $meta_key) {
      return $field->post_name; // 完全一致で即返す
    } elseif (strpos($meta_key, $field->post_excerpt) !== false) {
      $potential_field = $field;
      $current_field = $potential_field;

      // 親フィールドの post_excerpt が $meta_key に含まれるか
      while ($current_field && $current_field->post_type !== 'acf-field') {
        $parent_key = (int) $current_field->post_parent;
        if (! $parent_key) {
          break;
        }
        // 親フィールドが見つからない場合は終了
        $parent_field = get_post($parent_key);
        if (! $parent_field) {
          $potential_field = null; // 仮候補を消去
          break;
        }
        // グループ名が含まれていなければ判定終了
        if (strpos($meta_key, $parent_field->post_excerpt) === false) {
          $potential_field = null; // 仮候補を消去
          break;
        }

        $current_field = $parent_field;
      }

      if ($potential_field) {
        $ret = $potential_field->post_name;
      }
    }
  }

  return $ret;
}
