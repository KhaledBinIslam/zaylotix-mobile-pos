<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ whatsappContact: String });
const { t } = useI18n();

// digits only, with country code (see SiteSettingController::updateWhatsappContact) —
// wa.me needs no '+' prefix. No admin-set number yet = button simply doesn't render,
// rather than opening a broken/empty chat.
const whatsappUrl = props.whatsappContact ? `https://wa.me/${props.whatsappContact}` : null;

// A small hand-drawn icon set (24x24, stroke-based, currentColor) instead of
// emoji — emoji reads as a generic placeholder look on a real product's
// landing page, not this product's own visual identity. Kept as raw path
// data (not separate .vue files) since this page is the only consumer.
const ICON_PATHS = {
    billing: '<path d="M6 8h12l-1.2 11.5a1.8 1.8 0 0 1-1.8 1.6H9a1.8 1.8 0 0 1-1.8-1.6L6 8z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
    inventory: '<path d="M3 8l9-4.5 9 4.5-9 4.5L3 8z"/><path d="M3 8v8.5l9 4.5 9-4.5V8"/><path d="M12 12.5V21"/>',
    ledger: '<path d="M6 3.5h9l4 4V19a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 19V5A1.5 1.5 0 0 1 6 3.5z"/><path d="M14 3.5V8h4.5"/><path d="M8 12h7M8 15.5h5"/>',
    accounts: '<rect x="5" y="3" width="14" height="18" rx="2"/><rect x="7.5" y="5.5" width="9" height="3.5" rx="0.5"/><circle cx="8" cy="13" r=".9" fill="currentColor" stroke="none"/><circle cx="12" cy="13" r=".9" fill="currentColor" stroke="none"/><circle cx="16" cy="13" r=".9" fill="currentColor" stroke="none"/><circle cx="8" cy="17" r=".9" fill="currentColor" stroke="none"/><circle cx="12" cy="17" r=".9" fill="currentColor" stroke="none"/><circle cx="16" cy="17" r=".9" fill="currentColor" stroke="none"/>',
    reports: '<path d="M4 20V11"/><path d="M11 20V4"/><path d="M18 20v-7"/><path d="M3 20h18"/>',
    staff: '<circle cx="9" cy="8" r="3.2"/><path d="M3.2 20a5.8 5.8 0 0 1 11.6 0"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.8 20a4.6 4.6 0 0 1 7.4-3.7"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M8.5 10.5l2.3 2.3 4.7-4.7"/>',
    android: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><line x1="10.5" y1="18" x2="13.5" y2="18"/>',
    devices: '<rect x="2.5" y="4" width="14" height="10" rx="1.4"/><line x1="6" y1="17.5" x2="13" y2="17.5"/><rect x="15.5" y="9" width="6" height="10.5" rx="1.2"/>',
    grocery: '<path d="M4 9h16l-1.6 9.5A2 2 0 0 1 16.4 20H7.6a2 2 0 0 1-2-1.5L4 9z"/><path d="M8 9V7a4 4 0 0 1 8 0v2"/><path d="M9 13v3M15 13v3"/>',
    pharmacy: '<rect x="2.5" y="9.5" width="19" height="6.5" rx="3.25"/><line x1="12" y1="9.5" x2="12" y2="16"/>',
    restaurant: '<path d="M7 3v6a1.8 1.8 0 0 0 3.6 0V3"/><path d="M8.8 9v12"/><path d="M16.5 3c-1.4 0-2.3 1.8-2.3 4.5S15.1 12 16.5 12"/><path d="M16.5 12v9"/>',
    clothing: '<path d="M8.5 3l3.5 2 3.5-2 4 3.5-2.6 2.6-1.4-1.1V20a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V8L6.6 9.1 4 6.5 8.5 3z"/>',
    mobile: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><line x1="10.5" y1="18" x2="13.5" y2="18"/>',
    supershop: '<path d="M3.5 9l1-5h15l1 5"/><path d="M4.5 9v10.5h15V9"/><path d="M9.5 19.5V14h5v5.5"/>',
    whatsapp: '<path d="M12 3.5a8.5 8.5 0 0 0-7.3 12.8L3.5 20.5l4.4-1.2A8.5 8.5 0 1 0 12 3.5z"/><path d="M8.6 10.3c.3 2.6 2.6 4.9 5.2 5.2.9.1 1.6-.5 1.6-1.3v-.4l-1.7-.7-.6.6c-1-.5-1.9-1.4-2.4-2.4l.6-.6-.7-1.7h-.4c-.8 0-1.7.5-1.6 1.3z" fill="currentColor" stroke="none"/>',
};

