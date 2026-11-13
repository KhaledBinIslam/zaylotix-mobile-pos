import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';

/**
 * Every navigable destination in the app, grouped the same way the
 * desktop sidebar (AppLayout.vue) has always grouped them — extracted out
 * of that file so the Home dashboard's Quick Actions grid can show the
 * exact same list, gated by the exact same hasPerm()/hasFeature() checks,
 * without a second hand-maintained copy that could silently drift out of
 * sync with the sidebar (a Quick Action tile pointing somewhere a cashier
 * actually can't reach, or missing one they can, is worse than not having
 * the shortcut at all).
 *
 * `active` is only used to compute each item's `on` (currently-highlighted)
 * state — pass the same string AppLayout's own `active` prop gets. A
 * caller that doesn't care about highlighting (Home.vue's own grid) can
 * simply omit it; every item's `on` is then just false.
 */
export function useNavItems(active = null) {
    const page = usePage();
    const { t } = useI18n();
    const { hasPerm, isOwner } = usePermissions();
    const hasFeature = (key) => (page.props.features || []).includes(key);
    const shop = computed(() => page.props.shop);
    const isRestaurant = computed(() => shop.value?.business_type_slug === 'restaurant');

    // The "আরো" page (More.vue) holds a few actions that don't have their
    // own route — a cashier form, the shop logo, purchase/damage/return/
    // stock-count sheets, data export — opened as sheets on that one page.
    // Linking with ?open=<key> (read by More.vue on mount) lets any list
    // (sidebar or this one) point straight at one of them.
    function moreLink(openKey) {
        return route('app.more', openKey ? { open: openKey } : {});
    }
    function isMoreLinkActive(openKey) {
        if (active !== 'more') return false;
        const params = new URLSearchParams(page.url.split('?')[1] || '');
        return (params.get('open') || null) === (openKey || null);
    }

    const mobileSellHref = computed(() => (isRestaurant.value ? route('app.restaurant.tables.index') : route('app.pos')));
    const mobileSellActive = computed(() => active === 'sell' || active === 'restaurant');

    const navGroups = computed(() => [
        {
            label: t('nav.category.sales'),
            items: [
                { key: 'sell', label: t('nav.sellFull'), href: mobileSellHref.value, icon: 'cart', on: mobileSellActive.value, show: hasPerm('pos') },
                { key: 'reservations', label: t('nav.reservations'), href: route('app.reservations.index'), icon: 'calendar', on: active === 'reservations', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
                { key: 'kds', label: t('nav.kds'), href: route('app.kds.index'), icon: 'pot', on: active === 'kds', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
                { key: 'cds', label: t('nav.cds'), href: route('app.cds.index'), icon: 'monitor', on: active === 'cds', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
                { key: 'sales', label: t('nav.salesHistory'), navLabel: t('nav.salesHistoryShort'), href: route('app.sales'), icon: 'receipt', on: active === 'sales', show: hasPerm('sales_history') },
                { key: 'due', label: t('nav.dueFull'), navLabel: t('nav.due'), href: route('app.customers'), icon: 'invoice', on: active === 'due', show: hasPerm('customers') },
                { key: 'customerList', label: t('customerList.title'), navLabel: t('customerList.titleShort'), href: route('app.customerList.index'), icon: 'users', on: active === 'customerList', show: hasPerm('customers') },
                { key: 'promotions', label: t('nav.promotions'), navLabel: t('nav.promotionsShort'), href: route('app.promotions.index'), icon: 'gift', on: active === 'promotions', show: hasPerm('promotions') && hasFeature('promotions') },
                { key: 'campaigns', label: t('nav.campaigns'), navLabel: t('nav.campaignsShort'), href: route('app.campaigns.index'), icon: 'gift', on: active === 'campaigns', show: hasPerm('promotions') && hasFeature('season_campaigns') },
                { key: 'quotations', label: t('nav.quotations'), href: route('app.quotations.index'), icon: 'clipboard', on: active === 'quotations', show: hasPerm('pos') && hasFeature('quotations') },
                { key: 'onlineOrders', label: t('nav.onlineOrders'), href: route('app.onlineOrders.index'), icon: 'globe', on: active === 'onlineOrders', show: hasPerm('online_business') && hasFeature('online_business') },
                { key: 'channels', label: t('nav.channels'), href: route('app.channels.index'), icon: 'globe', on: active === 'channels', show: hasPerm('online_business') && hasFeature('online_business') },
                { key: 'couriers', label: t('nav.couriers'), href: route('app.couriers.index'), icon: 'truck', on: active === 'couriers', show: hasPerm('online_business') && hasFeature('online_business') },
                { key: 'ads', label: t('nav.ads'), href: route('app.ads.index'), icon: 'trending', on: active === 'ads', show: hasPerm('online_business') && hasFeature('online_business') },
                { key: 'onlineProfit', label: t('nav.onlineProfit'), href: route('app.onlineProfit.index'), icon: 'barChart', on: active === 'onlineProfit', show: hasPerm('online_business') && hasFeature('online_business') },
            ],
        },
        {
            label: t('nav.category.inventory'),
            items: [
                { key: 'stock', label: isRestaurant.value ? t('nav.stockFullRestaurant') : t('nav.stockFull'), navLabel: isRestaurant.value ? t('nav.stockRestaurant') : t('nav.stock'), href: route('app.stock'), icon: 'box', on: active === 'stock', show: hasPerm('stock') },
                { key: 'units', label: t('nav.units'), href: route('app.units.index'), icon: 'ruler', on: active === 'units', show: hasPerm('stock') },
                { key: 'prices', label: t('nav.priceUpdate'), href: route('app.prices.index'), icon: 'tag', on: active === 'prices', show: hasPerm('stock') },
                { key: 'deadStock', label: t('nav.deadStock'), href: route('app.deadStock.index'), icon: 'box', on: active === 'deadStock', show: hasPerm('stock') && hasFeature('dead_stock_clearance') },
                { key: 'catalog', label: t('nav.catalog'), href: route('app.catalog.index'), icon: 'gift', on: active === 'catalog', show: hasPerm('stock') && hasFeature('digital_catalog') },
                { key: 'barcode', label: t('nav.barcode'), href: route('app.barcodeLabels.index'), icon: 'tag', on: active === 'more' && page.url.startsWith('/app/barcode-labels'), show: hasPerm('barcode_labels') && hasFeature('barcode_printing') },
                { key: 'purchase', label: t('nav.purchase'), href: moreLink('purchase'), icon: 'truck', on: isMoreLinkActive('purchase'), show: hasPerm('purchases') && hasFeature('purchases') },
                { key: 'purchaseHistory', label: t('nav.purchaseHistory'), navLabel: t('nav.purchaseHistoryShort'), href: route('app.purchases.index'), icon: 'history', on: active === 'purchaseHistory', show: hasPerm('purchases') && hasFeature('purchases') },
                { key: 'suppliers', label: t('nav.suppliers'), href: route('app.suppliers'), icon: 'factory', on: active === 'suppliers', show: hasPerm('purchases') && hasFeature('suppliers') },
                { key: 'purchaseOrders', label: t('purchaseOrder.title'), href: route('app.purchaseOrders.index'), icon: 'invoice', on: page.url.startsWith('/app/purchase-orders'), show: hasPerm('purchases') && hasFeature('purchase_orders') },
                { key: 'serials', label: t('nav.serials'), href: route('app.serials.index'), icon: 'phone', on: active === 'serials', show: hasPerm('stock') && hasFeature('serial_tracking') },
                { key: 'damage', label: t('nav.damage'), href: moreLink('damage'), icon: 'trash', on: isMoreLinkActive('damage'), show: hasPerm('damages') && hasFeature('damages') },
                { key: 'return', label: t('nav.return'), href: moreLink('return'), icon: 'undo', on: isMoreLinkActive('return'), show: hasPerm('returns') && hasFeature('returns') },
                { key: 'count', label: t('nav.stockCount'), href: moreLink('count'), icon: 'hash', on: isMoreLinkActive('count'), show: hasPerm('stock_count') && hasFeature('stock_count') },
                { key: 'ingredients', label: t('nav.ingredients'), href: route('app.ingredients.index'), icon: 'flask', on: active === 'ingredients', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
                { key: 'preparations', label: t('nav.preparations'), href: route('app.preparations.index'), icon: 'pan', on: active === 'preparations', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
                { key: 'estimator', label: t('nav.estimator'), href: route('app.estimator.index'), icon: 'trending', on: active === 'estimator', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
            ],
        },
        {
            label: t('nav.category.accounts'),
            items: [
                { key: 'accounts', label: t('nav.accounts'), navLabel: t('nav.accountsShort'), href: route('app.accounts'), icon: 'briefcase', on: active === 'accounts', show: hasPerm('accounts') && hasFeature('accounts') },
                { key: 'partners', label: t('nav.partners'), href: route('app.partners.index'), icon: 'handshake', on: active === 'partners', show: isOwner.value && hasFeature('partners') },
                { key: 'reports', label: t('nav.reports'), href: route('app.reports'), icon: 'barChart', on: active === 'reports', show: hasPerm('reports') && hasFeature('reports') },
                { key: 'expenses', label: t('nav.expenses'), href: route('app.expenses'), icon: 'trendingDown', on: active === 'expenses', show: hasPerm('expenses') && hasFeature('expenses') },
                { key: 'export', label: t('nav.export'), href: moreLink('export'), icon: 'upload', on: isMoreLinkActive('export'), show: hasPerm('export') && hasFeature('export') },
            ],
        },
        {
            label: t('nav.category.settings'),
            items: [
                { key: 'cashier', label: t('nav.cashier'), href: route('app.staff.index'), icon: 'user', on: active === 'cashier', show: isOwner.value && hasFeature('cashier_management') },
                { key: 'activity', label: t('nav.activity'), href: route('app.activity'), icon: 'activity', on: active === 'activity', show: isOwner.value && hasFeature('activity_log') },
                { key: 'help', label: t('nav.help'), href: route('app.help'), icon: 'help', on: active === 'help', show: true },
                { key: 'employees', label: t('nav.employees'), href: route('app.employees.index'), icon: 'users', on: active === 'employees', show: isOwner.value && hasFeature('hr_payroll') },
                { key: 'logo', label: t('nav.logo'), href: moreLink('logo'), icon: 'image', on: isMoreLinkActive('logo'), show: hasPerm('settings') },
                { key: 'more', label: t('nav.moreAll'), href: moreLink(), icon: 'settings', on: isMoreLinkActive(), show: true },
            ],
        },
    ]);

    return { navGroups, moreLink, isMoreLinkActive, mobileSellHref, mobileSellActive, isRestaurant, hasPerm, hasFeature, isOwner };
}
