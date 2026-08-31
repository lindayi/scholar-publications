# Scholar Publications

An interactive, filterable publication list for WordPress, sourced from Google
Scholar.

Built for academic and researcher sites that want their publication list to stay
current without maintaining it by hand. Google Scholar is the single source of
truth: citation counts, h-index, i10-index and the citations-per-year graph all
come straight from your profile.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Features

- **Live metrics** — total citations, h-index, i10-index and a citations-per-year
  chart with Google-Scholar-style value gridlines, taken directly from Scholar.
- **Search** across title, authors, venue and year.
- **Multi-select type filters** — journal, conference, preprint, patent, thesis.
- **Venue filter with abbreviations** — full venue names are shortened to the
  forms researchers use (EMSE, TOSEM, FSE, ICSE, KDD…), and all preprints
  collapse into a single `arXiv` entry rather than one bucket per paper.
- **Sorting** by newest, oldest, most cited, or title.
- **Sticky year headings** so the year in view is always visible while scrolling.
- **Expandable details** with the abstract, publisher, and that paper's own
  citation history.
- **One-click BibTeX** copy per publication.
- **Sticky side rail layout** that keeps filters reachable, folding above the
  list on narrow screens.
- **Server rendered**, so the list is indexable and fully readable with
  JavaScript disabled. No external JavaScript libraries, no CDN, no tracking.

## Why an API key is required

Google Scholar has no public API, and it blocks requests from server IP
addresses — a direct request from a web host returns Google's anti-bot page
(HTTP 403). Client-side fetching is not possible either: Scholar sends no CORS
header, so a browser will not let JavaScript on your site read the response, and
`X-Frame-Options: DENY` rules out an iframe.

