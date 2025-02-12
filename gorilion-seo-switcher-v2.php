<?php  
/**  
 * Plugin Name: Gorilion SEO Switcher  
 * Plugin URI:  
 * Description: Dynamically enable code for Rank Math or Yoast SEO, and update from GitHub.  
 * Version:     1.0.0  
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
if ( ! defined( 'ABSPATH' ) ) {  
    exit;  
} 

/**
 * ------------------------------------------------------------------
 * 1) GITHUB PLUGIN UPDATE CONFIGURATION
 * ------------------------------------------------------------------
 *
 */
// Include the Plugin Update Checker if available.  
if ( ! class_exists( 'Puc_v4_Factory' ) ) {  
    $puc_library = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';  
    if ( file_exists( $puc_library ) ) {  
        require_once $puc_library;  
    }  
}  

// Initialize the update checker.  
if ( class_exists( 'Puc_v4_Factory' ) ) {  
    Puc_v4_Factory::buildUpdateChecker(  
        'https://github.com/YOUR-USER/gorilion-seo-switcher/', // Replace with your GitHub repo URL.  
        __FILE__,  
        'gorilion-seo-switcher'  
    )->setBranch( 'main' );  
}  

/**
 * ------------------------------------------------------------------
 * 2) ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------
 *
 * This adds a page under "Settings" that lets you choose Rank Math or Yoast.
 */
add_action( 'admin_menu', 'gorilion_seo_switcher_admin_menu' );  
function gorilion_seo_switcher_admin_menu() {  
    add_options_page(  
        'Gorilion SEO Switcher',  
        'Gorilion SEO Switcher',  
        'manage_options',  
        'gorilion_seo_switcher',  
        'gorilion_seo_switcher_options_page'  
    );  
}  

// Register settings  
add_action( 'admin_init', 'gorilion_seo_switcher_register_settings' );  
function gorilion_seo_switcher_register_settings() {  
    register_setting( 'gorilion_seo_switcher_settings_group', 'gorilion_seo_switcher_choice' );  
}  

// Display the settings page  
function gorilion_seo_switcher_options_page() {  
    ?>  
    <div class="wrap">  
        <h1>Gorilion SEO Switcher</h1>  
        <form method="post" action="options.php">  
            <?php  
                settings_fields( 'gorilion_seo_switcher_settings_group' );  
                do_settings_sections( 'gorilion_seo_switcher_settings_group' );  
                $choice = get_option( 'gorilion_seo_switcher_choice', 'rankmath' );  
            ?>  
            <table class="form-table">  
                <tr valign="top">  
                    <th scope="row">Which SEO plugin do you use?</th>  
                    <td>  
                        <label>  
                            <input type="radio" name="gorilion_seo_switcher_choice" value="rankmath" <?php checked( $choice, 'rankmath' ); ?>> Rank Math  
                        </label><br/>  
                        <label>  
                            <input type="radio" name="gorilion_seo_switcher_choice" value="yoast" <?php checked( $choice, 'yoast' ); ?>> Yoast SEO  
                        </label><br/>  
                    </td>  
                </tr>  
            </table>  
            <?php submit_button(); ?>  
        </form>  
    </div>  
    <?php  
}  

// Inject functions based on selected SEO plugin  
add_action( 'plugins_loaded', 'gorilion_seo_switcher_inject_functions' );  
function gorilion_seo_switcher_inject_functions() {  
    $choice = get_option( 'gorilion_seo_switcher_choice', 'rankmath' );  

    if ( $choice === 'rankmath' ) {  
        add_action( 'wp_head', 'gorilion_opengraph_rankmath' );  
        add_action( 'wp_head', 'rankmath_disable_features', 1 );  
    } else {  
        add_action( 'wp_head', 'gorilion_opengraph_yoast' );  
    }  
}  

// Function to disable Rank Math features  
function rankmath_disable_features() {  
    if ( is_singular( 'product' ) || is_singular( 'collection' ) ) {  
        remove_all_actions( 'rank_math/head' );  
    }  
}  

// Function to handle OpenGraph for Rank Math  
function gorilion_opengraph_rankmath() {  
    global $post;  
    if ( ! is_object( $post ) ) {  
        return;  
    }  
    if ( $post->post_name === 'product' ) {  
        add_filter( 'wpseo_canonical', '__return_false' );  
    }  
    $result = gorilion_get_slug_from_request();  
    if ( $post->post_name === 'collection' ) {  
        gorilion_handle_collection_meta( $result );  
    } elseif ( $post->post_name === 'product' ) {  
        gorilion_handle_product_meta( $result );  
    }  
}  

