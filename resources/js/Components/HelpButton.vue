<script setup>
import { computed, ref } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { openWhatsAppHelp } from '@/support/help';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ screenLabel: { type: String, default: '' } });
const page = usePage();
const shop = computed(() => page.props.shop);
const { t } = useI18n();

// tapping ❓ used to jump straight into WhatsApp with zero warning — a
// tech-nervous owner would tap it just to see what it does and suddenly be
// bounced out of the app. This menu tells them what each option does
// first; nothing opens WhatsApp until they explicitly choose to.
const menuOpen = ref(false);

function openWhatsApp() {
    menuOpen.value = false;
    openWhatsAppHelp(shop.value?.name, props.screenLabel);
}
</script>

<template>
    <button class="help-fab" :title="t('help.fabTitle')" @click="menuOpen = true">
        <span>❓</span>
    </button>

    <Sheet v-model="menuOpen" :title="t('help.menuTitle')">
        <Link :href="route('app.help')" class="row" @click="menuOpen = false">
            <div class="ava">📖</div>
            <div class="mid"><b>{{ t('help.menuFaq') }}</b><span>{{ t('help.menuFaqSub') }}</span></div>
            <div class="end">›</div>
        </Link>
        <button class="row" style="width:100%;text-align:left" @click="openWhatsApp">
            <div class="ava">💬</div>
            <div class="mid"><b>{{ t('help.menuWhatsapp') }}</b><span>{{ t('help.menuWhatsappSub') }}</span></div>
            <div class="end">›</div>
        </button>
    </Sheet>
</template>
