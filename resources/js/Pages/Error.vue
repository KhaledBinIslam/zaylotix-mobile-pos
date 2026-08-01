<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';

const props = defineProps({
    status: Number,
    message: { type: String, default: null },
});

const info = computed(() => {
    return {
        404: { icon: '🔍', title: 'পাওয়া যায়নি', hint: 'যা খুঁজছেন সেটা মুছে ফেলা হয়েছে অথবা ভুল লিংক।' },
        403: { icon: '🔒', title: 'অনুমতি নেই', hint: 'এই কাজটি করার অনুমতি আপনার নেই।' },
        419: { icon: '⏳', title: 'সেশনের মেয়াদ শেষ', hint: 'অনেকক্ষণ ধরে কোনো কাজ হয়নি, তাই আবার লগইন করতে হবে।' },
        429: { icon: '🐢', title: 'অনেক চেষ্টা হয়ে গেছে', hint: 'একটু অপেক্ষা করে আবার চেষ্টা করুন।' },
        500: { icon: '⚠️', title: 'সার্ভারে সমস্যা হয়েছে', hint: 'আমাদের পক্ষ থেকে একটা সমস্যা হয়েছে। একটু পর আবার চেষ্টা করুন।' },
        503: { icon: '🛠️', title: 'সাময়িক রক্ষণাবেক্ষণ চলছে', hint: 'অ্যাপটি এখন কিছুক্ষণের জন্য বন্ধ আছে। একটু পর আবার আসুন।' },
    }[props.status] || { icon: '⚠️', title: 'কিছু একটা সমস্যা হয়েছে', hint: 'আবার চেষ্টা করুন, সমস্যা থাকলে দোকান/এডমিনের সাথে যোগাযোগ করুন।' };
});

function goBack() {
    if (props.status === 419) {
        window.location.href = '/login';
        return;
    }
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/';
    }
}
</script>

<template>
    <Head :title="info.title" />

    <div id="login" style="display:flex">
        <div class="login-inner">
            <div class="login-brand">
                <ZaylotixLogo :size="60" />
            </div>

            <div class="login-card" style="text-align:center">
                <div style="font-size:46px;line-height:1">{{ info.icon }}</div>
                <div class="lc-h" style="margin-top:10px">{{ info.title }}</div>
                <div class="lc-s">{{ message || info.hint }}</div>

                <button class="btn" style="margin-top:18px" @click="goBack">
                    {{ status === 419 ? '🔓 আবার লগইন করুন' : '⬅️ ফিরে যান' }}
                </button>

                <div style="margin-top:14px;font-size:11px;color:var(--dim)" v-if="status">এরর কোড: {{ status }}</div>
            </div>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product<br>
                সমস্যা লেগেই থাকলে যোগাযোগ করুন: <a href="tel:01979894356">01979894356</a>
            </div>
        </div>
    </div>
</template>
