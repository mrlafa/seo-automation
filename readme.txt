=== SEO Automation ===
Contributors: ssomai
Tags: seo, content, audit, woocommerce, schema
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit your site's SEO, fix what it finds, and generate optimised content — with every automated change logged and reversible.

== Description ==

SEO Automation combines two things most sites handle separately: finding what is wrong with your SEO, and producing content that is right from the start.

**The audit loop**

Run an audit and the plugin inspects your posts, pages, products, categories and site configuration against 19 checks, then records each finding as a discrete issue you can act on:

* Metadata — titles, descriptions, duplicates, canonical URLs
* Content — heading structure, thin content, duplicate content, image alt text
* Links — broken links, internal link depth, anchor text, orphaned pages
* Technical — robots.txt, XML sitemaps, URL structure, indexability
* Commerce — product descriptions, images, SKU, price and brand markup
* Structured data — JSON-LD for Product, Article and BreadcrumbList
* Performance — Core Web Vitals and render-blocking resources
* Discovery — FAQ markup, AI crawler access, llms.txt

Findings are deduplicated across runs, so an issue you have already seen does not reappear as new. Long audits resume where they left off rather than restarting.

**Fixes you can undo**

Every fix is applied through a recorded change with its previous value stored, so any batch can be reverted from the change log. Three safety modes control how much the plugin may do without asking:

* `review` — nothing is written until you approve it (default)
* `auto_safe` — only deterministic fixes are applied automatically
* `auto_all` — all available fixes are applied automatically

**Content generation**

Queue a topic — or point the plugin at a WooCommerce product — and it researches, writes, and optimises a draft. Generated content always lands in a review queue as a pending draft. The plugin never publishes on your behalf.

**Works with your existing SEO plugin**

Metadata is read and written through whichever SEO plugin you already use — Yoast SEO, Rank Math, or SEOPress. If none is active, the plugin manages metadata itself. Your existing data is never duplicated or migrated.

**For developers**

* WP-CLI: `wp seo-agent audit`, `issues`, `fix`, `verify`, `revert`
* REST API under `/wp-json/seo-agent/v1/` for external tooling
* Filters for registering your own checkers and fixers

= External services =

This plugin can send data to third-party services. **No data leaves your site unless you supply an API key for that service and trigger an action that uses it.** No external request is made on installation, activation, or normal page views, and no usage tracking, analytics, or telemetry of any kind is collected.

**AI text providers — Anthropic, OpenAI, or DeepSeek**

Used only for content generation and AI-assisted metadata suggestions, and only for the one provider you select in the settings.

* What is sent: the topic or keyword you entered, and — where relevant to the request — existing post or WooCommerce product content from your site (title, description, attributes) that the plugin is asked to write about or optimise.
* When: only when you generate content manually, or when you enable Autopilot and a scheduled generation runs.
* Where: Anthropic — `api.anthropic.com`; OpenAI — `api.openai.com`; DeepSeek — `api.deepseek.com`.

Anthropic: [Terms](https://www.anthropic.com/legal/commercial-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)
OpenAI: [Terms](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/policies/privacy-policy)
DeepSeek: [Terms](https://www.deepseek.com/terms) | [Privacy Policy](https://www.deepseek.com/privacy)

**OpenAI image generation**

Optional, and disabled by default. If you set the image provider to OpenAI, the plugin sends a text prompt describing the desired featured image to `api.openai.com`. No image from your media library is uploaded. Covered by the OpenAI links above.

**Google PageSpeed Insights**

Optional. Used by the Core Web Vitals check to retrieve real-world performance data for your pages.

* What is sent: the public URL of the page being audited. No page content and no personal data are transmitted.
* When: only during an audit, and only if you have entered a PageSpeed Insights API key.
* Where: `www.googleapis.com`.
* Without a key, the plugin inspects your own markup locally instead and labels those findings as diagnostic rather than measured.

Google: [Terms](https://developers.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

== Installation ==

1. Upload the `seo-automation` folder to `/wp-content/plugins/`, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Go to **SEO Automation → Audit: Settings** to choose which post types and taxonomies to audit and to set your safety mode.
4. To generate content, go to **SEO Automation → Content: Settings** and add an API key for your chosen AI provider.

The plugin is fully functional for auditing without any API key. Keys are only needed for content generation and for field-data Core Web Vitals.

== Frequently Asked Questions ==

= Does the plugin need an API key to work? =

No. The entire audit and fix engine runs locally with no API key and no external requests. Keys are only required for AI content generation and for PageSpeed Insights field data.

= Will it change my site without asking? =

Not by default. The safety mode ships as `review`, which stages every fix for your approval. You have to deliberately switch to `auto_safe` or `auto_all` to let it write unattended.

= Can I undo a fix? =

Yes. Every change stores its previous value and is grouped into a batch. Any batch can be reverted from the change log screen, via WP-CLI, or through the REST API.

= Does it conflict with Yoast, Rank Math, or SEOPress? =

No. It detects your SEO plugin and reads and writes metadata through it, so there is one source of truth. If none is installed, the plugin manages metadata itself.

= Does it publish generated content automatically? =

No. Generated content always enters the review queue as a pending draft for a human to approve.

= Does it collect any analytics or usage data? =

No. There is no tracking, telemetry, or phone-home behaviour of any kind.

= Is WooCommerce required? =

No. WooCommerce-specific checks and the product-to-article feature activate only when WooCommerce is present.

== Changelog ==

= 2.0.0 =
* Renamed the plugin to SEO Automation.
* Fixed internationalisation: all user-facing strings now use the `seo-automation` text domain and are translatable. Previously the declared text domain did not match the strings in the code, so no string could be translated.
* API keys are now write-only in the admin. Stored keys are no longer rendered into the settings page HTML, and saving an unrelated setting no longer risks clearing a configured key. Removing a key is now an explicit action.
* Added `readme.txt` with full disclosure of every external service the plugin can contact.
* Added the missing `License` and `License URI` plugin headers.
* Removed a duplicate textdomain load that left part of the plugin untranslated.
* Merged the former TheBlog Automation and SEO Agent plugins into one codebase with a single admin menu, one bootstrap file, and one autoloader.

== Upgrade Notice ==

= 2.0.0 =
The main plugin file has been renamed, so WordPress will deactivate the plugin during this update. Reactivate it once from the Plugins screen. All settings, audits, issues, and change history are preserved, and no reconfiguration is needed.
