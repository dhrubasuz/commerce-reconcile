<?php
/** Posts index. Author: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="cc-wrap cc-content-page" id="main">
    <span class="cc-eyebrow"><?php esc_html_e('Commerce Canvas / Journal', 'commerce-canvas'); ?></span>
    <h1><?php echo esc_html(is_home() ? __('Notes & ideas', 'commerce-canvas') : wp_strip_all_tags(get_the_archive_title())); ?></h1>
    <div class="cc-prose">
        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
            <article <?php post_class('cc-entry'); ?> id="post-<?php the_ID(); ?>"><h2><a href="<?php echo esc_url(get_permalink()); ?>"><?php echo esc_html(get_the_title()); ?></a></h2><?php the_excerpt(); ?><a class="cc-product-link" href="<?php echo esc_url(get_permalink()); ?>"><?php echo esc_html(sprintf(__('Read %s', 'commerce-canvas'), get_the_title())); ?> <span aria-hidden="true">↗</span></a></article>
        <?php endwhile; ?><div class="cc-pagination"><?php the_posts_pagination(); ?></div>
        <?php else : ?><p><?php esc_html_e('There are no published entries in this view yet.', 'commerce-canvas'); ?></p><?php endif; ?>
    </div>
</main>
<?php get_footer(); ?>
