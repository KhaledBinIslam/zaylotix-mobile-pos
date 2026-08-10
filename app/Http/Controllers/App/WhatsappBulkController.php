<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappBulkLog;
use App\Models\WhatsappCredential;
use App\Models\WhatsappMessageTemplate;
use App\Support\Tenancy;
use App\Support\WhatsappCloudApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk WhatsApp sends to a shop's own customer list, via that shop's own
 * connected WhatsApp Business (Meta Cloud API) number — see
 * WhatsappCredentialController for the connection itself.
 *
 * Deliberately supports two send types, not one, because WhatsApp's own
 * rules force this split (see WhatsappCloudApi's docblock):
 *   - 'template': works for anyone, any time — requires a template name
 *     that's already been created + approved in the owner's Meta Business
 *     Manager (this app has no way to create/submit templates on their
 *     behalf, Meta's approval process is manual and account-specific).
 *   - 'text': free-form message, but Meta will silently reject it for any
 *     customer who hasn't messaged the business's WhatsApp number in the
 *     last 24 hours — the UI must make this limitation visible, not let an
 *     owner discover it only after a failed bulk send.
 */
class WhatsappBulkController extends Controller
{
    public function index()
    {
        $shopId = Tenancy::id();
        $credential = WhatsappCredential::where('shop_id', $shopId)->where('is_active', true)->first();

        return Inertia::render('App/WhatsappBulk/Index', [
            'connected' => (bool) $credential,
            'customers' => Customer::orderByDesc('total_spent')->get(['id', 'name', 'phone', 'due', 'total_spent', 'visits']),
            'recentLogs' => WhatsappBulkLog::with('user:id,name')->latest('id')->limit(20)->get(),
            'templates' => WhatsappMessageTemplate::orderBy('label')->get(),
        ]);
    }

    /**
     * "নতুন নম্বর import" — pasted/typed text and/or a CSV, either way
     * landing as real Customer records (find-or-create by phone, same
     * discipline CustomerController::store()/PosController's checkout use)
     * so an imported contact is immediately selectable here and reusable
     * everywhere else in the app too, not a throwaway list that only this
     * one send can see.
     */
    public function importContacts(Request $request)
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $rows = [];
        if (! empty($data['text'])) {
            $rows = array_merge($rows, $this->parsePastedText($data['text']));
        }
        if ($request->hasFile('file')) {
            $rows = array_merge($rows, $this->parseCsv($request->file('file')->getRealPath()));
        }

        if (! $rows) {
            throw ValidationException::withMessages([
                'text' => 'কোনো বৈধ নম্বর পাওয়া যায়নি — অন্তত একটা নম্বর টাইপ/পেস্ট করুন অথবা CSV ফাইল বেছে নিন।',
            ]);
        }

        $shopId = Tenancy::id();
        $added = 0;
        $matched = 0;
        $importedIds = [];

        foreach ($rows as $row) {
            $existing = Customer::where('phone', $row['phone'])->first();
            if ($existing) {
                $matched++;
                $importedIds[] = $existing->id;

                continue;
            }

            $customer = Customer::create([
                'shop_id' => $shopId,
                'name' => $row['name'] ?: $row['phone'],
                'phone' => $row['phone'],
                'due' => 0,
            ]);
            $added++;
            $importedIds[] = $customer->id;
        }

        $summary = "{$added}টি নতুন নম্বর যোগ হয়েছে".($matched > 0 ? ", {$matched}টি আগে থেকেই ছিল" : '').' — এখন তালিকা থেকে বাছাই করা যাবে।';

        return back()->with('success', $summary)->with('importedCustomerIds', $importedIds);
    }

    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens Bengali cells as UTF-8, not mojibake
            fputcsv($out, ['name', 'phone']);
            fputcsv($out, ['করিম মিয়া', '01712345678']);
            fclose($out);
        }, 'whatsapp-contacts-template.csv');
    }

    /**
     * One contact per line — "নাম, ফোন" when a line has exactly one comma
     * and the part after it looks like a phone number, otherwise every
     * comma/whitespace-separated token on the line that looks like a phone
     * number is taken as its own bare number (covers a block of numbers
     * pasted with no names at all, one per line or all crammed onto one).
     */
    private function parsePastedText(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', preg_split('/[,\t]+/', $line));
            if (count($parts) === 2 && ! $this->looksLikePhone($parts[0]) && $this->looksLikePhone($parts[1])) {
                $rows[] = ['name' => $parts[0], 'phone' => $this->normalizeDigits($parts[1])];

                continue;
            }

            foreach (preg_split('/[,\s]+/', $line) as $token) {
                if ($this->looksLikePhone($token)) {
                    $rows[] = ['name' => null, 'phone' => $this->normalizeDigits($token)];
                }
            }
        }

        return $rows;
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $rows;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $rows;
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $columns = array_map(fn ($h) => strtolower(trim($h)), $header);
        $phoneCol = array_search('phone', $columns, true);
        $nameCol = array_search('name', $columns, true);

        // no recognizable header — treat the file as headerless, phone-only,
        // one number per line (still useful for a bare list someone exported
        // from their phone contacts without ever adding a "phone" column)
        if ($phoneCol === false) {
            rewind($handle);
            while (($line = fgetcsv($handle)) !== false) {
                if (isset($line[0]) && $this->looksLikePhone($line[0])) {
                    $rows[] = ['name' => null, 'phone' => $this->normalizeDigits($line[0])];
                }
            }
            fclose($handle);

            return $rows;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $phone = trim((string) ($row[$phoneCol] ?? ''));
            if ($phone === '' || ! $this->looksLikePhone($phone)) {
                continue;
            }
            $rows[] = [
                'name' => $nameCol !== false ? trim((string) ($row[$nameCol] ?? '')) : null,
                'phone' => $this->normalizeDigits($phone),
            ];
        }
        fclose($handle);

        return $rows;
    }

    private function looksLikePhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) >= 10 && strlen($digits) <= 14;
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'send_type' => ['required', 'in:template,text'],
            'template_name' => ['required_if:send_type,template', 'nullable', 'string'],
            'language_code' => ['nullable', 'string'],
            'message' => ['required_if:send_type,text', 'nullable', 'string', 'max:1000'],
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
        ]);

        $shopId = Tenancy::id();
        $credential = WhatsappCredential::where('shop_id', $shopId)->where('is_active', true)->first();
        if (! $credential) {
            abort(422, 'আগে WhatsApp Business সংযুক্ত করুন (সেটিংস থেকে)।');
        }

        $customers = Customer::whereIn('id', $data['customer_ids'])->whereNotNull('phone')->where('phone', '!=', '')->get();
        if ($customers->isEmpty()) {
            abort(422, 'কোনো বৈধ মোবাইল নম্বরসহ কাস্টমার বাছাই করা হয়নি।');
        }

        $api = new WhatsappCloudApi;
        $sent = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            $result = $data['send_type'] === 'template'
                ? $api->sendTemplate($credential->credentials, $customer->phone, $data['template_name'], $data['language_code'] ?? 'bn', [$customer->name])
                : $api->sendText($credential->credentials, $customer->phone, $data['message']);

            $result['ok'] ? $sent++ : $failed++;
        }

        WhatsappBulkLog::create([
            'shop_id' => $shopId,
            'user_id' => Auth::guard('web')->id(),
            'send_type' => $data['send_type'],
            'template_name' => $data['template_name'] ?? null,
            'message' => $data['message'] ?? ($data['template_name'] ?? null),
            'recipients_count' => $customers->count(),
            'sent_count' => $sent,
            'failed_count' => $failed,
        ]);

        return back()->with('success', "{$sent} জনকে পাঠানো হয়েছে".($failed > 0 ? ", {$failed} জনকে পাঠানো যায়নি" : '').'।');
    }
}
