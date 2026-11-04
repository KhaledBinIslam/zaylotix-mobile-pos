<script setup>
/**
 * The shareable "install on iPhone" page — pos.zaylotix.com/ios.
 *
 * There is no APK for iOS and no sideloading: Apple only installs apps
 * through the App Store (a $99/yr developer account + a Mac + review),
 * so the honest equivalent of "apk share kore dibo" is this link. Safari's
 * Add to Home Screen gives the same thing the Android TWA gives — an icon,
 * a standalone window, offline support via the existing service worker —
 * with zero install friction. Khaled shares THIS link with iPhone users.
 *
 * Deliberately a dead-simple static page: the person reading it is mid-
 * setup on a phone, often on a shop floor.
 */
import { Head, Link } from '@inertiajs/vue3';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';

// Client-facing: whoever opens this is a shop's own iPhone user, not
// Khaled. Instructions only — no talk about who should share which link
// (his feedback: "client jabe, ora porbe, oder jettuk lagbe ettuk").
const steps = [
    { icon: '🧭', title: 'পেজটি Safari-তে খুলুন', detail: 'Chrome বা অন্য অ্যাপের ভেতরের ব্রাউজারে এই সুবিধা নেই।' },
    { icon: '⬆️', title: 'Share আইকনে ট্যাপ করুন', detail: 'স্ক্রিনের নিচে, চারকোনা বাক্স থেকে তীর ওঠা আইকন।' },
    { icon: '➕', title: '"Add to Home Screen" নির্বাচন করুন', detail: 'তারপর উপরে ডানদিকে "Add" চাপুন।' },
    { icon: '✅', title: 'ইনস্টল সম্পন্ন', detail: 'হোম স্ক্রিনের Zaylotix POS আইকন থেকে অ্যাপটি ব্যবহার করুন।' },
];
</script>

<template>
    <Head title="iPhone-এ Zaylotix POS" />
    <div style="min-height:100vh;background:var(--bg);display:flex;flex-direction:column;align-items:center;padding:28px 18px 60px">
        <div style="width:100%;max-width:430px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <ZaylotixMark :size="34" />
                <b style="font-size:17px">Zaylotix POS</b>
            </div>
            <h1 style="font-size:24px;font-weight:850;margin:14px 0 6px">iPhone-এ ইনস্টল করুন</h1>
            <p style="color:var(--mut);font-size:13.5px;line-height:1.6;margin-bottom:18px">
                চারটি ধাপ, এক মিনিটেরও কম সময়:
            </p>

            <div v-for="(s, i) in steps" :key="i" class="card" style="display:flex;gap:14px;align-items:flex-start;margin-bottom:12px">
                <div style="font-size:26px;line-height:1">{{ s.icon }}</div>
                <div>
                    <b style="display:block;margin-bottom:3px">{{ i + 1 }}. {{ s.title }}</b>
                    <span style="font-size:13px;color:var(--mut);line-height:1.55">{{ s.detail }}</span>
                </div>
            </div>

            <Link :href="route('login')" class="btn" style="text-decoration:none;display:block;text-align:center;margin-top:6px">লগইন করুন →</Link>
        </div>
    </div>
</template>
