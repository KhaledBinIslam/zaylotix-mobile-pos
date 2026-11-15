<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ShopResourceUsage;
use Inertia\Inertia;

/**
 * Khaled live: "ami ekta system cai amr hosting er k koto percent use
 * korteche kon shop beshi, cpu storage ram egular hisab shoho, shop
 * wise" — see ShopResourceUsage's own docblock for what's actually
 * measurable here (storage, exactly) versus what isn't (CPU/RAM — no
 * per-tenant isolation exists on this shared/Cloud hosting to measure).
 */
class ShopUsageController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Shops/Usage', ShopResourceUsage::perShop());
    }
}
