import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { fetchWithSessionRetry } from '../fetchWithSessionRetry.js';

describe('fetchWithSessionRetry', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
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
});
