<?php
/**
 * Plugin Name: Media Alt Text Enhancer
 * Description: Scans media library for images without alt text, generates descriptions using OpenAI vision models, and updates the alt attribute automatically.
 * Version: 1.0.0
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

        private $api_key = '';
        private $current_attachment_id = null;

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

            add_settings_field(
                'media_alt_text_enhancer_batch_size',
                __('Batch size', 'media-alt-text-enhancer'),
                function (): void {
                    $options    = $this->get_settings();
                    $batch_size = isset($options['mate_batch_size']) ? intval($options['mate_batch_size']) : 10;
                    printf(
                        '<input type="number" name="%1$s[mate_batch_size]" value="%2$d" class="small-text" min="1" max="50" step="1" />',
                        esc_attr(self::OPTION_KEY),
                        $batch_size
                    );
                    echo '<p class="description">' . esc_html__(
                        'Number of images to process per batch when generating alt text. Must be between 1 and 50.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer',
                'media_alt_text_enhancer_generation'
            );

            add_settings_field(
                'media_alt_text_enhancer_delay',
                __('Delay (ms)', 'media-alt-text-enhancer'),
                function (): void {
                    $options  = $this->get_settings();
                    $delay_ms = isset($options['mate_rate_limit_ms']) ? intval($options['mate_rate_limit_ms']) : 1500;
                    printf(
                        '<input type="number" name="%1$s[mate_rate_limit_ms]" value="%2$d" class="small-text" min="0" step="100" />',
                        esc_attr(self::OPTION_KEY),
                        $delay_ms
                    );
                    echo '<p class="description">' . esc_html__(
                        'Pause length in milliseconds between requests. Increase the delay to stay within API rate limits.',
                        'media-alt-text-enhancer'
                    ) . '</p>';
                },
                'media_alt_text_enhancer',
                'media_alt_text_enhancer_generation'
            );

            add_settings_field(
                'media_alt_text_enhancer_replace_mode',
                __('Replace policy', 'media-alt-text-enhancer'),
                function (): void {
                    $options      = $this->get_settings();
                    $replace_mode = $options['mate_replace_mode'] ?? 'only-missing';
                    $choices      = [
                        'only-missing' => __('Only fill missing alt text', 'media-alt-text-enhancer'),
                        'replace-all'  => __('Replace existing alt text', 'media-alt-text-enhancer'),
                    ];
                    echo '<select name="' . esc_attr(self::OPTION_KEY) . '[mate_replace_mode]">';
                    foreach ($choices as $value => $label) {
                        printf(
                            '<option value="%1$s" %2$s>%3$s</option>',
                            esc_attr($value),
                            selected($replace_mode, $value, false),
                            esc_html($label)
                        );
                    }
                    echo '</select>';
                    echo '<p class="description">' . esc_html__(
                        'Choose whether to only fill missing alt text or replace all existing alt attributes.',
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

            $batch_size = isset($settings['mate_batch_size']) ? intval($settings['mate_batch_size']) : 10;
            $batch_size = max(1, min(50, $batch_size));
            $clean['mate_batch_size'] = $batch_size;

            $delay_ms = isset($settings['mate_rate_limit_ms']) ? intval($settings['mate_rate_limit_ms']) : 1500;
            $delay_ms = max(0, $delay_ms);
            $clean['mate_rate_limit_ms'] = $delay_ms;

            $replace_mode = isset($settings['mate_replace_mode']) ? sanitize_text_field($settings['mate_replace_mode']) : 'only-missing';
            if (! in_array($replace_mode, [ 'only-missing', 'replace-all' ], true)) {
                $replace_mode = 'only-missing';
            }
            $clean['mate_replace_mode'] = $replace_mode;

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

            printf('<form method="post">');
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

            if (! empty($results['scan_summary'])) {
                echo '<p>' . esc_html($results['scan_summary']) . '</p>';
            }

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

            $batch_size    = isset($settings['mate_batch_size']) ? max(1, min(50, intval($settings['mate_batch_size']))) : 10;
            $delay_ms      = isset($settings['mate_rate_limit_ms']) ? max(0, intval($settings['mate_rate_limit_ms'])) : 1500;
            $replace_mode  = $settings['mate_replace_mode'] ?? 'only-missing';

            $query_args = [
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'post_status'    => 'inherit',
                'fields'         => 'ids',
            ];

            if ('replace-all' !== $replace_mode) {
                $query_args['meta_query'] = [
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
                ];
            }

            $attachments = get_posts($query_args);

            $this->api_key = $settings['api_key'];

            $results = [
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
                'scan_summary' => $only_non_greek
                    ? __('Scan included images with empty alt text and those whose existing alt text does not contain Greek characters.', 'media-alt-text-enhancer')
                    : __('Scan included only images missing alt text.', 'media-alt-text-enhancer'),
            ];

            foreach ($attachments as $attachment_id) {
                $alt_text = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));

                if ($only_non_greek) {
                    if ('' !== $alt_text && $this->contains_greek_characters($alt_text)) {
                        $results['skipped']++;
                        continue;
                    }
                } elseif ('' !== $alt_text) {
                    $results['skipped']++;
                    continue;
                }

                $this->current_attachment_id = $attachment_id;

                $rate_limit = $this->rate_limit_ms();
                if ($rate_limit > 0) {
                    usleep((int) $rate_limit * 1000);
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

            $this->api_key = '';
            $this->current_attachment_id = null;

            return $results;
        }

        private function contains_greek_characters(string $text): bool
        {
            return (bool) preg_match('/\p{Greek}/u', $text);
        }

        private function generate_alt_text_for_attachment(int $attachment_id, array $settings)
        {
            $image_url = wp_get_attachment_url($attachment_id);
            if (! $image_url) {
                return new WP_Error('missing_url', __('Unable to determine image URL.', 'media-alt-text-enhancer'));
            }

            $request_body = $this->build_openai_request_body($image_url, $settings['language'] ?? 'en');

            $response = $this->request_openai_with_retry($request_body, $this->max_retries());

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return new WP_Error('http_error', sprintf(
                    __('OpenAI API returned HTTP %d.', 'media-alt-text-enhancer'),
                    $code
                ));
            }

            $body = wp_remote_retrieve_body($response);
            if (! $body) {
                return new WP_Error('empty_body', __('Empty response from OpenAI API.', 'media-alt-text-enhancer'));
            }

            $data = json_decode($body, true);
            if (empty($data['choices'][0]['message']['content'])) {
                return new WP_Error('invalid_response', __('Unexpected response format from OpenAI API.', 'media-alt-text-enhancer'));
            }

            $content = $data['choices'][0]['message']['content'];

            return $this->normalize_alt_text($content);
        }

        private function rate_limit_ms(): int
        {
            $default = 1500;
            $value   = apply_filters('mate_rate_limit_ms', $default);

            if (! is_numeric($value)) {
                return $default;
            }

            return max(0, (int) $value);
        }

        private function max_retries(): int
        {
            $default = 6;
            $value   = apply_filters('mate_max_retries', $default);

            if (! is_numeric($value)) {
                return $default;
            }

            $value = (int) $value;

            return $value > 0 ? $value : $default;
        }

        private function request_openai_with_retry(array $payload, int $max_tries = 6)
        {
            if (empty($this->api_key)) {
                return new WP_Error('missing_api_key', __('OpenAI API key is not configured.', 'media-alt-text-enhancer'));
            }

            $max_tries = max(1, $max_tries);

            $endpoint = 'https://api.openai.com/v1/chat/completions';
            $attempt  = 0;
            $delays   = [0.5, 1, 2, 4, 8];

            while ($attempt < $max_tries) {
                $attempt++;

                $response = wp_remote_post(
                    $endpoint,
                    [
                        'headers' => [
                            'Content-Type'  => 'application/json',
                            'Authorization' => 'Bearer ' . $this->api_key,
                        ],
                        'body'    => wp_json_encode($payload),
                        'timeout' => 60,
                    ]
                );

                if (is_wp_error($response)) {
                    return $response;
                }

                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    return $response;
                }

                if ($code !== 429 && ($code < 500 || $code >= 600)) {
                    return new WP_Error('http_error', sprintf(
                        __('OpenAI API returned HTTP %d.', 'media-alt-text-enhancer'),
                        $code
                    ));
                }

                if ($attempt >= $max_tries) {
                    break;
                }

                $delay = $delays[min($attempt, count($delays)) - 1];

                $retry_after_header = wp_remote_retrieve_header($response, 'retry-after');
                $retry_after        = $this->parse_retry_after($retry_after_header);

                if (null !== $retry_after) {
                    $delay = max($delay, $retry_after);
                }

                $delay *= $this->jitter_multiplier();

                $this->log(
                    'info',
                    sprintf(
                        'Retrying OpenAI request for attachment %1$d after %.2f seconds (attempt %2$d of %3$d).',
                        $this->current_attachment_id ?? 0,
                        $delay,
                        $attempt + 1,
                        $max_tries
                    )
                );

                usleep((int) round($delay * 1000000));
            }

            $message = __('OpenAI API returned HTTP %d.', 'media-alt-text-enhancer');

            $final_code = isset($code) ? $code : 0;

            $this->log(
                'warning',
                sprintf(
                    'OpenAI request for attachment %1$d failed with HTTP %2$d after %3$d attempts.',
                    $this->current_attachment_id ?? 0,
                    $final_code,
                    $max_tries
                )
            );

            return new WP_Error('http_error', sprintf($message, $final_code));
        }

        private function parse_retry_after($header): ?float
        {
            if (empty($header)) {
                return null;
            }

            if (is_array($header)) {
                $header = reset($header);
            }

            if (is_numeric($header)) {
                return max(0, (float) $header);
            }

            $timestamp = strtotime($header);

            if (false === $timestamp) {
                return null;
            }

            $diff = $timestamp - time();

            return $diff > 0 ? (float) $diff : null;
        }

        private function jitter_multiplier(): float
        {
            $min = 1000;
            $max = 1250;

            if (function_exists('wp_rand')) {
                $random = wp_rand($min, $max);
            } else {
                $random = random_int($min, $max);
            }

            return $random / 1000;
        }

        private function log(string $level, string $message, array $context = []): void
        {
            if (function_exists('wp_get_logger')) {
                wp_get_logger()->log($level, $message, $context);
                return;
            }

            $context_string = '';
            if (! empty($context)) {
                $encoded        = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
                $context_string = ' ' . (string) $encoded;
            }

            error_log(sprintf('[media-alt-text-enhancer][%s] %s%s', strtoupper($level), $message, $context_string));
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
                                'type'       => 'image_url',
                                'image_url'  => [
                                    'url' => $image_url,
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

            if ($length_callback($text) > 120) {
                $text = $substr_callback($text, 0, 117) . '...';
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
