<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ groups: Array, isCustomized: Boolean, categories: Array, selectedCategoryIds: Array, savedCategoryCodes: Array });

const form = useForm({
    groups: props.groups.map((g) => ({ ...g })),
    category_ids: [...(props.selectedCategoryIds || [])],
});

function toggleCategory(id) {
    const i = form.category_ids.indexOf(id);
    if (i === -1) form.category_ids.push(id);
    else form.category_ids.splice(i, 1);
}

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

        <!--
            The checkboxes below are FORM state, which survives a refused
            save — so a tick on screen never proved a tick in the database.
            This line is the server's own answer, re-read on every page
            load, so the two visibly disagree when a save did not go
            through instead of looking identical.
        -->
        <div class="card" style="margin-bottom:14px;border-left:3px solid var(--brand)">
            <div v-if="savedCategoryCodes && savedCategoryCodes.length" style="font-size:13px">
                এখন <b>সংরক্ষিত</b> আছে: <b style="color:var(--brand)">{{ savedCategoryCodes.join(', ') }}</b> — এই কোডের ক্যাটাগরিগুলোতে গ্রুপ-দাম চলছে।
            </div>
            <div v-else style="font-size:13px;color:var(--rose)">
                এখনো <b>কোনো ক্যাটাগরিতেই</b> গ্রুপ-দাম সংরক্ষিত নেই। নিচে টিক দিয়ে <b>সংরক্ষণ করুন</b> চাপতে হবে — শুধু টিক দিলে হবে না, আর সাইজ রেঞ্জে ভুল থাকলে সেভ আটকে যায় (এরর নিচে দেখাবে)।
            </div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div style="font-weight:700;margin-bottom:4px">কোন ক্যাটাগরিতে এই গ্রুপ-দাম চলবে?</div>
            <div style="font-size:12px;color:var(--mut);margin-bottom:10px">
                টিক দেওয়া ক্যাটাগরির আর্টিকেলে একটা দামের বদলে নিচের প্রতিটা গ্রুপের জন্য আলাদা দামের ঘর আসবে। কোনোটাই টিক না দিলে আগের মতো শুধু "বাচ্চাদের জুতা"/"Kids' Shoes" নামের ক্যাটাগরিতে কাজ করবে।
            </div>
            <label v-for="c in categories" :key="c.id" style="display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer">
                <input type="checkbox" :checked="form.category_ids.includes(c.id)" @change="toggleCategory(c.id)">
                {{ c.name }}<span v-if="c.name_en && c.name_en !== c.name" style="color:var(--mut)"> ({{ c.name_en }})</span>
            </label>
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

        <!-- a refused save used to show only inside the ranges card, far
             below where the button is, so it was easy to miss entirely -->
        <div v-if="Object.keys(form.errors).length" class="card" style="margin-bottom:12px;border-left:3px solid var(--rose);color:var(--rose);font-size:13px">
            <b>সংরক্ষণ হয়নি</b> — {{ Object.values(form.errors)[0] }}
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">সংরক্ষণ করুন</button>

        <div class="card" style="margin-top:20px;font-size:13px;color:var(--mut);line-height:1.6">
            <b>দাম এখানে দিতে হয় না</b> — এখানে শুধু কোন সাইজ কোন গ্রুপে পড়বে সেটা ঠিক করা হয়, একবারই। দাম প্রতিটা আর্টিকেলের নিজের, তাই সেটা "নতুন আর্টিকেল" পেজে ওই আর্টিকেল তোলার সময় গ্রুপ অনুযায়ী আলাদা ঘরে বসাবেন।<br><br>
            প্রতিটা গ্রুপের সাইজ রেঞ্জ (ছোট–বড়) একে অপরের সাথে মিলতে পারবে না — যেমন Small 21–28 হলে Medium 29 থেকে শুরু করতে হবে, 28 বা তার আগে থেকে নয়। নতুন আর্টিকেল যোগ করার সময় এই রেঞ্জ অনুযায়ী প্রতিটা সাইজের ভ্যারিয়েন্ট স্বয়ংক্রিয়ভাবে সঠিক দামের গ্রুপে পড়বে।
        </div>
    </AppLayout>
</template>
