<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\WhatsappCredential;
use App\Support\Tenancy;
use App\Support\WhatsappCloudApi;
use Illuminate\Http\Request;

/**
 * Bring-your-own-key WhatsApp Business (Meta Cloud API) connection — each
 * shop owner connects their OWN Phone Number ID + permanent access token
 * from their own Meta Business account. Same "never send the raw secret
 * back to the browser" discipline as PaymentGatewayController: index()
 * only ever returns maskedSummary(). Owner-only route (see routes/web.php).
 */
class WhatsappCredentialController extends Controller
{
    public function index()
    {
        $credential = WhatsappCredential::where('shop_id', Tenancy::id())->first();

        return response()->json([
            'configured' => $credential ? [
                'is_active' => $credential->is_active,
                'masked_summary' => $credential->maskedSummary(),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
            'waba_id' => ['nullable', 'string'],
        ]);

        WhatsappCredential::updateOrCreate(
            ['shop_id' => Tenancy::id()],
            ['credentials' => $data, 'is_active' => true]
        );

        return back()->with('success', 'WhatsApp Business সংযুক্ত করা হয়েছে।');
    }

    /** Sends a fixed test template ("hello_world", Meta's own default sample template every WABA has pre-approved) to confirm the credentials actually work before the owner tries a real bulk send. */
    public function test(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string']]);

        $credential = WhatsappCredential::where('shop_id', Tenancy::id())->first();
        if (! $credential) {
            abort(422, 'আগে WhatsApp সংযুক্ত করুন।');
        }

        $result = (new WhatsappCloudApi)->sendTemplate($credential->credentials, $data['phone'], 'hello_world', 'en_US');

        if (! $result['ok']) {
            return back()->withErrors(['phone' => 'পাঠানো যায়নি: '.$result['error']]);
        }

        return back()->with('success', 'টেস্ট মেসেজ পাঠানো হয়েছে — WhatsApp চেক করুন।');
    }

    public function toggle(Request $request)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $credential = WhatsappCredential::where('shop_id', Tenancy::id())->firstOrFail();
        $credential->update(['is_active' => $data['is_active']]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    public function destroy()
    {
        WhatsappCredential::where('shop_id', Tenancy::id())->delete();

        return back()->with('success', 'সংযোগ বিচ্ছিন্ন করা হয়েছে।');
    }
}
