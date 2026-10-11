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
    <!-- ?v=2 cache-busts browsers/the service worker after a logo swap --
         the filename itself never changes, so without this every existing
         visitor keeps the stale cached image until manually clearing cache.
         Bump this number any time icon-192.png's actual content changes. -->
    <img src="/icons/icon-192.png?v=2" :width="size" :height="size" alt="Zaylotix" style="display:inline-block">
</template>
