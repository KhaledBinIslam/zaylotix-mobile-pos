<?php

namespace App\Http\Controllers\App;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\Season;
use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "সিজন ক্যাম্পেইন কিট" — see docs/FIX_PLAN.md's design note and the
 * seasons/campaigns migration's own docblock. Deliberately thin: this
 * reuses the existing Promotion engine (PromotionController already owns
 * creating/editing the actual discount) and the existing customer-segment
 * filter + WhatsApp send queue (both client-side, shared with
 * CustomerList/Index.vue) — launch() only flips the linked promotion
 * active and stamps last_launched_at; it never builds its own discount or
 * messaging logic.
 */
class CampaignController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Campaigns/Index', [
            'seasons' => Season::with(['campaigns.promotion'])->orderByDesc('starts_at')->get(),
            'promotions' => Promotion::orderByDesc('id')->get(['id', 'name', 'type', 'code', 'active']),
            // same shape CustomerListController::index() already sends —
            // the segment filter + WhatsApp queue on this page are the
            // exact same client-side code, just reused here
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone', 'due', 'total_spent', 'visits', 'loyalty_points', 'updated_at', 'no_marketing']),
        ]);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // table-wide exists:seasons,id would accept another shop's id
            // (the exists rule ignores Eloquent global scopes entirely —
            // the exact class of bug fixed in the offline-sync work, see
            // docs/FIX_PLAN.md) — explicitly scoped here instead.
            'season_id' => ['nullable', Rule::exists('seasons', 'id')->where('shop_id', Tenancy::id())],
            'promotion_id' => ['nullable', Rule::exists('promotions', 'id')->where('shop_id', Tenancy::id())],
            'segment' => ['required', 'in:all,topSpenders,mostVisits,highestDue,inactive'],
            'message_template' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request)
    {
        Campaign::create($request->validate($this->rules()));

        return back()->with('success', 'ক্যাম্পেইন তৈরি হয়েছে।');
    }

    public function update(Request $request, Campaign $campaign)
    {
        $campaign->update($request->validate($this->rules()));

        return back()->with('success', 'ক্যাম্পেইন হালনাগাদ হয়েছে।');
    }

    public function destroy(Campaign $campaign)
    {
        $campaign->delete();

        return back()->with('success', 'ক্যাম্পেইন মুছে ফেলা হয়েছে।');
    }

    /**
     * "চালু করুন" — activates the linked promotion (if any; a
     * message-only campaign has none) so checkout actually honors it, and
     * stamps when this was last launched. The customer list to message and
     * the rendered text are entirely resolved client-side (segment filter
     * + {name}/{due}/{shop} template, same as CustomerList/Index.vue) —
     * this endpoint never needs to return who they are.
     */
    public function launch(Campaign $campaign)
    {
        if ($campaign->promotion) {
            $campaign->promotion->update(['active' => true]);
        }
        $campaign->update(['last_launched_at' => now()]);

        return back()->with('success', 'ক্যাম্পেইন চালু করা হয়েছে।');
    }
}
