<script setup>
defineProps({ modelValue: Boolean, title: String, subtitle: String });
defineEmits(['update:modelValue']);
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" id="scrim" style="display: block" @click="$emit('update:modelValue', false)" />
        <div v-if="modelValue" id="sheet" style="display: block">
            <div class="grab" />
            <div v-if="title" class="shttl">{{ title }}</div>
            <div v-if="subtitle" class="shsub">{{ subtitle }}</div>
            <slot />
        </div>
    </Teleport>
</template>
