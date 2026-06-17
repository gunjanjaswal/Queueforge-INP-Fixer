/**
 * QueueForge INP Fixer — front-end runtime.
 *
 * 1. Holds scripts rewritten to type="queueforge/javascript" until the first
 *    real user interaction (or a fallback timeout), then restores them in
 *    order while yielding the main thread between each one.
 * 2. Optional live overlay reporting measured INP and long-task blocking time.
 */
(function () {
    'use strict';

    var cfg = window.QueueForgeINP || {};
    var INTERACTION_EVENTS = ['keydown', 'mousedown', 'mousemove', 'touchstart', 'touchmove', 'wheel', 'scroll'];
    var listenerOpts = { passive: true, capture: true };
    var started = false;

    /* --------------------------------------------------------------------
     * Main-thread yielding
     * ------------------------------------------------------------------ */

    function yieldToMain() {
        if (cfg.yield && window.scheduler && typeof window.scheduler.yield === 'function') {
            return window.scheduler.yield();
        }
        return new Promise(function (resolve) {
            setTimeout(resolve, 0);
        });
    }

    /* --------------------------------------------------------------------
     * Delayed-script loading
     * ------------------------------------------------------------------ */

    function restoreScript(oldScript) {
        return new Promise(function (resolve) {
            var newScript = document.createElement('script');

            for (var i = 0; i < oldScript.attributes.length; i++) {
                var attr = oldScript.attributes[i];
                if (attr.name === 'type') {
                    continue; // drop the dummy type so it executes
                }
                if (attr.name === 'data-queueforge-src') {
                    newScript.src = attr.value; // restore the real src
                    continue;
                }
                newScript.setAttribute(attr.name, attr.value);
            }

            // Inline script: copy body, swap node, done synchronously.
            if (!newScript.src) {
                newScript.text = oldScript.text || oldScript.textContent || '';
                oldScript.parentNode.replaceChild(newScript, oldScript);
                resolve();
                return;
            }

            // External script: wait for it before continuing to preserve order.
            newScript.addEventListener('load', resolve);
            newScript.addEventListener('error', resolve);
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function loadDelayedScripts() {
        if (started) {
            return;
        }
        started = true;

        INTERACTION_EVENTS.forEach(function (evt) {
            window.removeEventListener(evt, loadDelayedScripts, listenerOpts);
        });

        var nodes = document.querySelectorAll('script[type="queueforge/javascript"]');

        var chain = Promise.resolve();
        for (var i = 0; i < nodes.length; i++) {
            (function (node) {
                chain = chain
                    .then(function () { return restoreScript(node); })
                    .then(function () { return yieldToMain(); });
            })(nodes[i]);
        }

        chain.then(function () {
            // Let late-binding libraries hook in if they listen for these.
            try {
                document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
                window.dispatchEvent(new Event('load'));
            } catch (e) { /* older browsers */ }
            document.dispatchEvent(new Event('queueforge-scripts-loaded'));
        });
    }

    if (cfg.delay) {
        INTERACTION_EVENTS.forEach(function (evt) {
            window.addEventListener(evt, loadDelayedScripts, listenerOpts);
        });
        if (cfg.fallback && cfg.fallback > 0) {
            setTimeout(loadDelayedScripts, cfg.fallback * 1000);
        }
    }

    /* --------------------------------------------------------------------
     * Live INP + long-task overlay (admins only; gated server-side)
     * ------------------------------------------------------------------ */

    if (cfg.debug && 'PerformanceObserver' in window) {
        var maxInteraction = 0;
        var blockingTime = 0;
        var badge = null;

        function colorFor(inp) {
            if (inp <= 200) { return '#0a8754'; }   // good
            if (inp <= 500) { return '#b8860b'; }   // needs improvement
            return '#c0392b';                        // poor
        }

        function render() {
            if (!badge) {
                badge = document.createElement('div');
                badge.style.cssText = [
                    'position:fixed', 'z-index:2147483647', 'bottom:12px', 'right:12px',
                    'padding:6px 10px', 'border-radius:6px', 'font:12px/1.4 monospace',
                    'color:#fff', 'box-shadow:0 2px 8px rgba(0,0,0,.3)', 'pointer-events:none'
                ].join(';');
                (document.body || document.documentElement).appendChild(badge);
            }
            badge.style.background = colorFor(maxInteraction);
            badge.textContent = 'INP ~' + Math.round(maxInteraction) + 'ms · blocked ' + Math.round(blockingTime) + 'ms';
        }

        try {
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry.interactionId && entry.duration > maxInteraction) {
                        maxInteraction = entry.duration;
                        render();
                    }
                });
            }).observe({ type: 'event', durationThreshold: 16, buffered: true });
        } catch (e) { /* event timing unsupported */ }

        try {
            new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    // Portion of each long task beyond the 50ms threshold.
                    blockingTime += Math.max(0, entry.duration - 50);
                });
                render();
            }).observe({ type: 'longtask', buffered: true });
        } catch (e) { /* longtask unsupported */ }

        if (document.body) {
            render();
        } else {
            window.addEventListener('DOMContentLoaded', render);
        }
    }
})();
