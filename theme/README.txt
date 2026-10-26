Commerce Canvas
Author / Creator / Last Modified By: Dhruba Poudel
Version: 1.0.0
Licence: GPL-2.0-or-later

An original classic WordPress storefront theme with a matching static design preview.

INSTALL
Zip the theme directory as a single commerce-canvas/ folder containing style.css and index.php. Upload through WordPress Appearance > Themes > Add New > Upload Theme, then activate in a test WordPress installation. WordPress 6.6+ and PHP 8.1+ are the declared minimums. Runtime validation must be recorded against the actual tested WordPress, PHP and WooCommerce versions.

HOMEPAGE
front-page.php provides the designed landing page. It is a deliberately composed template rather than a page-builder integration. Generic pages, posts, archives, search and missing-page views have separate templates. Assign a primary navigation menu and custom logo through the usual WordPress controls when needed. The sample mark is used if no custom logo is set.

CATALOGUE MODES
Without WooCommerce, or when no visible published products are returned, the homepage displays six explicitly fictional sample objects. Prices are design fixtures; the sample homepage has no cart, checkout, payment collection or order mutation.

When WooCommerce supplies visible published products, the homepage reads up to six of them, displays their permitted product image/price HTML and escaped names/descriptions, and links to product pages. This read-only homepage does not add to cart, modify orders or configure gateways. Other WooCommerce pages use WooCommerce's own runtime. A real merchant remains responsible for taxes, shipping, product claims, payments, privacy and operating configuration. Test those flows separately before any commercial use.

STATIC PREVIEW
Serve theme/preview/index.html together with the relative theme/assets, style.css and repository brand/ directory. It works through a static HTTP server with no package installation. The preview uses the same design assets, stylesheet and navigation/filter script as the theme, with a matching fictional layout. It does not execute PHP or prove WordPress/WooCommerce integration.

Category buttons are progressively enhanced. With JavaScript disabled, all six products remain visible and native details still work. No remote image service, font CDN, analytics, cookies, local storage or credential input is used by the static preview.

DESIGN SOURCE
The original mark and all nine SVG illustrations are in assets/images/. theme/tools/build_preview.py regenerates the original SVG files, static preview and front-page.php from the source layout. Run it only when intentionally editing those generated design files; it overwrites those three generated groups. Review any hand edits before regeneration. It does not edit functions.php or the shared stylesheet.

DEVELOPMENT CHECKS
From the repository root, run: python theme/tools/check_theme.py --php /path/to/php
This checks two static pages, local references, SVG metadata, PHP syntax and alignment between the PHP fixture catalogue and its static preview. The actual PHP runtime version must be recorded with the result.

For the DOM interaction suite, use Node.js 24+ and pnpm 11.25.0, run pnpm --dir theme install --frozen-lockfile --ignore-scripts, then pnpm --dir theme test. jsdom is a pinned development-only dependency with a committed lockfile; it is not bundled in the installable theme or required by visitors. An already-installed jsdom module can be supplied to the test runner through CANVAS_JSDOM_MODULE as an absolute path to its lib/api.js instead of another install. DOM tests do not replace actual browser layout, keyboard, WordPress or WooCommerce runtime checks.

Run python theme/tools/package_theme.py to create the minimal installable ZIP, including the Appearance screenshot only if theme/screenshot.png exists. Preview, tests and build tools are intentionally omitted from that installable archive.

ACCESSIBILITY SCOPE
The theme includes a skip link, landmarks, descriptive illustration text, visible focus styles, native detail disclosures, mobile menu state, an announced catalogue count and a reduced-motion preference. These are implementation features, not a claim of complete WCAG conformance or accessibility certification. Custom menus, content and WooCommerce extensions require their own review. This theme has not been submitted to the WordPress theme directory.

SECURITY AND DATA
Dynamic catalogue text and URLs are escaped separately. WooCommerce-provided product image/price HTML is passed through wp_kses_post. The theme performs no custom database query, remote request or state-changing action. It does not collect API credentials or implement authentication. Commerce Reconcile is a separate plugin, not an invisible dependency of this theme.

All bundled shop names, products, descriptions, prices and artwork are fictional demonstrations. No client result, testimonial, business saving, payment-provider endorsement or production certification is implied.

LICENCE
Original theme code and bundled artwork by Dhruba Poudel are licensed under GNU GPL version 2 or, at your option, any later version. Distributed without warranty. The complete licence is available at https://www.gnu.org/licenses/old-licenses/gpl-2.0.html. WordPress and WooCommerce retain their own licences and trademarks.

OFFICIAL REFERENCES FOR INTEGRATION REVIEW
https://developer.wordpress.org/themes/classic-themes/basics/required-theme-files/
https://developer.wordpress.org/themes/basics/including-css-javascript/
https://developer.wordpress.org/apis/security/escaping/
https://developer.woocommerce.com/docs/theming/theme-development/classic-theme-developer-handbook/

Maintained by Dhruba Poudel.
