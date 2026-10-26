<?php
/** Footer. Author / Creator: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
?>
<footer class="cc-footer"><div class="cc-wrap"><div class="cc-footer-top"><h2><?php esc_html_e('Made for', 'commerce-canvas'); ?><br><?php esc_html_e('the everyday.', 'commerce-canvas'); ?></h2><nav class="cc-footer-nav" aria-label="<?php esc_attr_e('Footer navigation', 'commerce-canvas'); ?>"><a href="<?php echo esc_url(home_url('/#collection')); ?>"><?php esc_html_e('The collection', 'commerce-canvas'); ?></a><a href="<?php echo esc_url(home_url('/#theme-notes')); ?>"><?php esc_html_e('Theme notes', 'commerce-canvas'); ?></a><a href="#top"><?php esc_html_e('Back to top', 'commerce-canvas'); ?> ↑</a></nav></div><div class="cc-footer-bottom"><span><?php esc_html_e('Original theme, design & artwork by', 'commerce-canvas'); ?> <strong>Dhruba Poudel</strong></span><span><?php esc_html_e('Sample catalogue entries are fictional. Merchant configuration is separate.', 'commerce-canvas'); ?></span></div></div></footer>
<?php wp_footer(); ?>
</body>
</html>
