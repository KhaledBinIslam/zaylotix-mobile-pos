<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Shop;

/**
 * SEAMAN Phase 5 — A4 PDFs for the party due statement and a single bill.
 *
 * These use mPDF, NOT the dompdf the export pages use: dompdf has no
 * OpenType shaping, so Bengali conjuncts (ক্ত, ষ্ণ) and vowel reordering
 * (ি before its consonant) come out wrong/unreadable. mPDF ships Indic
 * shaping (autoScriptToLang/autoLangToFont → FreeSerif with OTL) and is
 * pure PHP, so it runs on the same shared hosting. Scope: these party
 * documents ONLY — every existing dompdf export stays exactly as it was.
 *
 * HOUSE RULE, enforced here by construction: these documents go to the
 * CUSTOMER/PARTY — supplier names and purchase prices must never appear.
 * Nothing in these templates ever touches cost/supplier fields.
 */
class PartyPdf
{
    private static function mpdf(): \Mpdf\Mpdf
    {
        // mPDF's bundled FreeSerif shapes Bengali perfectly (conjuncts,
        // vowel reordering — proven in the Phase 5 render test), but its
        // BOLD face has no Bengali glyphs: every <b>/heading rendered as
        // boxes. Modern Noto Sans Bengali can't be used instead — its GPOS
        // Lookup Type 5/3 tables crash mPDF's font parser. So the bold
        // face is mapped onto the regular file: <b> text stays readable
        // (just not visually bold), and the templates build hierarchy from
        // size and shading instead of weight.
        $fontDefaults = (new \Mpdf\Config\FontVariables)->getDefaults();

        $fontdata = $fontDefaults['fontdata'];
        $fontdata['freeserif']['B'] = 'FreeSerif.ttf';
        $fontdata['freeserif']['I'] = 'FreeSerif.ttf';
        $fontdata['freeserif']['BI'] = 'FreeSerif.ttf';

        if (! is_dir(storage_path('app/mpdf'))) {
            @mkdir(storage_path('app/mpdf'), 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('app/mpdf'),
            'fontdata' => $fontdata,
            'default_font' => 'freeserif',
            'margin_top' => 14, 'margin_bottom' => 14, 'margin_left' => 12, 'margin_right' => 12,
        ]);
        $mpdf->SetTitle('Statement');

        return $mpdf;
    }

    private static function header(Shop $shop): string
    {
        $name = e($shop->name);
        $phone = e($shop->phone ?? '');
        $website = e($shop->website ?? '');
        $sub = trim($phone.($website !== '' ? ' · '.$website : ''), ' ·');

        return '<div style="text-align:center;border-bottom:2px solid #333;padding-bottom:8px;margin-bottom:12px">'
            .'<div style="font-size:20px;font-weight:bold">'.$name.'</div>'
            .($sub !== '' ? '<div style="font-size:11px;color:#555">'.$sub.'</div>' : '')
            .'</div>';
    }

    private static function money(float $n): string
    {
        return '&#2547;'.number_format($n, 2);
    }

