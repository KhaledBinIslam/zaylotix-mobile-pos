import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { translations } from '@/i18n';

// Language is per-user (users.lang), not per-shop — each cashier/owner picks
// their own (see SettingController::updateLang). Falls back to the shop's
// default (set at creation), then to whatever language was last chosen on
// this browser (stored client-side — see More.vue's toggle) so the /login
// page itself, where there's no user yet, still remembers the choice, then
// finally to Bengali.
export function useI18n() {
    const page = usePage();
    const lang = computed(() =>
        page.props.auth?.user?.lang
        || page.props.shop?.lang
        || (typeof localStorage !== 'undefined' ? localStorage.getItem('zaylotix_lang') : null)
        || 'bn'
    );

    function t(key, vars) {
        const entry = translations[key];
        let str = entry ? (entry[lang.value] ?? entry.bn) : key;
        if (vars) {
            for (const [k, v] of Object.entries(vars)) {
                // replaceAll, not replace — a string's own author may
                // reasonably need the same placeholder twice (e.g. "already
                // X of Y, allocate up to Y"); String.replace() with a plain
                // string pattern only ever touches the first occurrence,
                // silently leaving every later one as a literal "{qty}".
                str = str.replaceAll(`{${k}}`, v);
            }
        }
        return str;
    }

    return { t, lang };
}
