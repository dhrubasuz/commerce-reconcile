<?php
/** Generic page. Author: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="cc-wrap cc-content-page" id="main">
    <?php while (have_posts()) : the_post(); ?>
        <article <?php post_class(); ?> id="post-<?php the_ID(); ?>"><h1><?php echo esc_html(get_the_title()); ?></h1><div class="cc-prose"><?php the_content(); ?><?php wp_link_pages(); ?></div></article>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
