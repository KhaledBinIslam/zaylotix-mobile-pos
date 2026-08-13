<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ businessTypes: Array });
const { t } = useI18n();

const TYPE_ICONS = {
    supershop: '🏬', grocery: '🛒', clothing: '👕', pharmacy: '💊',
    mobile: '📱', cosmetics: '💄', general: '📦', restaurant: '🍽️',
};

const form = useForm({
    name: '', business_type_id: '', owner_name: '', phone: '', password: '', area: '',
});

function submit() {
    form.post(route('signup.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head :title="t('signup.title')" />

    <div id="login" style="display: flex">
        <div class="login-inner">
            <div class="login-brand">
                <ZaylotixLogo :size="52" />
                <div class="login-tag" style="margin-top:8px">{{ t('signup.tagline') }}</div>
            </div>

            <div class="login-card">
                <div class="lc-h">{{ t('signup.title') }}</div>

                <form @submit.prevent="submit">
                    <div class="field">
                        <label>{{ t('signup.shopName') }}</label>
                        <input v-model="form.name" autocomplete="organization">
                        <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
                    </div>

                    <div class="field">
                        <label>{{ t('signup.businessType') }}</label>
                        <div class="onb-3grid" style="grid-template-columns:repeat(3,1fr)">
                            <button
                                v-for="bt in businessTypes" :key="bt.id" type="button"
                                class="item"
                                :style="{ cursor: 'pointer', border: form.business_type_id === bt.id ? '1.5px solid var(--gold)' : '1.5px solid var(--line2)', background: form.business_type_id === bt.id ? 'var(--goldSoft)' : 'var(--greenSoft)' }"
                                @click="form.business_type_id = bt.id"
                            >
                                <div class="ic">{{ TYPE_ICONS[bt.slug] || '📦' }}</div>
                                <b>{{ bt.label_bn }}</b>
                            </button>
                        </div>
                        <div v-if="form.errors.business_type_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.business_type_id }}</div>
                    </div>

                    <div class="field">
                        <label>{{ t('signup.ownerName') }}</label>
                        <input v-model="form.owner_name" autocomplete="name">
                        <div v-if="form.errors.owner_name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.owner_name }}</div>
                    </div>

                    <div class="field">
                        <label>{{ t('signup.phone') }}</label>
                        <input v-model="form.phone" inputmode="tel" placeholder="01XXXXXXXXX" autocomplete="username">
                        <div v-if="form.errors.phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.phone }}</div>
                    </div>

                    <div class="field">
                        <label>{{ t('signup.password') }}</label>
                        <input v-model="form.password" type="password" autocomplete="new-password">
                        <div v-if="form.errors.password" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.password }}</div>
                    </div>

                    <div class="field">
                        <label>{{ t('signup.area') }}</label>
                        <input v-model="form.area">
                    </div>

                    <button class="btn" type="submit" :disabled="form.processing">
                        {{ form.processing ? '...' : t('signup.submit') }}
                    </button>
                    <div class="lc-hint">
                        {{ t('signup.hasAccount') }}<Link :href="route('login')" style="color:var(--green);font-weight:700">{{ t('signup.loginLink') }}</Link>
                    </div>
                </form>
            </div>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>
