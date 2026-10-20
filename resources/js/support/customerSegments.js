// Pulled out of CustomerList/Index.vue (see docs/FIX_PLAN.md Phase 7) so
// the exact same segment logic can be reused anywhere a campaign/message
// needs to target "top spenders" / "most visits" / "highest due" /
// "inactive" customers — currently CustomerList/Index.vue's own filter and
// the season Campaigns page (Campaigns/Index.vue). One source of truth:
// a new segment definition only ever needs to change here.
export const SEGMENTS = ['all', 'topSpenders', 'mostVisits', 'highestDue', 'inactive'];

const INACTIVE_DAYS = 30;

export function daysSince(dateStr) {
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000);
}

/** Does not mutate or filter out anyone for 'all' — callers that also need a text/other filter apply that themselves before this. */
export function applySegment(customers, segment) {
    if (segment === 'topSpenders') return [...customers].sort((a, b) => b.total_spent - a.total_spent);
    if (segment === 'mostVisits') return [...customers].sort((a, b) => b.visits - a.visits);
    if (segment === 'highestDue') return customers.filter((c) => c.due > 0).sort((a, b) => b.due - a.due);
    if (segment === 'inactive') return customers.filter((c) => daysSince(c.updated_at) >= INACTIVE_DAYS).sort((a, b) => daysSince(b.updated_at) - daysSince(a.updated_at));

    return customers;
}
