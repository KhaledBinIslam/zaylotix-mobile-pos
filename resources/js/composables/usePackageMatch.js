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

// Features that only show up starting at Business (i.e. not already in
// Starter), and features that only show up starting at Ultimate (not already
// in Business). Per Khaled's explicit rule -- "keu jodi Business ba Ultimate-er
// je kono EKTA feature-o use kore, oi package-er odhin e cole jabe" -- ticking
// even a single one of these is enough to bump the match up, no longer
// requiring the WHOLE tier's list like the original superset-match did.
const businessOnly = PACKAGES.business.core.filter((k) => !PACKAGES.starter.core.includes(k));
const ultimateOnly = PACKAGES.ultimate.core.filter((k) => !PACKAGES.business.core.includes(k));

/**
 * Given the currently-ticked feature keys, finds which package they fall
 * under using Khaled's "any single higher-tier feature bumps you up" rule:
 * ticking even one Ultimate-only feature makes it Ultimate; failing that,
 * ticking even one Business-only feature makes it Business; otherwise it's
 * Starter (or null if nothing at all is ticked yet).
 */
export function matchPackage(tickedKeys) {
    const ticked = new Set(tickedKeys);

    let matched;
    if (ultimateOnly.some((k) => ticked.has(k))) matched = 'ultimate';
    else if (businessOnly.some((k) => ticked.has(k))) matched = 'business';
    else if (PACKAGES.starter.core.some((k) => ticked.has(k))) matched = 'starter';
    else matched = null;

    if (!matched) return { tier: null, label: null, missingForStarter: PACKAGES.starter.core };

    const nextTier = matched === 'starter' ? 'business' : matched === 'business' ? 'ultimate' : null;
    const nextOnly = nextTier === 'business' ? businessOnly : nextTier === 'ultimate' ? ultimateOnly : [];
    const extras = [...ticked].filter((k) => !PACKAGES[matched].core.includes(k));

    return {
        tier: matched,
        label: PACKAGES[matched].label,
        extras, // features ticked beyond what this tier requires
        // any ONE of these (not all) is enough to bump up to nextTier
        missingForNext: nextTier ? nextOnly.filter((k) => !ticked.has(k)) : [],
        nextLabel: nextTier ? PACKAGES[nextTier].label : null,
    };
}
