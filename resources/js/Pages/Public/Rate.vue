<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    sale: Object,
    existing: { type: Object, default: null },
});

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const stars = ref(props.existing?.stars || 0);
const hovered = ref(0);
const submitted = ref(!!props.existing);

const form = useForm({
    stars: props.existing?.stars || 0,
    comment: props.existing?.comment || '',
});

function pick(n) {
    if (submitted.value) return;
    stars.value = n;
    form.stars = n;
}

function submit() {
    if (!form.stars) return;
    form.post(route('rate.store', props.sale.id), {
        preserveScroll: true,
        onSuccess: () => { submitted.value = true; },
    });
}

const page = usePage();
</script>

<template>
    <Head :title="'রেটিং — ' + (sale.shop_name || 'Zaylotix POS')" />

    <div id="login" style="display:flex">
        <div class="login-inner">
            <div class="login-brand">
                <img v-if="sale.shop_logo_url" :src="sale.shop_logo_url" style="max-width:64px;border-radius:12px">
                <div v-else style="font-size:40px">🍽️</div>
            </div>

            <div class="login-card" style="text-align:center">
                <div class="lc-h">{{ sale.shop_name || 'Zaylotix POS' }}</div>
                <div class="lc-s">মেমো {{ sale.invoice_no }} • {{ money(sale.total) }}</div>

                <template v-if="submitted">
                    <div style="font-size:44px;margin-top:14px">🙏</div>
                    <div class="lc-h" style="margin-top:8px;font-size:16px">ধন্যবাদ! আপনার মতামতের জন্য</div>
                    <div style="margin-top:10px;font-size:26px;letter-spacing:4px">
                        <span v-for="n in 5" :key="n" :style="{ color: n <= stars ? '#F2A61B' : 'var(--line2)' }">★</span>
                    </div>
                </template>

                <template v-else>
                    <div style="margin:18px 0 6px;font-weight:700;color:var(--mut);font-size:13.5px">আমাদের সেবা কেমন লাগলো?</div>
                    <div style="font-size:36px;letter-spacing:6px;cursor:pointer" @mouseleave="hovered = 0">
                        <span
                            v-for="n in 5" :key="n"
                            :style="{ color: n <= (hovered || stars) ? '#F2A61B' : 'var(--line2)' }"
                            @click="pick(n)" @mouseenter="hovered = n"
                        >★</span>
                    </div>

                    <textarea
                        v-model="form.comment" rows="3" placeholder="মন্তব্য (ঐচ্ছিক)"
                        style="margin-top:14px;width:100%"
                    ></textarea>

                    <button class="btn" style="margin-top:14px" :disabled="!form.stars || form.processing" @click="submit">
                        {{ form.processing ? '...' : 'জমা দিন' }}
                    </button>
                </template>
            </div>

            <div class="login-foot">
                A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800">Zaylotix →</a> product
            </div>
        </div>
    </div>
</template>
