<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ shop: Object });
const { t } = useI18n();

const STEP_COUNT = 4;
const step = ref(1);
const processing = ref(false);

const isFirst = computed(() => step.value === 1);
const isLast = computed(() => step.value === STEP_COUNT);

function next() {
    if (isLast.value) return finish();
    step.value++;
}
function back() {
    if (!isFirst.value) step.value--;
}
function finish() {
    processing.value = true;
    router.post(route('app.onboarding.complete'), {}, { onFinish: () => { processing.value = false; } });
}
</script>

<template>
    <Head :title="t('onb.step1Title')" />

    <div id="onboarding">
        <div class="onb-inner">
            <div class="login-brand">
                <ZaylotixLogo :size="52" />
            </div>

            <div class="onb-dots">
                <span v-for="n in STEP_COUNT" :key="n" :class="{ on: n === step }"></span>
            </div>

            <div class="onb-card">
                <template v-if="step === 1">
                    <div class="onb-emoji">🎉</div>
                    <div class="onb-title">{{ t('onb.step1Title') }}</div>
                    <div class="onb-body">{{ shop?.name ? `${shop.name} — ` : '' }}{{ t('onb.step1Body') }}</div>
                </template>

                <template v-else-if="step === 2">
                    <div class="onb-title">{{ t('onb.step2Title') }}</div>
                    <div class="onb-body">{{ t('onb.step2Body') }}</div>
                    <div class="onb-3grid">
                        <div class="item">
                            <div class="ic">🛒</div>
                            <b>{{ t('onb.sellName') }}</b>
                            <span>{{ t('onb.sellDesc') }}</span>
                        </div>
                        <div class="item">
                            <div class="ic">📦</div>
                            <b>{{ t('onb.stockName') }}</b>
                            <span>{{ t('onb.stockDesc') }}</span>
                        </div>
                        <div class="item">
                            <div class="ic">🧾</div>
                            <b>{{ t('onb.dueName') }}</b>
                            <span>{{ t('onb.dueDesc') }}</span>
                        </div>
                    </div>
                </template>

                <template v-else-if="step === 3">
                    <div class="onb-emoji">💬</div>
                    <div class="onb-title">{{ t('onb.step3Title') }}</div>
                    <div class="onb-body">{{ t('onb.step3Body') }}</div>
                </template>

                <template v-else>
                    <div class="onb-emoji">🚀</div>
                    <div class="onb-title">{{ t('onb.step4Title') }}</div>
                    <div class="onb-body">{{ t('onb.step4Body') }}</div>
                </template>

                <div class="onb-actions">
                    <button v-if="!isFirst" class="btn ghost" @click="back">{{ t('onb.back') }}</button>
                    <button class="btn" style="flex:1" :disabled="processing" @click="next">
                        {{ processing ? '...' : (isLast ? t('onb.start') : t('onb.next')) }}
                    </button>
                </div>
            </div>

            <button v-if="!isLast" class="onb-skip" @click="finish">{{ t('onb.skip') }}</button>
        </div>
    </div>
</template>
