<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    shop: Object,
    products: Array,
});

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function waLink(product = null) {
    const msg = product
        ? `বিসমিল্লাহ। আমি *${product.name}* (${money(product.price)}) নিতে চাই।`
        : `বিসমিল্লাহ। আমি ${props.shop.name}-এর ক্যাটালগ দেখে অর্ডার করতে চাই।`;
    return 'https://wa.me/' + props.shop.whatsapp_number + '?text=' + encodeURIComponent(msg);
}
</script>

<template>
    <Head :title="shop.name + ' — ক্যাটালগ'" />

    <div style="max-width:560px;margin:0 auto;padding:16px 16px 40px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <img v-if="shop.logo_url" :src="shop.logo_url" style="width:52px;height:52px;border-radius:12px;object-fit:cover">
            <div v-else style="font-size:40px">🏪</div>
            <div>
                <div style="font-size:19px;font-weight:850">{{ shop.name }}</div>
                <div v-if="shop.area" style="font-size:12.5px;color:var(--mut)">📍 {{ shop.area }}</div>
            </div>
        </div>

        <a v-if="shop.whatsapp_number" class="btn wa" style="margin-bottom:18px;display:block;text-align:center" :href="waLink()" target="_blank" rel="noopener">
            💬 WhatsApp-এ অর্ডার করুন
        </a>

        <div v-if="!products.length" class="empty"><div class="big">📦</div>এখনো কোনো পণ্য যোগ করা হয়নি</div>

        <div v-for="p in products" :key="p.id" class="card" style="margin-bottom:10px;display:flex;align-items:center;gap:12px">
            <img v-if="p.photo_url" :src="p.photo_url" style="width:48px;height:48px;border-radius:10px;object-fit:cover;flex-shrink:0">
            <div v-else style="font-size:30px;flex-shrink:0">{{ p.emoji || '📦' }}</div>
            <div style="flex:1;min-width:0">
                <b>{{ p.name }}</b>
                <div style="font-size:13.5px;margin-top:2px">
                    <b :style="p.original_price ? 'color:var(--rose)' : ''">{{ money(p.price) }}</b>
                    <span v-if="p.original_price" style="color:var(--dim);text-decoration:line-through;margin-left:6px">{{ money(p.original_price) }}</span>
                    <span v-if="!p.in_stock" class="pill rose" style="margin-left:8px;font-size:11px">স্টক শেষ</span>
                </div>
            </div>
            <a v-if="shop.whatsapp_number && p.in_stock" class="btn wa sm" :href="waLink(p)" target="_blank" rel="noopener" style="flex-shrink:0">💬</a>
        </div>

        <div style="text-align:center;margin-top:20px;font-size:11.5px;color:var(--dim)">
            A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:var(--purple);font-weight:800">Zaylotix →</a> product
        </div>
    </div>
</template>
