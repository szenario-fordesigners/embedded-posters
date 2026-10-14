<br />
<div align="center"><strong>Embedded Posters: embed artwork, served from your own domain</strong></div>

<br />
<div align="center"><strong>Local poster images for Embedded Assets embeds in Craft CMS.</strong></div>
<div align="center">Show the YouTube, Vimeo or SoundCloud artwork on a consent placeholder without the visitor's browser contacting the provider.</div>

<br />
<div align="center">
  <sub>Made possible by</sub>
  <sub><br />
  <a href="https://www.szenario-design.com/" target="_blank">
    <img src="images/szenario-logo.svg" style="width:140px;" alt="szenario-design.com logo" /></a>
  </sub>

  <sub>The team behind the magic</sub><br />
  <sub><a href="https://twitter.com/smonist">Simon Wesp</a></sub>
  <sub><a href="https://twitter.com/thomasbendl">Thomas Bendl</a></sub>
  <sub>Erich Bendl</sub>
</div>

<br />
<br />

<div align="center"><strong>
An add-on for the great <a href="https://plugins.craftcms.com/embeddedassets">Embedded Assets</a> by Spicy Web. Not made or supported by Spicy Web.
</strong></div>

## Why

Embedded Assets stores the poster as a URL on the provider's CDN (`i1.sndcdn.com`, `i.ytimg.com`, ...). Putting that URL in an `<img>` on a consent placeholder sends the visitor's IP address to the provider before they have consented. This plugin downloads the poster on the server when the embed is saved and stores it as an ordinary image asset, served from your own domain.

The plugin removes this one request. Whether the rest of your consent setup is compliant is up to you; this is not legal advice.

## Requirements

Craft CMS 5.3 or later, PHP 8.2 or later, and Embedded Assets 5.

## Installation

Search for "Embedded Posters" in the Plugin Store in your control panel and click "Install", or use Composer:

```bash
composer require szenario/craft-embedded-posters
php craft plugin/install embedded-posters
php craft embedded-posters/fetch
```

The last command fetches posters for the embeds you already have.

## How it works

- Saving an embed (including Embedded Assets' **Refresh** action) downloads its `image` and stores it as `{embed uid}.{ext}` in the `embed-posters` folder of the embed's volume. If the poster already exists, its file is replaced in place; if the image hasn't changed, nothing is touched.
- Deleting an embed deletes its poster. Restoring an embed from the trash restores its poster.
- If an embed no longer has an image, its poster is deleted.
- A failed download never fails the embed's save. It logs a warning, and the placeholder simply has no poster.

Only JPEG, PNG, WebP, GIF and AVIF are accepted, up to 10 MB, over `http` or `https`, with a 10 second timeout and at most 3 redirects. SVG is rejected because, served from your own domain, it could run script. Requests use Craft's Guzzle client, so `config/guzzle.php` (proxies etc.) applies.

## Templating

`craft.embeddedPosters.poster(asset)` returns the poster as an `Asset`, or `null`. Transforms work as for any image:

```twig
{% set poster = craft.embeddedPosters.poster(asset) %}
{% if poster %}
	<img src="{{ poster.getUrl({ width: 800, format: 'webp' }) }}" alt="" loading="lazy">
{% endif %}
```

### Eager loading

Inside a loop over query results, `poster()` loads the posters of all results in one query on its first call, so a loop doesn't run one query per embed.

When the embeds sit behind a relation, for example an asset field on entries, eager-load the field as usual. `poster()` then loads the posters for all entries at once:

```twig
{% for entry in craft.entries.section('artists').with(['videos']).all() %}
	{% for asset in entry.videos %}
		{% set poster = craft.embeddedPosters.poster(asset) %}
	{% endfor %}
{% endfor %}
```

The posters can also be eager-loaded explicitly with the `embeddedPoster` handle, for example `.with(['videos', 'videos.embeddedPoster'])`, and read with `asset.getEagerLoadedElements('embeddedPoster').one()`.

## Configuration

Go to **Settings → Plugins → Embedded Posters**:

- **Volume:** where posters are stored. The default is each embed's own volume.
- **Folder:** a folder inside that volume. Empty means the volume root.

Changing either setting moves the existing posters there in a queue job, in every environment the change is deployed to. Nothing is downloaded again, and posters keep working while the job runs. A change made only in `config/embedded-posters.php` fires no event: run `php craft embedded-posters/fetch` afterwards, or let posters move as their embeds are saved.

The settings are stored in project config. You can also set them in `config/embedded-posters.php`, which wins over the settings page:

```php
<?php

return [
    // Volume handle for posters. null (the default) stores each poster in its embed's own volume.
    'volume' => null,
    // Folder inside that volume. '' means the volume root.
    'folder' => 'embed-posters',
];
```

A dedicated volume keeps posters out of editors' way. It also keeps them away from plugins that react to every new image asset, such as AI alt-text generators or image optimisers, which would otherwise process every poster.

## Backfill

```bash
php craft embedded-posters/fetch [--volume=<handle>]
```

Prints one line per JSON asset: `fetched`, `unchanged`, `moved`, `skipped (reason)` or `failed (reason)`. Unlike `resave/assets`, it doesn't re-save every asset, so other plugins' save listeners stay quiet.

## Not supported

- GraphQL.
- Control panel thumbnails: Embedded Assets still loads those from the provider.
- Moving an embed to another volume while `volume` is `null`: its poster follows on the embed's next save or `fetch` run.
- After a move triggered by a settings change, the old folder is deleted if nothing else is in it (no other files, no subfolders, nothing in the trash). Posters moved by `fetch` or by saving an embed leave their old folder in place.
- Embedded Assets' Refresh saves a temporary copy of the embed and moves it to the trash afterwards. That copy gets a poster too, which goes to the trash with it.
- Craft deletes an asset's file when it is deleted in the control panel, so such an embed (and its poster) can't be restored from the trash. Restoring only applies to embeds whose file Craft kept, for example after a folder deletion.

## Support

- Issues: https://github.com/szenario-fordesigners/embedded-posters/issues
- Source: https://github.com/szenario-fordesigners/embedded-posters
- Developer: https://www.szenario-design.com/
