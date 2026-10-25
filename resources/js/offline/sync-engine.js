/**
 * Sync engine — Slice 2.
 *
 * Drains the IndexedDB `sync_queue` of offline-completed writes when
 * the cashier comes back online. Idempotency is the server's job
 * (CompleteSale dedupes by `sales.local_uuid`); retries are safe by
 * construction.
 *
 * The engine runs ONE entry at a time per cycle — batched sync per
 * docs §18.2 is a Slice 2 polish. Each cycle:
 *
 *   1. Pull all `pending` + `failed` entries, oldest first.
 *   2. For each: mark `syncing`, POST to the right endpoint.
 *   3. On 2xx → `synced` with the server's response stashed for the
 *      sync log UI (Slice 4).
 *   4. On 422/5xx → `failed` with the error message + bumped
 *      `attempts`. Next online-event triggers a retry — up to
 *      MAX_AUTO_ATTEMPTS, after which the entry is parked as `stuck`
 *      and waits for a human (Retry / Discard in the queue panel).
 *   5. On 409 (future status) → `conflict` for manual resolution.
 *
 * Subscribers (the cashier-page's queue badge, future sync log)
 * register via `subscribeQueue(fn)` and get fresh depth on every
 * mutation.
 */

import { posPost } from '../lib/http.js';
import { subscribe as subscribeConnectivity } from './connectivity.js';
import {
    pendingQueue, markSyncing, markSynced, markFailed, markStuck,
    queueDepth, pruneSyncedQueue, resetStaleSyncing,
    requeueEntry, discardQueueEntry,
    recordCustomerRemap, readCustomerRemap,
} from './dexie-schema.js';

/**
 * How many times the engine re-sends an entry on its own before parking
 * it as `stuck`.
 *
 * The failures that matter here aren't network blips — they're server
 * REJECTIONS (insufficient stock, shift required, discount not allowed).
 * Those return the same answer every time, so an uncapped loop is just a
 * request every 60 seconds until the tab closes, with nobody told. Five
 * attempts comfortably outlasts a flapping connection; past that it's a
 * real problem and a human should see it.
 */
const MAX_AUTO_ATTEMPTS = 5;

const KIND_ENDPOINTS = {
    customer_create: '/cashier/customers',
    sale_complete:   '/cashier/complete',
    // Slice 4 — offline self-ordering kiosk orders. PlaceKioskOrder is
    // idempotent by local_uuid, so replays are safe. Shares the same
    // sync_queue (same-origin IndexedDB), so whichever page is open —
    // kiosk or cashier — drains it.
    kiosk_order:     '/kiosk/place',
    // Code-review #74 — a PAID kiosk checkout whose finalize POST kept
    // failing. Idempotent by session uuid server-side (a repeat returns
    // the same sale), so draining on reconnect rings it up unattended
    // instead of leaving a paid-but-unfulfilled order for staff.
    kiosk_checkout_complete: '/kiosk/checkout/complete',
};

// Drain order — customer_create MUST land server-side before any
// sale_complete that references its local negative id, so the
// id-remap is populated by the time the sale POSTs.
const KIND_PRIORITY = {
    customer_create: 0,
    sale_complete:   1,
    kiosk_order:     1,
    kiosk_checkout_complete: 1,
};

const _listeners = new Set();
let _isSyncing  = false;
let _retryTimer = null;

/* ── Public API ───────────────────────────────────────────────── */

/** Subscribe to queue-depth changes. Fires immediately with current. */
export function subscribeQueue(fn) {
    _listeners.add(fn);
    queueDepth().then(d => fn(d));
    return () => _listeners.delete(fn);
}

async function emit() {
    const depth = await queueDepth();
    for (const fn of _listeners) {
        try { fn(depth); } catch (_) { /* never let a listener kill the loop */ }
    }
}

/**
 * Manual "Retry now" from the cashier's queue panel — resets the attempt
 * counter so a parked (`stuck`) entry gets another full run of automatic
 * retries, then kicks a drain immediately.
 */
export async function retryQueueEntry(id) {
    await requeueEntry(id);
    await emit();
    await drainQueue();
}

/**
 * Manual "Discard" from the cashier's queue panel. Drops the entry for
 * good — only ever reached through a confirm dialog, because the sale
 * never posted server-side and nothing else holds a copy.
 */
export async function discardEntry(id) {
    await discardQueueEntry(id);
    await emit();
}

