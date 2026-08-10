<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Http\Request;

/**
 * CRUD for a shop's own saved WhatsApp message snippets — see
 * WhatsappMessageTemplate's docblock. Listed as part of
 * WhatsappBulkController::index()'s own props (same page), this
 * controller only ever handles the write side; picking one to actually
 * use is a pure client-side fill of the send form in WhatsappBulk/Index.vue.
 */
class WhatsappTemplateController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);

        WhatsappMessageTemplate::create($data);

        return back()->with('success', 'টেমপ্লেট সংরক্ষণ করা হয়েছে।');
    }

    public function update(Request $request, WhatsappMessageTemplate $whatsappMessageTemplate)
    {
        $data = $this->validated($request);

        $whatsappMessageTemplate->update($data);

        return back()->with('success', 'টেমপ্লেট হালনাগাদ করা হয়েছে।');
    }

    public function destroy(WhatsappMessageTemplate $whatsappMessageTemplate)
    {
        $whatsappMessageTemplate->delete();

        return back()->with('success', 'টেমপ্লেট মুছে ফেলা হয়েছে।');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'send_type' => ['required', 'in:template,text'],
            'template_name' => ['required_if:send_type,template', 'nullable', 'string', 'max:255'],
            'language_code' => ['nullable', 'string', 'max:10'],
            'message' => ['required_if:send_type,text', 'nullable', 'string', 'max:1000'],
        ]);
    }
}
