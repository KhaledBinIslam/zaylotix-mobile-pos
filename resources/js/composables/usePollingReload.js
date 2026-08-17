import { router } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';

// A real, sustained problem needs several failures in a row before it's
// worth interrupting anyone — one slow/dropped request on a busy shared
// host isn't actually a problem.
const FAILURES_BEFORE_WARNING = 3;

/**
 * Wraps router.reload() for this app's many "quietly refresh live data
 * every few seconds" screens (Order/Pos/Stock/Tables/Kds/Cds polling for
 * another device's changes) with two safety properties plain router.reload()
 * didn't have:
 *
 * 1. Marked as a background poll (X-Inertia-Poll header) so the server
 *    (EnsureShopUser/CheckSubscriptionActive) answers with a plain JSON 401
 *    instead of redirect()->route('login') when a session check happens to
 *    momentarily not go through. Root-caused live: Inertia's router
 *    auto-follows a real redirect response, which was silently dragging a
 *    cashier — mid-sale, whatever they'd already rung up still on screen —
 *    to the login page over something as transient as one slow session
 *    read under this host's chronically high load (never an actual elapsed
 *    timeout; it could hit at any point, which matches what was reported
 *    far better than a real session expiring would). A JSON error, by
 *    contrast, lands in onError below, which this wraps to never navigate
 *    anywhere on its own.
 *
 * 2. A single failed poll stays invisible. Only several in a row (a real,
 *    sustained problem) surface a small non-blocking toast — never a forced
 *    redirect, never losing whatever's already on screen. Whatever the
 *    cashier was doing keeps working; the next successful poll (this
 *    device's own, or the normal 8-15s cadence) quietly catches back up.
 */
export function usePollingReload() {
    const { toast } = useToast();
    const { t } = useI18n();
    let consecutiveFailures = 0;

    function pollReload(options = {}) {
        const { onSuccess, onError, headers, ...rest } = options;

        router.reload({
            ...rest,
            headers: { ...headers, 'X-Inertia-Poll': 'true' },
            onSuccess: (...args) => {
                consecutiveFailures = 0;
                onSuccess?.(...args);
            },
            onError: (...args) => {
                consecutiveFailures++;
                if (consecutiveFailures === FAILURES_BEFORE_WARNING) {
                    toast('⚠️ ' + t('common.connectionUnstable'));
                }
                onError?.(...args);
            },
        });
    }

    return { pollReload };
}
