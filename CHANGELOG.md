# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-02

### Added

- An optional ORCID iD setting. When set, the metrics rail shows the iD beside
  the Google Scholar profile link, carrying the ORCID iD mark and linking to the
  full `https://orcid.org/` URI as the display guidelines require. The icon is
  inlined, so the page still makes no third-party requests.
- The submitted value may be a bare iD or a full orcid.org URL, and is checked
  against the ISO 7064 MOD 11-2 checksum so a mistyped iD is rejected rather
  than published as a dead link. A rejected value raises an admin warning
  instead of being discarded silently.

## [1.0.1] - 2026-08-30

### Changed

- The block now follows the surrounding page's colour scheme instead of forcing
  a light one. Its accent and opaque surface colours are taken from the theme's
  palette (`--wp--preset--color--primary` and `--wp--preset--color--background`)
  when the theme provides them, so a dark theme or a dark mode plugin repaints
  the list with no configuration.
- Type badges resolve through `light-dark()`, with the light value repeated as a
  plain declaration for browsers that do not support it, so they stay legible on
  a dark surface without coupling the plugin to any particular dark mode
  implementation.

### Fixed

- The browser's native clear button no longer appears alongside the block's own
  one in the search field.

## [1.0.0] - 2026-08-30

Initial release.

### Added

- Google Scholar as the single source of publication data, read through the
  SerpAPI Google Scholar Author API.
- `[scholar_publications]` shortcode with `limit`, `type`, `layout`, `stats`,
  `chart`, `controls`, `group` and `sort` attributes.
- Metrics summary showing citations, h-index and i10-index, plus a
  citations-per-year chart with value gridlines.
- Search across title, authors, venue and year.
- Multi-select publication type filters.
- Venue filter using venue abbreviations, with preprints and patents grouped.
- Sorting by newest, oldest, most cited and title.
- Sticky year headings.
- Expandable details with abstract, publisher and per-publication citation
  history.
- One-click BibTeX copying.
- Sticky side rail layout that folds above the list on narrow screens.
- Scheduled refresh with quota protection: a remaining-search reserve, a
  per-sync cap on detail requests, and a time budget.
- Automatic pruning of cached records for publications removed from the profile.
- `scholar_publications_venue_map` and `scholar_publications_venue_abbr` filters
  for extending venue abbreviations to other disciplines.
