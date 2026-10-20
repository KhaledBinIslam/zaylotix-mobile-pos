<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { applySegment } from '@/support/customerSegments';
import { useWhatsappSendQueue } from '@/composables/useWhatsappSendQueue';

/**
 * "সিজন ক্যাম্পেইন কিট" — see docs/FIX_PLAN.md's design note. A campaign is
 * just a saved (promotion + segment + message) bundle; this page builds
 * nothing new for discounts or messaging — "চালু করুন" activates the
 * linked Promotion (PromotionController already owns that model), and
 * "WhatsApp পাঠান" reuses the exact same segment filter
 * (customerSegments.js) and send queue (useWhatsappSendQueue.js) that
 * CustomerList/Index.vue's bulk send already uses.
 */
const props = defineProps({ seasons: Array, promotions: Array, customers: Array });
const { t } = useI18n();
const { toast } = useToast();

const SEGMENT_KEYS = ['all', 'topSpenders', 'mostVisits', 'highestDue', 'inactive'];
function segmentLabel(key) {
    return t('customerList.segment' + key.charAt(0).toUpperCase() + key.slice(1));
}

// every campaign across every season, flattened with its season attached,
// so "no season yet" campaigns (season_id null) still show up somewhere
const campaignsFlat = computed(() => {
    const rows = [];
    for (const season of props.seasons) {
        for (const c of season.campaigns) rows.push({ ...c, season });
    }
    return rows;
});

function targetsFor(campaign) {
    return applySegment(props.customers, campaign.segment);
}

// ---------------- season create/edit ----------------
const seasonSheet = ref(false);
const editingSeason = ref(null);
const seasonForm = useForm({ name: '', tag: '', starts_at: '', expires_at: '' });
function openNewSeason() {
    editingSeason.value = null;
    seasonForm.reset();
    seasonForm.clearErrors();
    seasonSheet.value = true;
}
function openEditSeason(season) {
    editingSeason.value = season;
    seasonForm.clearErrors();
    seasonForm.name = season.name;
    seasonForm.tag = season.tag || '';
    seasonForm.starts_at = season.starts_at || '';
    seasonForm.expires_at = season.expires_at || '';
    seasonSheet.value = true;
}
function saveSeason() {
    if (editingSeason.value) {
        seasonForm.put(route('app.seasons.update', editingSeason.value.id), { onSuccess: () => (seasonSheet.value = false) });
    } else {
        seasonForm.post(route('app.seasons.store'), { onSuccess: () => (seasonSheet.value = false) });
    }
}
function removeSeason() {
    if (!editingSeason.value || !confirm(t('campaign.removeSeasonConfirm'))) return;
    router.delete(route('app.seasons.destroy', editingSeason.value.id), { onSuccess: () => (seasonSheet.value = false) });
}

// ---------------- campaign create/edit ----------------
const campaignSheet = ref(false);
const editingCampaign = ref(null);
const campaignForm = useForm({ name: '', season_id: '', promotion_id: '', segment: 'all', message_template: '' });
function openNewCampaign(seasonId = '') {
    editingCampaign.value = null;
    campaignForm.reset();
    campaignForm.clearErrors();
    campaignForm.season_id = seasonId;
    campaignForm.message_template = 'প্রিয় {name},\n\n';
    campaignSheet.value = true;
}
function openEditCampaign(c) {
    editingCampaign.value = c;
    campaignForm.clearErrors();
    campaignForm.name = c.name;
    campaignForm.season_id = c.season_id || '';
    campaignForm.promotion_id = c.promotion_id || '';
    campaignForm.segment = c.segment;
    campaignForm.message_template = c.message_template || '';
    campaignSheet.value = true;
}
function saveCampaign() {
    if (editingCampaign.value) {
        campaignForm.put(route('app.campaigns.update', editingCampaign.value.id), { onSuccess: () => (campaignSheet.value = false) });
    } else {
        campaignForm.post(route('app.campaigns.store'), { onSuccess: () => (campaignSheet.value = false) });
    }
}
function removeCampaign() {
    if (!editingCampaign.value || !confirm(t('campaign.removeConfirm'))) return;
    router.delete(route('app.campaigns.destroy', editingCampaign.value.id), { onSuccess: () => (campaignSheet.value = false) });
}

function launch(c) {
    router.post(route('app.campaigns.launch', c.id), {}, { preserveScroll: true });
}

// ---------------- WhatsApp send (reuses the shared queue as-is) ----------------
const wa = useWhatsappSendQueue('campaign');
function sendCampaign(c) {
    const targets = targetsFor(c);
    if (!targets.length) {
        toast('❌ ' + t('customerList.noPhoneSelected'));
        return;
    }
    wa.start(targets, c.message_template || `প্রিয় {name},\n\n`);
}
</script>

