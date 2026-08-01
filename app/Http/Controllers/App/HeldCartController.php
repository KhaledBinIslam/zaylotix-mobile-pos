<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\HeldCart;
use Illuminate\Http\Request;

/**
 * A held cart is just a JSON snapshot of client-side cart state, parked
 * server-side — checkout itself (PosController::checkout) is the only place
 * that actually validates stock/prices, exactly the same as a cart that was
 * never held, so no business-rule validation happens here.
 */
class HeldCartController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'cart' => ['required', 'array', 'min:1'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        HeldCart::create([
            'label' => $data['label'] ?? null,
            'cart_data' => [
                'cart' => $data['cart'],
                'discount' => $data['discount'] ?? 0,
                'customer_phone' => $data['customer_phone'] ?? '',
                'customer_name' => $data['customer_name'] ?? '',
            ],
        ]);

        return back()->with('success', 'বিল হোল্ড করা হয়েছে।');
    }

    /** Returns the held cart's data as JSON (consumed via fetch, not a full Inertia visit) and removes it — resuming puts it back in the active cart, so it's no longer "held". */
    public function resume(HeldCart $heldCart)
    {
        $data = $heldCart->cart_data;
        $heldCart->delete();

        return response()->json($data);
    }

    public function destroy(HeldCart $heldCart)
    {
        $heldCart->delete();

        return back()->with('success', 'হোল্ড করা বিল মুছে ফেলা হয়েছে।');
    }
}
