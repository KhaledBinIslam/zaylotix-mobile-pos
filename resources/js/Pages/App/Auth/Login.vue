<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';
import Spinner from '@/Components/Spinner.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ subscriptionExpired: Boolean });

const { t } = useI18n();
/** Off by default — someone can be standing at the counter. */
const showPassword = ref(false);

const form = useForm({
    login: '',
    password: '',
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
                        <!-- Khaled: a shopkeeper typing a password on a
                             phone keyboard cannot tell a typo from a
                             wrong password, and gets locked out guessing
                             which it was. -->
                        <div style="position:relative">
                            <input
                                v-model="form.password" :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password" style="padding-right:46px"
                            >
                            <button
                                type="button"
                                :aria-label="t(showPassword ? 'login.hidePassword' : 'login.showPassword')"
                                style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;font-size:18px;line-height:1;padding:6px;cursor:pointer"
                                @click="showPassword = !showPassword"
                            >{{ showPassword ? '🙈' : '👁' }}</button>
                        </div>
                    </div>
                    <div v-if="form.errors.login" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ form.errors.login }}</div>

                    <label class="remember">
                        <input v-model="form.remember" type="checkbox">
                        <span>{{ t('login.remember') }}</span>
                    </label>

                    <button class="btn" type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" /><span v-else>{{ t('login.submit') }}</span>
                    </button>
                    <div class="lc-hint">{{ t('login.noAccount') }}<Link :href="route('signup')" style="color:var(--green);font-weight:700">{{ t('login.signupLink') }}</Link></div>
                </form>
            </div>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:var(--purple);font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>
