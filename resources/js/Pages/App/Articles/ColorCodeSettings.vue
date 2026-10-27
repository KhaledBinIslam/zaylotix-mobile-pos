<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { suggestColorCode } from '@/support/colorCodeSuggestions';

const props = defineProps({ colorCodeMap: Object, barcodeIncludeSize: Boolean });

const form = useForm({
    rows: Object.entries(props.colorCodeMap || {}).map(([color, code]) => ({ color, code })),
    barcode_include_size: props.barcodeIncludeSize,
});

function addRow() {
    form.rows.push({ color: '', code: '' });
}
function removeRow(i) {
    form.rows.splice(i, 1);
}
/** Only suggests while the code field is still blank — never overwrites something the owner already typed or edited. */
function onColorInput(row) {
    if (!row.code) row.code = suggestColorCode(row.color);
}
function save() {
    form.patch(route('app.articles.colorCodeSettings.update'));
}
</script>

<template>
    <Head title="রং-কোড সেটিংস" />
    <AppLayout active="stock">
        <div class="pgttl">রং-কোড সেটিংস</div>
        <div class="pgsub">প্রতিটা রঙের একটা ছোট কোড — বারকোডে ব্যবহার হয় (যেমন SILVER → SIL)</div>

        <Link :href="route('app.articles.create')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← নতুন আর্টিকেলে ফিরুন</Link>

        <div class="card" style="margin-bottom:14px">
            <div v-for="(row, i) in form.rows" :key="i" class="f2" style="grid-template-columns:2fr 1fr auto;align-items:end;margin-bottom:10px">
                <div class="field" style="margin:0">
                    <label>রঙের নাম</label>
                    <input v-model="row.color" placeholder="SILVER" @input="onColorInput(row)">
                </div>
                <div class="field" style="margin:0">
                    <label>কোড</label>
                    <input v-model="row.code" placeholder="SIL" maxlength="10" style="text-transform:uppercase">
                </div>
                <button type="button" class="btn sm rose" @click="removeRow(i)">মুছুন</button>
            </div>
            <button type="button" class="btn ghost sm" @click="addRow">+ নতুন রং</button>
            <div v-if="form.errors.rows" style="color:var(--rose);font-size:12px;margin-top:10px">{{ form.errors.rows }}</div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input v-model="form.barcode_include_size" type="checkbox">
                বারকোডে সাইজও যোগ করুন
            </label>
            <div style="font-size:12px;color:var(--mut);margin-top:6px">
                বন্ধ থাকলে (ডিফল্ট) স্টিকারে শুধু রং পর্যন্ত বারকোড থাকবে — স্ক্যান করলে POS সেই রঙের স্টকে থাকা সাইজ দেখাবে, বেছে নিলেই কার্টে যাবে। চালু করলে প্রতিটা সাইজের নিজস্ব আলাদা বারকোড হবে — স্ক্যান করলে সরাসরি কার্টে যাবে, সাইজ বাছতে হবে না।
            </div>
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">সংরক্ষণ করুন</button>

        <div class="card" style="margin-top:20px;font-size:13px;color:var(--mut);line-height:1.6">
            একটা নতুন রং লিখলে কোড নিজে থেকে সাজেস্ট হবে (বদলে নিতে পারেন)। দুইটা ভিন্ন রং একই কোড নিতে পারবে না — সংরক্ষণ করার সময় মিলে গেলে স্পষ্ট বার্তা দেখাবে। একটা রঙের কোড পরে বদলালে আগে ছাপানো স্টিকার অচল হবে না — পুরোনো বারকোডও স্ক্যানে কাজ করতে থাকবে।
        </div>
    </AppLayout>
</template>
