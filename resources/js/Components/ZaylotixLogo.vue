<script setup>
import ZaylotixMark from '@/Components/ZaylotixMark.vue';

defineProps({
    size: { type: [Number, String], default: 56 },
    tagline: { type: Boolean, default: true },
});
</script>

<template>
    <div style="text-align:center">
        <ZaylotixMark :size="size" />
        <div style="font-weight:850;letter-spacing:-.5px;margin-top:8px;font-size:1.4em">
            <span style="color:#7C3AED">Zaylotix</span>
        </div>
        <div v-if="tagline" style="font-size:12px;color:#8A94A6;font-weight:600;letter-spacing:.2px">Where Business Clicks</div>
    </div>
</template>