/** Force a drain pass — used after enqueueSale + on online events. */
export async function drainQueue() {
    if (_isSyncing) return;
    _isSyncing = true;

    try {
        const pending = await pendingQueue();
        if (pending.length === 0) return;

        // Sort by KIND_PRIORITY first, then created_at. Without this,
        // a sale_complete created right after a customer_create at
        // the same wall-clock millisecond could drain first and POST
        // with the un-remapped negative customer_id → 422.
        pending.sort((a, b) => {
            const pa = KIND_PRIORITY[a.kind] ?? 99;
            const pb = KIND_PRIORITY[b.kind] ?? 99;
            if (pa !== pb) return pa - pb;
            return String(a.created_at).localeCompare(String(b.created_at));
        });

        for (const entry of pending) {
            await processOne(entry);
        }
    } finally {
        _isSyncing = false;
        emit();
    }
}

/** Wire the sync engine to the connectivity machine. Call once on app boot. */
export function startSyncEngine() {
    // Recover entries orphaned as 'syncing' by a crash/close mid-POST
    // (code-review #55) BEFORE wiring the drain trigger — the
    // connectivity subscribe fires immediately when already online, and
    // the recovered rows must be 'pending' by then so that first drain
    // replays them. Server-side local_uuid dedupe makes replays safe.
    resetStaleSyncing().then(() => {
        emit();
        subscribeConnectivity((state) => {
            if (state.status === 'online') drainQueue();
        });
    });

    // Periodic retry tick — if the server flapped or returned 500 mid-
    // sync, the engine will pick failed entries up on its own every
    // 60 seconds. Cheap because the queue is usually empty.
    if (!_retryTimer) {
        _retryTimer = setInterval(() => {
            // Cheap check first: skip the work entirely if queue is empty.
            queueDepth().then(d => { if (d > 0) drainQueue(); });
        }, 60_000);
    }

    // Tidy: synced entries older than a week get pruned on boot so the
    // queue table doesn't grow unboundedly.
    pruneSyncedQueue(7);
}

/* ── Internals ────────────────────────────────────────────────── */

async function processOne(entry) {
    const url = KIND_ENDPOINTS[entry.kind];
    if (!url) {
        // Not retryable by definition — park it straight away rather than
        // burning five attempts on a kind no build of this app can POST.
        await markStuck(entry.id, `unknown kind: ${entry.kind}`, (entry.attempts ?? 0) + 1);
        return;
    }

    await markSyncing(entry.id);
    emit();

    try {
        // Slice 4: for sale_complete entries, rewrite any negative
        // customer_id from the local→server remap before POST.
        let payload = entry.payload;
        if (entry.kind === 'sale_complete' && payload?.customer_id != null && payload.customer_id < 0) {
            const serverId = await readCustomerRemap(payload.customer_id);
            if (serverId == null) {
                // Customer hasn't synced yet — bail and retry on next
                // drain. The drain order should prevent this, but a
                // failed earlier customer_create could leave this entry
                // stranded; mark failed so the periodic tick retries.
                await recordAttempt(entry, 'Waiting for customer to sync.');
                return;
            }
            payload = { ...payload, customer_id: serverId };
        }

        // `posPost` already includes CSRF, JSON content-type, and the
        // shared http interceptor's auth flow. The server's CompleteSale
        // action dedupes by `payload.local_uuid` so retries from any
        // prior partial failure produce the same canonical sale.
        const { data } = await posPost(url, payload);

        // Slice 4: on customer_create success, stash localId→serverId
        // so subsequent sale_complete entries get their customer_id
        // rewritten before POST.
        if (entry.kind === 'customer_create' && entry.local_id != null && data?.id) {
            await recordCustomerRemap(entry.local_id, data.id);
        }

        await markSynced(entry.id, data ?? null);
    } catch (e) {
        // 422 → validation/business error; mark failed so the next
        //       online cycle retries (unless it's truly permanent, in
        //       which case the cashier sees it in the queue panel).
        // 5xx → transient; same treatment.
        // Anything else (network drop mid-request) → same.
        const msg = e?.message
            || e?.errors?._action?.[0]
            || (e?.status ? `HTTP ${e.status}` : 'sync failed');
        await recordAttempt(entry, msg);
    }
}

/**
 * Book one failed attempt against an entry — and park it as `stuck` once
 * it has used up its automatic retries, so the queue panel can surface it
 * instead of the engine quietly re-POSTing forever.
 */
async function recordAttempt(entry, message) {
    const attempts = (entry.attempts ?? 0) + 1;

    return attempts >= MAX_AUTO_ATTEMPTS
        ? markStuck(entry.id, message, attempts)
        : markFailed(entry.id, message, attempts);
}
