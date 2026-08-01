<script setup>
import { useToast } from '@/composables/useToast';

const { message, visible } = useToast();
</script>

<template>
    <div
        id="toast"
        v-show="visible"
        style="display: block"
    >
        {{ message }}
    </div>
</template>
