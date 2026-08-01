// Single place for the in-app WhatsApp support contact — same number already
// shown on the login footer and the expiry banner, reused here instead of a
// second hardcoded copy.
export const SUPPORT_PHONE = '01979894356';

export function openWhatsAppHelp(shopName, screenLabel) {
    const num = '88' + SUPPORT_PHONE;
    const text = `সাহায্য দরকার 🙏\n\nদোকান: ${shopName || '-'}\nস্ক্রিন: ${screenLabel || '-'}\n\n`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
}
