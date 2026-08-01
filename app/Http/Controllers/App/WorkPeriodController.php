<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\WorkPeriod;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shift/cash-drawer tracking — entirely optional, never blocks POS/checkout.
 * A shop that never opens a work period behaves exactly as it always did.
 */
class WorkPeriodController extends Controller
{
    public function open(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();

        DB::transaction(function () use ($data, $shopId, $userId) {
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            // one active shift per shop at a time — the cash drawer itself
            // is shared, a second concurrent "open" makes no physical sense
            if (WorkPeriod::whereNull('closed_at')->exists()) {
                abort(422, 'ইতিমধ্যে একটি শিফট চলছে।');
            }

            WorkPeriod::create([
                'shop_id' => $shopId,
                'opened_by' => $userId,
                'opened_at' => now(),
                'opening_cash' => $data['opening_cash'],
                'cash_balance_at_open' => $shop->cash_balance,
            ]);
        });

        return back()->with('success', 'শিফট শুরু হয়েছে।');
    }

    public function close(Request $request, WorkPeriod $workPeriod)
    {
        $data = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
        ]);

        if ($workPeriod->closed_at) {
            abort(422, 'এই শিফট ইতিমধ্যে বন্ধ হয়ে গেছে।');
        }

        $shop = Tenancy::shop();
        // shop.cash_balance is a lifetime running total, not a per-shift
        // drawer amount — comparing physically-counted drawer cash against
        // it directly would always show a huge, meaningless variance. What
        // actually matters is whether the *change* in counted cash during
        // this shift matches the *change* the ledger recorded over the
        // same window.
        $expectedChange = (float) $shop->cash_balance - (float) $workPeriod->cash_balance_at_open;
        $actualChange = $data['closing_cash'] - (float) $workPeriod->opening_cash;

        $workPeriod->update([
            'closed_at' => now(),
            'closing_cash' => $data['closing_cash'],
            'cash_balance_at_close' => $shop->cash_balance,
            'variance' => round($actualChange - $expectedChange, 2),
        ]);

        return back()->with('success', 'শিফট বন্ধ হয়েছে।');
    }
}
