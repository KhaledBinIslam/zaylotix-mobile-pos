import { describe, expect, it } from 'vitest';
import {
    buildQueueTargets,
    currentTarget,
    initQueueState,
    isQueueDone,
    markCurrent,
    normalizePhone,
    renderTemplate,
    sameTargetSet,
    sentCount,
    shouldShowPauseReminder,
    skippedCount,
    undoMarked,
    waLink,
} from '../whatsappQueue.js';

describe('normalizePhone', () => {
    it('normalizes a bare local number to 88-prefixed', () => {
        expect(normalizePhone('01712345678')).toBe('8801712345678');
    });

    it('leaves an already 88-prefixed number as-is', () => {
        expect(normalizePhone('8801712345678')).toBe('8801712345678');
    });

    it('strips non-digit characters first', () => {
        expect(normalizePhone('+880 171-234-5678')).toBe('8801712345678');
    });

    it('rejects something too short to be a real number', () => {
        expect(normalizePhone('12345')).toBeNull();
    });

    it('rejects an empty/missing value', () => {
        expect(normalizePhone('')).toBeNull();
        expect(normalizePhone(null)).toBeNull();
    });
});

describe('buildQueueTargets', () => {
    const base = [
        { id: 1, name: 'Karim', phone: '01711111111', due: 100 },
        { id: 2, name: 'Rahim', phone: '01722222222', due: 0 },
    ];

    it('keeps valid, non-opted-out customers with a real phone', () => {
        const { targets } = buildQueueTargets(base);
        expect(targets).toHaveLength(2);
        expect(targets[0].phone).toBe('8801711111111');
    });

    it('drops a customer who opted out of marketing messages', () => {
        const { targets } = buildQueueTargets([...base, { id: 3, name: 'Jamal', phone: '01733333333', due: 0, no_marketing: true }]);
        expect(targets.map((t) => t.id)).toEqual([1, 2]);
    });

    it('drops a customer with no usable phone number and reports it as skipped', () => {
        const { targets, skippedInvalid } = buildQueueTargets([...base, { id: 4, name: 'No Phone', phone: '', due: 0 }]);
        expect(targets).toHaveLength(2);
        expect(skippedInvalid).toHaveLength(1);
        expect(skippedInvalid[0].id).toBe(4);
    });

    it('dedups two customer records that share the same phone number', () => {
        const { targets } = buildQueueTargets([...base, { id: 5, name: 'Duplicate Karim', phone: '8801711111111', due: 0 }]);
        expect(targets).toHaveLength(2);
    });
});

describe('renderTemplate', () => {
    it('substitutes name/due/shop variables', () => {
        const text = renderTemplate('প্রিয় {name}, আপনার বাকি {due} টাকা — {shop} থেকে।', { name: 'Karim', due: 150 }, 'Zaylotix Store');
        expect(text).toBe('প্রিয় Karim, আপনার বাকি 150 টাকা — Zaylotix Store থেকে।');
    });

    it('replaces every occurrence, not just the first', () => {
        expect(renderTemplate('{name} {name}', { name: 'A', due: 0 }, 'S')).toBe('A A');
    });
});

describe('waLink', () => {
    it('builds a wa.me link with the message URL-encoded', () => {
        expect(waLink('8801712345678', 'হ্যালো')).toBe('https://wa.me/8801712345678?text=' + encodeURIComponent('হ্যালো'));
    });
});

describe('queue state machine', () => {
    const targets = [
        { id: 1, name: 'A', phone: '8801711111111', due: 0 },
        { id: 2, name: 'B', phone: '8801722222222', due: 0 },
        { id: 3, name: 'C', phone: '8801733333333', due: 0 },
    ];

    it('starts on the first target, pending', () => {
        const state = initQueueState(targets);
        expect(currentTarget(state).id).toBe(1);
        expect(isQueueDone(state)).toBe(false);
    });

    it('marking current as sent advances to the next pending target', () => {
        let state = initQueueState(targets);
        state = markCurrent(state, 'sent');
        expect(currentTarget(state).id).toBe(2);
        expect(sentCount(state)).toBe(1);
    });

    it('marking current as skipped advances too, without counting as sent', () => {
        let state = initQueueState(targets);
        state = markCurrent(state, 'skipped');
        expect(currentTarget(state).id).toBe(2);
        expect(sentCount(state)).toBe(0);
        expect(skippedCount(state)).toBe(1);
    });

    it('is done once every target has been resolved', () => {
        let state = initQueueState(targets);
        state = markCurrent(state, 'sent');
        state = markCurrent(state, 'skipped');
        state = markCurrent(state, 'sent');
        expect(isQueueDone(state)).toBe(true);
        expect(currentTarget(state)).toBeNull();
    });

    it('marking current on an already-done queue is a no-op', () => {
        let state = initQueueState([targets[0]]);
        state = markCurrent(state, 'sent');
        const again = markCurrent(state, 'sent');
        expect(again).toEqual(state);
    });
});

describe('shouldShowPauseReminder', () => {
    it('is false before 30 sends', () => {
        expect(shouldShowPauseReminder(1)).toBe(false);
        expect(shouldShowPauseReminder(29)).toBe(false);
    });

    it('fires exactly on every multiple of 30', () => {
        expect(shouldShowPauseReminder(30)).toBe(true);
        expect(shouldShowPauseReminder(60)).toBe(true);
    });

    it('is false at zero sends', () => {
        expect(shouldShowPauseReminder(0)).toBe(false);
    });
});

describe('sameTargetSet', () => {
    it('is true when the saved batch covers the exact same ids', () => {
        const saved = [{ id: 1 }, { id: 2 }];
        const fresh = [{ id: 2, name: 'B' }, { id: 1, name: 'A' }];
        expect(sameTargetSet(saved, fresh)).toBe(true);
    });

    it('is false when the selection changed', () => {
        const saved = [{ id: 1 }, { id: 2 }];
        const fresh = [{ id: 1 }, { id: 3 }];
        expect(sameTargetSet(saved, fresh)).toBe(false);
    });

    it('is false when the count differs', () => {
        expect(sameTargetSet([{ id: 1 }], [{ id: 1 }, { id: 2 }])).toBe(false);
    });
});

describe('undoMarked', () => {
    const targets = [
        { id: 1, name: 'A', phone: '8801711111111', due: 0 },
        { id: 2, name: 'B', phone: '8801722222222', due: 0 },
        { id: 3, name: 'C', phone: '8801733333333', due: 0 },
    ];

    it('puts a sent entry back to pending and the cursor finds it again', () => {
        let state = initQueueState(targets);
        state = markCurrent(state, 'sent'); // sends #1, cursor now on #2
        state = undoMarked(state, 1);
        expect(currentTarget(state).id).toBe(1);
        expect(sentCount(state)).toBe(0);
    });

    it('does not disturb entries other than the one being undone', () => {
        let state = initQueueState(targets);
        state = markCurrent(state, 'sent'); // #1 sent
        state = markCurrent(state, 'skipped'); // #2 skipped, cursor on #3
        state = undoMarked(state, 1);
        expect(state.items.find((i) => i.id === 2).status).toBe('skipped');
        expect(currentTarget(state).id).toBe(1); // the undone one is next again, before #3
    });
});
