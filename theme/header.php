<?php
/** Header. Author / Creator: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="author" content="Dhruba Poudel">
    <meta name="creator" content="Dhruba Poudel">
    <meta name="last-modified-by" content="Dhruba Poudel">
    <meta name="theme-color" content="#173b32">
    <?php if (!has_site_icon()) : ?><link rel="icon" type="image/svg+xml" href="<?php echo esc_url(commerce_canvas_asset('images/canvas-mark.svg')); ?>"><?php endif; ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?> id="top">
<?php wp_body_open(); ?>
<a class="cc-skip" href="#main"><?php esc_html_e('Skip to content', 'commerce-canvas'); ?></a>
<div class="cc-notice"><div class="cc-wrap cc-notice-inner"><span><?php esc_html_e('Commerce Canvas / Original WordPress theme', 'commerce-canvas'); ?></span><span><?php echo esc_html(count(commerce_canvas_live_products()) === 0 ? __('Fictional catalogue · Sample mode', 'commerce-canvas') : __('Powered by WordPress & WooCommerce', 'commerce-canvas')); ?></span></div></div>
<header class="cc-header" data-canvas-header>
    <div class="cc-wrap cc-header-inner">
        <?php if (has_custom_logo()) : ?>
            <div class="cc-brand"><?php the_custom_logo(); ?></div>
        <?php else : ?>
            <a class="cc-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php esc_attr_e('Commerce Canvas home', 'commerce-canvas'); ?>"><img src="<?php echo esc_url(commerce_canvas_asset('images/canvas-mark.svg')); ?>" alt="" width="100" height="100"><span><span class="cc-brand-name">canvas</span><span class="cc-brand-subtitle"><?php esc_html_e('Everyday objects, considered', 'commerce-canvas'); ?></span></span></a>
        <?php endif; ?>
        <button class="cc-menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="canvas-navigation" hidden><?php esc_html_e('Menu', 'commerce-canvas'); ?></button>
        <nav class="cc-nav" id="canvas-navigation" data-canvas-nav aria-label="<?php esc_attr_e('Main navigation', 'commerce-canvas'); ?>">
            <?php if (has_nav_menu('primary')) : ?>
                <?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'menu_class' => 'cc-nav-list', 'fallback_cb' => false, 'depth' => 2]); ?>
            <?php else : ?>
                <ul class="cc-nav-list"><li><a href="<?php echo esc_url(home_url('/#collection')); ?>"><?php esc_html_e('Collection', 'commerce-canvas'); ?></a></li><li><a href="<?php echo esc_url(home_url('/#the-idea')); ?>"><?php esc_html_e('The idea', 'commerce-canvas'); ?></a></li><li><a href="<?php echo esc_url(home_url('/#theme-notes')); ?>"><?php esc_html_e('Craft notes', 'commerce-canvas'); ?></a></li></ul>
            <?php endif; ?>
        </nav>
        <a class="cc-header-note" href="<?php echo esc_url(home_url('/#theme-notes')); ?>"><span><?php esc_html_e('Inside the build', 'commerce-canvas'); ?></span><span aria-hidden="true">↗</span></a>
    </div>
</header>
