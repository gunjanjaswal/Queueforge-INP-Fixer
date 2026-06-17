=== QueueForge – Interaction to Next Paint Fixer ===
Contributors: gunjanjaswal
Donate link: https://ko-fi.com/gunjanjaswal
Tags: performance, web-vitals, inp, javascript, core-web-vitals
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lowers Interaction to Next Paint (INP) by delaying heavy JavaScript until the first user interaction and yielding the main thread between scripts.

== Description ==

Google's Core Web Vitals now use **Interaction to Next Paint (INP)** instead of First Input Delay. Caching plugins make pages *load* fast, but they do nothing about the bloated JavaScript from other plugins, themes, ads, and analytics that blocks the browser's main thread and makes mobile interactions feel choppy.

QueueForge INP Fixer attacks INP directly:

* **Delays eligible JavaScript** until the visitor's first scroll, tap, key press, or mouse move. The main thread stays free during the critical early window, so the page responds immediately.
* **Yields the main thread between scripts** when the deferred code finally runs, using the native `scheduler.yield()` (with a `setTimeout` fallback) so the queued scripts do not re-block the thread in one long task.
* **Fallback timeout** loads delayed scripts automatically after N seconds even with no interaction, protecting analytics and ad impressions.
* **Live INP overlay** for admins shows measured INP and long-task blocking time right on the front end, using `PerformanceObserver` (`event` + `longtask`).

= Key Features =

* Delay all eligible third-party / theme JavaScript until first interaction
* Main-thread yielding between deferred scripts (`scheduler.yield()` + fallback)
* Configurable fallback timeout (0 = interaction only)
* Optional jQuery delay (off by default for safety)
* Keyword exclusion list, plus per-tag `data-no-optimize` opt-out
* Skips logged-in editors so page builders keep working
* Live INP + blocking-time debug overlay (admins only)
* `?queueforge_off` URL switch to bypass the delay for one page load
* No database writes on the front end; nothing is cached or stored per visitor

= Why INP? =

INP measures how quickly the page visually responds to *every* interaction across the whole visit, not just the first one. The single biggest cause of poor INP is JavaScript executing long tasks on the main thread while the user is trying to interact. Delaying that JavaScript until it is actually needed — and breaking its execution into yield-separated chunks — is the most direct fix.

= Developer-Friendly =

* `qfinp_exclusions` filter to programmatically add never-delay keywords.
* Uses an output buffer on `template_redirect`; no edits to your theme or other plugins.

== Installation ==

1. Upload the `queueforge-inp-fixer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit **Settings → QueueForge INP** to tune the delay, exclusions, and debug overlay. Defaults work out of the box.

== Frequently Asked Questions ==

= Will this break my site? =

Delaying JavaScript can affect scripts that expect to run before interaction (consent banners, some sliders, reCAPTCHA). Add a keyword for those scripts to the exclusion list, or add `data-no-optimize` to the tag. Logged-in editors are skipped by default so you can preview safely.

= Does it delay jQuery? =

No, unless you enable "Delay jQuery". Many themes assume jQuery is present at load, so it is opt-in.

= How do I test the effect? =

Enable the **Live INP overlay** in settings and browse the front end while logged in as an admin. Compare against a page loaded with `?queueforge_off` appended to the URL.

= Does it work with caching plugins? =

Yes. It rewrites the HTML as it is generated; the result can be cached normally. It complements page caching rather than replacing it.

== Changelog ==

= 1.0.0 =
* Initial release: interaction-delayed JavaScript, main-thread yielding, fallback timeout, exclusions, logged-in skip, and live INP/long-task overlay.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
