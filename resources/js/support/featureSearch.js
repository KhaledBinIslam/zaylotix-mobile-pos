// Pure, dependency-free matching logic for the app-wide feature/menu
// search (Home.vue + More.vue) — kept out of the .vue component so it can
// be unit-tested with plain arrays instead of mounting a real page, and so
// "does it actually filter per-shop dynamically" is provable without a
// browser: pass it two shops' different `navGroups` (different features/
// permissions => different `show` flags) and assert the results differ.

/** Flattens useNavItems()'s grouped shape into one list, dropping any item this user/shop can't actually reach (`show: false`). */
export function flattenSearchable(navGroups, extraItems = []) {
    const fromGroups = navGroups.flatMap((g) => g.items.filter((i) => i.show).map((i) => ({
        key: i.key, label: i.label, navLabel: i.navLabel, href: i.href, icon: i.icon, groupLabel: g.label,
    })));
    const fromExtra = extraItems.filter((i) => i.show).map((i) => ({
        key: i.key, label: i.label, navLabel: i.navLabel, href: i.href, icon: i.icon, groupLabel: i.groupLabel,
    }));
    return [...fromGroups, ...fromExtra];
}

/** Case-insensitive substring match against the label, its short nav label, and its stable English `key` (so "barcode" finds "বারকোড লেবেল" even on a Bangla UI). */
export function searchItems(items, query) {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return items.filter((i) => `${i.label} ${i.navLabel || ''} ${i.key}`.toLowerCase().includes(q));
}
