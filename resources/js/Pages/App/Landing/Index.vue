<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';
import { useI18n } from '@/composables/useI18n';
import { isInApp } from '@/composables/useInApp';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

// code-split — the Three.js scene (and three.js itself) only ever loads for
// a visitor who actually lands on this page, never bundled into the app's
// own shop-facing chunk
const LandingHeroScene = defineAsyncComponent(() => import('@/Components/LandingHeroScene.vue'));

const props = defineProps({ whatsappContact: String, playStoreUrl: String, apkAvailable: Boolean, signupEnabled: { type: Boolean, default: true } });
const { t } = useI18n();

// Opened inside the Android app itself — hide Zaylotix's own pricing/
// contact-to-buy section (Play policy: no in-app purchase flow that
// bypasses Play Billing, payment here is manual) and the "download the
// app" section (pointless to tell someone already inside the app to
// download it).
const inApp = isInApp();

// digits only, with country code (see SiteSettingController::updateWhatsappContact) —
// wa.me needs no '+' prefix. No admin-set number yet = button simply doesn't render,
// rather than opening a broken/empty chat.
const whatsappUrl = props.whatsappContact ? `https://wa.me/${props.whatsappContact}` : null;

const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

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
    shield: '<path d="M12 3l7 3v5.5c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4.5"/>',
    check: '<path d="M4 12.5l5 5L20 6"/>',
    barcode: '<path d="M4 5v14M8 5v14M11 5v14M15 5v14M17 5v14M21 5v14" stroke-width="2"/>',
};

// Grouped like the app's own admin feature categories (billing/inventory/
// accounts/reports/staff — see FeatureSeeder) — the same real feature set an
// owner sees once they sign up, not a marketing-only summary that undersells
// how much is actually here. The first three (billing/inventory/accounts)
// each get their own cinematic scroll section below instead of sitting
// in the flat grid — the grid now only holds the remaining three.
const PILLARS = [
    { icon: 'billing', accent: 'orange', title: 'landing.pillar.billing', items: ['landing.pillar.billing.1', 'landing.pillar.billing.2', 'landing.pillar.billing.3', 'landing.pillar.billing.4'] },
    { icon: 'inventory', accent: 'green', title: 'landing.pillar.inventory', items: ['landing.pillar.inventory.1', 'landing.pillar.inventory.2', 'landing.pillar.inventory.3', 'landing.pillar.inventory.4'] },
    { icon: 'ledger', accent: 'sky', title: 'landing.pillar.ledger', items: ['landing.pillar.ledger.1', 'landing.pillar.ledger.2', 'landing.pillar.ledger.3', 'landing.pillar.ledger.4'] },
    { icon: 'accounts', accent: 'blue', title: 'landing.pillar.accounts', items: ['landing.pillar.accounts.1', 'landing.pillar.accounts.2', 'landing.pillar.accounts.3', 'landing.pillar.accounts.4'] },
    { icon: 'reports', accent: 'rose', title: 'landing.pillar.reports', items: ['landing.pillar.reports.1', 'landing.pillar.reports.2', 'landing.pillar.reports.3', 'landing.pillar.reports.4'] },
    { icon: 'staff', accent: 'green2', title: 'landing.pillar.staff', items: ['landing.pillar.staff.1', 'landing.pillar.staff.2', 'landing.pillar.staff.3', 'landing.pillar.staff.4'] },
];
const GRID_PILLARS = [PILLARS[2], PILLARS[4], PILLARS[5]];

const PLATFORM = [
    { icon: 'globe', title: 'landing.platform.offline', desc: 'landing.platform.offlineDesc' },
    { icon: 'android', title: 'landing.platform.android', desc: 'landing.platform.androidDesc' },
    { icon: 'devices', title: 'landing.platform.devices', desc: 'landing.platform.devicesDesc' },
];

// Real, verifiable counts (not marketing guesses) — 8 business types in
// config/business_types.php, 32 opt-in features in FeatureSeeder (rounded
// down to 30+ so the claim stays true even as that list changes), rather
// than an invented "500+ shops" style stat this app has no way to back up.
const STATS = [
    { n: '৮+', label: 'landing.stat.types' },
    { n: '৩০+', label: 'landing.stat.features' },
    { n: '১৪', label: 'landing.stat.trial' },
];

// Genuine differentiators, not fabricated reviews/testimonials
const WHY = [
    { icon: 'globe', accent: 'purple', title: 'landing.why.bangla', desc: 'landing.why.banglaDesc' },
    { icon: 'supershop', accent: 'orange', title: 'landing.why.types', desc: 'landing.why.typesDesc' },
    { icon: 'inventory', accent: 'green', title: 'landing.why.allInOne', desc: 'landing.why.allInOneDesc' },
    { icon: 'shield', accent: 'blue', title: 'landing.why.offline', desc: 'landing.why.offlineDesc' },
];

// Beyond the 6 main pillars above — real modules (see app/Http/Controllers/App)
// this system already has, kept as a dense checklist rather than bloating
// every pillar card into an unreadable wall of text
const EXTRAS = [
    'landing.extra.gateway', 'landing.extra.branch', 'landing.extra.reservation',
    'landing.extra.kds', 'landing.extra.cds', 'landing.extra.workPeriod',
    'landing.extra.medicine', 'landing.extra.loan', 'landing.extra.heldCart',
    'landing.extra.attendance', 'landing.extra.stockTransfer', 'landing.extra.notifications',
];

