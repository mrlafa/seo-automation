# SEO Automation

A comprehensive SEO automation suite: research, write, optimize, and audit content from one unified plugin with a single PSR-4 codebase and one admin menu.

## Features

### From TheBlog Automation:
- Research, write, optimize, and publish blog content from one system
- Run on autopilot with a human approval gate, or generate posts on demand
- AI-powered content generation (Anthropic Claude, OpenAI GPT, DeepSeek)
- WooCommerce product to blog post conversion
- SEO optimization for Yoast SEO and Rank Math
- Internal linking automation
- Featured image generation (DALL·E)
- Review queue for human approval
- Autopilot mode with WP-Cron scheduling

### From SEO Agent:
- Complete SEO audit loop: audit → identify → fix → verify → report
- Technical SEO checks (indexing, robots.txt, XML sitemap, canonical URLs, etc.)
- Content SEO checks (meta titles/descriptions, headings, image alt text, thin/duplicate content)
- Link checks (broken links, internal linking depth, anchor text, orphan pages)
- Commerce SEO checks (product descriptions, images, SKU, price, brand, GTIN)
- Schema validation (JSON-LD for Product, Article, BreadcrumbList)
- Performance diagnostics (Core Web Vitals, render-blocking resources)
- Discovery checks (FAQ markup, AI-crawler access, llms.txt)
- Reversible fixes with full undo trail
- WP-CLI commands
- MCP server for external agent integration (Claude Code)
- Safety modes: review (nothing writes unapproved), auto_safe (deterministic fixes only), auto_all

## Installation

1. Upload the `seo-automation` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to SEO Automation → Settings to configure AI providers and SEO plugin integrations.
4. Use the single SEO Automation menu in the WordPress dashboard — it holds both the
   audit screens (Dashboard, Issues, Change log, Settings) and the content
   generation screens (Dashboard, Topics, Review Queue, Settings, Logs).

## Codebase

The plugin is distributed as `seo-automation` and uses the `seo-automation`
text domain. Its internal PHP namespace, database tables, option keys, REST
namespace, capability, cron hooks and WP-CLI command deliberately keep their
original `SEOAgent` / `seo_agent` / `theblog` names: they are internal or
already-published API surfaces, and renaming them would migrate data and break
integrations for no user-visible gain.

The whole plugin is one PSR-4 tree under `src/`, autoloaded via `src/autoload.php`:

- `src/*` (namespace `SEOAgent\`) — the SEO audit engine: checkers, fixers,
  REST API, WP-CLI, database layer.
- `src/Blog/*` (namespace `SEOAgent\Blog\`) — the content-generation engine:
  AI providers, research/writer pipeline, WooCommerce product analysis,
  topic queue, scheduler, review queue.
- `src/Admin/AdminMenu.php` registers the single top-level "SEO Automation" admin
  menu; `SEOAgent\Blog\Admin::add_submenus()` attaches the content screens to
  it as submenus, so the plugin surfaces one menu entry in wp-admin.
- `seo-automation.php` is the single bootstrap file (one set of plugin headers,
  one activation/deactivation hook, one `plugins_loaded` handler).

## Configuration

### AI Providers
The suite supports multiple AI providers for content generation:
- Anthropic (Claude) - text generation
- OpenAI (GPT) - text generation and optional DALL·E featured images
- DeepSeek - text generation

### SEO Plugin Integration
The suite integrates with:
- Yoast SEO
- Rank Math
- SEOPress
If none of these are installed, the suite manages metadata directly.

## Usage

### Content Generation (TheBlog Automation)
1. Go to SEO Automation → Topics to add content topics
2. For WooCommerce products, use the "WooCommerce Product" content source
3. Enable Autopilot in Settings for automated content generation
4. Review and approve content in the Review Queue

### SEO Auditing & Optimization (SEO Agent)
1. Go to SEO Automation → Dashboard to see site health score
2. Run audits manually or schedule them
3. Review issues in the issue queue
4. Apply fixes with confidence (each change is recorded and reversible)
5. Verify fixes after application

## Developer Features

### WP-CLI Commands
Available commands include:
- `wp seo-agent audit` - Run SEO audits
- `wp seo-agent issues` - List SEO issues
- `wp seo-agent fix` - Apply specific fixes
- `wp seo-agent verify` - Verify fixes
- `wp seo-agent revert` - Revert fix batches

### REST API
The suite exposes a REST API at `/wp-json/seo-agent/v1/` for:
- Managing audits
- Retrieving issues
- Applying fixes
- Reverting changes
- Accessing settings

### MCP Server
For integration with external agents like Claude Code, the suite includes an MCP server that exposes the full SEO auditing engine as tools.

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- For AI features: API key(s) for at least one supported AI provider
- For WooCommerce product features: WooCommerce plugin active

## License

GPL-2.0-or-later

## Credits

- TheBlog Automation by Nexcove
- SEO Agent by SKS
