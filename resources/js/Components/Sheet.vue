<script setup>
// `wide` — opt-in, only for a sheet whose content genuinely benefits from
// desktop/tablet room (checkout being the main case: Khaled's explicit
// request was a wide, side-by-side, non-scrolling modal on a bigger
// screen instead of the same narrow bottom-drawer meant for a phone).
// Every other sheet (settings, pickers, short forms) is untouched — this
// is scoped per-usage, not a global behavior change to #sheet.
defineProps({ modelValue: Boolean, title: String, subtitle: String, wide: { type: Boolean, default: false } });
defineEmits(['update:modelValue']);
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" id="scrim" style="display: block" @click="$emit('update:modelValue', false)" />
        <div v-if="modelValue" id="sheet" :class="{ wide }" style="display: block">
            <div class="grab" />
            <div v-if="title" class="shttl">{{ title }}</div>
            <div v-if="subtitle" class="shsub">{{ subtitle }}</div>
            <slot />
        </div>
    </Teleport>
</template>
