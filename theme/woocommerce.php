<?php
/** WooCommerce content wrapper. Author: Dhruba Poudel. GPL-2.0-or-later. */
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="cc-wrap cc-wc-content" id="main"><div class="woocommerce">
    <?php if (function_exists('woocommerce_content')) { woocommerce_content(); } ?>
</div></main>
<?php get_footer(); ?>
