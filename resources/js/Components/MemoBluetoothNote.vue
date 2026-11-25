<script setup>
// The line under the receipt buttons that says what the Bluetooth print did,
// plus the one thing that makes the share list come up empty: the helper app
// not being installed on THIS phone. Persistent text, not a toast — a toast
// fades before anyone can read it.
defineProps({ status: { type: String, default: '' } });
</script>

<template>
    <div class="no-print" style="margin-top:8px;font-size:12px;line-height:1.5;color:var(--mut);text-align:center">
        <div v-if="status" style="color:var(--text);font-weight:600;margin-bottom:4px">{{ status }}</div>
        Share তালিকায় <b>Zaylotix Printer</b> না এলে এই ফোনে অ্যাপটি নেই —
        <a href="/downloads/zaylotix-printer.apk" style="color:var(--purple);font-weight:800">অ্যাপ ডাউনলোড</a>
        করে ইনস্টল করুন।
    </div>
</template>
