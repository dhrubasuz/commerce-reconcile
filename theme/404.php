<?php
/** Missing page. Author: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="cc-wrap cc-content-page" id="main"><span class="cc-eyebrow"><?php esc_html_e('404 / Page not found', 'commerce-canvas'); ?></span><h1><?php esc_html_e('A small detour.', 'commerce-canvas'); ?></h1><div class="cc-prose"><p><?php esc_html_e('The page could not be found. Return to the homepage or search the site.', 'commerce-canvas'); ?></p><p><a class="cc-button" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Return home', 'commerce-canvas'); ?> <span aria-hidden="true">↗</span></a></p><?php get_search_form(); ?></div></main>
<?php get_footer(); ?>
