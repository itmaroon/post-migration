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
        }

        // **オーバーレイの HTML を出力**
        public function render_overlay()
        {
?>
            <div id="importOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 10000; text-align: center;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border-radius: 10px;">
                    <h3><?php echo esc_html__("Processing...", "post-migration"); ?></h3>
                    <img id="progressLoadingImg" src="<?php echo plugin_dir_url(__FILE__) . 'img/transloading.gif'; ?>" alt="Loading...">
                    <div style="width: 300px; background: #ccc; border-radius: 5px; overflow: hidden; display: none;" id="progressBarWrapper">
                        <div id="progressBar" style="width: 0%; height: 20px; background: #28a745;"></div>
                    </div>
                    <p id="progressText">0%</p>
                </div>
            </div>
<?php
        }

        // **スクリプトとスタイルを追加**
        public function enqueue_scripts()
        {
            wp_enqueue_script('progress-overlay', plugin_dir_url(__FILE__) . 'js/itmar-progress-overlay.js', ['jquery'], null, true);
        }
    }

    ProgressOverlay::get_instance();
}
