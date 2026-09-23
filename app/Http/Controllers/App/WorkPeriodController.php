<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\WorkPeriod;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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

        $variance = round($actualChange - $expectedChange, 2);

        $workPeriod->update([
            'closed_at' => now(),
            'closing_cash' => $data['closing_cash'],
            'cash_balance_at_close' => $shop->cash_balance,
            'variance' => $variance,
        ]);

        // the whole point of counting cash at close is to catch a shortage
        // (or a surprise overage) immediately - the variance used to be
        // computed and saved but never actually shown to anyone, so closing
        // a shift was a black box. Fold it straight into the same toast the
        // UI already displays on close, no new plumbing needed.
        $amount = number_format(abs($variance), 2);
        $message = match (true) {
            $variance === 0.0 => 'শিফট বন্ধ হয়েছে। হিসাব মিলেছে ✅',
            $variance < 0 => "শিফট বন্ধ হয়েছে। ঘাটতি ৳{$amount} ⚠️",
            default => "শিফট বন্ধ হয়েছে। বাড়তি ৳{$amount}",
        };

        return back()->with('success', $message);
    }

    /** Past (closed) shifts, newest first - so a shortage/overage caught at
     *  close time isn't lost the moment the toast disappears. Owner-only in
     *  the nav (see More.vue), same convention as the Activity Log. */
    public function index()
    {
        $periods = WorkPeriod::with('openedByUser:id,name')
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->paginate(30);

        return Inertia::render('App/Shifts/Index', ['periods' => $periods]);
    }
}
