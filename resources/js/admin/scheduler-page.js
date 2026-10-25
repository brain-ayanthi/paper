import { posGet } from '../lib/http.js';

/**
 * Settings → Scheduler (Cron Job).
 *
 * Two jobs: copy the cron line to the clipboard, and run an HONEST test of
 * whether the customer's cron job is actually firing.
 *
 * The test is the whole point of the page, so it's worth being explicit about
 * what it does NOT do: it never asks the server to run the scheduler. That
 * would prove only that PHP works, and would show a reassuring green tick on an
 * install whose cron was never set up — which is exactly the failure we're here
 * to eliminate. Instead we snapshot the current heartbeat and poll until the
 * server's own crontab advances it. Passing therefore requires the customer's
 * cron to genuinely run, and no amount of clicking can fake it.
 *
 * Timeout is generous because plenty of budget hosts cap cron at once per 5
 * minutes; a fail here says "we didn't see it within N seconds", never "your
 * cron is definitely broken".
 */
export function schedulerPage({ statusUrl, lastRunAt = null, pollSeconds = 5, timeoutSeconds = 150 } = {}) {
    return {
        testing: false,
        // 'idle' | 'waiting' | 'passed' | 'timedout'
        result: 'idle',
        elapsed: 0,
        baseline: lastRunAt,
        latest: null,
        _timer: null,

        // Lifetime applied when re-minting the URL-cron token (SEC #101).
        // Days; 0 = never expires.
        expiresDays: '365',

        get remaining() {
            return Math.max(0, timeoutSeconds - this.elapsed);
        },

        async copy(text, label) {
            try {
                await navigator.clipboard.writeText(text);
                this.$store.toasts?.push({
                    type: 'success',
                    message: (label || 'Copied') + ' copied to clipboard.',
                    duration: 1800,
                });
            } catch (e) {
                // Clipboard API needs a secure context; plenty of customers run
                // their POS on plain http:// over the shop LAN. Fall back to the
                // old select-and-execCommand path so copy still works there.
                this._legacyCopy(text);
            }
        },

        _legacyCopy(text) {
            const el = document.createElement('textarea');
            el.value = text;
            el.setAttribute('readonly', '');
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            try {
                document.execCommand('copy');
                this.$store.toasts?.push({ type: 'success', message: 'Copied to clipboard.', duration: 1800 });
            } catch (e) {
                this.$store.toasts?.push({ type: 'error', message: 'Could not copy — select the text and copy manually.' });
            } finally {
                document.body.removeChild(el);
            }
        },

        startTest() {
            if (this.testing) return;

            this.testing = true;
            this.result = 'waiting';
            this.elapsed = 0;
            this.latest = null;

            // Re-snapshot at the moment the test starts rather than trusting the
            // value the page was rendered with — the page may have been open for
            // an hour, and a stale baseline would pass instantly.
            this._poll(true);

            this._timer = setInterval(() => {
                this.elapsed += pollSeconds;
                if (this.elapsed >= timeoutSeconds) {
                    this._stop();
                    this.result = 'timedout';
                    return;
                }
                this._poll(false);
            }, pollSeconds * 1000);
        },

        stopTest() {
            this._stop();
            this.result = 'idle';
        },

        _stop() {
            if (this._timer) clearInterval(this._timer);
            this._timer = null;
            this.testing = false;
        },

        async _poll(isBaseline) {
            try {
                const { data } = await posGet(statusUrl);

                if (isBaseline) {
                    this.baseline = data.last_run_at;
                    return;
                }

                this.latest = data;

                // Advanced (or appeared for the first time) → the cron fired.
                if (data.last_run_at && data.last_run_at !== this.baseline) {
                    this._stop();
                    this.result = 'passed';
                }
            } catch (e) {
                // A failed poll is not a failed test — the next tick retries,
                // and the overall timeout still bounds the wait.
            }
        },

        destroy() {
            this._stop();
        },
    };
}