// Grouped like the app's own admin feature categories (billing/inventory/
// accounts/reports/staff — see FeatureSeeder) — the same real feature set an
// owner sees once they sign up, not a marketing-only summary that undersells
// how much is actually here.
const PILLARS = [
    { icon: 'billing', accent: 'green', title: 'landing.pillar.billing', items: ['landing.pillar.billing.1', 'landing.pillar.billing.2', 'landing.pillar.billing.3', 'landing.pillar.billing.4'] },
    { icon: 'inventory', accent: 'gold', title: 'landing.pillar.inventory', items: ['landing.pillar.inventory.1', 'landing.pillar.inventory.2', 'landing.pillar.inventory.3', 'landing.pillar.inventory.4'] },
    { icon: 'ledger', accent: 'sky', title: 'landing.pillar.ledger', items: ['landing.pillar.ledger.1', 'landing.pillar.ledger.2', 'landing.pillar.ledger.3', 'landing.pillar.ledger.4'] },
    { icon: 'accounts', accent: 'purple', title: 'landing.pillar.accounts', items: ['landing.pillar.accounts.1', 'landing.pillar.accounts.2', 'landing.pillar.accounts.3', 'landing.pillar.accounts.4'] },
    { icon: 'reports', accent: 'rose', title: 'landing.pillar.reports', items: ['landing.pillar.reports.1', 'landing.pillar.reports.2', 'landing.pillar.reports.3', 'landing.pillar.reports.4'] },
    { icon: 'staff', accent: 'green2', title: 'landing.pillar.staff', items: ['landing.pillar.staff.1', 'landing.pillar.staff.2', 'landing.pillar.staff.3', 'landing.pillar.staff.4'] },
];

const PLATFORM = [
    { icon: 'globe', title: 'landing.platform.offline', desc: 'landing.platform.offlineDesc' },
    { icon: 'android', title: 'landing.platform.android', desc: 'landing.platform.androidDesc' },
    { icon: 'devices', title: 'landing.platform.devices', desc: 'landing.platform.devicesDesc' },
];

const TRUST = ['landing.trust.1', 'landing.trust.2', 'landing.trust.3'];

const AUDIENCE = [
    { icon: 'grocery', label: 'landing.audience.grocery' },
    { icon: 'pharmacy', label: 'landing.audience.pharmacy' },
    { icon: 'restaurant', label: 'landing.audience.restaurant' },
    { icon: 'clothing', label: 'landing.audience.clothing' },
    { icon: 'mobile', label: 'landing.audience.mobile' },
    { icon: 'supershop', label: 'landing.audience.supershop' },
];
</script>

<template>
    <Head :title="t('app.name')" />

    <div class="landing">
        <!-- sticky mini-nav — Login/Signup stay one tap away no matter how far
             down this page a visitor has scrolled, instead of only living in
             the hero and the closing band -->
        <div class="landing-nav">
            <div class="landing-nav-inner">
                <div class="landing-nav-brand"><ZaylotixMark :size="26" /> Zaylotix</div>
                <div class="landing-nav-cta">
                    <Link :href="route('login')" class="btn ghost sm">{{ t('landing.loginBtn') }}</Link>
                    <Link :href="route('signup')" class="btn sm">{{ t('landing.signupBtn') }}</Link>
                </div>
            </div>
        </div>

        <div class="landing-inner">
            <header class="landing-hero">
                <ZaylotixMark :size="64" />
                <div class="landing-pill">{{ t('landing.tagline') }}</div>
                <h1>{{ t('landing.heroTitle') }}</h1>
                <p>{{ t('landing.heroSubtitle') }}</p>

                <div class="landing-cta">
                    <Link :href="route('signup')" class="btn lg">{{ t('landing.signupBtn') }}</Link>
                    <Link :href="route('login')" class="btn ghost lg light">{{ t('landing.loginBtn') }}</Link>
                    <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa lg">
                        <span class="licon" v-html="ICON_PATHS.whatsapp"></span> {{ t('landing.whatsappBtn') }}
                    </a>
                </div>

                <div class="landing-trust">
                    <span v-for="tr in TRUST" :key="tr">{{ t(tr) }}</span>
                </div>
            </header>

            <section class="landing-section">
                <h2>{{ t('landing.featuresTitle') }}</h2>
                <p class="landing-section-sub">{{ t('landing.featuresSub') }}</p>
                <div class="landing-pillars">
                    <div v-for="p in PILLARS" :key="p.title" class="landing-pillar">
                        <div class="landing-pillar-icon" :class="'ac-' + p.accent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[p.icon]"></svg>
                        </div>
                        <b>{{ t(p.title) }}</b>
                        <ul>
                            <li v-for="it in p.items" :key="it">{{ t(it) }}</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <h2>{{ t('landing.platformTitle') }}</h2>
                <div class="landing-platform">
                    <div v-for="p in PLATFORM" :key="p.title" class="landing-platform-card">
                        <span class="licon lg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[p.icon]"></svg></span>
                        <b>{{ t(p.title) }}</b>
                        <span>{{ t(p.desc) }}</span>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <h2>{{ t('landing.audienceTitle') }}</h2>
                <div class="landing-audience">
                    <div v-for="a in AUDIENCE" :key="a.label" class="landing-audience-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[a.icon]"></svg>
                        {{ t(a.label) }}
                    </div>
                </div>
            </section>

            <footer class="landing-closing">
                <h2>{{ t('landing.closingTitle') }}</h2>
                <p>{{ t('landing.closingSub') }}</p>
                <div class="landing-cta">
                    <Link :href="route('signup')" class="btn lg">{{ t('landing.signupBtn') }}</Link>
                    <Link :href="route('login')" class="btn ghost lg light">{{ t('landing.loginBtn') }}</Link>
                    <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa lg">
                        <span class="licon" v-html="ICON_PATHS.whatsapp"></span> {{ t('landing.whatsappBtn') }}
                    </a>
                </div>
            </footer>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>
