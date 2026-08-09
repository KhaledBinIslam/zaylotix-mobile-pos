<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappBulkLog;
use App\Models\WhatsappCredential;
use App\Support\Tenancy;
use App\Support\WhatsappCloudApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

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
        ]);
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
