<script setup>
defineProps({ size: { type: [Number, String], default: 40 } });
</script>

<template>
    <!-- The real Zaylotix mark (from assets/icon.png — the same source the
         browser favicon/PWA icon/Android app icon are all generated from),
         not a hand-redrawn approximation — every place this component is
         used (Login, Signup, Landing, AppLayout, AdminLayout, Onboarding,
         Error, Admin Login) now shows the exact same real logo. -->
    <img src="/icons/icon-192.png" :width="size" :height="size" alt="Zaylotix" style="display:block">
</template>
