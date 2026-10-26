<?php
/**
 * Commerce Canvas theme setup and catalogue helpers.
 * Author / Creator / Last Modified By: Dhruba Poudel
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

function commerce_canvas_setup(): void
{
    load_theme_textdomain('commerce-canvas', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('automatic-feed-links');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', ['height' => 80, 'width' => 220, 'flex-width' => true, 'flex-height' => true]);
    add_theme_support('woocommerce', ['thumbnail_image_width' => 640, 'single_image_width' => 1000]);
    register_nav_menus(['primary' => __('Primary navigation', 'commerce-canvas')]);
}
add_action('after_setup_theme', 'commerce_canvas_setup');

function commerce_canvas_assets(): void
{
    $version = (string) wp_get_theme()->get('Version');
    wp_enqueue_style('commerce-canvas', get_stylesheet_uri(), [], $version);
    wp_enqueue_script('commerce-canvas', get_template_directory_uri() . '/assets/main.js', [], $version, true);
}
add_action('wp_enqueue_scripts', 'commerce_canvas_assets');

/** Return at most six visible published products without writing to an order or cart. */
function commerce_canvas_live_products(): array
{
    static $products = null;
    if ($products !== null) {
        return $products;
    }
    $products = [];
    if (!function_exists('wc_get_products')) {
        return $products;
    }
    $candidates = wc_get_products(['status' => 'publish', 'visibility' => 'catalog', 'limit' => 12, 'orderby' => 'date', 'order' => 'DESC']);
    foreach ($candidates as $product) {
        if ($product instanceof WC_Product && $product->is_visible()) {
            $products[] = $product;
            if (count($products) === 6) {
                break;
            }
        }
    }
    return $products;
}

/** The fallback catalogue is an explicit design fixture, not a merchant's inventory. */
function commerce_canvas_sample_products(): array
{
    return require get_template_directory() . '/inc/sample-products.php';
}

function commerce_canvas_asset(string $path): string
{
    return get_template_directory_uri() . '/assets/' . ltrim($path, '/');
}

function commerce_canvas_product_card($product, int $index, bool $is_sample): void
{
    if ($is_sample) {
        if (!is_array($product)) {
            return;
        }
        $name = $product['name'];
        $category = $product['category'];
        $description = $product['description'];
        $details = $product['details'];
        $image = commerce_canvas_asset('images/' . $product['image']);
        $price = $product['price'];
        $category_key = $product['filter'];
    } else {
        if (!($product instanceof WC_Product)) {
            return;
        }
        $name = $product->get_name();
        $category = __('Catalogue', 'commerce-canvas');
        $description = wp_trim_words(wp_strip_all_tags($product->get_short_description()), 20);
        $category_key = 'catalogue';
    }
    ?>
    <article class="cc-product-card" data-product-category="<?php echo esc_attr($category_key); ?>">
        <div class="cc-product-image">
            <?php if ($is_sample) : ?>
                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr(sprintf(__('Original illustration of the fictional %s', 'commerce-canvas'), $name)); ?>" width="680" height="680" loading="lazy">
            <?php else : ?>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" tabindex="-1" aria-hidden="true"><?php echo wp_kses_post($product->get_image('woocommerce_thumbnail', ['loading' => 'lazy'])); ?></a>
            <?php endif; ?>
            <span class="cc-product-category"><?php echo esc_html($category . ' / ' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
        </div>
        <div class="cc-product-heading">
            <h3><?php if (!$is_sample) : ?><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php endif; ?><?php echo esc_html($name); ?><?php if (!$is_sample) : ?></a><?php endif; ?></h3>
            <span class="cc-price"><?php if ($is_sample) : ?><?php echo esc_html($price); ?> <small>AUD</small><?php else : ?><?php echo wp_kses_post($product->get_price_html()); ?><?php endif; ?></span>
        </div>
        <p class="cc-product-copy"><?php echo esc_html($description); ?></p>
        <?php if ($is_sample) : ?>
            <details class="cc-product-details"><summary><?php echo esc_html(sprintf(__('Explore %s details', 'commerce-canvas'), $name)); ?></summary><p><?php echo esc_html($details); ?></p><p class="cc-sample-note"><?php esc_html_e('Fictional product · Sample price · No checkout', 'commerce-canvas'); ?></p></details>
        <?php else : ?>
            <a class="cc-product-link" href="<?php echo esc_url($product->get_permalink()); ?>"><?php esc_html_e('View product', 'commerce-canvas'); ?> <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
    </article>
    <?php
}
