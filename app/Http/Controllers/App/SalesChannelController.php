<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreSalesChannelRequest;
use App\Models\ChannelProductPrice;
use App\Models\Courier;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** "Online Business" module, part 1 — see docs/FIX_PLAN.md's Phase 31 design note. */
class SalesChannelController extends Controller
{
    public function index()
    {
        // channel-wise return rate — courier-wise already exists on
        // CourierController::show() (Part 3); product/reason/area cross-tabs
        // are deliberately deferred to Part 6's dedicated reports (see
        // docs/FIX_PLAN.md's Phase 34 design note)
        $orderCounts = OnlineOrder::selectRaw("channel_id, count(*) as total, sum(case when status = 'returned' then 1 else 0 end) as returned")
            ->groupBy('channel_id')->get()->keyBy('channel_id');

        $channels = SalesChannel::orderBy('name')->get()->each(function (SalesChannel $c) use ($orderCounts) {
            $counts = $orderCounts->get($c->id);
            $c->return_rate = $counts && $counts->total > 0 ? round($counts->returned / $counts->total * 100, 1) : null;
        });

        return Inertia::render('App/OnlineBusiness/Channels/Index', [
            'channels' => $channels,
            'couriers' => Courier::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreSalesChannelRequest $request)
    {
        $data = $request->validated();

        SalesChannel::create([
            'name' => $data['name'],
            'platform_type' => $data['platform_type'],
            'logo_path' => $request->hasFile('logo') ? $request->file('logo')->store('channel-logos', 'public') : null,
            'active' => $data['active'] ?? true,
            'order_prefix' => $data['order_prefix'],
            'platform_fee_percent' => $data['platform_fee_percent'] ?? null,
            'gateway_fee_percent' => $data['gateway_fee_percent'] ?? null,
            'default_courier_id' => $data['default_courier_id'] ?? null,
        ]);

        return back()->with('success', "{$data['name']} চ্যানেল যোগ করা হয়েছে।");
    }

    public function update(StoreSalesChannelRequest $request, SalesChannel $channel)
    {
        $this->authorize('update', $channel);

        $data = $request->validated();

        $fields = [
            'name' => $data['name'],
            'platform_type' => $data['platform_type'],
            'active' => $data['active'] ?? $channel->active,
            'order_prefix' => $data['order_prefix'],
            'platform_fee_percent' => $data['platform_fee_percent'] ?? null,
            'gateway_fee_percent' => $data['gateway_fee_percent'] ?? null,
            'default_courier_id' => $data['default_courier_id'] ?? null,
        ];

        if ($request->hasFile('logo') || ! empty($data['remove_logo'])) {
            if ($channel->logo_path) {
                Storage::disk('public')->delete($channel->logo_path);
            }
            $fields['logo_path'] = $request->hasFile('logo') ? $request->file('logo')->store('channel-logos', 'public') : null;
        }

        $channel->update($fields);

        return back()->with('success', "{$channel->name} চ্যানেল আপডেট করা হয়েছে।");
    }

    public function destroy(SalesChannel $channel)
    {
        $this->authorize('delete', $channel);

        // Hard delete, not soft — a channel has no online orders pointing
        // at it yet (Part 2 builds that); once orders exist, Part 2's own
        // migration will need to decide whether this stays allowed. Price
        // overrides cascade via the FK, same as everywhere else in the app.
        $channel->delete();

        return back()->with('success', 'চ্যানেল মুছে ফেলা হয়েছে।');
    }

    /**
     * Per-channel price-override list — every product, with this channel's
     * own override (if any) alongside its normal price, so the owner can
     * see at a glance what's overridden vs. falling through to the default.
     */
    public function prices(SalesChannel $channel)
    {
        $this->authorize('view', $channel);

        $overrides = $channel->priceOverrides()->pluck('price', 'product_id');

        return Inertia::render('App/OnlineBusiness/Channels/Prices', [
            'channel' => $channel,
            'products' => Product::orderBy('name')->get(['id', 'name', 'price', 'discount_price'])
                ->map(fn (Product $p) => [
                    'id' => $p->id, 'name' => $p->name,
                    'default_price' => (float) ($p->discount_price ?? $p->price),
                    'override_price' => $overrides->has($p->id) ? (float) $overrides->get($p->id) : null,
                ]),
        ]);
    }

    /** Set, or clear (null price), this channel's override for one product. */
    public function setPrice(Request $request, SalesChannel $channel, Product $product)
    {
        $this->authorize('update', $channel);

        $data = $request->validate(['price' => ['nullable', 'numeric', 'min:0']]);

        if ($data['price'] === null) {
            $channel->priceOverrides()->where('product_id', $product->id)->delete();

            return back();
        }

        ChannelProductPrice::updateOrCreate(
            ['channel_id' => $channel->id, 'product_id' => $product->id],
            ['shop_id' => Tenancy::id(), 'price' => $data['price']],
        );

        return back();
    }
}
