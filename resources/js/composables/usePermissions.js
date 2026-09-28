import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Single source of truth for "can the current user reach X" — mirrors the
// server's perm:<key> middleware (config/staff_permissions.php). An owner
// always passes; a staff account passes only if `key` is in their granted
// `permissions` array (see StaffController). Pages that bundle more than
// one permission behind a single route (e.g. Customers/Index.vue holds
// both the `customers` list and `due` collection UI) must check the finer
// key themselves here rather than relying on the route having let them in.
export function usePermissions() {
    const page = usePage();
    const user = computed(() => page.props.auth?.user);
    const isOwner = computed(() => user.value?.role === 'owner');
    const hasPerm = (key) => isOwner.value || (user.value?.permissions || []).includes(key);
    return { hasPerm, isOwner };
}
