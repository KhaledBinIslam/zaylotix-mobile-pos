<script setup>
defineProps({ size: { type: [Number, String], default: 40 } });
</script>

<template>
    <!-- The real Zaylotix mark (from assets/icon.png — the same source the
         browser favicon/PWA icon/Android app icon are all generated from),
         not a hand-redrawn approximation — every place this component is
         used (Login, Signup, Landing, AppLayout, AdminLayout, Onboarding,
         Error, Admin Login) now shows the exact same real logo. -->
    <!-- inline-block (not block) deliberately — this needs to respect a
         parent's `text-align: center` (login-brand, landing-hero) to
         actually center itself; a block-level img ignores text-align
         entirely and was sitting flush-left instead, the exact bug found
         live on the landing page. -->
    <img src="/icons/icon-192.png" :width="size" :height="size" alt="Zaylotix" style="display:inline-block">
</template>