// Function to get slug from request  
function gorilion_get_slug_from_request() {  
    $request_url = trim( $_SERVER['REQUEST_URI'], '/' );  
    $parts = explode( '/', $request_url );  
    $result = end( $parts );  
    if ( empty( $result ) || $result === 'product' ) {  
        $redirect_url = isset( $_SERVER['REDIRECT_URL']) ? trim( $_SERVER['REDIRECT_URL'], '/') : '';  
        $parts2 = explode( '/', $redirect_url );  
        $result = end( $parts2 );  
    }  
    return $result ?: '';  
}  

// Function to handle collection meta  
function gorilion_handle_collection_meta( $result ) {  
    $collection_url_base = "https://api.commerce7.com/v1/product/for-web?&collectionSlug=";  
    $url = $collection_url_base . $result;  
    $headers = array( "tenant: baldacci-family-vineyards" );  

    $response = wp_remote_get( $url, array( 'headers' => $headers ) );  
    if ( is_wp_error( $response ) ) {  
        echo "Error fetching collection data: " . esc_html( $response->get_error_message() );  
        return;  
    }  

    $response_body = json_decode( wp_remote_retrieve_body( $response ) );  
    if ( isset( $response_body->collection->seo ) ) {  
        $new_title = esc_html( $response_body->collection->seo->title );  
        $new_description = esc_html( $response_body->collection->seo->description );  

        echo "<!-- Gorilion meta -->";  
        echo "<title>{$new_title}</title>\n";  
        echo "<meta name=\"description\" content=\"{$new_description}\"/>\n";  
    }  
}  

// Function to handle product meta  
function gorilion_handle_product_meta( $result ) {  
    $url = "https://api.commerce7.com/v1/product/slug/{$result}/for-web";  
    $headers = array( "tenant: baldacci-family-vineyards" );  

    $response = wp_remote_get( $url, array( 'headers' => $headers ) );  
    if ( is_wp_error( $response ) ) {  
        echo "Error fetching product data: " . esc_html( $response->get_error_message() );  
        return;  
    }  

    $response_body = json_decode( wp_remote_retrieve_body( $response ) );  

    // Avoid warnings if data doesn't exist  
    $price = isset( $response_body->variants[0]->price) ? $response_body->variants[0]->price / 100.00 : '';  
    $description = isset( $response_body->seo->description) ? esc_html( $response_body->seo->description) : '';  
    $wine = isset( $response_body->wine) ? $response_body->wine : array();  
    $title = isset( $response_body->seo->title) ? esc_html( $response_body->seo->title) : '';  
    $sku = isset( $response_body->variants[0]->sku) ? esc_html( $response_body->variants[0]->sku) : '';  
    $img = isset( $response_body->image) ? esc_url( $response_body->image) : '';  

    $keywords = implode( ',', array_filter( array( $title, $sku, implode( ',', (array) $wine ) ) ) );  
    $full_url = 'https://' . rtrim( $_SERVER['HTTP_HOST'], '/' ) . '/' . esc_url( $_SERVER['REQUEST_URI'] );  
    $site_title = esc_html( get_bloginfo( 'name' ) );  

    echo "<!-- Gorilion meta :: VERSION 1.2 -->";  
    echo "<title>{$title}</title>\n";  
    echo "<meta name=\"description\" content=\"{$description}\"/>\n";  
    echo "<meta name=\"keywords\" content=\"{$keywords}\">\n";  
    echo "<link rel=\"canonical\" href=\"" . esc_url( $full_url ) . "\"/>\n";  
    echo "<meta property=\"og:type\" content=\"product\" />\n";  
    echo "<meta property=\"og:title\" content=\"{$title}\"/>\n";  
    echo "<meta property=\"og:description\" content=\"{$description}\"/>\n";  
    echo "<meta property=\"og:image\" content=\"{$img}\"/>\n";  
    echo "<meta property=\"og:url\" content=\"{$full_url}\"/>\n";  
    echo "<meta property=\"og:site_name\" content=\"" . esc_attr( $site_title ) . "\" />\n";  
    echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";  

    echo '<script type="application/ld+json">  
            {  
                "@context": "http://schema.org",  
                "@type": "Product",  
                "name": "' . esc_js( $title ) . '",  
                "image": "' . esc_url( $img ) . '",  
                "description": "' . esc_js( $description ) . '",  
                "brand": {  
                    "@type": "Brand",  
                    "name": "' . esc_js( $site_title ) . '",  
                    "logo": "' . esc_url( wp_get_attachment_image_src( get_theme_mod( "custom_logo" ), "full" )[0] ) . '"  
                },  
                "offers": {  
                    "@type": "Offer",  
                    "priceCurrency": "USD",  
                    "price": "' . esc_js( $price ) . '"  
                }  
            }  
          </script>';  
}
