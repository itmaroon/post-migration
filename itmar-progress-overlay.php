<?php
if (!class_exists('ProgressOverlay')) {
    class ProgressOverlay
    {
        private static $instance = null;

        public static function get_instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            add_action('admin_footer', [$this, 'render_overlay']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('wp_ajax_start_cancel_progress', [$this, 'start_cancel_progress']);
        }

        // **オーバーレイの HTML を出力**
        public function render_overlay()
        {
?>
            <div id="importOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 10000; text-align: center;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border-radius: 10px;">
                    <h3><?php echo esc_html__("Processing...", "post-migration"); ?></h3>
                    <img id="progressLoadingImg" src="<?php echo plugin_dir_url(__FILE__) . 'img/transloading.gif'; ?>" style="margin: 0 auto" alt="Loading...">
                    <div style="width: 300px; background: #ccc; border-radius: 5px; overflow: hidden; display: none;" id="progressBarWrapper">
                        <div id="progressBar" style="width: 0%; height: 20px; background: #28a745;"></div>
                    </div>
                    <p id="progressText">0%</p>
                    <button id="cancelButton" style="
                        margin-top: 10px; 
                        padding: 10px; 
                        background: rgb(204, 197, 136); 
                        color: white; 
                        border: none; 
                        border-radius: 5px;
                        transition: opacity 0.3s ease;
                    ">
                        <?php echo esc_html__("Cancel", "post-migration"); ?>
                    </button>
                </div>
            </div>
            <style>
                /* ✅ キャンセルボタンのホバー時のスタイル */
                #cancelButton:hover {
                    opacity: 0.8;
                    /* ✅ 透明度を下げる */
                    cursor: pointer;
                    /* ✅ ポインタに変える */
                }
            </style>
<?php
        }

        // **スクリプトとスタイルを追加**
        public function enqueue_scripts()
        {
            $dir = dirname(__FILE__);
            wp_enqueue_script(
                'progress-overlay',
                plugin_dir_url(__FILE__) . 'assets/js/itmar-progress-overlay.js',
                ['jquery', 'wp-i18n'],
                filemtime("$dir/assets/js/itmar-progress-overlay.js"),
                true
            );

            // ✅ `wp_localize_script()` で `nonce` を JavaScript に渡す
            wp_localize_script('progress-overlay', 'ajax_object', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('itmar-ajax-nonce') // **nonceを生成**
            ]);
        }

        // 処理の停止フラグをセットするフック
        public function start_cancel_progress()
        {
            check_ajax_referer('itmar-ajax-nonce', 'nonce'); // ✅ `nonce` を検証

            $flg = isset($_POST['flg']) ? sanitize_text_field($_POST['flg']) : 'false';
            $is_cancel = ($flg === 'true'); // ✅ `"true"` の場合 `true` に変換

            $updated = update_option('start_cancel', $is_cancel);

            wp_send_json_success([
                'message' => $is_cancel ? __('proceed canceled', 'post-migration') : __('proceed started', 'post-migration'),
                'updated' => $updated, // ✅ `true` または `false` をレスポンスに含める
                'new_value' => $is_cancel // ✅ 現在の `start_cancel` の値
            ]);
        }
    }

    ProgressOverlay::get_instance();
}
