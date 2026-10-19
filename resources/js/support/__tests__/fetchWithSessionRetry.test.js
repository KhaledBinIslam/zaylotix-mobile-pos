import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { fetchWithSessionRetry, refreshCsrfToken } from '../fetchWithSessionRetry.js';

// The test environment is plain 'node' (see vitest.config.js) — no real
// DOM — so the one DOM call these functions make (reading/writing the
// page's <meta name="csrf-token"> tag) needs a minimal stand-in rather
// than pulling in jsdom just for this.
function stubMetaTag() {
    const meta = { setAttribute: vi.fn() };
    global.document = { querySelector: vi.fn(() => meta) };
    return meta;
}

describe('fetchWithSessionRetry', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        global.route = (name) => '/app/' + name.split('.').pop();
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete global.route;
        delete global.document;
    });

    it('returns the first response directly when it is not a 401', async () => {
        const ok = new Response(null, { status: 200 });
        global.fetch = vi.fn().mockResolvedValue(ok);

        const res = await fetchWithSessionRetry('/x', { method: 'POST' });

        expect(res).toBe(ok);
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('does not retry a genuine rejection like a 422 validation error', async () => {
        const rejected = new Response(null, { status: 422 });
        global.fetch = vi.fn().mockResolvedValue(rejected);

        const res = await fetchWithSessionRetry('/x', { method: 'POST' });

        expect(res.status).toBe(422);
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('retries exactly once on a 401 — a transient session hiccup is safe to retry (401 always happens before any transaction starts)', async () => {
        const firstFail = new Response(null, { status: 401 });
        const secondOk = new Response(null, { status: 200 });
        global.fetch = vi.fn().mockResolvedValueOnce(firstFail).mockResolvedValueOnce(secondOk);

        const promise = fetchWithSessionRetry('/x', { method: 'POST' });
        await vi.runAllTimersAsync();
        const res = await promise;

        expect(res).toBe(secondOk);
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('gives up after a second 401 in a row — a real logout, not a blip', async () => {
        const fail = new Response(null, { status: 401 });
        global.fetch = vi.fn().mockResolvedValue(fail);

        const promise = fetchWithSessionRetry('/x', { method: 'POST' });
        await vi.runAllTimersAsync();
        const res = await promise;

        expect(res.status).toBe(401);
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('calls fetch with the exact same url and options both times, never mutating the caller\'s options', async () => {
        const options = { method: 'POST', headers: { 'X-Test': '1' }, body: '{"a":1}' };
        global.fetch = vi.fn()
            .mockResolvedValueOnce(new Response(null, { status: 401 }))
            .mockResolvedValueOnce(new Response(null, { status: 200 }));

        const promise = fetchWithSessionRetry('/y', options);
        await vi.runAllTimersAsync();
        await promise;

        expect(global.fetch).toHaveBeenNthCalledWith(1, '/y', options);
        expect(global.fetch).toHaveBeenNthCalledWith(2, '/y', options);
    });

    // ---------------- 419 (stale CSRF token on a long-open tab) — see docs/SESSION_FIX.md ----------------

    it('on a 419, fetches a fresh token and retries once with it in the X-CSRF-TOKEN header', async () => {
        const meta = stubMetaTag();
        const options = { method: 'POST', headers: { 'X-CSRF-TOKEN': 'stale-token' }, body: '{}' };
        global.fetch = vi.fn()
            .mockResolvedValueOnce(new Response(null, { status: 419 })) // the original request
            .mockResolvedValueOnce(new Response(JSON.stringify({ token: 'fresh-token' }), { status: 200 })) // the token refresh
            .mockResolvedValueOnce(new Response(null, { status: 200 })); // the retried original request

        const res = await fetchWithSessionRetry('/x', options);

        expect(res.status).toBe(200);
        expect(global.fetch).toHaveBeenCalledTimes(3);
        expect(global.fetch).toHaveBeenNthCalledWith(3, '/x', { ...options, headers: { 'X-CSRF-TOKEN': 'fresh-token' } });
        expect(meta.setAttribute).toHaveBeenCalledWith('content', 'fresh-token');
    });

    it('on a 419, leaves the response as-is when the caller never sent a CSRF header to patch', async () => {
        stubMetaTag();
        const options = { method: 'GET' };
        global.fetch = vi.fn()
            .mockResolvedValueOnce(new Response(null, { status: 419 }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ token: 'fresh-token' }), { status: 200 }));

        const res = await fetchWithSessionRetry('/x', options);

        expect(res.status).toBe(419);
        expect(global.fetch).toHaveBeenCalledTimes(2); // original + the token fetch, but no retry of /x
    });

    it('on a 419, leaves the response as-is when the token refresh itself fails', async () => {
        stubMetaTag();
        const options = { method: 'POST', headers: { 'X-CSRF-TOKEN': 'stale-token' } };
        global.fetch = vi.fn()
            .mockResolvedValueOnce(new Response(null, { status: 419 }))
            .mockResolvedValueOnce(new Response(null, { status: 500 })); // the token endpoint itself is down

        const res = await fetchWithSessionRetry('/x', options);

        expect(res.status).toBe(419);
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});

describe('refreshCsrfToken', () => {
    beforeEach(() => {
        global.route = (name) => '/app/' + name.split('.').pop();
    });
    afterEach(() => {
        vi.restoreAllMocks();
        delete global.route;
        delete global.document;
    });

    it('fetches the current token and writes it onto the meta tag', async () => {
        const meta = stubMetaTag();
        global.fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ token: 'abc123' }), { status: 200 }));

        const token = await refreshCsrfToken();

        expect(token).toBe('abc123');
        expect(meta.setAttribute).toHaveBeenCalledWith('content', 'abc123');
    });

    it('returns null without throwing when the request itself fails (offline)', async () => {
        stubMetaTag();
        global.fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

        await expect(refreshCsrfToken()).resolves.toBeNull();
    });

    it('returns null when the endpoint responds but not ok', async () => {
        stubMetaTag();
        global.fetch = vi.fn().mockResolvedValue(new Response(null, { status: 401 }));

        await expect(refreshCsrfToken()).resolves.toBeNull();
    });
});
