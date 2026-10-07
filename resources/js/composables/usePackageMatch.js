// Encodes the 3 published packages (Starter/Business/Ultimate) as feature-key
// sets, straight off Khaled's pricing flyer, so the admin shop form can tell
// him live "this ticked feature-set = Business" instead of him having to
// eyeball-compare a checklist against a screenshot every time. The flyer's
// 32 bullets (10 Starter, +10 Business, +12 Ultimate) map onto real
// `features` table keys 1:1, 'pos' (selling itself, the flyer's Starter
// item #1) included -- it's purely a package-pricing bookkeeping entry,
// not the actual access gate (that's still the staff `pos` permission,
// unaffected by any of this).
export const PACKAGES = {
    starter: {
        label: 'Starter',
        core: ['pos', 'memo_whatsapp', 'memo_print', 'weight_based_selling', 'reports', 'expenses', 'accounts', 'quotations', 'cashier_management', 'export'],
    },
    business: {
        label: 'Business',
        core: [
            'pos', 'memo_whatsapp', 'memo_print', 'weight_based_selling', 'reports', 'expenses', 'accounts', 'quotations', 'cashier_management', 'export',
            'barcode_printing', 'unit_conversion', 'purchases', 'returns', 'damages', 'stock_count', 'restaurant_tables', 'product_variants', 'whatsapp_bulk', 'activity_log',
        ],
    },
    ultimate: {
        label: 'Ultimate',
        core: [
            'pos', 'memo_whatsapp', 'memo_print', 'weight_based_selling', 'reports', 'expenses', 'accounts', 'quotations', 'cashier_management', 'export',
            'barcode_printing', 'unit_conversion', 'purchases', 'returns', 'damages', 'stock_count', 'restaurant_tables', 'product_variants', 'whatsapp_bulk', 'activity_log',
            'promotions', 'loyalty_points', 'hr_payroll', 'vat', 'suppliers', 'low_stock_alerts', 'batch_tracking', 'serial_tracking', 'prescription_records', 'wholesale_pricing', 'ingredient_tracking', 'partners',
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

/**
 * A package's monthly price, derived purely from the SAME per-feature
 * `monthly_price` values already set on the admin Features screen -- not a
 * separate hardcoded number. Summing a tier's own full core list (not just
 * whatever happens to be ticked) means "this shop is on Ultimate" always
 * prices as the whole Ultimate package, the same way the printed flyer
 * sells it, regardless of which subset of it this particular shop actually
 * uses day to day.
 */
export function tierPrice(tier, features) {
    if (!tier || !PACKAGES[tier]) return 0;
    const priceByKey = Object.fromEntries(features.map((f) => [f.key, Number(f.monthly_price || 0)]));

    return PACKAGES[tier].core.reduce((sum, key) => sum + (priceByKey[key] || 0), 0);
}
