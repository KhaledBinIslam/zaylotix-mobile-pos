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

const steps = [
    { icon: '🧭', title: 'Safari-তে খুলুন', detail: 'এই পেজটা iPhone-এর Safari ব্রাউজারে খুলতে হবে। Chrome বা Facebook-এর ভেতরের ব্রাউজার থেকে হবে না — লিংকটা কপি করে Safari-তে পেস্ট করুন।' },
    { icon: '⬆️', title: 'Share বাটনে চাপ দিন', detail: 'স্ক্রিনের নিচে (বা উপরে) চারকোনা বাক্স থেকে তীর বের হওয়া আইকনটা — ওটাই Share।' },
    { icon: '➕', title: '"Add to Home Screen" বাছুন', detail: 'তালিকা একটু নিচে নামলে "Add to Home Screen" পাবেন। চাপ দিন, তারপর ডানদিকে উপরে "Add"।' },
    { icon: '✅', title: 'হয়ে গেল', detail: 'হোম স্ক্রিনে Zaylotix POS-এর আইকন চলে এসেছে — অ্যাপের মতোই আলাদা উইন্ডোতে খুলবে, লগইন মনে রাখবে।' },
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
            <h1 style="font-size:24px;font-weight:850;margin:14px 0 6px">iPhone-এ অ্যাপ বসান</h1>
            <p style="color:var(--mut);font-size:13.5px;line-height:1.6;margin-bottom:18px">
                iPhone-এ আলাদা কিছু ডাউনলোড করতে হয় না — Safari থেকে এক মিনিটে হোম স্ক্রিনে বসিয়ে নিলেই এটা অ্যাপের মতো চলে। নিচের ৪টা ধাপ:
            </p>

            <div v-for="(s, i) in steps" :key="i" class="card" style="display:flex;gap:14px;align-items:flex-start;margin-bottom:12px">
                <div style="font-size:26px;line-height:1">{{ s.icon }}</div>
                <div>
                    <b style="display:block;margin-bottom:3px">{{ i + 1 }}. {{ s.title }}</b>
                    <span style="font-size:13px;color:var(--mut);line-height:1.55">{{ s.detail }}</span>
                </div>
            </div>

            <div class="card" style="background:var(--greenSoft);border-color:var(--green);font-size:13px;line-height:1.6;margin-bottom:18px">
                💡 দোকানের সবাইকে শুধু এই লিংকটা পাঠালেই হবে: <b>pos.zaylotix.com/ios</b>
            </div>

            <Link :href="route('login')" class="btn" style="text-decoration:none;display:block;text-align:center">লগইন পেজে যান →</Link>
        </div>
    </div>
</template>
