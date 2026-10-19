# TekstTV (WordPress plugin)

WordPress plugin to manage text-TV slides and serve them as JSON to the [TekstTV playout app](https://github.com/oszuidwest/teksttv-frontend). In the Tekst TV admin menu you set up channels, build the broadcast loop from blocks (posts, images, iframes, commercials, weather, ticker items), and manage settings, commercials and optional AI-assisted content.

## How it fits with the frontend

The playout in [oszuidwest/teksttv-frontend](https://github.com/oszuidwest/teksttv-frontend) is a thin client: it polls a JSON playlist on a timer and renders the slides plus the ticker bar. This plugin is the usual content source: editing in WordPress, slide and ticker assembly in PHP, delivery via `GET /wp-json/teksttv/v1/slides`. A different CMS can fill the same role, provided it returns the same payload shape (see `src/types.ts` and the schema in the frontend README).

## Requirements

- WordPress 7.0 or newer
- PHP 8.3 or newer

For development from a Git checkout you also need [Composer](https://getcomposer.org/) and [Bun](https://bun.sh/).
The Playground E2E suite additionally uses Node.js 24 LTS.

## Installation

### Pre-built zip (recommended)

Pushing a version tag starts the Release workflow and publishes
`teksttv-<version>.zip`. It uses the
same canonical packager as the E2E suite: tracked production source, the exact
built asset set, and a fresh `composer install --no-dev` inside the staged
plugin. Upload the ZIP under Plugins → Add New → Upload Plugin and activate.

The tag must exactly match the version in the plugin header, which is also the
runtime source for `TEKSTTV_VERSION`. For example, release version `0.5.0` with
`git tag 0.5.0 && git push origin 0.5.0`. The workflow validates and packages
that tagged commit, smoke-tests the ZIP in WordPress Playground, and then
publishes it using repository-native immutable
releases, so published tags and assets cannot be replaced.

### Build from source

1. Drop the folder in `wp-content/plugins/` (any folder name, `teksttv` is fine).
2. Install PHP dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. Install JS/CSS dependencies and compile:

   ```bash
   bun install
   bun run build
   ```

4. Activate TekstTV under Plugins.

Without `vendor/` and a built `assets/` the plugin won't load: `vendor/autoload.php` and the admin assets are missing.

## Capabilities after activation

| Role          | Capabilities |
|---------------|--------------|
| Administrator | `manage_teksttv`, `manage_teksttv_commercials`, `manage_teksttv_content`, `edit_teksttv` |
| Editor        | `edit_teksttv` (TekstTV fields on posts) |

If you need a different distribution, use a capability plugin.

## Usage

- Tekst TV → Loop (per configured channel): the order and composition of the broadcast loop. Block types include posts, image, commercial and weather. The ticker is configured separately.
- Settings: channel slugs (`tv1`, `tv2`, …), display duration for text and images, OpenWeather API key, feature toggles (TinyMCE, AI, scheduling), preview URL.
- Commercials (`Reclame`): commercial blocks and their campaigns used in the loop.
- Content & AI / AI Audit, when AI generation is enabled and a configured provider supports TekstTV's text-generation requirements: prompts and audit log.

If no channels are stored, `tv1` is assumed.

## RSS export

Enable **TekstTV opnemen in RSS** under Tekst TV → Instellingen → RSS to add
TekstTV fields to the existing RSS 2.0 feeds (main, category, tag, author,
search, …); only items of type `post` receive them. This feature is off by
default. Atom, RSS 0.92, RDF and comment feeds are unchanged. The normal RSS
fields and the WordPress feed selection/size remain unchanged; this does not
export a channel's playlist or apply its block filters.

The namespace URI is
`https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0`. It identifies the XML
vocabulary; it is not a feed endpoint. Consumers should select fields by this
URI and local name, rather than relying on the `teksttv` prefix.

```xml
<rss version="2.0"
     xmlns:teksttv="https://github.com/oszuidwest/teksttv-wp-plugin/rss/1.0">
  <channel>
    <!-- Normal RSS channel fields and item fields omitted here. -->
    <item>
      <teksttv:title>TekstTV-kop</teksttv:title>
      <teksttv:content><![CDATA[<p>Eerste slide.</p>
<hr />
<p>Tweede slide.</p>]]></teksttv:content>
      <teksttv:image>https://example.com/sidebar.jpg</teksttv:image>
      <teksttv:extraImage>https://example.com/foto-1.jpg</teksttv:extraImage>
      <teksttv:extraImage>https://example.com/foto-2.jpg</teksttv:extraImage>
    </item>
  </channel>
</rss>
```

- Only published, non-password-protected posts with **Toon op Tekst TV** enabled
  receive TekstTV fields, and only while they are within **Maximale leeftijd
  berichten**. If scheduling is enabled, their start/end dates (inclusive) and
  allowed weekdays must match the current WordPress site date.
  As in the playout, disabled features ignore their stored post meta.
- `title` is plain text: the custom TekstTV title when that feature is enabled
  and a title is set, otherwise the post title.
- `content` contains the TekstTV HTML with paragraphs, wrapped in CDATA. When
  **Meerdere slides** is enabled, page separators become `<hr />` boundaries.
- `image` is optional and uses the same sidebar image selection as the playout:
  a custom override (or explicit suppression), category image, then featured
  image.
- `extraImage` is repeated in saved order, omitting unavailable attachments.
  Image-only posts receive only `title` and `extraImage`; posts with neither
  text nor usable extra images receive no TekstTV fields.

The playout and RSS use the same per-post eligibility, title, page and image
rules (`ArticleContent::prepare`). Invalid stored scheduling dates exclude the
post in both, and an empty URL from `teksttv_image_url` suppresses an image.
The callers intentionally select different sets of posts: the playout applies
its channel taxonomy, count and deduplication rules and includes
password-protected published posts; RSS follows the requested WordPress feed
and never exposes TekstTV fields for password-protected posts.

Attachment posts, their metadata and category images are primed once per feed
or playout query batch, so image queries do not grow with the number of
articles. `tests/e2e/attachment-cache.spec.ts` enforces this.

Poll the feed regularly and replace the imported TekstTV data for each returned
item: if its TekstTV fields disappear, remove that item's imported TekstTV
version. Missing fields do not remove the ordinary RSS item. Items that leave
the feed window need a retention policy on the receiving narrowcaster.

RSS 2.0 feeds support ETag and Last-Modified conditional requests: unchanged
polls return 304. Validators advance on post saves, feature or maximum-age
changes, category images, and direct metadata writes through WordPress APIs.
While export is enabled they also advance at midnight in the site timezone
for date and weekday transitions, and when a feed post reaches the maximum age.
Direct SQL writes bypass these hooks; importers
must use the WordPress APIs. CDNs may cache feeds, but should revalidate on
each poll to see these changes promptly.

## REST API

Public endpoint, no login required:

```http
GET /wp-json/teksttv/v1/slides?channel=<channel-slug>
```

The payload contains `slides` (the loop) and `ticker` entries. `channel` must match a configured slug (`validate_channel`). Responses are built fresh on every request; add edge caching at the hosting layer (e.g. Cloudflare) if needed. The [playout app](https://github.com/oszuidwest/teksttv-frontend) consumes this shape on a timer (see Auto-Refresh in its README).

Editor-only endpoints (image metadata, generation, …) need a user with `edit_teksttv`. See `TekstTV\RestApi::register_routes()` in [`src/RestApi.php`](src/RestApi.php), namespace `teksttv/v1`.

## Development scripts

From [`package.json`](package.json):

| Command            | Purpose |
|--------------------|---------|
| `bun run build`    | Bundle and minify JS/CSS to `assets/`, copy TinyMCE support files |
| `bun run build:package` | Build assets and create the validated `release/teksttv/` directory plus versioned ZIP |
| `bun run dev`      | Watch JS and CSS |
| `bun run check`    | PHPCS plus all frontend checks |
| `bun run check:frontend` | Biome, TypeScript and Bun unit tests |
| `bun run lint:js`  | Biome on maintained JS, TS and CSS sources/tests |
| `bun run lint:fix` | PHPCBF + Biome `--write` |
| `bun run typecheck`| TypeScript type checking without emitting files |
| `bun run analyse`  | PHPStan |
| `bun run test`     | PHPUnit (unit) |
| `bun run env:start`| Build + package the artifact and boot an interactive [WordPress Playground](https://wordpress.github.io/wordpress-playground/) on port 8888 |
| `bun run test:e2e` | Start a disposable Blueprint-configured Playground and run the Playwright smoke suite |

### End-to-end smoke suite

The e2e suite mounts the **built plugin artifact** (not the raw checkout) into
a disposable WordPress Playground and checks activation, administrator and
custom-role settings saves, admin screen rendering, and the `/slides` REST
shape. [`blueprint.json`](blueprint.json) pins WordPress and PHP, activates and
validates the artifact, and loads the deterministic fixtures. Locally:

```bash
bun run build:package
bun run test:e2e
```

For interactive inspection, run `bun run env:start` and sign in with
`admin` / `password`. Stop the ephemeral server with Ctrl-C. Playground uses
SQLite, while the plugin itself relies on WordPress database APIs rather than
database-specific queries.

CI runs lint, the plugin artifact build, and the e2e suite; see [`.github/workflows/`](.github/workflows/).