const AUDIENCE = [
    { icon: 'grocery', label: 'landing.audience.grocery' },
    { icon: 'pharmacy', label: 'landing.audience.pharmacy' },
    { icon: 'restaurant', label: 'landing.audience.restaurant' },
    { icon: 'clothing', label: 'landing.audience.clothing' },
    { icon: 'mobile', label: 'landing.audience.mobile' },
    { icon: 'supershop', label: 'landing.audience.supershop' },
];

const STEPS = [
    { n: 1, title: 'landing.step.1', desc: 'landing.step.1desc' },
    { n: 2, title: 'landing.step.2', desc: 'landing.step.2desc' },
    { n: 3, title: 'landing.step.3', desc: 'landing.step.3desc' },
    { n: 4, title: 'landing.step.4', desc: 'landing.step.4desc' },
];

const SHOWCASE = [
    { img: '/images/landing/shot-pos.png', caption: 'landing.showcase.pos' },
    { img: '/images/landing/shot-stock.png', caption: 'landing.showcase.stock' },
    { img: '/images/landing/shot-reports.png', caption: 'landing.showcase.reports' },
];

// --- refs for the bespoke scroll-storytelling sections below ---
const posVisual = ref(null);
const invVisual = ref(null);
const analyticsVisual = ref(null);
const showcaseVisual = ref(null);

let revealObserver = null;
const scrollTriggers = [];

/** gsap.from() only moves an element AWAY from its natural CSS position while
 *  the tween runs — skipped entirely under reduced motion, the element just
 *  sits at its normal (fully visible) layout position the whole time. */
function revealFrom(targets, from, opts = {}) {
    if (reducedMotion || !targets) return;
    const tween = gsap.from(targets, {
        ...from,
        duration: opts.duration ?? 0.9,
        ease: opts.ease ?? 'power3.out',
        stagger: opts.stagger,
        scrollTrigger: { trigger: opts.trigger ?? targets, start: 'top 82%', toggleActions: 'play none none none' },
    });
    if (tween.scrollTrigger) scrollTriggers.push(tween.scrollTrigger);
}

function setupPosSection() {
    if (!posVisual.value) return;
    const root = posVisual.value;
    revealFrom(root.querySelector('.lp-pos-phone'), { opacity: 0, x: 60, rotateY: 26 }, { trigger: root, duration: 1.1 });
    revealFrom(root.querySelectorAll('.lp-cart-chip'), { opacity: 0, y: 22, x: -16 }, { trigger: root, stagger: 0.16, duration: 0.6 });
    revealFrom(root.querySelector('.lp-success-badge'), { opacity: 0, scale: 0.4 }, { trigger: root, duration: 0.6, ease: 'back.out(2.2)' });
}

function setupInventorySection() {
    if (!invVisual.value) return;
    const root = invVisual.value;
    revealFrom(root.querySelectorAll('.lp-stock-chip'), { opacity: 0, scale: 0.4, rotateX: 40 }, { trigger: root, stagger: 0.07, duration: 0.55 });
    revealFrom(root.querySelector('.lp-low-stock'), { opacity: 0, y: 10 }, { trigger: root, duration: 0.5 });
}

function setupAnalyticsSection() {
    if (!analyticsVisual.value) return;
    const root = analyticsVisual.value;
    const line = root.querySelector('.lp-chart-line');
    if (line && !reducedMotion) {
        const len = line.getTotalLength();
        line.style.strokeDasharray = String(len);
        line.style.strokeDashoffset = String(len);
        const tween = gsap.to(line, {
            strokeDashoffset: 0, duration: 1.4, ease: 'power2.inOut',
            scrollTrigger: { trigger: root, start: 'top 80%', toggleActions: 'play none none none' },
        });
        if (tween.scrollTrigger) scrollTriggers.push(tween.scrollTrigger);
    }
    revealFrom(root.querySelectorAll('.lp-chart-bar'), { scaleY: 0, transformOrigin: '50% 100%' }, { trigger: root, stagger: 0.08, duration: 0.7, ease: 'power2.out' });
    revealFrom(root.querySelector('.lp-chart-chip'), { opacity: 0, y: -12 }, { trigger: root, duration: 0.6 });
}

function setupShowcaseSection() {
    if (!showcaseVisual.value) return;
    const phones = showcaseVisual.value.querySelectorAll('.lp-showcase-phone');
    phones.forEach((phone, i) => {
        const tilt = i % 2 === 0 ? -22 : 22;
        revealFrom(phone, { opacity: 0, y: 36, rotateY: tilt }, { trigger: phone, duration: 0.85 });
    });
}

onMounted(() => {
    // same plain-fade reveal used by every simpler section below
    const els = document.querySelectorAll('.reveal');
    revealObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                revealObserver.unobserve(entry.target);
            }
        }
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    els.forEach((el) => revealObserver.observe(el));

    setupPosSection();
    setupInventorySection();
    setupAnalyticsSection();
    setupShowcaseSection();
});
onBeforeUnmount(() => {
    revealObserver?.disconnect();
    scrollTriggers.forEach((st) => st.kill());
});
</script>

