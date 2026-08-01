<script setup>
defineProps({ size: { type: [Number, String], default: 40 } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
        <circle cx="100" cy="100" r="100" fill="#7C3AED" />
        <g fill="none" stroke="#FFFFFF" stroke-width="26" stroke-linecap="round">
            <path d="M76 74 H128" />
            <path d="M124 78 L76 146" />
            <path d="M72 146 H124" />
        </g>
        <circle cx="128" cy="72" r="17" fill="#EC1E79" />
        <circle cx="72" cy="148" r="17" fill="#EC1E79" />
    </svg>
</template>
