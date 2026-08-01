<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index()
    {
        // same reasoning as CustomerController::index — every supplier you
        // owe money to must stay fully visible, only the settled/reference
        // half of the list is capped.
        $withPayable = Supplier::where('payable', '>', 0)->orderByDesc('payable')->get();
        $settled = Supplier::where('payable', '<=', 0)->orderByDesc('updated_at')->limit(300)->get();

        return Inertia::render('App/Suppliers/Index', [
            'suppliers' => $withPayable->concat($settled)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = $data['phone'] ?? null;
        $existing = $phone ? Supplier::where('phone', $phone)->first() : null;

        if ($existing) {
            return back()->withErrors([
                'phone' => "এই নম্বরে আগে থেকেই সাপ্লায়ার আছে: {$existing->name}। নতুন করে যোগ করার দরকার নেই।",
            ]);
        }

        Supplier::create([
            'name' => $data['name'],
            'phone' => $phone,
            'address' => $data['address'] ?? null,
        ]);

        return back()->with('success', "{$data['name']} added.");
    }
}
