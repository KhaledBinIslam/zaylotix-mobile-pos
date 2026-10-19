import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import {
    buildQueueTargets,
    currentTarget,
    initQueueState,
    isQueueDone,
    markCurrent,
    renderTemplate,
    sameTargetSet,
    sentCount,
    shouldShowPauseReminder,
    skippedCount,
    stateFromItems,
    undoMarked,
    waLink,
} from '@/support/whatsappQueue';

/**
 * The "পরবর্তী জনকে পাঠান" (send to next) queue — shared by
 * CustomerList/Index.vue's bulk send and Customers/Index.vue's due-ledger
 * "অফার পাঠান", which both had this exact logic copy-pasted before this
 * refactor. See docs/WHATSAPP_BULK_PLAN.md for the root cause this exists
 * to fix (a window.open() not triggered by a direct tap gets silently
 * popup-blocked) and for why marking "sent" waits for the tab to become
 * visible again instead of happening the instant the button is tapped:
 * tapping wa.me on a phone typically hands off to the WhatsApp app
 * entirely — `visibilitychange` firing when the cashier returns is the
 * actual signal they were there to press Send, not the tap itself, which
 * only ever opened the chat. Explicitly reversible (`undoLastConfirm`) in
 * case that assumption is wrong for a given tap (switched away for an
 * unrelated reason, decided not to send after all, etc.).
 *
 * `scopeKey` namespaces the localStorage progress key (e.g. 'cl' vs
 * 'due') so the two pages' batches never collide if both happen to be
 * mid-send on the same device/user at once.
 */
export function useWhatsappSendQueue(scopeKey) {
    const page = usePage();
    const { toast } = useToast();
    const { t } = useI18n();

    const sheet = ref(false);
    const state = ref(null);
    const skippedInvalid = ref([]);
    const awaitingConfirmId = ref(null);
    const lastConfirmedId = ref(null);
    let template = '';

    const storageKey = computed(() => `zaylotix_wa_queue_${scopeKey}_${page.props.shop?.id ?? 0}_${page.props.auth?.user?.id ?? 0}`);

    function loadSaved() {
        try {
            const raw = localStorage.getItem(storageKey.value);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function save() {
        try {
            if (!state.value) {
                localStorage.removeItem(storageKey.value);
                return;
            }
            localStorage.setItem(storageKey.value, JSON.stringify({ items: state.value.items, template }));
        } catch {
            // resuming is a convenience, not a correctness requirement
        }
    }

    /** customers: the full selected rows (with .phone/.no_marketing/.due). tpl: the editable {name}/{due}/{shop} message template. */
    function start(customers, tpl) {
        template = tpl;
        const built = buildQueueTargets(customers);
        if (!built.targets.length) return false;
        skippedInvalid.value = built.skippedInvalid;
        awaitingConfirmId.value = null;
        lastConfirmedId.value = null;

        const saved = loadSaved();
        if (saved && sameTargetSet(saved.items, built.targets) && saved.items.some((i) => i.status === 'pending')) {
            state.value = stateFromItems(saved.items);
            if (saved.template) template = saved.template;
            toast('↩️ ' + t('customerList.resumedQueue'));
        } else {
            state.value = initQueueState(built.targets);
        }
        save();
        sheet.value = true;
        return true;
    }

    const current = computed(() => (state.value ? currentTarget(state.value) : null));
    const sent = computed(() => (state.value ? sentCount(state.value) : 0));
    const skipped = computed(() => (state.value ? skippedCount(state.value) : 0));
    const total = computed(() => (state.value ? state.value.items.length : 0));
    const done = computed(() => (state.value ? isQueueDone(state.value) : false));
    const showPauseReminder = computed(() => state.value && shouldShowPauseReminder(sent.value));

    /** The one and only window.open() — called directly from the button's own click, so it always carries a real user gesture. Doesn't mark anything sent yet; see confirmOnReturn(). */
    function sendToCurrent() {
        const target = current.value;
        if (!target) return;
        const text = renderTemplate(template, target, page.props.shop?.name);
        window.open(waLink(target.phone, text), '_blank');
        awaitingConfirmId.value = target.id;
        lastConfirmedId.value = null; // undo only ever applies to the immediately-previous confirm
    }

    /**
     * Marks the awaited target as sent — called automatically on
     * visibilitychange (the phone case: wa.me handed off to the WhatsApp
     * app entirely, so returning here means the tab became visible again),
     * AND exposed as a manual "✓ পাঠানো হয়েছে" button for the desktop case,
     * where window.open() opens a NEW tab and this one never actually
     * leaves the visible state, so the automatic path would never fire.
     */
    function confirmSent() {
        if (!awaitingConfirmId.value || !state.value) return;
        const target = current.value;
        if (!target || target.id !== awaitingConfirmId.value) {
            awaitingConfirmId.value = null;
            return;
        }
        state.value = markCurrent(state.value, 'sent');
        lastConfirmedId.value = awaitingConfirmId.value;
        awaitingConfirmId.value = null;
        save();
    }

    function undoLastConfirm() {
        if (!lastConfirmedId.value || !state.value) return;
        state.value = undoMarked(state.value, lastConfirmedId.value);
        lastConfirmedId.value = null;
        save();
    }

    function skipCurrent() {
        if (!current.value) return;
        state.value = markCurrent(state.value, 'skipped');
        awaitingConfirmId.value = null;
        lastConfirmedId.value = null;
        save();
    }

    function close() {
        if (done.value) save();
        sheet.value = false;
        awaitingConfirmId.value = null;
    }

    function onVisibilityChange() {
        if (typeof document !== 'undefined' && document.visibilityState === 'visible') confirmSent();
    }
    onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange));
    onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibilityChange));

    return {
        sheet,
        skippedInvalid,
        awaitingConfirmId,
        lastConfirmedId,
        current,
        sent,
        skipped,
        total,
        done,
        showPauseReminder,
        start,
        sendToCurrent,
        confirmSent,
        skipCurrent,
        undoLastConfirm,
        close,
    };
}
