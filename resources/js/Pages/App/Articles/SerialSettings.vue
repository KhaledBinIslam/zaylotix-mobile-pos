<script setup>
/**
 * Where each category's article numbers begin.
 *
 * Khaled files by range, not by one running counter: slippers 90000, ladies
 * 10000, baby 20000, pumpi 50000, ladies-cat 30000 — the number itself tells
 * him what the shoe is. Every category sits on one screen because the real
 * question is "which band is still free", which no single-category form can
 * answer.
 *
 * Two categories may share a code on purpose (Slippers and Ladies are both
 * L here), so this page warns when two such bands sit close enough to run
 * into each other — it never refuses. The owner's filing scheme wins; the
 * page's job is to make the consequence visible before it bites.
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ rows: Array, defaultStart: Number });

const form = useForm({
    starts: Object.fromEntries(props.rows.map((r) => [r.id, r.serial_start])),
});

/** Bands on one code draw from one pool of names, so two that are close will eventually collide. 1000 apart is the point where that stops being a near-term worry. */
const clashes = computed(() => {
    const byCode = {};
    for (const row of props.rows) {
        const code = (row.code || '').trim().toUpperCase();
        if (!code) continue;
        const start = Number(form.starts[row.id]) || props.defaultStart;
        (byCode[code] ||= []).push({ name: row.name, start });
    }
    const out = [];
    for (const [code, list] of Object.entries(byCode)) {
        if (list.length < 2) continue;
        const sorted = [...list].sort((a, b) => a.start - b.start);
        for (let i = 1; i < sorted.length; i++) {
            if (sorted[i].start - sorted[i - 1].start < 1000) {
                out.push(`${code}: "${sorted[i - 1].name}" (${sorted[i - 1].start}) আর "${sorted[i].name}" (${sorted[i].start})`);
            }
        }
    }
    return out;
});

function save() {
    form.patch(route('app.articles.serialSettings.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="সিরিয়াল রেঞ্জ" />
    <AppLayout active="stock">
        <div class="pgttl">সিরিয়াল রেঞ্জ</div>
        <div class="pgsub">কোন ক্যাটাগরির আর্টিকেল নম্বর কত থেকে শুরু হবে</div>

        <Link :href="route('app.articles.create')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← নতুন আর্টিকেলে ফিরুন</Link>

        <div class="card" style="margin-bottom:14px;font-size:13px;color:var(--mut);line-height:1.6">
            নম্বর দেখেই যেন বোঝা যায় জিনিসটা কী — যেমন <b>স্লিপার ৯০০০০</b>, <b>লেডিস ১০০০০</b>, <b>বেবি ২০০০০</b>, <b>পাম্পি ৫০০০০</b>, <b>লেডিস ক্যাট ৩০০০০</b>।
            এখানে শুরুর নম্বরটা বসিয়ে দিলে ওই ক্যাটাগরির নতুন আর্টিকেল সেখান থেকেই নম্বর পাবে।
            <br><br>
            ঘর <b>খালি</b> রাখলে আগের মতোই {{ defaultStart }} থেকে চলবে। <b>আগের আর্টিকেলের নম্বর কখনো বদলায় না</b> — এটা শুধু এর পরের নতুন আর্টিকেলের জন্য।
        </div>

        <div v-if="clashes.length" class="card" style="margin-bottom:14px;border-left:3px solid var(--gold2);background:var(--goldSoft);font-size:13px;line-height:1.6">
            ⚠️ একই কোডের দুটো রেঞ্জ খুব কাছাকাছি — কিছুদিন পর নম্বর একটার সাথে আরেকটা লেগে যেতে পারে:
            <div v-for="c in clashes" :key="c" style="margin-top:6px"><b>{{ c }}</b></div>
            <div style="margin-top:8px;color:var(--mut)">সমস্যা হবে না যদি দুটোর মাঝে অন্তত ১০০০ ফাঁক রাখেন। চাইলে এভাবেই রাখতে পারেন — সেভ আটকাবে না।</div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div v-for="row in rows" :key="row.id" style="padding:12px 0;border-bottom:1px solid var(--line)">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <div style="flex:1;min-width:150px">
                        <div style="font-weight:700">{{ row.name }}</div>
                        <div style="font-size:12px;color:var(--mut)">
                            কোড: <b>{{ row.code || '—' }}</b>
                            <span v-if="row.article_count"> · {{ row.article_count }}টা আর্টিকেল ({{ row.lowest }}–{{ row.highest }})</span>
                            <span v-else> · এখনো কোনো আর্টিকেল নেই</span>
                        </div>
                    </div>
                    <div class="field" style="margin:0;width:150px">
                        <input v-model.number="form.starts[row.id]" type="number" min="1" :placeholder="String(defaultStart)">
                    </div>
                </div>
                <!-- the form's own answer for this category, straight from
                     ArticleSerial — so what this page promises and what the
                     entry screen prefills can never drift apart -->
                <div v-if="row.next_free" style="font-size:12px;color:var(--brand);margin-top:6px">
                    পরের আর্টিকেল পাবে: <b>{{ row.code }}-{{ row.next_free }}</b>
                    <span v-if="form.starts[row.id] !== row.serial_start" style="color:var(--mut)"> (সেভ করার পর নতুন রেঞ্জ অনুযায়ী বদলাবে)</span>
                </div>
                <div v-else-if="!row.code" style="font-size:12px;color:var(--rose);margin-top:6px">
                    এই ক্যাটাগরির কোনো কোড নেই, তাই রেঞ্জ কাজ করবে না — নতুন আর্টিকেল পেজে এটাকে একটা কোড (যেমন L) দিয়ে নিন।
                </div>
            </div>
            <div v-if="!rows.length" style="color:var(--mut);font-size:13px">এখনো কোনো ক্যাটাগরি নেই।</div>
        </div>

        <div v-if="Object.keys(form.errors).length" class="card" style="margin-bottom:12px;border-left:3px solid var(--rose);color:var(--rose);font-size:13px">
            <b>সংরক্ষণ হয়নি</b> — {{ Object.values(form.errors)[0] }}
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">
            {{ form.processing ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন' }}
        </button>
    </AppLayout>
</template>
