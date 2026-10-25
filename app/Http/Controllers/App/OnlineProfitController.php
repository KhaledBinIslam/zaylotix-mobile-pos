<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use App\Support\OnlineProfitReport;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** "Online Business" module, part 6 — see docs/FIX_PLAN.md's Phase 36 design note. */
class OnlineProfitController extends Controller
{
    public function index(Request $request)
    {
        $preset = $request->get('preset', 'month');
        [$from, $to] = $this->resolveRange($preset, $request->get('from'), $request->get('to'));

        return Inertia::render('App/OnlineBusiness/Profit/Index', [
            'from' => $from, 'to' => $to, 'preset' => $preset,
            'channels' => SalesChannel::orderBy('name')->get(['id', 'name']),
            'consolidated' => OnlineProfitReport::consolidated($from, $to),
            'byChannel' => OnlineProfitReport::byChannel($from, $to),
        ]);
    }

    private function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        return match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            'custom' => [$from ?: now()->startOfMonth()->toDateString(), $to ?: now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString()], // month
        };
    }
}
