// Encodes the 3 published packages (Starter/Business/Ultimate) as feature-key
// sets, straight off Khaled's pricing flyer, so the admin shop form can tell
// him live "this ticked feature-set = Business" instead of him having to
// eyeball-compare a checklist against a screenshot every time. serial_tracking
// and prescription_records are deliberately left OUT of Ultimate's "core" set
// below -- the flyer lists them as an either/or depending on business type
// (mobile shop vs pharmacy), so requiring BOTH would make a legitimate
// Ultimate grocery/mobile-only shop never match. They still count as
// Ultimate-tier features for pricing purposes, just not required for the
// match itself.
export const PACKAGES = {
    starter: {
        label: 'Starter',
        core: ['memo_print', 'memo_whatsapp', 'unit_conversion', 'weight_based_selling', 'accounts', 'partners', 'expenses', 'reports'],
    },
    business: {
        label: 'Business',
        core: [
            'memo_print', 'memo_whatsapp', 'unit_conversion', 'weight_based_selling', 'accounts', 'partners', 'expenses', 'reports',
            'barcode_printing', 'purchases', 'returns', 'stock_count', 'damages', 'restaurant_tables', 'export', 'cashier_management',
        ],
    },
    ultimate: {
        label: 'Ultimate',
        core: [
            'memo_print', 'memo_whatsapp', 'unit_conversion', 'weight_based_selling', 'accounts', 'partners', 'expenses', 'reports',
            'barcode_printing', 'purchases', 'returns', 'stock_count', 'damages', 'restaurant_tables', 'export', 'cashier_management',
            'vat', 'activity_log', 'suppliers', 'low_stock_alerts', 'wholesale_pricing', 'hr_payroll', 'promotions', 'loyalty_points', 'quotations', 'ingredient_tracking',
        ],
    },
};

/**
 * Given the currently-ticked feature keys, finds the highest tier whose
 * ENTIRE core list is included -- not an exact-set match, since a shop can
 * legitimately have extras on top (business-type-specific things like
 * product_variants, batch_tracking, serial_tracking, prescription_records
 * aren't part of any tier's core, they're added per business type
 * regardless of package). Returns null if it doesn't even cover Starter.
 */
export function matchPackage(tickedKeys) {
    const ticked = new Set(tickedKeys);
    const coversAll = (list) => list.every((k) => ticked.has(k));

    let matched = null;
    for (const tier of ['starter', 'business', 'ultimate']) {
        if (coversAll(PACKAGES[tier].core)) matched = tier;
    }

    if (!matched) return { tier: null, label: null, missingForStarter: PACKAGES.starter.core.filter((k) => !ticked.has(k)) };

    const nextTier = matched === 'starter' ? 'business' : matched === 'business' ? 'ultimate' : null;
    const extras = [...ticked].filter((k) => !PACKAGES[matched].core.includes(k));

    return {
        tier: matched,
        label: PACKAGES[matched].label,
        extras, // features ticked beyond what this tier requires
        missingForNext: nextTier ? PACKAGES[nextTier].core.filter((k) => !ticked.has(k)) : [],
        nextLabel: nextTier ? PACKAGES[nextTier].label : null,
    };
}
