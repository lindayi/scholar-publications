# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
