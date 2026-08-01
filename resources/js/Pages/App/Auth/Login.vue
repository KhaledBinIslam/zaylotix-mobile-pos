<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ subscriptionExpired: Boolean });

const { t } = useI18n();

const form = useForm({
    login: '01979894356',
    password: '1234',
    remember: true,
});

function submit() {
    form.post(route('login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head :title="t('login.title')" />

    <div id="login" style="display: flex">
        <div class="login-inner">
            <div class="login-brand">
                <ZaylotixLogo :size="60" />
                <div class="login-tag" style="margin-top:8px">{{ t('login.tagline') }}</div>
            </div>

            <div class="login-card">
                <div class="lc-h">{{ t('login.welcome') }}</div>
                <div class="lc-s">{{ t('login.subtitle') }}</div>

                <div v-if="subscriptionExpired" class="card" style="background:var(--roseSoft);border-color:var(--rose);margin-bottom:14px;color:var(--rose);font-size:13px;font-weight:600">
                    {{ t('login.subscriptionExpired') }}
                </div>

                <form @submit.prevent="submit">
                    <div class="field">
                        <label>{{ t('login.idLabel') }}</label>
                        <input v-model="form.login" autocomplete="username">
                    </div>
                    <div class="field">
                        <label>{{ t('login.passwordLabel') }}</label>
                        <input v-model="form.password" type="password" autocomplete="current-password">
                    </div>
                    <div v-if="form.errors.login" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ form.errors.login }}</div>

                    <label class="remember">
                        <input v-model="form.remember" type="checkbox" style="width:auto">
                        <span>{{ t('login.remember') }}</span>
                    </label>

                    <button class="btn" type="submit" :disabled="form.processing">
                        {{ form.processing ? '...' : t('login.submit') }}
                    </button>
                    <div class="lc-hint">{{ t('login.hint') }} <b>1234</b></div>
                </form>
            </div>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product<br>
                Owner: <a href="https://khaledbinislam.com/" target="_blank" rel="noopener">Khaled Bin Islam →</a><br>
                Contact: <a href="tel:01979894356">01979894356</a>
            </div>
        </div>
    </div>
</template>
