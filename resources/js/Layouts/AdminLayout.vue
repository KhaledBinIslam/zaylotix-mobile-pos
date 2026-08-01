<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Toast from '@/Components/Toast.vue';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';
import { useToast } from '@/composables/useToast';

defineProps({ active: String });

const page = usePage();
const { toast } = useToast();
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const isSuperAdmin = computed(() => page.props.auth?.admin?.role === 'super_admin');
watch(() => page.props.flash?.success, (msg) => { if (msg) toast(msg); });

const mobileOpen = ref(false);

function logout() {
    if (!confirm('Log out of the admin panel?')) return;
    router.post(route('admin.logout'));
}

const nav = computed(() => [
    { key: 'dashboard', label: 'Dashboard', icon: '📊', route: 'admin.dashboard', show: true },
    { key: 'shops', label: 'Shops', icon: '🏬', route: 'admin.shops.index', show: true },
    { key: 'analytics', label: 'Analytics', icon: '📈', route: 'admin.analytics.index', show: true },
    { key: 'subscriptions', label: 'Subscriptions', icon: '💳', route: 'admin.subscriptions.index', show: true },
    { key: 'businessTypes', label: 'Business Types', icon: '🗂️', route: 'admin.businessTypes.index', show: isSuperAdmin.value },
    { key: 'features', label: 'Features', icon: '🧩', route: 'admin.features.index', show: isSuperAdmin.value },
    { key: 'activityLog', label: 'Activity Log', icon: '📋', route: 'admin.activityLog.index', show: true },
    { key: 'systemHealth', label: 'System Health', icon: '🩺', route: 'admin.systemHealth.index', show: true },
    { key: 'admins', label: 'Admin Accounts', icon: '🔑', route: 'admin.admins.index', show: isSuperAdmin.value },
    { key: 'siteSettings', label: 'Site Settings', icon: '🖼️', route: 'admin.siteSettings.edit', show: isSuperAdmin.value },
    { key: 'backups', label: 'Backups', icon: '💾', route: 'admin.backups.index', show: isSuperAdmin.value },
].filter((i) => i.show));
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900 flex">
        <!-- sidebar -->
        <aside class="w-64 shrink-0 bg-white border-r flex flex-col fixed inset-y-0 left-0 z-30 -translate-x-full lg:translate-x-0 transition-transform" :class="{ 'translate-x-0': mobileOpen }">
            <div class="p-5 border-b flex items-center gap-2">
                <img v-if="platformLogoUrl" :src="platformLogoUrl" class="w-[34px] h-[34px] object-contain rounded-lg shrink-0">
                <ZaylotixMark v-else :size="34" />
                <div>
                    <div class="font-extrabold text-base leading-tight" style="color:#7C3AED">Zaylotix <span style="color:#F0369B">POS</span></div>
                    <div class="text-[11px] text-gray-400 leading-tight">Admin Panel</div>
                </div>
            </div>

            <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
                <Link
                    v-for="item in nav" :key="item.key"
                    :href="route(item.route)"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium"
                    :class="active === item.key ? 'bg-violet-50 text-violet-700' : 'text-gray-600 hover:bg-gray-100'"
                >
                    <span class="text-lg">{{ item.icon }}</span> {{ item.label }}
                </Link>
            </nav>

            <div class="p-3 border-t">
                <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-rose-600 hover:bg-rose-50" @click="logout">
                    <span class="text-lg">🚪</span> Log out
                </button>
            </div>

            <div class="p-4 border-t text-center text-[11px] text-gray-400">
                A <a href="https://zaylotix.com/" target="_blank" class="font-semibold" style="color:#7C3AED">Zaylotix</a> product<br>
                Owner: <a href="https://khaledbinislam.com/" target="_blank" class="font-semibold text-gray-600">Khaled Bin Islam</a>
            </div>
        </aside>

        <!-- mobile overlay -->
        <div v-if="mobileOpen" class="fixed inset-0 bg-black/40 z-20 lg:hidden" @click="mobileOpen = false" />

        <!-- main -->
        <div class="flex-1 lg:ml-64">
            <header class="bg-white border-b sticky top-0 z-10 flex items-center gap-3 px-4 py-3 lg:hidden">
                <button class="text-xl" @click="mobileOpen = true">☰</button>
                <div class="font-bold" style="color:#7C3AED">Zaylotix POS Admin</div>
            </header>

            <main class="max-w-6xl mx-auto px-4 py-6">
                <slot />
            </main>
        </div>

        <Toast />
    </div>
</template>
