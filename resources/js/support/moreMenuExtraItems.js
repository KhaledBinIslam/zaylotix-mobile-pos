// useNavItems() drives the sidebar + Home quick-actions grid, so it was
// deliberately kept to the destinations Khaled wanted pinned there — it
// does NOT cover everything More.vue itself links to (shift history,
// stock transfer, size/colour/cost code settings, HR, shop backup, ...).
// The global search (Home.vue + More.vue) needs ALL of it, so this file
// lists exactly the extra, real-route rows More.vue has that useNavItems()
// doesn't — each `show` copied verbatim from that row's own v-if in
// More.vue, so this can never show a destination the row itself would
// refuse to show.
export function buildExtraSearchItems({ hasPerm, isOwner, hasFeature, branches, t }) {
    return [
        // THE real invoice-based return/exchange screen (Returns/Index.vue)
        // — missing from useNavItems() entirely; its own 'return' entry
        // there points at the OLD no-invoice sheet (label "বিক্রয় ফেরত" /
        // "Sales Returns", no "exchange" in it at all), so searching
        // "exchange" found nothing. key/label both carry "exchange" so an
        // English or Bangla query for either word finds this one.
        { key: 'returnExchange', label: t('more.returnInvoiceTitle'), icon: 'undo', groupLabel: t('nav.category.inventory'), href: route('app.returns.index'), show: hasPerm('returns') && hasFeature('returns') },
        { key: 'workPeriodHistory', label: t('workPeriod.historyPrompt'), icon: 'activity', groupLabel: t('nav.category.sales'), href: route('app.workPeriod.index'), show: isOwner },
        { key: 'stockTransfer', label: t('more.stockTransfer'), icon: 'truck', groupLabel: t('nav.category.inventory'), href: route('app.stockTransfers.index'), show: isOwner && !!branches },
        { key: 'serviceTickets', label: t('nav.serviceTickets'), icon: 'box', groupLabel: t('nav.category.sales'), href: route('app.serviceTickets.index'), show: hasPerm('service_billing') && hasFeature('service_billing') },
        { key: 'installmentPlans', label: t('nav.installments'), icon: 'calendar', groupLabel: t('nav.category.sales'), href: route('app.installments.index'), show: hasPerm('installments') && hasFeature('installment_sales') },
        { key: 'receiptLabelSettings', label: t('nav.receiptLabelSettings'), icon: 'receipt', groupLabel: t('nav.category.settings'), href: route('app.settings.receiptLabel'), show: hasPerm('settings') },
        { key: 'whatsappBulk', label: t('more.whatsappBulk'), icon: 'trending', groupLabel: t('nav.category.settings'), href: route('app.whatsappBulk.index'), show: isOwner && hasFeature('whatsapp_bulk') },
        { key: 'shopBackup', label: t('backup.title'), icon: 'box', groupLabel: t('nav.category.settings'), href: route('app.shopBackups.index'), show: isOwner && hasFeature('shop_backup') },
        { key: 'attendance', label: t('nav.attendance'), icon: 'calendar', groupLabel: t('nav.category.settings'), href: route('app.attendance.index'), show: isOwner && hasFeature('hr_payroll') },
        { key: 'payroll', label: t('nav.payroll'), icon: 'briefcase', groupLabel: t('nav.category.settings'), href: route('app.payroll.index'), show: isOwner && hasFeature('hr_payroll') },
        { key: 'sizeGroupSettings', label: t('more.sizeGroupSettingsTitle'), icon: 'ruler', groupLabel: t('nav.category.inventory'), href: route('app.articles.sizeGroupSettings.edit'), show: hasPerm('stock') && hasFeature('article_series') },
        { key: 'colorCodeSettings', label: t('more.colorCodeSettingsTitle'), icon: 'tag', groupLabel: t('nav.category.inventory'), href: route('app.articles.colorCodeSettings.edit'), show: hasPerm('stock') && hasFeature('article_series') },
        { key: 'costCodeSettings', label: t('more.costCodeSettingsTitle'), icon: 'tag', groupLabel: t('nav.category.inventory'), href: route('app.articles.costCodeSettings.edit'), show: hasPerm('stock') && hasFeature('article_series') },
        { key: 'stockHistory', label: t('more.stockHistoryTitle'), icon: 'history', groupLabel: t('nav.category.inventory'), href: route('app.stockHistory.index'), show: isOwner },
        { key: 'cashLedger', label: t('nav.cashLedger'), icon: 'briefcase', groupLabel: t('nav.category.accounts'), href: route('app.cashLedger.index'), show: hasPerm('accounts') && hasFeature('accounts') },
        { key: 'loans', label: t('nav.loans'), icon: 'handshake', groupLabel: t('nav.category.accounts'), href: route('app.loans.index'), show: hasPerm('accounts') && hasFeature('accounts') },
        { key: 'parties', label: t('party.title'), icon: 'users', groupLabel: t('nav.category.sales'), href: route('app.parties.index'), show: hasFeature('parties') },
        { key: 'supplierReturn', label: t('more.supplierReturnTitle'), icon: 'undo', groupLabel: t('nav.category.inventory'), href: route('app.more', { open: 'supplierReturn' }), show: hasPerm('purchases') && hasFeature('suppliers') },
    ];
}
