<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
    }

    public static function menu(): void {
        add_options_page(
            'WP AI Bridge',
            'WP AI Bridge',
            'manage_options',
            'wp-ai-bridge',
            [self::class, 'render']
        );
    }

    public static function register(): void {
        register_setting('wpaib', WPAIB_OPTION_WRITE_ENABLED, [
            'type' => 'string',
            'sanitize_callback' => static fn($value): string => '1' === (string) $value ? '1' : '0',
            'default' => '0',
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $write_enabled = '1' === get_option(WPAIB_OPTION_WRITE_ENABLED, '0');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP AI Bridge', 'wp-ai-bridge'); ?></h1>
            <p><?php esc_html_e('A guarded diagnostics bridge. Version 0.1 exposes read-only REST tools only.', 'wp-ai-bridge'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('wpaib'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Safe write mode', 'wp-ai-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(WPAIB_OPTION_WRITE_ENABLED); ?>" value="1" <?php checked($write_enabled); ?> />
                                <?php esc_html_e('Enable write capability flag', 'wp-ai-bridge'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('The v0.1 API does not implement write endpoints yet. This flag is reserved for the next guarded-write milestone.', 'wp-ai-bridge'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Authentication', 'wp-ai-bridge'); ?></h2>
            <p><?php esc_html_e('Use HTTPS and a dedicated WordPress administrator Application Password. Never expose your normal WordPress password or server credentials.', 'wp-ai-bridge'); ?></p>

            <h2><?php esc_html_e('Available REST endpoints', 'wp-ai-bridge'); ?></h2>
            <code>/wp-json/wp-ai-bridge/v1/site-info</code><br>
            <code>/wp-json/wp-ai-bridge/v1/plugins</code><br>
            <code>/wp-json/wp-ai-bridge/v1/file?path=plugins/example/example.php</code><br>
            <code>/wp-json/wp-ai-bridge/v1/audit</code>
        </div>
        <?php
    }
}
