<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index()
    {
        return response()->json(Customer::orderByDesc('due')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'due' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = Customer::create($data + ['due' => $data['due'] ?? 0]);

        return response()->json($customer, 201);
    }
}
