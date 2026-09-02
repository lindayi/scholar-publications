=== Scholar Publications ===
Contributors: lindayi
Tags: publications, google scholar, academic, citations, bibliography
Requires at least: 6.1
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

An interactive, filterable publication list for WordPress, sourced from Google Scholar.

== Description ==

Built for academic and researcher sites that want their publication list to stay current without maintaining it by hand. Google Scholar is the single source of truth: citation counts, h-index, i10-index and the citations-per-year graph all come straight from your profile.

**Features**

* Live metrics: total citations, h-index, i10-index, and a citations-per-year chart with value gridlines
* Search across title, authors, venue and year
* Multi-select type filters: journal, conference, preprint, patent, thesis
* Venue filter using the abbreviations researchers actually use (EMSE, TOSEM, FSE, ICSE, KDD), with all preprints collapsed into a single arXiv entry
* Sort by newest, oldest, most cited, or title
* Sticky year headings so the year in view is always visible while scrolling
* Expandable details with the abstract, publisher, and that publication's own citation history
* One-click BibTeX copy
* Optional ORCID iD shown beside the Scholar profile link, following ORCID's display guidelines, with both identifier links icon-aligned
* Sticky side rail layout that folds above the list on narrow screens
* Server rendered, so the list is indexable and readable with JavaScript disabled
* No external JavaScript libraries, no CDN, no tracking

**Why an API key is required**

Google Scholar has no public API and blocks requests from server IP addresses, returning an anti-bot page. Fetching from the visitor's browser is not possible either, because Scholar sends no CORS header and forbids being framed.

This plugin therefore reads your profile through the SerpAPI Google Scholar Author API, which performs the lookup and returns the same Scholar records as structured JSON. Scholar remains the only source of data. SerpAPI's free tier allows 250 searches per month and a daily refresh uses about 30.

**Quota safety**

A routine refresh costs one search. Per-publication details are cached by citation ID and are not re-fetched. Three limits protect the quota: a reserve of remaining searches, a cap on detail requests per sync, and a time budget. A failed sync never clears the cache, so the published page keeps working.

**Disclaimer**

This plugin is not affiliated with, endorsed by, or sponsored by Google, or by ORCID. Google Scholar is a trademark of Google LLC. SerpAPI is a trademark of SerpApi, LLC. ORCID and the ORCID iD mark are trademarks of ORCID, Inc.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/scholar-publications`, or install it through the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Go to Settings > Scholar Publications and enter your Google Scholar ID and SerpAPI key.
4. Press "Sync with Google Scholar".
5. Add `[scholar_publications]` to any page.

Your Scholar ID is the `user` parameter in your profile URL: `https://scholar.google.com/citations?user=THIS_PART&hl=en`

== Frequently Asked Questions ==

= Do I need a paid SerpAPI plan? =

No. The free tier allows 250 searches per month. A daily refresh uses about 30, because one request returns your whole profile. Only the first run is expensive, as it fetches one record per publication to collect abstracts and full author lists.

= Why can't the plugin read Google Scholar directly? =

Scholar has no public API and blocks datacenter IP addresses, so a request from your web host is refused. It also sends no CORS header and cannot be framed, so the visitor's browser cannot read it either. A service such as SerpAPI is the only reliable route.

= What happens if a publication is removed from my profile? =

The profile response is authoritative. The list is replaced on each sync, so the publication disappears immediately and its cached record is pruned.

= Will my citation counts stay current? =

Yes. Citation counts, h-index, i10-index and the year graph all arrive with the single profile request, so they refresh on every sync at no extra cost.

= Can I use it for a field other than computer science? =

Yes. Venue abbreviations are extensible with the `scholar_publications_venue_map` filter, documented in the readme on GitHub.

= Is my API key exposed to visitors? =

No. The key is stored in the database, never written to a file, and all API calls are made server side.

== Screenshots ==

1. The publication list with the sticky filter rail.
2. An expanded publication showing the abstract and its citation history.
3. The settings screen with sync status and quota.

== Changelog ==

= 1.1.1 =
* The Google Scholar profile link now carries a mortarboard icon so it aligns with the ORCID line.

= 1.1.0 =
* Added an optional ORCID iD setting, shown beside the Google Scholar profile link.

= 1.0.1 =
* The block now follows the surrounding page's colour scheme instead of forcing light.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds an optional ORCID iD field. Existing settings are unaffected.

= 1.0.0 =
Initial release.
