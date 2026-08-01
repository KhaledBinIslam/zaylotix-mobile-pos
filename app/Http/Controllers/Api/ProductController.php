<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::with(['category', 'unit'])->orderByDesc('id')->get());
    }

    public function barcode(string $barcode)
    {
        $product = Product::where('barcode', $barcode)->first();

        return $product
            ? response()->json(['found' => true, 'product' => $product])
            : response()->json(['found' => false], 404);
    }
}
