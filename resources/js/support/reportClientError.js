// A "কিছু একটা ভুল হয়েছে" toast used to be a dead end — the only trace of
// what actually broke lived in a cashier's own devtools, which nobody ever
// opens, so a report like that had nothing to investigate. This sends the
// real error to app.clientErrorLog.store, which writes it into the normal
// Laravel log (storage/logs/laravel.log) instead, tagged with which shop/
// user/page hit it. Fire-and-forget on purpose: if the log call itself
// fails (offline, etc.) that's just one fewer log entry, never a second
// error shown on top of the first.
export function reportClientError(error, context) {
    console.error(`[${context}]`, error);
    try {
        const url = typeof route === 'function' ? route('app.clientErrorLog.store') : '/app/client-error-log';
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                message: error?.message || String(error),
                stack: error?.stack || null,
                url: window.location.href,
                context,
            }),
        }).catch(() => {});
    } catch (e) {
        // never let logging itself throw
    }
}
