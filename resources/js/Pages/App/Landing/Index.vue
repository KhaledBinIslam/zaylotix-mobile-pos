<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ whatsappContact: String });
const { t } = useI18n();

// digits only, with country code (see SiteSettingController::updateWhatsappContact) —
// wa.me needs no '+' prefix. No admin-set number yet = button simply doesn't render,
// rather than opening a broken/empty chat.
const whatsappUrl = props.whatsappContact ? `https://wa.me/${props.whatsappContact}` : null;

const FEATURES = [
    { icon: '🧾', title: 'landing.feature.sale', desc: 'landing.feature.saleDesc' },
    { icon: '📦', title: 'landing.feature.stock', desc: 'landing.feature.stockDesc' },
    { icon: '💳', title: 'landing.feature.due', desc: 'landing.feature.dueDesc' },
    { icon: '📊', title: 'landing.feature.report', desc: 'landing.feature.reportDesc' },
    { icon: '💬', title: 'landing.feature.whatsapp', desc: 'landing.feature.whatsappDesc' },
    { icon: '📶', title: 'landing.feature.offline', desc: 'landing.feature.offlineDesc' },
];

const AUDIENCE = [
    { icon: '🛒', label: 'landing.audience.grocery' },
    { icon: '💊', label: 'landing.audience.pharmacy' },
    { icon: '🍽️', label: 'landing.audience.restaurant' },
    { icon: '👕', label: 'landing.audience.clothing' },
    { icon: '📱', label: 'landing.audience.mobile' },
    { icon: '🏬', label: 'landing.audience.supershop' },
];
</script>

<template>
    <Head :title="t('app.name')" />

    <div class="landing">
        <div class="landing-inner">
            <header class="landing-hero">
                <ZaylotixLogo :size="64" :tagline="false" />
                <div class="landing-pill">{{ t('landing.tagline') }}</div>
                <h1>{{ t('landing.heroTitle') }}</h1>
                <p>{{ t('landing.heroSubtitle') }}</p>

                <div class="landing-cta">
                    <Link :href="route('login')" class="btn">{{ t('landing.loginBtn') }}</Link>
                    <Link :href="route('signup')" class="btn ghost">{{ t('landing.signupBtn') }}</Link>
                    <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa">{{ t('landing.whatsappBtn') }}</a>
                </div>
            </header>

            <section class="landing-section">
                <h2>{{ t('landing.featuresTitle') }}</h2>
                <div class="landing-grid">
                    <div v-for="f in FEATURES" :key="f.title" class="landing-card">
                        <div class="landing-card-icon">{{ f.icon }}</div>
                        <b>{{ t(f.title) }}</b>
                        <span>{{ t(f.desc) }}</span>
                    </div>
                </div>
            </section>

            <section class="landing-section">
                <h2>{{ t('landing.audienceTitle') }}</h2>
                <div class="landing-audience">
                    <div v-for="a in AUDIENCE" :key="a.label" class="landing-audience-pill">
                        <span>{{ a.icon }}</span> {{ t(a.label) }}
                    </div>
                </div>
            </section>

            <footer class="landing-cta landing-cta-bottom">
                <Link :href="route('login')" class="btn">{{ t('landing.loginBtn') }}</Link>
                <Link :href="route('signup')" class="btn ghost">{{ t('landing.signupBtn') }}</Link>
                <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener" class="btn wa">{{ t('landing.whatsappBtn') }}</a>
            </footer>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>
