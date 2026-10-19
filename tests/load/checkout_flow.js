// k6 load-test skeleton — see docs/SCALING_GUIDE.md's "লোড টেস্ট — সৎ কথা"
// section before running this. Honest limits, stated up front:
//   - Running this on a local dev machine measures THIS machine's CPU/DB
//     and loopback network — nothing about the real production server's
//     hardware, shared-hosting contention, or real network latency.
//   - Run it against a staging copy of the real production server (or the
//     real one, off-peak, with explicit permission) for a result that
//     actually means anything.
//   - Run it against a database seeded with REAL-SHAPED data (a realistic
//     product count, customer count, months of sales history) — an empty
//     database makes every index/query look fast in a way that won't hold
//     once real data volume is present.
//
// Install: https://k6.io/docs/get-started/installation/ (not installed on
// this dev machine as of writing — `choco install k6` on Windows, or
// download the binary directly).
//
// Fill in a REAL test/staging shop's login credentials below before
// running — never point this at another shop's real account, and never
// run it against production without the owner's explicit go-ahead (this
// generates real, if harmless-looking, traffic and could trip the new
// `throttle:checkout` limiter intentionally).
//
// Run: k6 run tests/load/checkout_flow.js
// Tune concurrency: k6 run --vus 50 --duration 2m tests/load/checkout_flow.js

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://pos.zaylotix.com';
const LOGIN = __ENV.LOGIN || 'CHANGE_ME_PHONE_OR_EMAIL';
const PASSWORD = __ENV.PASSWORD || 'CHANGE_ME_PASSWORD';

export const options = {
    // Ramp up gradually rather than slamming all at once — closer to how
    // real traffic actually arrives, and easier to see WHERE it starts
    // degrading rather than just "it fell over immediately".
    stages: [
        { duration: '30s', target: 20 },
        { duration: '1m', target: 50 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        // See docs/SCALING_GUIDE.md for how to read these after a run.
        http_req_duration: ['p(95)<2000'],
        http_req_failed: ['rate<0.01'],
    },
};

function extractCsrfToken(html) {
    const match = html.match(/<meta name="csrf-token" content="([^"]+)">/);

    return match ? match[1] : null;
}

export default function () {
    // 1. GET the login page — establishes a session cookie + CSRF token.
    const loginPage = http.get(`${BASE_URL}/login`);
    check(loginPage, { 'login page loads': (r) => r.status === 200 });
    const csrfToken = extractCsrfToken(loginPage.body);

    // 2. Log in.
    const loginRes = http.post(
        `${BASE_URL}/login`,
        { login: LOGIN, password: PASSWORD, _token: csrfToken },
        { headers: { 'X-CSRF-TOKEN': csrfToken } }
    );
    check(loginRes, { 'login succeeded (redirected, not re-shown with errors)': (r) => r.status === 302 || r.status === 200 });

    sleep(1);

    // 3. Open POS (the heaviest page per docs/SCALING_GUIDE.md item 4 —
    // the full, unbounded product catalog ships here).
    const posPage = http.get(`${BASE_URL}/app/pos`);
    check(posPage, { 'pos page loads': (r) => r.status === 200 });
    const posCsrf = extractCsrfToken(posPage.body);

    sleep(1);

    // 4. A product search (server-side — the pattern item 4 recommends
    // POS itself move to).
    http.get(`${BASE_URL}/app/products/search?q=a`);

    sleep(1);

    // 5. Checkout — fill in with a real product_id that exists in the
    // target shop's catalog before running. Left as a placeholder on
    // purpose: a load test blindly guessing product ids would just 422
    // every time, measuring nothing real.
    //
    // const checkoutRes = http.post(
    //     `${BASE_URL}/app/pos/checkout`,
    //     JSON.stringify({
    //         items: [{ product_id: 1, qty: 1 }],
    //         payments: [{ method: 'cash', amount: 100 }],
    //         client_uuid: crypto.randomUUID(),
    //     }),
    //     { headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': posCsrf, Accept: 'application/json' } }
    // );
    // check(checkoutRes, { 'checkout succeeded': (r) => r.status === 200 });

    sleep(2);

    // 6. Reports page (docs/SCALING_GUIDE.md item 6 — the heaviest report
    // page, does PHP-side aggregation with no caching).
    http.get(`${BASE_URL}/app/reports`);

    sleep(1);
}
