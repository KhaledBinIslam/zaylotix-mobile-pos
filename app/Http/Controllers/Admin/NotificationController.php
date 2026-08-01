<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Notifications\AdminMessage;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Send a free-text notification to one shop's owner, or to every active
     * shop's owner at once (shop_id omitted = broadcast). Only owners are
     * notified — a cashier calling in about a system announcement isn't
     * the point of this.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['nullable', 'exists:shops,id'],
            'message' => ['required', 'string', 'max:500'],
        ]);

        $shops = isset($data['shop_id'])
            ? Shop::with('owner')->where('id', $data['shop_id'])->get()
            : Shop::with('owner')->where('status', 'active')->get();

        $sent = 0;
        foreach ($shops as $shop) {
            if ($shop->owner) {
                $shop->owner->notify(new AdminMessage($data['message']));
                $sent++;
            }
        }

        return back()->with('success', "{$sent} shop(s) notified.");
    }
}
