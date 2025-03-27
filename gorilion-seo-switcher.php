<?php
/**
 * Plugin Name: Gorilion SEO Switcher
 * Plugin URI:
 * Description: Dynamically enable code for Rank Math or Yoast SEO, and update from GitHub.
 * Version:     1.3
 * Author:      Gorilion
 * Author URI:
 * License:     GPL2
 * Text Domain: gorilion-seo-switcher
 *
 * -----------------------------------------------------------------------
 * This plugin allows an admin to choose which SEO plugin (Rank Math or Yoast)
 * they use. Depending on that choice, it hooks specific functions into 'wp_head'
 * and optionally disables certain SEO plugin features.
 *
 * Additionally, it supports GitHub-based updates if the Plugin Update Checker
 * library is included in a "plugin-update-checker" subfolder.
 * -----------------------------------------------------------------------
 */

// Prevent direct file access.
if (!defined('ABSPATH')) {
    exit;
}

$tenant_id = 'baldacci-family-vineyards';
$repo_usr = 'mdeiriondo';

/**
 * ------------------------------------------------------------------
 * 1) GITHUB PLUGIN UPDATE CONFIGURATION
 * ------------------------------------------------------------------
 *
 */
if (!class_exists('Puc_v4_Factory')) {
    // Require the library if it's available.
    $puc_library = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
    if (file_exists($puc_library)) {
        require_once $puc_library;
    }
}

if (class_exists('Puc_v4_Factory')) {
    // Initialize the update checker, pointing to your GitHub repository.
    $updateChecker = Puc_v4_Factory::buildUpdateChecker(
        'https://github.com/' . $repo_usr . '/gorilion-seo-switcher/', // Replace with your GitHub repo URL.
        __FILE__, // Path to the main plugin file.
        'gorilion-seo-switcher' // Plugin slug (unique identifier).
    );
    // Set the branch (could be 'main' or 'master') used in your repo.
    $updateChecker->setBranch('main');
}

/**
 * ------------------------------------------------------------------
 * 2) ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------
 *
 * This adds a page under "Settings" that lets you choose Rank Math or Yoast.
 */
add_action('admin_menu', 'gorilion_seo_switcher_admin_menu');
function gorilion_seo_switcher_admin_menu()
{
    add_options_page(
        'Gorilion SEO Switcher', // Page title
        'Gorilion SEO Switcher', // Menu title
        'manage_options', // Capability required
        'gorilion_seo_switcher', // Menu slug
        'gorilion_seo_switcher_options_page' // Callback function
    );
}

// Register the setting where we store the user's choice (Rank Math or Yoast).
add_action('admin_init', 'gorilion_seo_switcher_register_settings');
function gorilion_seo_switcher_register_settings()
{
    register_setting(
        'gorilion_seo_switcher_settings_group', // Settings group
        'gorilion_seo_switcher_choice' // Option name
    );
}

/**
 * Display the settings page form.
 */
function gorilion_seo_switcher_options_page()
{
    ?>
    <div class="wrap">
        <h1>Gorilion SEO Switcher</h1>
        <form method="post" action="options.php">
            <?php
settings_fields('gorilion_seo_switcher_settings_group');
    do_settings_sections('gorilion_seo_switcher_settings_group');

    // Get current choice or default to 'rankmath'
    $choice = get_option('gorilion_seo_switcher_choice', 'rankmath');
    ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Which SEO plugin do you use?</th>
                    <td>
                        <label>
                            <input type="radio" name="gorilion_seo_switcher_choice"
                                   value="rankmath" <?php checked($choice, 'rankmath'); ?>>
                            Rank Math
                        </label>
                        <br/>
                        <label>
                            <input type="radio" name="gorilion_seo_switcher_choice"
                                   value="yoast" <?php checked($choice, 'yoast'); ?>>
                            Yoast SEO
                        </label>
                        <br/>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * ------------------------------------------------------------------
 * 3) CODE INJECTION BASED ON SELECTED SEO PLUGIN
 * ------------------------------------------------------------------
 *
 */
add_action('plugins_loaded', 'gorilion_seo_switcher_inject_functions');