<template>
    <Head :title="t('app.name')" />

    <div class="landing">
        <!-- sticky mini-nav — Login/Signup stay one tap away no matter how far
             down this page a visitor has scrolled, instead of only living in
             the hero and the closing band -->
        <div class="lp-nav">
            <div class="lp-nav-inner">
                <div class="lp-nav-brand"><ZaylotixMark :size="26" /> Zaylotix</div>
                <div class="lp-nav-links">
                    <a href="#features">{{ t('landing.navFeatures') }}</a>
                    <a href="#how-it-works">{{ t('landing.navHowItWorks') }}</a>
                    <a v-if="!inApp" href="#pricing">{{ t('landing.navPricing') }}</a>
                </div>
                <div class="lp-nav-cta">
                    <Link :href="route('login')" class="btn ghost sm">{{ t('landing.loginBtn') }}</Link>
                    <Link v-if="signupEnabled" :href="route('signup')" class="btn sm">{{ t('landing.signupBtn') }}</Link>
                </div>
            </div>
        </div>

        <!-- ================= HERO — deep navy, real WebGL 3D phone ================= -->
        <header class="lp-hero">
            <div class="lp-hero-inner">
                <div class="lp-hero-copy">
                    <ZaylotixMark :size="60" />
                    <div class="lp-pill">{{ t('landing.tagline') }}</div>
                    <h1>{{ t('landing.heroTitle') }}</h1>
                    <p>{{ t('landing.heroSubtitle') }}</p>

                    <div class="lp-cta">
                        <Link v-if="signupEnabled" :href="route('signup')" class="btn lg">{{ t('landing.signupBtn') }}</Link>
                        <Link :href="route('login')" class="btn ghost lg light">{{ t('landing.loginBtn') }}</Link>
                        <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa lg">
                            <span class="licon" v-html="ICON_PATHS.whatsapp"></span> {{ t('landing.whatsappBtn') }}
                        </a>
                    </div>

                    <div class="lp-stat-row">
                        <div v-for="s in STATS" :key="s.label" class="lp-stat">
                            <b>{{ s.n }}</b>
                            <span>{{ t(s.label) }}</span>
                        </div>
                    </div>
                </div>
                <div class="lp-hero-visual">
                    <div class="phone-mock lp-hero-phone-fallback"><img :src="SHOWCASE[0].img" :alt="t(SHOWCASE[0].caption)" loading="eager" /></div>
                    <LandingHeroScene :screen-image="SHOWCASE[0].img" />
                </div>
            </div>
        </header>

        <div class="lp-inner">
            <section id="features" class="lp-section-head reveal">
                <h2>{{ t('landing.featuresTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.featuresSub') }}</p>
            </section>

            <!-- ================= POS BILLING — off-white, orange accent ================= -->
            <section class="lp-story lp-story-offwhite">
                <div class="lp-story-grid" ref="posVisual">
                    <div class="lp-story-copy">
                        <div class="lp-story-icon ac-orange">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[PILLARS[0].icon]"></svg>
                        </div>
                        <b>{{ t(PILLARS[0].title) }}</b>
                        <ul><li v-for="it in PILLARS[0].items" :key="it">{{ t(it) }}</li></ul>
                    </div>
                    <div class="lp-story-visual">
                        <div class="lp-pos-phone phone-mock sm"><img :src="SHOWCASE[0].img" :alt="t(PILLARS[0].title)" loading="lazy" /></div>
                        <div class="lp-cart-chip" style="--d:0">👟 {{ t('landing.pillar.billing.1') }}</div>
                        <div class="lp-cart-chip" style="--d:1">🧾 ৳১,২৫০</div>
                        <div class="lp-success-badge">✓</div>
                    </div>
                </div>
            </section>

            <!-- ================= INVENTORY — neutral, green accent ================= -->
            <section class="lp-story lp-story-neutral">
                <div class="lp-story-grid is-reversed" ref="invVisual">
                    <div class="lp-story-copy">
                        <div class="lp-story-icon ac-green">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[PILLARS[1].icon]"></svg>
                        </div>
                        <b>{{ t(PILLARS[1].title) }}</b>
                        <ul><li v-for="it in PILLARS[1].items" :key="it">{{ t(it) }}</li></ul>
                    </div>
                    <div class="lp-story-visual">
                        <div class="lp-shelf-scene">
                            <div class="lp-shelf-row" v-for="row in 2" :key="row">
                                <div class="lp-stock-chip" v-for="chip in 4" :key="chip"></div>
                            </div>
                            <div class="lp-scan-line"></div>
                            <svg class="lp-barcode" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" v-html="ICON_PATHS.barcode"></svg>
                            <div class="lp-low-stock">⚠ {{ t('landing.pillar.inventory.4') }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================= ACCOUNTS & ANALYTICS — dark navy, data viz ================= -->
            <section class="lp-story lp-story-navy">
                <div class="lp-story-grid" ref="analyticsVisual">
                    <div class="lp-story-copy">
                        <div class="lp-story-icon ac-blue">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[PILLARS[3].icon]"></svg>
                        </div>
                        <b>{{ t(PILLARS[3].title) }}</b>
                        <ul><li v-for="it in PILLARS[3].items" :key="it">{{ t(it) }}</li></ul>
                    </div>
                    <div class="lp-story-visual">
                        <div class="lp-chart-chip">📈 +{{ t('landing.stat.features') }}</div>
                        <svg class="lp-chart" viewBox="0 0 300 150" preserveAspectRatio="none">
                            <rect class="lp-chart-bar" x="20" y="70" width="26" height="60" rx="4"></rect>
                            <rect class="lp-chart-bar" x="70" y="48" width="26" height="82" rx="4"></rect>
                            <rect class="lp-chart-bar" x="120" y="60" width="26" height="70" rx="4"></rect>
                            <rect class="lp-chart-bar" x="170" y="30" width="26" height="100" rx="4"></rect>
                            <rect class="lp-chart-bar" x="220" y="18" width="26" height="112" rx="4"></rect>
                            <path class="lp-chart-line" d="M22 118 L96 90 L146 98 L196 58 L246 36" fill="none" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </div>
                </div>
            </section>

            <section id="how-it-works" class="lp-section lp-section-white reveal">
                <h2>{{ t('landing.howItWorksTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.howItWorksSub') }}</p>
                <div class="lp-steps">
                    <div v-for="s in STEPS" :key="s.n" class="lp-step">
                        <div class="lp-step-num">{{ s.n }}</div>
                        <b>{{ t(s.title) }}</b>
                        <span>{{ t(s.desc) }}</span>
                    </div>
                </div>
            </section>

            <!-- ================= MOBILE BUSINESS — white, multi-device showcase ================= -->
            <section id="showcase" class="lp-section lp-section-white">
                <h2>{{ t('landing.showcaseTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.showcaseSub') }}</p>
                <div class="lp-platform-row">
                    <div v-for="p in PLATFORM" :key="p.title" class="lp-platform-chip">
                        <span class="licon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[p.icon]"></svg></span>
                        <b>{{ t(p.title) }}</b>
                    </div>
                </div>
                <div class="lp-showcase" ref="showcaseVisual">
                    <div v-for="s in SHOWCASE" :key="s.img" class="lp-showcase-phone">
                        <div class="phone-mock sm"><img :src="s.img" :alt="t(s.caption)" loading="lazy" /></div>
                        <span>{{ t(s.caption) }}</span>
                    </div>
                </div>
            </section>

            <section class="lp-section lp-section-neutral reveal">
                <h2>{{ t('landing.featuresTitle') }} — {{ t('landing.extrasTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.extrasSub') }}</p>
                <div class="lp-pillars">
                    <div v-for="p in GRID_PILLARS" :key="p.title" class="lp-pillar">
                        <div class="lp-pillar-icon" :class="'ac-' + p.accent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[p.icon]"></svg>
                        </div>
                        <b>{{ t(p.title) }}</b>
                        <ul>
                            <li v-for="it in p.items" :key="it">{{ t(it) }}</li>
                        </ul>
                    </div>
                </div>
                <div class="lp-extras">
                    <div v-for="ex in EXTRAS" :key="ex" class="lp-extras-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS.check"></svg>
                        {{ t(ex) }}
                    </div>
                </div>
            </section>

            <section class="lp-section lp-section-white reveal">
                <h2>{{ t('landing.whyTitle') }}</h2>
                <div class="lp-why">
                    <div v-for="w in WHY" :key="w.title" class="lp-why-item">
                        <div class="lp-pillar-icon" :class="'ac-' + w.accent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[w.icon]"></svg>
                        </div>
                        <div>
                            <b>{{ t(w.title) }}</b>
                            <span>{{ t(w.desc) }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="lp-section lp-section-neutral reveal">
                <h2>{{ t('landing.audienceTitle') }}</h2>
                <div class="lp-audience">
                    <div v-for="a in AUDIENCE" :key="a.label" class="lp-audience-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICON_PATHS[a.icon]"></svg>
                        {{ t(a.label) }}
                    </div>
                </div>
            </section>

            <section class="lp-section lp-section-white reveal">
                <h2>{{ t('landing.testimonialsTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.testimonialsSub') }}</p>
                <div class="lp-testimonials">
                    <div v-for="i in 3" :key="i" class="lp-testimonial-ghost">
                        <div class="gavatar"></div>
                        <div class="gline" style="width: 85%"></div>
                        <div class="gline" style="width: 65%"></div>
                        <div class="gline" style="width: 45%"></div>
                    </div>
                </div>
                <div class="lp-testimonials-note">{{ t('landing.testimonialsComingSoon') }}</div>
            </section>

            <section v-if="!inApp" id="pricing" class="lp-section lp-section-neutral reveal">
                <h2>{{ t('landing.pricingTitle') }}</h2>
                <div class="lp-pricing-card">
                    <b>{{ t('landing.pricingCardTitle') }}</b>
                    <p>{{ t('landing.pricingCardBody') }}</p>
                    <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa lg">
                        <span class="licon" v-html="ICON_PATHS.whatsapp"></span> {{ t('landing.pricingCta') }}
                    </a>
                    <Link v-else-if="signupEnabled" :href="route('signup')" class="btn lg">{{ t('landing.pricingCta') }}</Link>
                </div>
            </section>

            <section v-if="!inApp && (playStoreUrl || apkAvailable)" class="lp-section lp-section-white reveal">
                <h2>{{ t('landing.appDownloadTitle') }}</h2>
                <p class="lp-section-sub">{{ t('landing.appDownloadSub') }}</p>
                <div class="lp-cta">
                    <a v-if="playStoreUrl" :href="playStoreUrl" target="_blank" rel="noopener" class="btn lg">
                        <span class="licon" v-html="ICON_PATHS.android"></span> {{ t('landing.appDownloadPlayStore') }}
                    </a>
                    <a v-if="apkAvailable" href="/downloads/zaylotix-pos.apk" class="btn ghost lg">
                        <span class="licon" v-html="ICON_PATHS.devices"></span> {{ t('landing.appDownloadApk') }}
                    </a>
                    <!-- iOS has no APK to offer — the honest install path
                         is Safari's Add to Home Screen, walked through at
                         /ios (the page Khaled shares with iPhone users) -->
                    <Link :href="route('landing.ios')" class="btn ghost lg" style="text-decoration:none">
                        🍎 {{ t('landing.appDownloadIos') }}
                    </Link>
                </div>
            </section>

            <!-- ================= FINAL CTA — the one deliberate purple band ================= -->
            <footer class="lp-closing">
                <h2>{{ t('landing.closingTitle') }}</h2>
                <p>{{ t('landing.closingSub') }}</p>
                <p class="lp-closing-punch">{{ t('landing.closingPunch') }}</p>
                <div class="lp-cta">
                    <Link v-if="signupEnabled" :href="route('signup')" class="btn lg">{{ t('landing.signupBtn') }}</Link>
                    <Link :href="route('login')" class="btn ghost lg light">{{ t('landing.loginBtn') }}</Link>
                    <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa lg">
                        <span class="licon" v-html="ICON_PATHS.whatsapp"></span> {{ t('landing.whatsappBtn') }}
                    </a>
                </div>
            </footer>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:var(--lp-purple);font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>

<style>
/* ================================================================
   Landing page design system — scoped entirely under `.landing`.
   New tokens (--lp-*), never the shared app-wide --purple/--green/
   etc. (those drive the whole rest of the app, e.g. Admin's --purple
   is a completely different indigo — touching them here would repaint
   screens this page has nothing to do with). Per Khaled's brief:
   purple is the BRAND accent only (logo, primary CTA, the one closing
   band) — every section background below uses the neutral/navy/white
   family instead, with green/orange/blue as PURPOSEFUL accents
   (financial growth, sales/checkout, inventory/info respectively).
   ================================================================ */
.landing {
    --lp-purple: #7138E8;
    --lp-purple2: #5B2CC4;
    --lp-navy: #17152D;
    --lp-navy2: #201C3D;
    --lp-offwhite: #FAFAF7;
    --lp-white: #FFFFFF;
    --lp-neutral: #F1F2F4;
    --lp-ink: #202124;
    --lp-mut: #6B6D75;
    --lp-mut-on-navy: #AEB0C8;
    --lp-line: #E6E6E9;
    --lp-green: #20A36A;
    --lp-greenSoft: #E3F5EC;
    --lp-orange: #FF8A36;
    --lp-orangeSoft: #FFEEE0;
    --lp-blue: #4285E8;
    --lp-blueSoft: #E8F0FD;

    min-height: 100vh;
    background: var(--lp-offwhite);
    color: var(--lp-ink);
}
.lp-inner { width: 100%; max-width: 1040px; margin: 0 auto; padding: 0 18px 40px; }

/* ---------- sticky mini-nav ---------- */
.lp-nav { position: sticky; top: 0; z-index: 40; background: rgba(255, 255, 255, .92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--lp-line); }
.lp-nav-inner { max-width: 1040px; margin: 0 auto; padding: 10px 18px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.lp-nav-brand { display: flex; align-items: center; gap: 8px; font-family: var(--font-display); font-weight: 700; color: var(--lp-purple); font-size: 16px; letter-spacing: -.2px; }
.lp-nav-cta { display: flex; gap: 8px; }
.lp-nav-links { display: none; align-items: center; gap: 24px; }
.lp-nav-links a { font-size: 13.5px; font-weight: 650; color: var(--lp-mut); text-decoration: none; transition: color .15s ease; }
.lp-nav-links a:hover { color: var(--lp-purple); }
@media (min-width: 700px) { .lp-nav-links { display: flex; } }

/* ---------- hero — deep navy, full-bleed, real WebGL phone ---------- */
.lp-hero {
    position: relative; overflow: hidden; color: #fff; text-align: center;
    padding: 48px 18px 54px;
    background:
        radial-gradient(640px 360px at 85% -8%, rgba(113, 56, 232, .35), transparent),
        radial-gradient(520px 320px at 6% 108%, rgba(66, 133, 232, .2), transparent),
        linear-gradient(165deg, var(--lp-navy) 0%, var(--lp-navy2) 100%);
}
.lp-hero-inner { max-width: 1040px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.lp-hero-copy { max-width: 620px; }
.lp-pill { display: inline-block; margin: 14px 0 4px; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #fff; background: rgba(255, 255, 255, .12); border: 1px solid rgba(255, 255, 255, .25); border-radius: 999px; padding: 6px 15px; }
.lp-hero h1 { font-family: var(--font-display); font-size: 30px; font-weight: 700; letter-spacing: -.5px; line-height: 1.15; margin: 16px 0 10px; color: #fff; }
.lp-hero p { font-size: 14.5px; color: rgba(255, 255, 255, .78); line-height: 1.7; max-width: 560px; margin: 0 auto; }
.lp-cta { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 24px; }
.lp-cta .btn { width: auto; }
.lp-stat-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 28px; margin: 22px 0 4px; }
.lp-stat { text-align: center; }
.lp-stat b { display: block; font-family: var(--font-display); font-size: 26px; font-weight: 700; color: #fff; }
.lp-stat span { display: block; font-size: 12px; color: rgba(255, 255, 255, .68); font-weight: 600; }

.lp-hero-visual { position: relative; width: 100%; max-width: 320px; height: 360px; margin: 22px auto 0; }
.lp-hero-phone-fallback { position: absolute; inset: 0; margin: 0 auto; opacity: .9; transition: opacity .6s ease; }
.lp-hero-canvas { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; transition: opacity .6s ease; }
.lp-hero-canvas.is-ready { opacity: 1; }
/* once the WebGL scene is live, fade the static screenshot out from under it.
   On a browser without :has() the fallback just stays put behind the canvas —
   harmless, the canvas paints over it. */
.lp-hero-visual:has(.lp-hero-canvas.is-ready) .lp-hero-phone-fallback { opacity: 0; }
.lp-hero-canvas canvas { display: block; }

.btn.lg { padding: 15px 26px; font-size: 15.5px; border-radius: 15px; }
.btn.ghost.light { background: rgba(255, 255, 255, .14); color: #fff; border: 1.5px solid rgba(255, 255, 255, .55); }
/* purple is the brand accent — the one place that rule applies to every
   non-ghost/non-WhatsApp button on this page, landing-scoped only */
.landing .btn:not(.ghost):not(.wa) { background: var(--lp-purple); box-shadow: 0 10px 24px rgba(113, 56, 232, .28); }
.landing .btn:not(.ghost):not(.wa):hover { background: var(--lp-purple2); }

@media (min-width: 1024px) {
    .lp-hero { padding: 68px 40px 70px; }
    .lp-hero h1 { font-size: 46px; }
    .lp-hero-inner { flex-direction: row; text-align: left; gap: 56px; align-items: center; }
    .lp-hero-copy { flex: 1 1 auto; }
    .lp-hero-copy .lp-pill, .lp-hero-copy p, .lp-hero-copy .lp-cta, .lp-hero-copy .lp-stat-row { margin-left: 0; justify-content: flex-start; }
    .lp-hero-visual { flex: 0 0 auto; margin: 0; width: 300px; height: 420px; }
}

/* ---------- generic section scaffolding ---------- */
.lp-section-head { padding-top: 44px; text-align: center; }
.lp-section { margin-top: 0; padding: 44px 0; }
.lp-section-white { background: var(--lp-white); }
.lp-section-neutral { background: var(--lp-neutral); }
.lp-section h2, .lp-section-head h2 { font-family: var(--font-display); text-align: center; font-size: 21px; font-weight: 700; letter-spacing: -.2px; color: var(--lp-ink); margin-bottom: 6px; }
.lp-section-sub { text-align: center; font-size: 13px; color: var(--lp-mut); margin-bottom: 20px; }
.lp-section-white, .lp-section-neutral { padding-left: 18px; padding-right: 18px; }
.lp-section-white > *, .lp-section-neutral > * { max-width: 1040px; margin-left: auto; margin-right: auto; }

/* full-bleed section backgrounds, content kept to the same reading column */
.lp-story { padding: 44px 18px; }
.lp-story-offwhite { background: var(--lp-offwhite); }
.lp-story-neutral { background: var(--lp-neutral); }
.lp-story-navy { background: linear-gradient(165deg, var(--lp-navy), var(--lp-navy2)); color: #fff; }
.lp-story-grid { max-width: 1040px; margin: 0 auto; display: flex; flex-direction: column; gap: 28px; align-items: center; }
.lp-story-copy { max-width: 440px; text-align: center; }
.lp-story-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.lp-story-icon svg { width: 23px; height: 23px; }
.lp-story-copy b { display: block; font-family: var(--font-display); font-size: 19px; margin-bottom: 10px; }
.lp-story-copy ul { list-style: none; margin: 0; padding: 0; text-align: left; display: inline-block; }
.lp-story-copy li { position: relative; padding-left: 18px; font-size: 13px; line-height: 1.9; }
.lp-story-navy .lp-story-copy li { color: var(--lp-mut-on-navy); }
.lp-story-offwhite .lp-story-copy li, .lp-story-neutral .lp-story-copy li { color: var(--lp-mut); }
.lp-story-copy li::before { content: ''; position: absolute; left: 0; top: 9px; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: .4; }
.lp-story-visual { position: relative; width: 100%; max-width: 320px; min-height: 260px; display: flex; align-items: center; justify-content: center; }
@media (min-width: 900px) {
    .lp-story-grid { flex-direction: row; text-align: left; gap: 60px; }
    .lp-story-grid.is-reversed { flex-direction: row-reverse; }
    .lp-story-copy { text-align: left; flex: 1 1 380px; }
    .lp-story-visual { flex: 1 1 380px; }
}

.ac-green { background: var(--lp-greenSoft); color: var(--lp-green); }
.ac-orange { background: var(--lp-orangeSoft); color: var(--lp-orange); }
.ac-blue { background: var(--lp-blueSoft); color: var(--lp-blue); }
.ac-sky { background: var(--lp-blueSoft); color: var(--lp-blue); }
.ac-purple { background: #EFE6FD; color: var(--lp-purple); }
.ac-rose { background: #FCE4E6; color: #D23B47; }
.ac-green2 { background: var(--lp-greenSoft); color: var(--lp-green); }

/* POS billing visual — real phone-mock screenshot + flying cart chips.
   perspective lives on the PARENT so GSAP's own rotateY tween on the phone
   doesn't flatten it away when it rebuilds the element's transform */
.lp-story-visual { perspective: 900px; }
.lp-pos-phone { margin: 0 auto; transform: rotateY(-6deg); }
.lp-cart-chip { position: absolute; background: #fff; border: 1px solid var(--lp-line); border-radius: 12px; padding: 7px 12px; font-size: 12.5px; font-weight: 700; color: var(--lp-ink); box-shadow: 0 10px 22px rgba(20, 20, 30, .12); }
.lp-cart-chip:nth-child(2) { top: 14%; left: -6%; }
.lp-cart-chip:nth-child(3) { bottom: 22%; right: -8%; }
.lp-success-badge { position: absolute; top: 6%; right: 4%; width: 34px; height: 34px; border-radius: 50%; background: var(--lp-green); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; box-shadow: 0 10px 22px rgba(32, 163, 106, .35); }

/* inventory visual — a tiny shelf scene with a scanning beam */
.lp-shelf-scene { position: relative; width: 240px; padding: 18px; background: #fff; border: 1px solid var(--lp-line); border-radius: 20px; box-shadow: 0 16px 40px rgba(20, 20, 30, .08); perspective: 700px; }
.lp-shelf-row { display: flex; gap: 10px; margin-bottom: 14px; transform: rotateX(6deg); }
.lp-stock-chip { flex: 1; height: 34px; border-radius: 8px; background: var(--lp-greenSoft); border: 1.5px solid var(--lp-green); }
.lp-shelf-row:nth-child(2) .lp-stock-chip:last-child { background: var(--lp-orangeSoft); border-color: var(--lp-orange); }
.lp-barcode { width: 46px; height: 46px; margin: 6px auto 0; display: block; color: var(--lp-ink); opacity: .55; }
.lp-scan-line { position: absolute; left: 10%; right: 10%; height: 2px; background: linear-gradient(90deg, transparent, var(--lp-blue), transparent); top: 18px; animation: lp-scan 2.6s ease-in-out infinite; box-shadow: 0 0 10px var(--lp-blue); }
@keyframes lp-scan { 0%, 100% { top: 18px; opacity: 0; } 10% { opacity: 1; } 50% { top: calc(100% - 28px); opacity: 1; } 60% { opacity: 0; } }
.lp-low-stock { margin-top: 10px; text-align: center; font-size: 11.5px; font-weight: 700; color: var(--lp-orange); background: var(--lp-orangeSoft); border-radius: 999px; padding: 6px 12px; animation: lp-pulse 2.4s ease-in-out infinite; }
@keyframes lp-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .55; } }
@media (prefers-reduced-motion: reduce) { .lp-scan-line, .lp-low-stock { animation: none; } }

/* analytics visual — a self-drawing line chart over growing bars */
.lp-chart { width: 100%; max-width: 300px; height: auto; overflow: visible; }
.lp-chart-bar { fill: var(--lp-blue); opacity: .85; }
.lp-chart-bar:nth-child(4), .lp-chart-bar:nth-child(5) { fill: var(--lp-green); }
.lp-chart-line { stroke: #fff; }
.lp-chart-chip { position: absolute; top: -6px; right: 6px; background: var(--lp-green); color: #fff; font-size: 11.5px; font-weight: 800; border-radius: 999px; padding: 5px 12px; box-shadow: 0 8px 18px rgba(32, 163, 106, .4); }

/* ---------- how it works ---------- */
.lp-steps { display: grid; grid-template-columns: 1fr; gap: 20px; max-width: 1040px; margin: 0 auto; }
.lp-step { text-align: center; padding: 4px; }
.lp-step-num { width: 42px; height: 42px; margin: 0 auto 12px; border-radius: 50%; background: var(--lp-orangeSoft); color: var(--lp-orange); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 17px; }
.lp-step b { display: block; font-size: 14.5px; margin-bottom: 5px; color: var(--lp-ink); }
.lp-step span { display: block; font-size: 12.8px; color: var(--lp-mut); line-height: 1.6; }
@media (min-width: 700px) { .lp-steps { grid-template-columns: repeat(4, 1fr); } }

/* ---------- mobile business / showcase ---------- */
.lp-platform-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-bottom: 28px; }
.lp-platform-chip { display: flex; align-items: center; gap: 8px; background: var(--lp-blueSoft); color: var(--lp-blue); border-radius: 999px; padding: 9px 16px; font-size: 13px; font-weight: 700; }
.lp-platform-chip .licon svg { width: 16px; height: 16px; }
.lp-showcase { display: grid; grid-template-columns: 1fr; gap: 34px; max-width: 1040px; margin: 0 auto; perspective: 1100px; }
.lp-showcase-phone { text-align: center; }
.lp-showcase-phone:nth-child(odd) .phone-mock { transform: rotateY(-8deg); }
.lp-showcase-phone:nth-child(even) .phone-mock { transform: rotateY(8deg); }
.lp-showcase-phone b, .lp-showcase-phone span { display: block; margin-top: 14px; font-size: 13.5px; color: var(--lp-ink); }
@media (min-width: 700px) { .lp-showcase { grid-template-columns: repeat(3, 1fr); align-items: start; } }

/* ---------- remaining feature grid ---------- */
.lp-pillars { display: grid; grid-template-columns: 1fr; gap: 14px; max-width: 1040px; margin: 0 auto 22px; }
.lp-pillar { background: var(--lp-white); border: 1px solid var(--lp-line); border-radius: 18px; padding: 20px; box-shadow: 0 6px 20px rgba(20, 20, 30, .05); transition: transform .18s ease, box-shadow .18s ease; }
.lp-pillar:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(20, 20, 30, .08); }
.lp-pillar-icon { width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.lp-pillar-icon svg { width: 22px; height: 22px; }
.lp-pillar b { display: block; font-size: 15px; margin-bottom: 8px; color: var(--lp-ink); }
.lp-pillar ul { list-style: none; margin: 0; padding: 0; }
.lp-pillar li { position: relative; padding-left: 18px; font-size: 12.8px; color: var(--lp-mut); line-height: 1.9; }
.lp-pillar li::before { content: ''; position: absolute; left: 0; top: 9px; width: 6px; height: 6px; border-radius: 50%; background: var(--lp-line); }
.lp-extras { display: grid; grid-template-columns: 1fr; gap: 8px 20px; max-width: 1040px; margin: 0 auto; background: var(--lp-white); border: 1px solid var(--lp-line); border-radius: 18px; padding: 22px; box-shadow: 0 6px 20px rgba(20, 20, 30, .05); }
.lp-extras-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--lp-ink); padding: 5px 0; }
.lp-extras-item svg { width: 15px; height: 15px; flex: 0 0 auto; color: var(--lp-green); }
@media (min-width: 700px) { .lp-pillars { grid-template-columns: repeat(3, 1fr); } .lp-extras { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .lp-extras { grid-template-columns: repeat(3, 1fr); } }

/* ---------- why / audience / testimonials / pricing ---------- */
.lp-why { display: grid; grid-template-columns: 1fr; gap: 12px; max-width: 1040px; margin: 0 auto; }
.lp-why-item { display: flex; gap: 12px; align-items: flex-start; background: var(--lp-offwhite); border: 1px solid var(--lp-line); border-radius: 16px; padding: 16px; transition: transform .18s ease; }
.lp-why-item:hover { transform: translateY(-3px); }
.lp-why-item .lp-pillar-icon { flex: 0 0 auto; margin-bottom: 0; }
.lp-why-item b { display: block; font-size: 14px; margin-bottom: 3px; color: var(--lp-ink); }
.lp-why-item span { display: block; font-size: 12.8px; color: var(--lp-mut); line-height: 1.6; }
@media (min-width: 700px) { .lp-why { grid-template-columns: repeat(2, 1fr); } }

.lp-audience { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; max-width: 1040px; margin: 0 auto; }
.lp-audience-pill { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 700; color: var(--lp-ink); background: var(--lp-white); border: 1px solid var(--lp-line); border-radius: 999px; padding: 9px 16px 9px 12px; transition: border-color .18s ease, transform .18s ease; }
.lp-audience-pill:hover { border-color: var(--lp-green); transform: translateY(-2px); }
.lp-audience-pill svg { width: 18px; height: 18px; flex: 0 0 auto; color: var(--lp-green); }

.lp-testimonials { display: grid; grid-template-columns: 1fr; gap: 14px; margin-bottom: 16px; max-width: 1040px; margin-left: auto; margin-right: auto; }
.lp-testimonial-ghost { background: var(--lp-offwhite); border: 1.5px dashed var(--lp-line); border-radius: 16px; padding: 20px; opacity: .6; }
.lp-testimonial-ghost .gline { height: 9px; border-radius: 6px; background: var(--lp-line); margin-bottom: 9px; }
.lp-testimonial-ghost .gavatar { width: 30px; height: 30px; border-radius: 50%; background: var(--lp-line); margin-top: 14px; }
.lp-testimonials-note { display: block; width: fit-content; margin: 0 auto; text-align: center; font-size: 12.5px; font-weight: 700; color: var(--lp-mut); background: var(--lp-neutral); border-radius: 999px; padding: 9px 18px; }
@media (min-width: 700px) { .lp-testimonials { grid-template-columns: repeat(3, 1fr); } }

.lp-pricing-card { max-width: 480px; margin: 0 auto; text-align: center; background: linear-gradient(165deg, var(--lp-orangeSoft), var(--lp-white) 65%); border: 1px solid #FBD9B8; border-radius: 20px; padding: 34px 26px; box-shadow: 0 10px 30px rgba(20, 20, 30, .07); }
.lp-pricing-card b { display: block; font-family: var(--font-display); font-size: 19px; margin-bottom: 8px; color: var(--lp-ink); }
.lp-pricing-card p { font-size: 13.5px; color: var(--lp-mut); line-height: 1.7; margin-bottom: 20px; }

/* ---------- closing — the one deliberate purple band ---------- */
.lp-closing {
    text-align: center; border-radius: 28px; padding: 44px 22px 38px; color: #fff; position: relative; overflow: hidden; margin-top: 44px;
    background:
        radial-gradient(520px 280px at 88% -10%, rgba(255, 255, 255, .14), transparent),
        linear-gradient(135deg, var(--lp-purple2) 0%, var(--lp-purple) 100%);
    box-shadow: 0 20px 50px rgba(113, 56, 232, .28);
}
.lp-closing h2 { font-family: var(--font-display); font-size: 28px; font-weight: 700; letter-spacing: -.5px; margin: 0 0 10px; color: #fff; }
.lp-closing p { font-size: 14.5px; color: rgba(255, 255, 255, .88); line-height: 1.7; max-width: 560px; margin: 0 auto; }
.lp-closing-punch { font-size: 16px !important; font-weight: 800; color: #fff !important; background: rgba(255, 255, 255, .16); border-radius: 12px; padding: 10px 18px; display: inline-block; margin-top: 8px !important; }
@media (min-width: 1024px) { .lp-closing { padding: 56px 40px 48px; } .lp-closing h2 { font-size: 34px; } }

/* ---------- shared phone-mock frame ---------- */
.phone-mock { position: relative; width: 240px; max-width: 74vw; margin: 0 auto; background: #0B0B0F; border-radius: 34px; padding: 9px; box-shadow: 0 26px 60px rgba(20, 20, 30, .22), 0 4px 14px rgba(0, 0, 0, .18); }
.phone-mock::before { content: ''; position: absolute; top: 9px; left: 50%; transform: translateX(-50%); width: 60px; height: 5px; border-radius: 4px; background: rgba(255, 255, 255, .22); z-index: 1; }
.phone-mock img { display: block; width: 100%; border-radius: 25px; }
.phone-mock.sm { width: 100%; max-width: 210px; }

/* jumping to a section from the nav shouldn't tuck its heading behind the sticky nav bar */
#features, #how-it-works, #showcase, #pricing { scroll-margin-top: 66px; }

/* plain fade reveal for the simpler recolored sections (IntersectionObserver-driven) */
.reveal { opacity: 0; transform: translateY(22px); transition: opacity .7s ease, transform .7s ease; }
.reveal.is-visible { opacity: 1; transform: translateY(0); }
@media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } }
</style>
