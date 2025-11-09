<?php
/**
 * Plugin Name: Media Alt Text Enhancer
 * Description: Scans media library for images without alt text, generates descriptions using OpenAI vision models, and updates the alt attribute automatically.
 * Version: 1.0.1
 * Author: OpenAI Assistant
 * License: GPL2
 * Text Domain: media-alt-text-enhancer
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('Media_Alt_Text_Enhancer')) {
    class Media_Alt_Text_Enhancer
    {
        private const OPTION_KEY = 'media_alt_text_enhancer_settings';
        private const NONCE_ACTION = 'media_alt_text_enhancer_generate';
        private const MAX_ALT_LENGTH = 120;
        private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

        public function __construct()
        {
            add_action('init', [ $this, 'load_textdomain' ]);
            add_action('admin_menu', [ $this, 'register_admin_menu' ]);
            add_action('admin_init', [ $this, 'register_settings' ]);
        }

        public function load_textdomain(): void
        {
            load_plugin_textdomain(
                'media-alt-text-enhancer',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages'
            );
        }

        public function register_admin_menu(): void
        {
            add_media_page(
                __('Media Alt Text Enhancer', 'media-alt-text-enhancer'),
                __('Alt Text Enhancer', 'media-alt-text-enhancer'),
                'manage_options',
                'media-alt-text-enhancer',
                [ $this, 'render_admin_page' ]
            );
        }

        public function register_settings(): void
        {
            register_setting(
                'media_alt_text_enhancer',
                self::OPTION_KEY,
                [ $this, 'sanitize_settings' ]
            );

            add_settings_section(
                'media_alt_text_enhancer_api',
                __('OpenAI API Settings', 'media-alt-text-enhancer'),
                function (): void {
                    echo '<p>' . esc_html__(
                        'Provide your OpenAI API credentials so the plugin can describe your images and populate their alt text.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer'
            );

            add_settings_field(
                'media_alt_text_enhancer_api_key',
                __('OpenAI API Key', 'media-alt-text-enhancer'),
                function (): void {
                    $options = $this->get_settings();
                    printf(
                        '<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" />',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($options['api_key'] ?? '')
                    );
                    echo '<p class="description">' . esc_html__(
                        'Generate an API key in your OpenAI dashboard and paste it here.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer',
                'media_alt_text_enhancer_api'
            );

            add_settings_field(
                'media_alt_text_enhancer_model',
                __('Model', 'media-alt-text-enhancer'),
                function (): void {
                    $options = $this->get_settings();
                    $model   = $options['model'] ?? 'gpt-4o-mini';
                    printf(
                        '<input type="text" name="%1$s[model]" value="%2$s" class="regular-text" />',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($model)
                    );
                    echo '<p class="description">' . esc_html__(
                        'Vision-capable model to use when generating alt text (e.g. gpt-4o-mini).',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer',
                'media_alt_text_enhancer_api'
            );

            add_settings_section(
                'media_alt_text_enhancer_generation',
                __('Generation Settings', 'media-alt-text-enhancer'),
                function (): void {
                    echo '<p>' . esc_html__(
                        'Control how alt text is generated and applied to your images.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer'
            );

            add_settings_field(
                'media_alt_text_enhancer_language',
                __('Alt Text Language', 'media-alt-text-enhancer'),
                function (): void {
                    $options  = $this->get_settings();
                    $language = $options['language'] ?? 'en';
                    printf(
                        '<input type="text" name="%1$s[language]" value="%2$s" class="regular-text" />',
                        esc_attr(self::OPTION_KEY),
                        esc_attr($language)
                    );
                    echo '<p class="description">' . esc_html__(
                        'Two-letter language code that instructs the model which language to use for the generated alt text.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer',
                'media_alt_text_enhancer_generation'
            );
        }

        public function sanitize_settings(array $settings): array
        {
            $clean = [];
            if (! empty($settings['api_key'])) {
                $clean['api_key'] = sanitize_text_field($settings['api_key']);
            }

            $clean['model'] = ! empty($settings['model'])
                ? sanitize_text_field($settings['model'])
                : 'gpt-4o-mini';

            $clean['language'] = ! empty($settings['language'])
                ? sanitize_text_field($settings['language'])
                : 'en';

            return $clean;
        }

        public function render_admin_page(): void
        {
            if (! current_user_can('manage_options')) {
                return;
            }

            $results = null;
            if (isset($_POST['media_alt_text_enhancer_generate'])) {
                check_admin_referer(self::NONCE_ACTION);
                $results = $this->handle_generation_request();
            }

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Media Alt Text Enhancer', 'media-alt-text-enhancer') . '</h1>';

            if (isset($_GET['settings-updated'])) {
                echo '<div id="message" class="updated notice notice-success is-dismissible"><p>' . esc_html__(
                    'Settings saved.',
                    'media-alt-text-enhancer'
                ) . '</p></div>';
            }

            if (is_array($results)) {
                $this->render_results_notice($results);
            }

            echo '<form method="post" action="options.php">';
            settings_fields('media_alt_text_enhancer');
            do_settings_sections('media_alt_text_enhancer');
            submit_button(__('Save Settings', 'media-alt-text-enhancer'));
            echo '</form>';

            echo '<hr />';

            echo '<h2>' . esc_html__('Generate missing alt text', 'media-alt-text-enhancer') . '</h2>';
            echo '<p>' . esc_html__(
                'Scan the media library for images that are missing alt text. For each image, the plugin will ask the configured model to describe the contents and use the result as the new alt attribute.',
                'media-alt-text-enhancer'
            ) . '</p>';

            echo '<form method="post">';
            wp_nonce_field(self::NONCE_ACTION);
            submit_button(__('Generate Alt Text Now', 'media-alt-text-enhancer'), 'primary', 'media_alt_text_enhancer_generate');
            echo '</form>';

            echo '</div>';
        }

        private function render_results_notice(array $results): void
        {
            $class = 'notice';
            if (! empty($results['errors'])) {
                $class .= ' notice-warning';
            } else {
                $class .= ' notice-success';
            }

            echo '<div class="' . esc_attr($class) . '">';
            echo '<p>' . esc_html(
                sprintf(
                    /* translators: 1: number of updated images, 2: number of skipped images */
                    __('Updated %1$d images. %2$d images were skipped.', 'media-alt-text-enhancer'),
                    intval($results['updated'] ?? 0),
                    intval($results['skipped'] ?? 0)
                )
            ) . '</p>';

            if (! empty($results['errors'])) {
                echo '<ul>';
                foreach ($results['errors'] as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul>';
            }

            echo '</div>';
        }

        private function handle_generation_request(): array
        {
            $settings = $this->get_settings();
            if (empty($settings['api_key'])) {
                return [
                    'updated' => 0,
                    'skipped' => 0,
                    'errors'  => [
                        __('Generation skipped because the OpenAI API key is not configured.', 'media-alt-text-enhancer'),
                    ],
                ];
            }

            $attachments = get_posts([
                'post_type'           => 'attachment',
                'post_mime_type'      => 'image',
                'posts_per_page'      => -1,
                'post_status'         => 'inherit',
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'          => [
                    'relation' => 'OR',
                    [
                        'key'     => '_wp_attachment_image_alt',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_wp_attachment_image_alt',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ]);

            $results = [
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
            ];

            foreach ($attachments as $attachment_id) {
                $attachment_id = intval($attachment_id);

                $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                if ('' !== trim((string) $alt)) {
                    $results['skipped']++;
                    continue;
                }

                $description = $this->generate_alt_text_for_attachment($attachment_id, $settings);

                if (is_wp_error($description)) {
                    $results['errors'][] = sprintf(
                        /* translators: 1: attachment ID, 2: error message */
                        __('Attachment %1$d skipped: %2$s', 'media-alt-text-enhancer'),
                        $attachment_id,
                        $description->get_error_message()
                    );
                    $results['skipped']++;
                    continue;
                }

                if ($description) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags($description));
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            }

            return $results;
        }

        private function generate_alt_text_for_attachment(int $attachment_id, array $settings)
        {
            $image_url = wp_get_attachment_url($attachment_id);
            if (! $image_url || ! wp_http_validate_url($image_url)) {
                return new WP_Error('missing_url', __('Unable to determine a valid image URL.', 'media-alt-text-enhancer'));
            }

            $request_body = $this->build_openai_request_body($image_url, $settings['language'] ?? 'en');

            $response = wp_safe_remote_post(
                self::OPENAI_ENDPOINT,
                [
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $settings['api_key'],
                    ],
                    'body'    => wp_json_encode($request_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'timeout' => 60,
                ]
            );

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                $message = wp_remote_retrieve_response_message($response);
                return new WP_Error(
                    'http_error',
                    sprintf(
                        __('OpenAI API returned HTTP %1$d %2$s.', 'media-alt-text-enhancer'),
                        intval($code),
                        $message ? esc_html($message) : ''
                    )
                );
            }

            $body = wp_remote_retrieve_body($response);
            if (! $body) {
                return new WP_Error('empty_body', __('Empty response from OpenAI API.', 'media-alt-text-enhancer'));
            }

            $data = json_decode($body, true);
            if (! is_array($data) || empty($data['choices'][0]['message']['content'])) {
                return new WP_Error('invalid_response', __('Unexpected response format from OpenAI API.', 'media-alt-text-enhancer'));
            }

            $content = $data['choices'][0]['message']['content'];

            return $this->normalize_alt_text($content);
        }

        private function build_openai_request_body(string $image_url, string $language): array
        {
            $settings = $this->get_settings();
            $model    = $settings['model'] ?? 'gpt-4o-mini';
            $prompt   = sprintf(
                /* translators: %s: language code */
                __('You are writing alt text in %s. Produce a single concise sentence (max 120 characters) that accurately and accessibly describes the image for someone who cannot see it. Do not prefix with "Image of" or similar.', 'media-alt-text-enhancer'),
                $language
            );

            return [
                'model'    => $model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                        ],
                    ],
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => __('Describe the contents of this image for alt text purposes.', 'media-alt-text-enhancer'),
                            ],
                            [
                                'type'      => 'image_url',
                                'image_url' => [
                                    'url' => esc_url_raw($image_url),
                                ],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 150,
            ];
        }

        private function normalize_alt_text(string $content): string
        {
            $text = trim(wp_strip_all_tags($content));
            $text = preg_replace('/^(Image of|Photo of|Picture of)\s+/i', '', $text ?? '');

            $length_callback = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
            $substr_callback = function_exists('mb_substr') ? 'mb_substr' : 'substr';

            if ($length_callback($text) > self::MAX_ALT_LENGTH) {
                $text = $substr_callback($text, 0, self::MAX_ALT_LENGTH - 3) . '...';
            }

            return $text;
        }

        private function get_settings(): array
        {
            $settings = get_option(self::OPTION_KEY, []);
            if (! is_array($settings)) {
                return [];
            }

            return $settings;
        }
    }
}

new Media_Alt_Text_Enhancer();
