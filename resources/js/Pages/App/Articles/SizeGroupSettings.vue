<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ groups: Array, isCustomized: Boolean });

const form = useForm({
    groups: props.groups.map((g) => ({ ...g })),
});

function addGroup() {
    form.groups.push({ label: '', min_size: 0, max_size: 0 });
}
function removeGroup(i) {
    form.groups.splice(i, 1);
}
function save() {
    form.patch(route('app.articles.sizeGroupSettings.update'));
}
</script>

<template>
    <Head title="সাইজ-গ্রুপ সেটিংস" />
    <AppLayout active="stock">
        <div class="pgttl">সাইজ-গ্রুপ সেটিংস</div>
        <div class="pgsub">বাচ্চাদের জুতার Small/Medium/Large সাইজ রেঞ্জ — আপনার দোকানের মতো করে বদলে নিন</div>

        <Link :href="route('app.articles.create')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← নতুন আর্টিকেলে ফিরুন</Link>

        <div v-if="!isCustomized" class="card" style="margin-bottom:14px;font-size:13px;color:var(--mut)">
            এখনো ডিফল্ট রেঞ্জ ব্যবহার হচ্ছে। নিচে বদলে "সংরক্ষণ করুন" চাপলে আপনার দোকানের নিজস্ব রেঞ্জ সেট হয়ে যাবে।
        </div>

        <div class="card" style="margin-bottom:14px">
            <div v-for="(g, i) in form.groups" :key="i" class="f2" style="grid-template-columns:2fr 1fr 1fr auto;align-items:end;margin-bottom:10px">
                <div class="field" style="margin:0">
                    <label>গ্রুপের নাম</label>
                    <input v-model="g.label" placeholder="Small">
                </div>
                <div class="field" style="margin:0">
                    <label>ছোট সাইজ</label>
                    <input v-model.number="g.min_size" type="number" min="0">
                </div>
                <div class="field" style="margin:0">
                    <label>বড় সাইজ</label>
                    <input v-model.number="g.max_size" type="number" min="0">
                </div>
                <button type="button" class="btn sm rose" @click="removeGroup(i)">মুছুন</button>
            </div>
            <button type="button" class="btn ghost sm" @click="addGroup">+ নতুন গ্রুপ</button>
            <div v-if="form.errors.groups" style="color:var(--rose);font-size:12px;margin-top:10px">{{ form.errors.groups }}</div>
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">সংরক্ষণ করুন</button>

        <div class="card" style="margin-top:20px;font-size:13px;color:var(--mut);line-height:1.6">
            প্রতিটা গ্রুপের সাইজ রেঞ্জ (ছোট–বড়) একে অপরের সাথে মিলতে পারবে না — যেমন Small 21–28 হলে Medium 29 থেকে শুরু করতে হবে, 28 বা তার আগে থেকে নয়। নতুন আর্টিকেল যোগ করার সময় এই রেঞ্জ অনুযায়ী প্রতিটা সাইজের ভ্যারিয়েন্ট স্বয়ংক্রিয়ভাবে সঠিক দামের গ্রুপে পড়বে।
        </div>
    </AppLayout>
</template>
