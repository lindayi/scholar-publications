# Contributing

Thanks for your interest in improving Scholar Publications.

## Reporting issues

When reporting a problem with the publication list, it helps enormously to
include the venue string exactly as Google Scholar shows it, since almost all
parsing issues trace back to how Scholar formats a particular record.

Please do not include your SerpAPI key in an issue.

## Development

The plugin has no build step. Clone it into `wp-content/plugins/` and activate.

Before opening a pull request:

```bash
# Every PHP file must lint cleanly
find . -name '*.php' -exec php -l {} \;

# And the script must parse
node --check assets/js/app.js
```

## Style

- Follow the WordPress coding standards.
- Escape all output at the point of use: `esc_html()`, `esc_attr()`, `esc_url()`.
- Check capabilities and verify nonces for anything that writes.
- No external front end dependencies. The list is server rendered and the
  bundled script only enhances what is already in the DOM, so the page must
  remain usable with JavaScript disabled.
- Comment the reasoning behind non-obvious code, not what the code does.

## Adding venue abbreviations

The bundled lookup table in `includes/class-store.php` covers computer science.
Rather than adding your discipline to that table, prefer the
`scholar_publications_venue_map` filter, which is documented in the readme.
Additions to the bundled table are welcome when a venue is widely known.
