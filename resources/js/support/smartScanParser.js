// Turns raw OCR text (from a photographed product label/package) into
// best-guess field values — every result here is a SUGGESTION shown on an
// editable review screen, never written to a product record directly.
// Deliberately conservative: a field is only guessed when a fairly specific
// pattern matches (a labeled "MRP"/"Batch"/"Exp" etc.), never from a bare
// number that could mean anything — a wrong price/expiry guess on a
// pharmacy product is worse than no guess at all.

/** DD/MM/YYYY, DD-MM-YYYY, MM/YYYY, or a 2-digit year — normalized to YYYY-MM-DD for a date input, or null if it doesn't parse into a real calendar date. */
function normalizeDate(raw) {
    if (!raw) return null;
    const cleaned = raw.trim().replace(/[.\s]/g, '/').replace(/\/+/g, '/');
    const full = cleaned.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
    if (full) {
        let [, d, m, y] = full;
        if (y.length === 2) y = (Number(y) > 50 ? '19' : '20') + y;
        d = d.padStart(2, '0');
        m = m.padStart(2, '0');
        const iso = `${y}-${m}-${d}`;
        const asDate = new Date(iso);
        if (!Number.isNaN(asDate.getTime()) && Number(m) <= 12 && Number(d) <= 31) return iso;
        return null;
    }
    const monthYear = cleaned.match(/^(\d{1,2})\/(\d{4})$/);
    if (monthYear) {
        const [, m, y] = monthYear;
        if (Number(m) > 12) return null;
        return `${y}-${m.padStart(2, '0')}-01`;
    }
    return null;
}

function firstMatch(text, patterns) {
    for (const re of patterns) {
        const m = text.match(re);
        if (m && m[1]) return m[1].trim();
    }
    return null;
}

export function parseProductText(rawText) {
    const text = rawText || '';
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

    // best-guess name: the first line that reads like an actual product
    // name — not just digits/symbols, not a known label line, reasonably
    // short (a full OCR line-wrap of body text is never a product name)
    const skipLinePattern = /^(mfg|mfd|exp|batch|lot|mrp|price|generic|manufactured|marketed|net\s*wt|reg(\.|istration)?\s*no)/i;
    const name = lines.find((l) => l.length >= 3 && l.length <= 60 && !/^[\d\W]+$/.test(l) && !skipLinePattern.test(l)) || '';

    const price = firstMatch(text, [
        /M\.?R\.?P\.?\s*[:\-]?\s*(?:Tk\.?|৳|BDT)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i,
        /(?:Tk\.?|৳|BDT)\s*([0-9]+(?:\.[0-9]{1,2})?)/i,
        /Price\s*[:\-]?\s*([0-9]+(?:\.[0-9]{1,2})?)/i,
    ]);

    const batchNo = firstMatch(text, [
        // longer label variants tried first ("Batch No" before bare "Batch")
        // so the "No"/"Number" word itself is consumed as part of the
        // label, never mistaken for the start of the actual value
        /(?:Batch\s*No\.?|Batch\s*Number|Batch|B\.?No\.?|Lot\s*No\.?|Lot)\s*[:#\-]?\s*([A-Za-z0-9\-\/]{2,20})/i,
    ]);

    const expiryRaw = firstMatch(text, [
        /(?:Exp(?:iry)?(?:\s*Date)?)\s*[:\-]?\s*(\d{1,2}[.\/\-]\d{1,2}[.\/\-]\d{2,4}|\d{1,2}[.\/\-]\d{4})/i,
    ]);

    const mfgRaw = firstMatch(text, [
        /(?:Mfg|Mfd|Manufactur(?:ed|ing)?)(?:\s*Date)?\s*[:\-]?\s*(\d{1,2}[.\/\-]\d{1,2}[.\/\-]\d{2,4}|\d{1,2}[.\/\-]\d{4})/i,
    ]);

    const company = firstMatch(text, [
        /(?:Manufactured\s*by|Mfg\.?\s*by|Marketed\s*by)\s*[:\-]?\s*([A-Za-z0-9&.,\-\s]{3,60})/i,
    ]);

    // \s inside the capture would happily cross into the NEXT line (\s
    // matches \n too) and swallow it whole — restricted to this line only,
    // same reasoning as the company match just below
    const genericNameRaw = firstMatch(text, [
        /Generic(?:\s*Name)?\s*[:\-]?\s*([A-Za-z0-9\s\-]{3,60})/i,
    ]);
    const genericName = genericNameRaw ? genericNameRaw.split('\n')[0].trim() : null;

    // a standalone 8/12/13/14-digit line is the human-readable number
    // printed under most barcodes (EAN-8/UPC-A/EAN-13/GTIN-14) — OCR can
    // often read this even when it can't decode the bars themselves
    const barcodeLine = lines.find((l) => /^\d{8}$|^\d{12,14}$/.test(l.replace(/\s/g, '')));

    return {
        name,
        generic_name: genericName || '',
        company: company ? company.split('\n')[0].trim() : '',
        price: price || '',
        batch_no: batchNo || '',
        expiry_date: normalizeDate(expiryRaw) || '',
        mfg_date: normalizeDate(mfgRaw) || '',
        barcode: barcodeLine ? barcodeLine.replace(/\s/g, '') : '',
    };
}
