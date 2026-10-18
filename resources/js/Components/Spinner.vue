<script setup>
defineProps({ size: { type: [Number, String], default: 18 } });
</script>

<template>
    <!-- currentColor so it always matches whatever button/text it's dropped
         into (e.g. .btn's white text) with zero color prop needed. Replaces
         a flat "..." on the handful of highest-visibility submit buttons
         (auth screens) — see docs/FIX_PLAN.md Phase 10 for why this wasn't
         retrofitted onto all ~55 "..." occurrences in one pass. -->
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" style="vertical-align:-3px">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity=".25"></circle>
        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur=".7s" repeatCount="indefinite"></animateTransform>
        </path>
    </svg>
</template>