This plugin therefore reads your profile through the
[SerpAPI Google Scholar Author API](https://serpapi.com/google-scholar-author-api),
which performs the lookup and returns the same Scholar records as structured
JSON. Scholar remains the only source of data; no other bibliographic database
is consulted.

SerpAPI's free tier allows 250 searches per month, and a daily refresh uses
about 30. See [API usage](#api-usage) for the details.

## Requirements

- WordPress 6.1 or newer
- PHP 7.4 or newer
- A [SerpAPI](https://serpapi.com/) API key (the free tier is sufficient)
- Your Google Scholar profile ID

## Installation

1. Download the latest release, or clone this repository into your plugins
   directory:

   ```bash
   git clone https://github.com/lindayi/scholar-publications.git \
     wp-content/plugins/scholar-publications
   ```

2. Activate **Scholar Publications** in **Plugins**.
3. Go to **Settings → Scholar Publications** and enter your Google Scholar ID and
   SerpAPI key.
4. Press **Sync with Google Scholar**.
5. Add the shortcode to a page:

   ```
   [scholar_publications]
   ```

Your Scholar ID is the `user` parameter in your profile URL:
`https://scholar.google.com/citations?user=THIS_PART&hl=en`

The first sync fetches one record per publication, so it may take a few passes;
press **Fetch pending article details** until nothing is pending. Every sync
after that costs a single request.

## Shortcode

```
[scholar_publications]
```

| Attribute  | Default | Description                                                                                     |
| ---------- | ------- | ----------------------------------------------------------------------------------------------- |
| `limit`    | `0`     | Show only the newest N publications                                                             |
| `type`     | —       | Restrict to `journal`, `conference`, `preprint`, `patent`, `thesis`, `other` (comma separated)   |
| `layout`   | setting | `sidebar` or `stacked`                                                                          |
| `stats`    | setting | `0` hides the metrics                                                                           |
| `chart`    | setting | `0` hides the citations-per-year chart                                                          |
| `controls` | `1`     | `0` hides search, filters and sorting                                                           |
| `group`    | setting | `0` disables year headings                                                                      |
| `sort`     | setting | `year`, `oldest`, `citations` or `title`                                                        |

Examples:

```
[scholar_publications type="journal,conference" sort="citations"]
[scholar_publications limit="5" controls="0" chart="0" stats="0"]
```

## API usage

A routine refresh costs **one** SerpAPI search. The profile call returns up to
100 publications at once, and per-article details are cached by citation ID and
never re-fetched unless you ask for it.

| Situation                     | Searches spent                   |
| ----------------------------- | -------------------------------- |
| Nothing changed               | 1                                |
| Citation counts changed       | 1                                |
| N new publications appeared   | 1 + N (N is capped per sync)     |
| A publication was deleted     | 1                                |
| First ever run                | 1 + one per publication          |

Citation totals, h-index, i10-index and the year graph all arrive with the
profile call, so **they refresh on every sync at no extra cost**. Only the
fields that live on a publication's own Scholar page — abstract, full author
list, venue, pages — need a per-article request.

Three independent limits protect the quota:

- **Reserve searches** (default 40) — per-article fetching pauses once the plan
  drops to this many remaining searches, checked against SerpAPI's own
  `total_searches_left`. Profile syncs are never blocked, so the page keeps
  updating.
- **Detail requests per sync** (default 10) — a burst of new publications is
  spread over several syncs instead of draining the quota at once.
- **Time budget** — a cron run cannot stall a request.

A failed sync never clears the cache. The previous snapshot stays published and
the error is shown on the settings screen.

## Handling profile changes

- **Added** — a new citation ID has no cached detail, so it is queued and
  fetched, subject to the per-sync cap.
- **Deleted or merged** — the profile response is authoritative, so the list is
  replaced on every sync and removed publications disappear immediately. Their
  orphaned cache entries are pruned so the cache cannot grow without bound.
- **Edited on Scholar** — details are fetched once and kept, because every
  re-check costs a search. Set **Re-check details after** to a number of days if
  you want edits picked up; entries then refresh oldest first.

## Theming and dark mode

The block inherits the page's colour scheme rather than declaring its own. Where
the active theme exposes the standard block-theme palette, the accent colour and
the opaque surface behind sticky year headings are taken from
`--wp--preset--color--primary` and `--wp--preset--color--background`, so the list
matches the theme automatically.

Type badges resolve through the CSS `light-dark()` function, which follows the
`color-scheme` in effect. If your theme or a dark mode plugin sets
`color-scheme: dark`, the badges switch to lifted hues that stay legible against
a dark background; otherwise they render light. Browsers without `light-dark()`
fall back to the light values.

Everything else derives from `currentColor` through `color-mix()`, so no
configuration is needed either way.

## Extending

Venue abbreviations ship with computer science venues. Add your own field
without forking:

```php
add_filter( 'scholar_publications_venue_map', function ( $map ) {
    $map['/journal of the american chemical society/i'] = 'JACS';
    $map['/physical review letters/i']                  = 'PRL';
    return $map;
} );
```

Or override a single result outright:

```php
add_filter( 'scholar_publications_venue_abbr', function ( $abbr, $venue ) {
    return str_contains( $venue, 'Nature Communications' ) ? 'Nat. Commun.' : $abbr;
}, 10, 2 );
```

Abbreviations are resolved when the page renders, so changes take effect
immediately and cost no API quota.

## How it is built

```
scholar-publications.php     Bootstrap, settings helpers, activation hooks
includes/class-serpapi.php   Scholar client, payload normalisation, quota tracking
includes/class-store.php     Snapshot storage, filtering, statistics, venue abbreviations
includes/class-sync.php      Cron scheduling and refresh orchestration
includes/class-admin.php     Settings screen and manual actions
includes/class-shortcode.php Markup and BibTeX generation
assets/css/app.css           Front end styles
assets/js/app.js             Search, filtering, sorting, panels, clipboard
```

Data is cached in the `schpub_data` option and rendered from cache, so visitors
never wait on an external request. WP-Cron refreshes on the configured interval.

Your SerpAPI key is stored in the database, never written to a file, and is
never exposed to the front end — all API calls are made server side.

## Known Google Scholar limitations

These are properties of Scholar itself, not of the plugin:

- Very long titles are truncated with an ellipsis, on both the profile listing
  and the publication's own page, so the full text is not available at all.
- Proceedings front matter (for example "Message from the Organizers") is
  indexed as a publication. Hide such entries with the **Hide titles containing**
  setting.
- Abstracts are Scholar's own summaries and may end mid-sentence.
- Author name matching is best effort, since Scholar abbreviates given names.

## Privacy

The plugin talks to SerpAPI only, from your server, and only to read your own
public Scholar profile. Nothing is sent about your visitors, and no third-party
scripts are loaded in their browsers.

## Contributing

Issues and pull requests are welcome. Please keep the existing code style:
WordPress coding standards, all output escaped, and no external front end
dependencies.

## License

[MIT](LICENSE) © Dayi Lin

MIT is GPL-compatible, so the plugin may be distributed through the
wordpress.org plugin directory.

This project is not affiliated with, endorsed by, or sponsored by Google.
Google Scholar is a trademark of Google LLC. SerpAPI is a trademark of SerpApi,
LLC.
