<div align="center">

# ⚡ QueueForge – Interaction to Next Paint Fixer

### Pass Core Web Vitals by fixing the metric caching plugins ignore: **Interaction to Next Paint (INP)**

[![Version](https://img.shields.io/badge/version-1.0.0-2ea44f?style=for-the-badge)](https://github.com/gunjanjaswal/Queueforge-INP-Fixer)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPLv2-blue?style=for-the-badge)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white)](https://ko-fi.com/gunjanjaswal)

**No build step · No database writes · Works alongside your cache plugin**

</div>

---

## 🎯 The Problem

Google's Core Web Vitals now score **INP — Interaction to Next Paint** instead of First Input Delay. INP measures how fast your page *visually responds* to **every** tap, click, and keypress during the whole visit.

> 🟢 **Good:** ≤ 200 ms  🟡 **Needs work:** ≤ 500 ms  🔴 **Poor:** > 500 ms

Caching plugins make pages **load** fast — but they do **nothing** about the bloated JavaScript from other plugins, themes, ad networks, and analytics that hogs the browser's **main thread**. The result: pages that *look* loaded but feel frozen and janky when a user actually touches them on mobile.

## 💡 The Fix

QueueForge attacks the root cause of high INP in two moves:

| # | Technique | What it does |
|:-:|-----------|--------------|
| 1️⃣ | **Delay JS until interaction** | Eligible `<script>` tags are held until the visitor's first scroll / tap / key / mouse-move. The main thread stays free during the critical early window, so the page responds instantly. |
| 2️⃣ | **Yield the main thread** | When the deferred scripts finally run, they execute one-by-one with `scheduler.yield()` between each, so they never re-block the thread in one giant long task. |

```text
  ┌─ Before ──────────────────────────────────────────────┐
  │ load ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓ (one long task) 👆 tap → 😴 480ms │
  └────────────────────────────────────────────────────────┘
  ┌─ With QueueForge ──────────────────────────────────────┐
  │ load ░░  👆 tap → ⚡ 90ms   …then ▓ yield ▓ yield ▓     │
  └────────────────────────────────────────────────────────┘
```

---

## ✨ Features

- ⏳ **Interaction-delayed JavaScript** — first scroll, tap, key, wheel, or mouse-move releases the queue
- 🧵 **Main-thread yielding** — native `scheduler.yield()` with a `setTimeout` fallback for older browsers
- ⏱️ **Fallback timeout** — auto-loads after *N* seconds (default `8`) so analytics & ads still fire; `0` = interaction-only
- 🪶 **jQuery stays safe** — never delayed unless you explicitly opt in
- 🚫 **Exclusion list** — keyword-match any script to leave it alone, or add `data-no-optimize` to a single tag
- 👤 **Skips logged-in editors** — page builders keep working while you edit
- 📊 **Live INP overlay** — admins see real measured INP + long-task blocking time, color-coded, right on the page
- 🔌 **`?queueforge_off`** — append to any URL to bypass the delay for one page load
- 🧩 **Caching-friendly** — rewrites HTML as it's generated, so the output caches normally

---

## 🚀 Installation

```bash
# Manual
1. Download / clone into wp-content/plugins/queueforge-inp-fixer
2. Activate via Plugins → Installed Plugins
3. Settings → QueueForge INP  (defaults work out of the box)
```

Then enable the **Live INP overlay**, browse your front end as an admin, and compare a normal load against the same URL with `?queueforge_off`.

---

## ⚙️ Settings

| Setting | Default | Description |
|---------|:-------:|-------------|
| Delay until interaction | ✅ | Hold eligible scripts until first input |
| Yield between scripts | ✅ | `scheduler.yield()` between each deferred script |
| Fallback timeout (s) | `8` | Auto-load after N seconds; `0` = interaction only |
| Delay jQuery | ❌ | Opt-in — only if your theme runs without jQuery at load |
| Never delay (keywords) | — | One keyword per line (e.g. `recaptcha`, `consent`) |
| Skip logged-in editors | ✅ | Don't delay for users who can edit posts |
| Live INP overlay | ❌ | Floating INP / blocking-time badge (admins only) |

---

## 🛠️ For Developers

```php
// Add never-delay keywords programmatically
add_filter( 'qfinp_exclusions', function ( $list ) {
    $list[] = 'my-critical-widget';
    return $list;
} );
```

```js
// Run code after the deferred scripts have all loaded
document.addEventListener( 'queueforge-scripts-loaded', function () {
    // safe to use libraries that were delayed
} );
```

**How it works:** an output buffer on `template_redirect` rewrites eligible scripts to `type="queueforge/javascript"` and moves `src` → `data-queueforge-src` (the browser won't fetch or execute them). A tiny runtime restores them in order on the first interaction. No theme or plugin files are touched.

---

## 💎 Want more? → Pro

The free plugin delays **all** eligible scripts on first interaction. **QueueForge INP Fixer PRO** adds:

- 📱 Separate **mobile / desktop** fallback timeouts
- 👁️ **Load-on-visible** triggers (IntersectionObserver) for comments, maps, embeds
- 🛒 **WooCommerce-safe mode** (never delay cart / checkout / account)
- 🔗 **Per-URL rules** to disable on specific paths
- 🎫 Licensing & auto-updates via Freemius

[![Personal](https://img.shields.io/badge/Personal%201%20site-%2439%2Fyr-2ea44f?style=for-the-badge)](https://checkout.freemius.com/plugin/30738/plan/50449/)
[![Professional](https://img.shields.io/badge/Professional%205%20sites-%2479%2Fyr-6c5ce7?style=for-the-badge)](https://checkout.freemius.com/plugin/30738/plan/50450/)
[![Agency](https://img.shields.io/badge/Agency%2025%20sites-%24149%2Fyr-e67e22?style=for-the-badge)](https://checkout.freemius.com/plugin/30738/plan/50451/)

---

## ❤️ Support

If this helped you turn your INP green, consider supporting development:

[![Ko-fi](https://img.shields.io/badge/Buy%20me%20a%20coffee-Ko--fi-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white)](https://ko-fi.com/gunjanjaswal)

**Author:** [Gunjan Jaswal](https://www.gunjanjaswal.me) · ✉️ hello@gunjanjaswal.me · 📜 GPLv2 or later
