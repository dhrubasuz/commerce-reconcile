<?php
/**
 * Plugin Name: Commerce Reconcile
 * Description: Read-only WooCommerce/API snapshot reconciliation with explicit exceptions, duplicate protection and reproducible review hashes.
 * Version: 1.0.0
 * Author: Dhruba Poudel
 * Author URI: https://about.dhrub.com.np/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: commerce-reconcile
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/CanonicalJson.php';
require_once __DIR__ . '/includes/Reconciler.php';
require_once __DIR__ . '/includes/WooCommerceAdapter.php';
require_once __DIR__ . '/includes/Plugin.php';

(new DhrubaPoudel\CommerceReconcile\Plugin(__FILE__))->boot();