function gorilion_seo_switcher_inject_functions()
{
    $choice = get_option('gorilion_seo_switcher_choice', 'rankmath');

    if ($choice === 'rankmath') {
        // --------------------------------------------------
        // RANK MATH CODE BLOCK
        // --------------------------------------------------

        add_action('wp_head', 'rankmath_disable_features', 1);
        function rankmath_disable_features()
        {
            global $post;
            // Safety check: $post might be null on some pages
            if (!is_object($post)) {
                return;
            }

            if ($post->post_name === 'product' || $post->post_name === 'collection') {
                remove_all_actions('rank_math/head');
            }
        }

        add_filter('rank_math/sitemap/providers', function ($external_providers) {
            $external_providers['custom'] = new \RankMath\Sitemap\Providers\Custom();
            return $external_providers;
        });

        add_action('wp_head', 'gorilion_opengraph_rankmath');
        function gorilion_opengraph_rankmath()
        {
            global $post;
            if (!is_object($post)) {
                return;
            }

            // Remove canonical if it's a product page
            if ($post->post_name === 'product') {
                add_filter('wpseo_canonical', '__return_false');
            }

            // Get the request URL to determine slug
            $request_url = $_SERVER['REQUEST_URI'];
            $request_url = trim($request_url, '/');
            $parts = explode('/', $request_url);
            $result = end($parts);

            // Fallback logic if slug is missing
            if (!$result || $result === '' || $result === 'product') {
                $redirect_url = isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '';
                $redirect_url = trim($redirect_url, '/');
                $parts2 = explode('/', $redirect_url);
                $result = end($parts2);
            }
            if (!$result || $result === '' || $result === 'product') {
                echo "<!-- Your server either needs REQUEST_URI or REDIRECT_URL for passthrough -->";
            }

            // If it's a "collection" page
            if ($post->post_name === 'collection') {
                $collection_url_base = "https://api.commerce7.com/v1/product/for-web?&collectionSlug=";
                $url = $collection_url_base . $result;
                $headers = array("tenant: " . $tenant_id);

                // Use wp_remote_get instead of curl
                $response = wp_remote_get($url, array('headers' => $headers));
                if (is_wp_error($response)) {
                    echo "Error fetching collection data: " . esc_html($response->get_error_message());
                    return;
                }

                $response_body = json_decode(wp_remote_retrieve_body($response));
                if (isset($response_body->collection->seo)) {
                    $new_title = esc_html($response_body->collection->seo->title);
                    $new_description = esc_html($response_body->collection->seo->description);

                    echo "<!-- Gorilion meta -->";
                    echo "<title>{$new_title}</title>\n";
                    echo "<meta name=\"description\" content=\"{$new_description}\"/>\n";
                }
            }

            // If it's a "product" page
            if ($post->post_name === 'product') {
                $url = "https://api.commerce7.com/v1/product/slug/" . $result . "/for-web";
                $headers = array("tenant: " . $tenant_id);

                // Use wp_remote_get instead of curl
                $responseData = wp_remote_get($url, array('headers' => $headers));
                if (is_wp_error($responseData)) {
                    echo "Error fetching product data: " . esc_html($responseData->get_error_message());
                    return;
                }

                $response = json_decode(wp_remote_retrieve_body($responseData));
                if (!$response) {
                    echo "Error decoding product data.";
                    return;
                }

                // Avoid warnings if data doesn't exist
                $price = isset($response->variants[0]->price) ? $response->variants[0]->price / 100.00 : '';
                $description = isset($response->seo->description) ? esc_html($response->seo->description) : '';
                $wine = isset($response->wine) ? $response->wine : array();
                $title = isset($response->seo->title) ? esc_html($response->seo->title) : '';
                $sku = isset($response->variants[0]->sku) ? esc_html($response->variants[0]->sku) : '';
                $img = isset($response->image) ? esc_url($response->image) : '';

                $keywords = implode(',', array_filter(array($title, $sku, implode(',', (array) $wine))));
                $full_url = 'https://' . rtrim($_SERVER['HTTP_HOST'], '/') . '/' . esc_url($request_url);
                $site_title = esc_html(get_bloginfo('name'));

                echo "<!-- Gorilion meta :: VERSION 1.2 -->";
                echo "<title>{$title}</title>\n";
                echo "<meta name=\"description\" content=\"{$description}\"/>\n";
                echo "<meta name=\"keywords\" content=\"{$keywords}\">\n";
                echo "<link rel=\"canonical\" href=\"" . esc_url($full_url) . "\"/>\n";
                echo "<meta property=\"og:type\" content=\"product\" />\n";
                echo "<meta property=\"og:title\" content=\"{$title}\"/>\n";
                echo "<meta property=\"og:description\" content=\"{$description}\"/>\n";
                echo "<meta property=\"og:image\" content=\"" . esc_url($img) . "\"/>\n";
                echo "<meta property=\"og:url\" content=\"" . esc_url($full_url) . "\"/>\n";
                echo "<meta property=\"og:site_name\" content=\"" . esc_attr($site_title) . "\" />\n";
                echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";

                echo '<script type="application/ld+json">
                        {
                            "@context": "http://schema.org",
                            "@type": "Product",
                            "name": "' . esc_js($title) . '",
                            "image": "' . esc_url($img) . '",
                            "description": "' . esc_js($description) . '",
                            "brand": {
                                "@type": "Brand",
                                "name": "' . esc_js($site_title) . '",
                                "logo": "' . esc_url(wp_get_attachment_image_src(get_theme_mod("custom_logo"), "full")[0]) . '"
                            },
                            "offers": {
                                "@type": "Offer",
                                "priceCurrency": "USD",
                                "price": "' . esc_js($price) . '"
                            }
                        }
                      </script>';
            }
        }

    } else {
        // --------------------------------------------------
        // YOAST SEO CODE BLOCK
        // --------------------------------------------------

        add_action('wp_head', 'gorilion_opengraph_yoast');
        function gorilion_opengraph_yoast()
        {
            global $post;
            if (!is_object($post)) {
                return;
            }

            // Remove canonical if it's a product page
            if ($post->post_name === 'product') {
                add_filter('wpseo_canonical', '__return_false');
            }

            // Similar logic to get $result from the request URI
            $request_url = $_SERVER['REQUEST_URI'];
            $request_url = trim($request_url, '/');
            $parts = explode('/', $request_url);
            $result = end($parts);

            if (!$result || $result === '' || $result === 'product') {
                $redirect_url = isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '';
                $redirect_url = trim($redirect_url, '/');
                $parts2 = explode('/', $redirect_url);
                $result = end($parts2);
            }
            if (!$result || $result === '' || $result === 'product') {
                echo "<!-- Your server either needs REQUEST_URI or REDIRECT_URL for passthrough -->";
            }

            // If it's a "collection" page
            if ($post->post_name === 'collection') {
                $collection_url_base = "https://api.commerce7.com/v1/product/for-web?&collectionSlug=";
                $url = $collection_url_base . $result;
                $headers = array("tenant: " . $tenant_id);

                // Use wp_remote_get instead of curl
                $response = wp_remote_get($url, array('headers' => $headers));
                if (is_wp_error($response)) {
                    echo "Error fetching collection data: " . esc_html($response->get_error_message());
                    return;
                }

                $response_body = json_decode(wp_remote_retrieve_body($response));
                if (isset($response_body->collection->seo)) {
                    $new_title = esc_html($response_body->collection->seo->title);
                    $new_description = esc_html($response_body->collection->seo->description);

                    echo "<!-- Gorilion meta -->";
                    echo "<title>{$new_title}</title>\n";
                    echo "<meta name=\"description\" content=\"{$new_description}\"/>\n";
                }
            }

            // If it's a "product" page
            if ($post->post_name === 'product') {
                $url = "https://api.commerce7.com/v1/product/slug/" . $result . "/for-web";
                $headers = array("tenant: " . $tenant_id);

                // Use wp_remote_get instead of curl
                $responseData = wp_remote_get($url, array('headers' => $headers));
                if (is_wp_error($responseData)) {
                    echo "Error fetching product data: " . esc_html($responseData->get_error_message());
                    return;
                }

                $response = json_decode(wp_remote_retrieve_body($responseData));
                if (!$response) {
                    echo "Error decoding product data.";
                    return;
                }

                $price = isset($response->variants[0]->price) ? $response->variants[0]->price / 100.00 : '';
                $description = isset($response->seo->description) ? esc_html($response->seo->description) : '';
                $wine = isset($response->wine) ? $response->wine : array();
                $title = isset($response->seo->title) ? esc_html($response->seo->title) : '';
                $sku = isset($response->variants[0]->sku) ? esc_html($response->variants[0]->sku) : '';
                $img = isset($response->image) ? esc_url($response->image) : '';

                $keywords = implode(',', array_filter(array($title, $sku, implode(',', (array) $wine))));
                $full_url = 'https://' . rtrim($_SERVER['HTTP_HOST'], '/') . '/' . esc_url($request_url);
                $site_title = esc_html(get_bloginfo('name'));

                echo "\n<!-- Gorilion meta :: VERSION 1.3 -->\n";
                echo "<title>{$title}</title>\n";
                echo "<meta name=\"description\" content=\"{$description}\"/>\n";
                echo "<meta name=\"keywords\" content=\"{$keywords}\">\n";
                echo "<link rel=\"canonical\" href=\"" . esc_url($full_url) . "\"/>\n";
                echo "<meta property=\"og:type\" content=\"product\" />\n";
                echo "<meta property=\"og:title\" content=\"{$title}\"/>\n";
                echo "<meta property=\"og:description\" content=\"{$description}\"/>\n";
                echo "<meta property=\"og:image\" content=\"" . esc_url($img) . "\"/>\n";
                echo "<meta property=\"og:url\" content=\"" . esc_url($full_url) . "\"/>\n";
                echo "<meta property=\"og:site_name\" content=\"" . esc_attr($site_title) . "\" />\n";
                echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";

                echo '<script type="application/ld+json">
                        {
                            "@context": "http://schema.org",
                            "@type": "Product",
                            "name": "' . esc_js($title) . '",
                            "image": "' . esc_url($img) . '",
                            "description": "' . esc_js($description) . '",
                            "brand": {
                                "@type": "Brand",
                                "name": "' . esc_js($site_title) . '",
                                "logo": "' . esc_url(wp_get_attachment_image_src(get_theme_mod("custom_logo"), "full")[0]) . '"
                            },
                            "offers": {
                                "@type": "Offer",
                                "priceCurrency": "USD",
                                "price": "' . esc_js($price) . '"
                            }
                        }
                      </script>';
            }
        }
    }
}
