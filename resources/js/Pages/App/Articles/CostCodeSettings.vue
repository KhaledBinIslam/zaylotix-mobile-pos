<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ costCodeDigitMap: Object });

const digits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

const form = useForm({
    map: Object.fromEntries(digits.map((d) => [d, props.costCodeDigitMap?.[d] || ''])),
});

const missing = computed(() => digits.filter((d) => !form.map[d]));

/** Live echo of what a cost would turn into, so the owner can check their own letters against a sticker they already have. Every code ends in Z, same as the barcodes already on the shelves. */
const sample = computed(() => {
    const cost = '1190';
    const letters = cost.split('').map((d) => form.map[d] || '?');

    return { cost, code: letters.join('') + 'Z' };
});

function save() {
    form.patch(route('app.articles.costCodeSettings.update'));
}
</script>

<template>
    <Head title="কস্ট-কোড সেটিংস" />
    <AppLayout active="stock">
        <div class="pgttl">কস্ট-কোড সেটিংস</div>
        <div class="pgsub">প্রতিটা অঙ্কের জন্য একটা অক্ষর — বারকোডে ক্রয়মূল্য এই অক্ষরগুলো দিয়ে লেখা থাকে, যাতে কাস্টমার পড়তে না পারে</div>

        <Link :href="route('app.articles.create')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← নতুন আর্টিকেলে ফিরুন</Link>

        <div v-if="missing.length" class="card" style="margin-bottom:14px;font-size:13px;color:var(--rose);font-weight:600">
            এখনো অক্ষর দেওয়া হয়নি: {{ missing.join(', ') }} — এই অঙ্কগুলো আছে এমন কোনো দাম বারকোডে বসানো যাবে না।
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="f2" style="grid-template-columns:repeat(5,1fr);gap:10px">
                <div v-for="d in digits" :key="d" class="field" style="margin:0">
                    <label>{{ d }}</label>
                    <input v-model="form.map[d]" maxlength="1" placeholder="—" style="text-transform:uppercase;text-align:center">
                </div>
            </div>
            <div v-if="form.errors.map" style="color:var(--rose);font-size:12px;margin-top:10px">{{ form.errors.map }}</div>
        </div>

        <div class="card" style="margin-bottom:14px;font-size:13px;color:var(--mut)">
            উদাহরণ: ক্রয়মূল্য <b>৳{{ sample.cost }}</b> বারকোডে যাবে <b style="letter-spacing:1px">{{ sample.code }}</b> হিসেবে।
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">সংরক্ষণ করুন</button>

        <div class="card" style="margin-top:20px;font-size:13px;color:var(--mut);line-height:1.6">
            প্রতিটা কস্ট-কোডের শেষে <b>Z</b> বসে — এটা দামের অংশ নয়, শুধু শেষ বোঝায়। তাই কোনো অঙ্কের জন্য Z দেওয়া যাবে না। দুটো অঙ্কের অক্ষরও এক হতে পারবে না — হলে বারকোড থেকে দাম ফিরে পড়ার সময় ভুল সংখ্যা আসবে। আপনার দোকানে আগে থেকে কোনো অক্ষর-পদ্ধতি চালু থাকলে সেটাই এখানে বসান, তাহলে পুরোনো স্টিকারগুলোও ঠিকভাবে পড়া যাবে। কোনো ঘর খালি রাখলে সেই অঙ্ক আছে এমন দাম বারকোডে বসানো যাবে না, তবে বাকি সব কাজ আগের মতোই চলবে।
        </div>
    </AppLayout>
</template>
