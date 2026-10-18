# Analytics

Admin > **Analytics** (System) is where the site chooses how its visits are counted. It needs the `analytics.manage` capability (administrators; a custom role can be given it). Changing the tracking code also needs the raw HTML permission, because code runs in the visitors' browsers.

The choice is stored under `analytics` in the site settings:

| Choice | What happens |
|---|---|
| **No tracking** (default) | Nothing is added to the pages. |
| **Your own tracking code** | A Google tag or Tag Manager ID (`G-…`, `GT-…`, `AW-…`, `GTM-…`) is made into its code for you, and two boxes take any other code (Plausible, Matomo, Meta Pixel…): one for the head, one for the start of the body. A person who is signed in gets none of it (unless the site chooses to count them). |
| **GnozioCMS analytics** | The site counts its visits itself, and the reports are in the admin. |

## How the platform's analytics count

A small script (`public/assets/js/faros-analytics.js`, about 2 KB, deferred) reports each page view to `/_a/collect` with `navigator.sendBeacon`, so it never slows a page. It also reports clicks on links that leave the site, downloads (pdf, doc, zip…), email and phone links, and `gnozio.event("name")` for events a page defines itself. Existing `faros.event("name")` calls remain supported. A form that is sent is counted on the server, as a goal.

Nothing is stored in the browser, and nothing identifies the person:

* **No cookies, no address.** A visitor is a code, the hash of the address, the browser and a secret that is made again every day and thrown away, so it cannot be traced back or matched with the next day. A visitor is therefore counted once a day.
* **Counted by class.** The kind of device (from the width of the window), the browser and the system (from the browser's name), the country (only when the host's network says it: `CF-IPCountry` and similar headers; there is no GeoIP database), the language, and where the visit came from (direct, search engines, social networks, other websites, paid, email, campaigns tagged with `utm_source`, `utm_medium`, `utm_campaign`).
* **Not counted:** robots that say what they are, signed-in people (an option), visitors whose browser says Do Not Track or Global Privacy Control (an option), pages the site lists as ignored, reports that come from another site, more than 400 reports of one visitor in a day, and anything that is not a page of the site (not the admin).
* **The email address and the phone number of a link are never kept**; an email link is counted by its domain, a phone link has no value.

This is built to work without a consent banner, but the rules differ between countries: check the ones that apply to you.

## What is kept

The system database (`storage/db/app.sqlite`) holds two tables: `analytics_hits` has one row for each view or event of the last two days (no address, no cookie), and `analytics_daily` has the counts of every finished day: the total, and for each page, entry page, channel, source, campaign, device, browser, system, country, language and goal, its views and visitors. The first request after midnight sums the day that ended, deletes the rows of the day before it, and deletes days older than the time the site chose to keep reports (1 year, 2 years, 5 years or forever; 2 by default). A site therefore stays small: a few hundred rows a day however many visits there are. The data is part of the backups and counts in the storage limit. *Delete everything counted* empties both tables.

## The reports

* **Period:** today, yesterday, 7, 30 or 90 days, 12 months, this or last month, or two dates.
* **Totals** with the change from the period before: visitors, page views, pages per visit, bounce rate (visits that ended after one page), visit time (of the visits with more than one page).
* **Chart** of visitors and page views: an hour at a time for one day (today or yesterday), a day at a time up to three months, a month at a time after that. Hover a point for its numbers; the same numbers are in a table for screen readers.
* **Lists** of the top pages and entry pages, channels, sources and campaigns, devices, browsers and systems, countries and languages, and goals (outbound links, downloads, email and phone links, forms, events). Each list can be exported as CSV.
* **On the site now:** the visitors with a view in the last five minutes and the pages they are on, refreshed every 30 seconds.

Because a visitor is a different code every day, the visitors of a long period are the sum of its days: a person who comes on two days is two visitors. That is the price of not following people.

## Limits

No funnels, audiences, attribution models or user journeys, and no way to follow one person across days: that is the point of counting without cookies. A site that needs them uses its own tracking code. Very busy sites (hundreds of page views a minute) should use an outside service too: every page view is one write to SQLite.