    public static function statement(Shop $shop, Customer $party, string $from, string $to): string
    {
        $data = PartyStatement::build($party, $from, $to);

        $partyName = e($party->company_name ?: $party->name);
        $contact = e($party->contact_person ?: '');
        $phone = e($party->phone ?: '');

        $rowsHtml = '';
        $rowsHtml .= '<tr><td>'.e(date('d/m/Y', strtotime($from))).'</td><td>পূর্বের বাকি (ওপেনিং)</td><td></td><td></td><td style="text-align:right">'.self::money($data['opening']).'</td></tr>';
        foreach ($data['rows'] as $row) {
            $isBill = $row['type'] === 'bill';
            $desc = $isBill ? 'বিল '.e($row['label']) : 'জমা ('.e(self::methodBn($row['label'])).')';
            $rowsHtml .= '<tr>'
                .'<td>'.e(date('d/m/Y', strtotime($row['date']))).'</td>'
                .'<td>'.$desc.'</td>'
                .'<td style="text-align:right">'.($isBill ? self::money($row['bill_total']) : '').'</td>'
                .'<td style="text-align:right">'.($row['paid'] > 0 ? self::money($row['paid']) : '').'</td>'
                .'<td style="text-align:right">'.self::money($row['balance']).'</td>'
                .'</tr>';
        }

        $html = '<html><body style="font-family:freeserif;font-size:12px;color:#222">'
            .self::header($shop)
            .'<div style="font-size:15px;font-weight:bold;margin-bottom:2px">বাকি খাতা স্টেটমেন্ট</div>'
            .'<table width="100%" style="font-size:11px;margin-bottom:10px"><tr>'
            .'<td>পার্টি: <b>'.$partyName.'</b>'
            .($contact !== '' ? ' · যোগাযোগ: '.$contact : '')
            .($phone !== '' ? ' · ফোন: '.$phone : '').'</td>'
            .'<td style="text-align:right">সময়কাল: '.e(date('d/m/Y', strtotime($from))).' — '.e(date('d/m/Y', strtotime($to))).'</td>'
            .'</tr></table>'
            .'<table width="100%" cellpadding="6" style="border-collapse:collapse;font-size:11px" border="1">'
            .'<thead><tr style="background:#f0f0f0;font-weight:bold"><td width="14%">তারিখ</td><td>বিবরণ</td><td width="16%" style="text-align:right">বিল</td><td width="16%" style="text-align:right">জমা</td><td width="18%" style="text-align:right">ব্যালেন্স</td></tr></thead>'
            .$rowsHtml
            .'</table>'
            .'<table width="100%" style="margin-top:12px;font-size:12px"><tr>'
            .'<td>মোট বিল: <b>'.self::money($data['total_billed']).'</b> · মোট জমা: <b>'.self::money($data['total_paid']).'</b></td>'
            .'<td style="text-align:right;font-size:14px">ক্লোজিং বাকি: <b>'.self::money($data['closing']).'</b></td>'
            .'</tr></table>'
            .'<div style="margin-top:18px;font-size:10px;color:#777;text-align:center">এই স্টেটমেন্টটি '.e($shop->name).' থেকে তৈরি · '.e(now()->format('d/m/Y')).'</div>'
            .'</body></html>';

        $mpdf = self::mpdf();
        $mpdf->SetTitle('Due Statement - '.($party->company_name ?: $party->name));
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public static function bill(Shop $shop, Customer $party, Sale $sale): string
    {
        $partyName = e($party->company_name ?: $party->name);

        $rowsHtml = '';
        foreach ($sale->items as $i => $item) {
            $lineTotal = (float) $item->qty * (float) $item->price - (float) $item->discount;
            $name = e($item->product_name.($item->variant_label ? ' — '.$item->variant_label : ''));
            $rowsHtml .= '<tr>'
                .'<td style="text-align:center">'.($i + 1).'</td>'
                .'<td>'.$name.'</td>'
                .'<td style="text-align:center">'.(float) $item->qty.'</td>'
                .'<td style="text-align:right">'.self::money((float) $item->price).'</td>'
                .'<td style="text-align:right">'.self::money($lineTotal).'</td>'
                .'</tr>';
        }

        $tendered = (float) $sale->payments->sum('amount');
        $due = max(0, round((float) $sale->total - $tendered, 2));

        $html = '<html><body style="font-family:freeserif;font-size:12px;color:#222">'
            .self::header($shop)
            .'<table width="100%" style="font-size:11px;margin-bottom:10px"><tr>'
            .'<td>বিল নং: <b>'.e($sale->invoice_no).'</b><br>পার্টি: <b>'.$partyName.'</b></td>'
            .'<td style="text-align:right">তারিখ: '.e(date('d/m/Y', strtotime((string) $sale->date))).'</td>'
            .'</tr></table>'
            .'<table width="100%" cellpadding="6" style="border-collapse:collapse;font-size:11px" border="1">'
            .'<thead><tr style="background:#f0f0f0;font-weight:bold"><td width="7%" style="text-align:center">#</td><td>পণ্য</td><td width="10%" style="text-align:center">পরিমাণ</td><td width="18%" style="text-align:right">দর</td><td width="18%" style="text-align:right">মোট</td></tr></thead>'
            .$rowsHtml
            .'</table>'
            .'<table width="38%" align="right" cellpadding="4" style="margin-top:10px;font-size:12px">'
            .'<tr><td>সাবটোটাল</td><td style="text-align:right">'.self::money((float) $sale->subtotal).'</td></tr>'
            .((float) $sale->discount > 0 ? '<tr><td>ডিসকাউন্ট</td><td style="text-align:right">-'.self::money((float) $sale->discount).'</td></tr>' : '')
            .((float) $sale->vat > 0 ? '<tr><td>ভ্যাট</td><td style="text-align:right">'.self::money((float) $sale->vat).'</td></tr>' : '')
            .'<tr style="font-weight:bold"><td>মোট</td><td style="text-align:right">'.self::money((float) $sale->total).'</td></tr>'
            .'<tr><td>জমা</td><td style="text-align:right">'.self::money($tendered).'</td></tr>'
            .'<tr style="font-weight:bold"><td>বাকি</td><td style="text-align:right">'.self::money($due).'</td></tr>'
            .'</table>'
            .'</body></html>';

        $mpdf = self::mpdf();
        $mpdf->SetTitle('Bill '.$sale->invoice_no);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private static function methodBn(string $method): string
    {
        return [
            'cash' => 'নগদ টাকা',
            'bkash' => 'বিকাশ',
            'nagad' => 'নগদ (Nagad)',
            'bank' => 'ব্যাংক',
            'advance' => 'অগ্রিম সমন্বয়',
        ][$method] ?? $method;
    }
}