<template>
    <Head :title="t('nav.campaigns')" />
    <AppLayout active="campaigns">
        <div class="pgttl">{{ t('nav.campaigns') }}</div>
        <div class="pgsub">{{ t('campaign.subtitle') }}</div>

        <button class="btn ghost sm" style="margin-bottom:16px" @click="openNewSeason">➕ {{ t('campaign.addSeason') }}</button>

        <template v-for="season in seasons" :key="season.id">
            <div class="sechead">
                <h2 @click="openEditSeason(season)" style="cursor:pointer">{{ season.name }} <span v-if="season.tag" class="pill mut" style="margin-left:6px">{{ season.tag }}</span></h2>
                <span v-if="season.starts_at || season.expires_at" style="font-size:12px;color:var(--mut)">
                    {{ season.starts_at }} – {{ season.expires_at }}
                </span>
                <Link :href="route('app.seasons.stockPlan', season.id)" class="btn ghost sm" style="width:auto;padding:0 10px;margin-top:6px">📦 {{ t('seasonPlan.title') }}</Link>
            </div>

            <div v-for="c in season.campaigns" :key="c.id" class="card" style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div @click="openEditCampaign(c)" style="cursor:pointer;flex:1">
                        <b>{{ c.name }}</b>
                        <div style="font-size:12.5px;color:var(--mut);margin-top:3px">
                            🎯 {{ segmentLabel(c.segment) }} ({{ targetsFor(c).length }} {{ t('campaign.people') }})
                            <template v-if="c.promotion"> • 🎁 {{ c.promotion.name }}</template>
                        </div>
                    </div>
                </div>
                <div class="btnrow" style="margin-top:10px">
                    <button class="btn sm" style="flex:1" @click="launch(c)">▶️ {{ t('campaign.launch') }}</button>
                    <button class="btn wa sm" style="flex:1" @click="sendCampaign(c)">💬 {{ t('campaign.sendWhatsapp') }}</button>
                </div>
            </div>
            <button class="btn ghost sm" style="margin-bottom:20px" @click="openNewCampaign(season.id)">➕ {{ t('campaign.addCampaign') }}</button>
        </template>

        <div v-if="!seasons.length" class="empty"><div class="big">🎉</div>{{ t('campaign.noSeasons') }}</div>

        <!-- campaigns with no season -->
        <template v-if="campaignsFlat.some((c) => !c.season_id)">
            <div class="sechead"><h2>{{ t('campaign.noSeason') }}</h2></div>
            <div v-for="c in campaignsFlat.filter((c) => !c.season_id)" :key="c.id" class="card" style="margin-bottom:10px">
                <div @click="openEditCampaign(c)" style="cursor:pointer">
                    <b>{{ c.name }}</b>
                    <div style="font-size:12.5px;color:var(--mut);margin-top:3px">
                        🎯 {{ segmentLabel(c.segment) }} ({{ targetsFor(c).length }} {{ t('campaign.people') }})
                        <template v-if="c.promotion"> • 🎁 {{ c.promotion.name }}</template>
                    </div>
                </div>
                <div class="btnrow" style="margin-top:10px">
                    <button class="btn sm" style="flex:1" @click="launch(c)">▶️ {{ t('campaign.launch') }}</button>
                    <button class="btn wa sm" style="flex:1" @click="sendCampaign(c)">💬 {{ t('campaign.sendWhatsapp') }}</button>
                </div>
            </div>
        </template>
        <button v-if="seasons.length" class="btn ghost sm" style="margin-bottom:20px" @click="openNewCampaign()">➕ {{ t('campaign.addCampaignNoSeason') }}</button>

        <!-- season sheet -->
        <Sheet v-model="seasonSheet" :title="editingSeason ? t('campaign.editSeasonTitle') : t('campaign.addSeasonTitle')">
            <div class="field">
                <label>{{ t('campaign.seasonName') }}</label>
                <input v-model="seasonForm.name" :placeholder="t('campaign.seasonNamePlaceholder')">
                <div v-if="seasonForm.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ seasonForm.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('campaign.seasonTag') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="seasonForm.tag" :placeholder="t('campaign.seasonTagPlaceholder')">
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('promotion.startsAt') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="seasonForm.starts_at" type="date"></div>
                <div class="field"><label>{{ t('promotion.expiresAt') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="seasonForm.expires_at" type="date"></div>
            </div>
            <button class="btn" :disabled="seasonForm.processing" @click="saveSeason">{{ seasonForm.processing ? '...' : t('stock.save') }}</button>
            <button v-if="editingSeason" class="btn rose" style="margin-top:10px" @click="removeSeason">{{ t('promotion.remove') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="seasonSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- campaign sheet -->
        <Sheet v-model="campaignSheet" :title="editingCampaign ? t('campaign.editCampaignTitle') : t('campaign.addCampaignTitle')">
            <div class="field">
                <label>{{ t('campaign.campaignName') }}</label>
                <input v-model="campaignForm.name" :placeholder="t('campaign.campaignNamePlaceholder')">
                <div v-if="campaignForm.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ campaignForm.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('campaign.season') }}</label>
                <select v-model="campaignForm.season_id">
                    <option value="">{{ t('campaign.noSeason') }}</option>
                    <option v-for="s in seasons" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('campaign.promotion') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <select v-model="campaignForm.promotion_id">
                    <option value="">{{ t('campaign.messageOnly') }}</option>
                    <option v-for="p in promotions" :key="p.id" :value="p.id">{{ p.name }}{{ p.code ? ' (' + p.code + ')' : '' }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('campaign.segment') }}</label>
                <select v-model="campaignForm.segment">
                    <option v-for="key in SEGMENT_KEYS" :key="key" :value="key">{{ segmentLabel(key) }}</option>
                </select>
                <div v-if="campaignForm.errors.segment" style="color:var(--rose);font-size:12px;margin-top:6px">{{ campaignForm.errors.segment }}</div>
            </div>
            <div class="field">
                <label>{{ t('customerList.messageLabel') }}</label>
                <textarea v-model="campaignForm.message_template" rows="4" :placeholder="t('customerList.messagePlaceholder')"></textarea>
                <div style="font-size:11.5px;color:var(--mut);margin-top:4px">{{ t('customerList.templateVarsHint') }}</div>
            </div>
            <button class="btn" :disabled="campaignForm.processing" @click="saveCampaign">{{ campaignForm.processing ? '...' : t('stock.save') }}</button>
            <button v-if="editingCampaign" class="btn rose" style="margin-top:10px" @click="removeCampaign">{{ t('promotion.remove') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="campaignSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- the shared "send to next" WhatsApp queue sheet — identical markup to CustomerList/Index.vue's -->
        <Sheet v-model="wa.sheet.value" :title="t('customerList.queueSheetTitle')">
            <div v-if="wa.skippedInvalid.value.length" class="card" style="margin-bottom:12px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                {{ t('customerList.queueSkippedInvalid', { n: wa.skippedInvalid.value.length }) }}
            </div>
            <div class="card" style="margin-bottom:12px;font-size:12.5px;color:var(--mut)">{{ t('customerList.queueSpamWarning') }}</div>
            <div v-if="wa.showPauseReminder.value" class="card" style="margin-bottom:12px;background:var(--goldSoft);font-size:12.5px">⏸️ {{ t('customerList.queuePauseReminder') }}</div>
            <div style="font-size:13px;color:var(--mut);margin-bottom:10px">{{ t('customerList.queueProgress', { sent: wa.sent.value, total: wa.total.value }) }}</div>
            <template v-if="!wa.done.value">
                <div class="card" style="margin-bottom:14px">
                    <b>{{ wa.current.value?.name }}</b>
                    <div style="font-size:13px;color:var(--mut)">📞 {{ wa.current.value?.phone }}</div>
                </div>
                <template v-if="wa.awaitingConfirmId.value">
                    <div class="card" style="margin-bottom:8px;font-size:12.5px;color:var(--mut)">{{ t('customerList.queueAwaitingConfirm') }}</div>
                    <button class="btn" style="margin-bottom:8px" @click="wa.confirmSent()">✓ {{ t('customerList.queueConfirmSent') }}</button>
                </template>
                <button v-else class="btn wa" style="margin-bottom:8px" @click="wa.sendToCurrent()">
                    💬 {{ t('customerList.queueSendNext', { current: wa.sent.value + wa.skipped.value + 1, total: wa.total.value }) }}
                </button>
                <button class="btn ghost sm" style="margin-bottom:8px" @click="wa.skipCurrent()">{{ t('customerList.queueSkip') }}</button>
            </template>
            <div v-else class="card" style="margin-bottom:14px;background:var(--goldSoft)">
                ✅ {{ t('customerList.queueDoneSummary', { sent: wa.sent.value, skipped: wa.skipped.value }) }}
            </div>
            <button v-if="wa.lastConfirmedId.value" class="btn ghost sm" style="margin-bottom:8px" @click="wa.undoLastConfirm()">↩️ {{ t('customerList.queueUndoLastSent') }}</button>
            <button class="btn ghost" @click="wa.close()">{{ wa.done.value ? t('common.close') : t('customerList.queueStop') }}</button>
        </Sheet>
    </AppLayout>
</template>
