<?php
/** Search results. Author: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="cc-wrap cc-content-page" id="main"><span class="cc-eyebrow"><?php esc_html_e('Site search', 'commerce-canvas'); ?></span><h1><?php echo esc_html(sprintf(__('Results for “%s”', 'commerce-canvas'), get_search_query(false))); ?></h1><div class="cc-prose"><?php get_search_form(); ?><?php if (have_posts()) : while (have_posts()) : the_post(); ?><article <?php post_class('cc-entry'); ?>><h2><a href="<?php echo esc_url(get_permalink()); ?>"><?php echo esc_html(get_the_title()); ?></a></h2><?php the_excerpt(); ?></article><?php endwhile; ?><div class="cc-pagination"><?php the_posts_pagination(); ?></div><?php else : ?><p><?php esc_html_e('No published content matched this search.', 'commerce-canvas'); ?></p><?php endif; ?></div></main>
<?php get_footer(); ?>
