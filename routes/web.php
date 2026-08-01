<?php

use App\Http\Controllers\App\Auth\LoginController;
use App\Http\Controllers\App\AccountsController;
use App\Http\Controllers\App\ActivityLogController;
use App\Http\Controllers\App\BarcodeLabelController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DamageController;
use App\Http\Controllers\App\ExpenseController;
use App\Http\Controllers\App\ExportController;
use App\Http\Controllers\App\GatewayCheckoutController;
use App\Http\Controllers\App\GatewayWebhookController;
use App\Http\Controllers\App\CashTransactionController;
use App\Http\Controllers\App\HeldCartController;
use App\Http\Controllers\App\HomeController;
use App\Http\Controllers\App\LoanController;
use App\Http\Controllers\App\LoanPaymentController;
use App\Http\Controllers\App\MedicineCatalogController;
use App\Http\Controllers\App\MoreController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\PaymentController;
use App\Http\Controllers\App\PaymentGatewayController;
use App\Http\Controllers\App\PosController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ProductImportController;
use App\Http\Controllers\App\ProductUnitController;
use App\Http\Controllers\App\ProductVariantController;
use App\Http\Controllers\App\PromotionController;
use App\Http\Controllers\App\PurchaseController;
use App\Http\Controllers\App\QuotationController;
use App\Http\Controllers\App\SerialLookupController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\RestaurantTableController;
use App\Http\Controllers\App\ReturnController;
use App\Http\Controllers\App\SalesController;
use App\Http\Controllers\App\SettingController;
use App\Http\Controllers\App\StaffController;
use App\Http\Controllers\App\StockCountController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\SupplierPaymentController;
use App\Http\Controllers\App\SupplierReturnController;
use App\Http\Controllers\App\TableOrderController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Deliberately not Route::redirect('/', '/login') — this app has no route
 * named `home` or `dashboard` (the shop one is `app.home`, admin's is
 * `admin.dashboard`), so Laravel's `guest` middleware falls back to
 * redirecting an already-authenticated visitor back to `/` when they land
 * on /login — which then bounced them straight back to /login, forever
 * (ERR_TOO_MANY_REDIRECTS). Sending an authenticated visitor to their own
 * home directly breaks that loop instead of feeding it.
 */
Route::get('/', function () {
    if (Auth::guard('web')->check()) {
        return redirect()->route('app.home');
    }
    if (Auth::guard('admin')->check()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['shop', 'subscription'])->prefix('app')->name('app.')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // always reachable regardless of permission grants
    Route::get('home', [HomeController::class, 'index'])->name('home');
    Route::get('more', [MoreController::class, 'index'])->name('more');
    Route::patch('settings/lang', [SettingController::class, 'updateLang'])->name('settings.lang');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    Route::middleware('perm:pos')->group(function () {
        Route::get('pos', [PosController::class, 'index'])->name('pos');
        Route::post('pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::get('pos/barcode/{barcode}', [PosController::class, 'barcode'])
            ->middleware('sales.mode')->name('pos.barcode');
        Route::get('customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');

        Route::post('pos/gateway/{provider}/initiate', [GatewayCheckoutController::class, 'initiate'])->name('gatewayCheckout.initiate');
        Route::get('pos/gateway/status/{reference}', [GatewayCheckoutController::class, 'status'])->name('gatewayCheckout.status');

        Route::post('pos/held-carts', [HeldCartController::class, 'store'])->name('heldCarts.store');
        Route::post('pos/held-carts/{heldCart}/resume', [HeldCartController::class, 'resume'])->name('heldCarts.resume');
        Route::delete('pos/held-carts/{heldCart}', [HeldCartController::class, 'destroy'])->name('heldCarts.destroy');

        Route::middleware('feature:quotations')->group(function () {
            Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
            Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
            Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
            Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
        });

        Route::middleware('feature:restaurant_tables')->group(function () {
            Route::get('restaurant/tables', [RestaurantTableController::class, 'index'])->name('restaurant.tables.index');
            Route::post('restaurant/tables', [RestaurantTableController::class, 'store'])->name('restaurant.tables.store');
            Route::delete('restaurant/tables/{restaurantTable}', [RestaurantTableController::class, 'destroy'])->name('restaurant.tables.destroy');
            Route::post('restaurant/tables/{restaurantTable}/open', [RestaurantTableController::class, 'open'])->name('restaurant.tables.open');
            Route::post('restaurant/tables/{restaurantTable}/transfer', [RestaurantTableController::class, 'transfer'])->name('restaurant.tables.transfer');
            Route::post('restaurant/tables/{restaurantTable}/merge', [RestaurantTableController::class, 'merge'])->name('restaurant.tables.merge');

            Route::get('restaurant/orders/{tableOrder}', [TableOrderController::class, 'show'])->name('restaurant.orders.show');
            Route::post('restaurant/orders/{tableOrder}/items', [TableOrderController::class, 'addItem'])->name('restaurant.orders.items.store');
            Route::delete('restaurant/order-items/{tableOrderItem}', [TableOrderController::class, 'removeItem'])->name('restaurant.orderItems.destroy');
            Route::patch('restaurant/order-items/{tableOrderItem}/decrement', [TableOrderController::class, 'decrementItem'])->name('restaurant.orderItems.decrement');
            Route::post('restaurant/order-items/{tableOrderItem}/toggle-served', [TableOrderController::class, 'toggleServed'])->name('restaurant.orderItems.toggleServed');
            Route::patch('restaurant/orders/{tableOrder}/meta', [TableOrderController::class, 'updateMeta'])->name('restaurant.orders.meta');
            Route::post('restaurant/orders/{tableOrder}/kot', [TableOrderController::class, 'printKot'])->name('restaurant.orders.kot');
            Route::post('restaurant/orders/{tableOrder}/bill', [TableOrderController::class, 'bill'])->name('restaurant.orders.bill');
            Route::post('restaurant/orders/{tableOrder}/cancel', [TableOrderController::class, 'cancel'])->name('restaurant.orders.cancel');
        });
    });

    Route::middleware('perm:stock')->group(function () {
        Route::get('stock', [ProductController::class, 'index'])->name('stock');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/{product}/stock-in', [ProductController::class, 'stockIn'])->name('products.stockIn');

        Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import.store');

        Route::get('medicine-catalog/search', [MedicineCatalogController::class, 'search'])->name('medicineCatalog.search');

        Route::middleware('feature:unit_conversion')->group(function () {
            Route::post('products/{product}/units', [ProductUnitController::class, 'store'])->name('productUnits.store');
            Route::delete('product-units/{productUnit}', [ProductUnitController::class, 'destroy'])->name('productUnits.destroy');
        });

        Route::middleware('feature:product_variants')->group(function () {
            Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('productVariants.store');
            Route::put('product-variants/{productVariant}', [ProductVariantController::class, 'update'])->name('productVariants.update');
            Route::post('product-variants/{productVariant}/stock-in', [ProductVariantController::class, 'stockIn'])->name('productVariants.stockIn');
            Route::delete('product-variants/{productVariant}', [ProductVariantController::class, 'destroy'])->name('productVariants.destroy');
        });

        Route::middleware('feature:serial_tracking')->group(function () {
            Route::get('serials', [SerialLookupController::class, 'index'])->name('serials.index');
        });
    });

    Route::middleware('perm:sales_history')->group(function () {
        Route::get('sales', [SalesController::class, 'index'])->name('sales');
        Route::get('sales/{sale}', [SalesController::class, 'show'])->name('sales.show');
    });

    // void is owner-only regardless of sales_history grant — a cashier who
    // can view sales history must never be able to reverse one
    Route::middleware('owner')->group(function () {
        Route::delete('sales/{sale}', [SalesController::class, 'destroy'])->name('sales.destroy');
    });

    Route::middleware(['perm:barcode_labels', 'feature:barcode_printing'])->group(function () {
        Route::get('barcode-labels', [BarcodeLabelController::class, 'index'])->name('barcodeLabels.index');
    });

    Route::middleware('perm:customers')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    });

    Route::middleware('perm:due')->group(function () {
        Route::post('customers/{customer}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('customers/{customer}/payments/full', [PaymentController::class, 'full'])->name('payments.full');
    });

    Route::middleware(['perm:purchases', 'feature:purchases'])->group(function () {
        Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'markReceived'])->name('purchases.receive');
    });

    Route::middleware(['perm:expenses', 'feature:expenses'])->group(function () {
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    });

    Route::middleware(['perm:damages', 'feature:damages'])->group(function () {
        Route::post('damages', [DamageController::class, 'store'])->name('damages.store');
    });

    Route::middleware(['perm:returns', 'feature:returns'])->group(function () {
        Route::post('returns', [ReturnController::class, 'store'])->name('returns.store');
    });

    Route::middleware(['perm:stock_count', 'feature:stock_count'])->group(function () {
        Route::post('stock-count', [StockCountController::class, 'store'])->name('stockCount.store');
    });

    Route::middleware(['perm:accounts', 'feature:accounts'])->group(function () {
        Route::get('accounts', [AccountsController::class, 'index'])->name('accounts');
        Route::get('accounts/ledger', [CashTransactionController::class, 'index'])->name('cashLedger.index');
        Route::post('accounts/ledger', [CashTransactionController::class, 'store'])->name('cashLedger.store');
        Route::get('accounts/loans', [LoanController::class, 'index'])->name('loans.index');
        Route::post('accounts/loans', [LoanController::class, 'store'])->name('loans.store');
        Route::post('accounts/loans/{loan}/payments', [LoanPaymentController::class, 'store'])->name('loans.payments.store');
    });

    Route::middleware(['perm:reports', 'feature:reports'])->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports');
    });

    Route::middleware(['perm:export', 'feature:export'])->group(function () {
        Route::get('export/{kind}', [ExportController::class, 'download'])->name('export');
    });

    Route::middleware('perm:settings')->group(function () {
        Route::post('settings/logo', [SettingController::class, 'updateLogo'])->name('settings.logo');
        Route::patch('settings/receipt-footer', [SettingController::class, 'updateReceiptFooter'])->name('settings.receiptFooter');
        Route::patch('settings/hardware-scanner', [SettingController::class, 'updateHardwareScanner'])->name('settings.hardwareScanner');

        Route::middleware('feature:vat')->group(function () {
            Route::patch('settings/vat', [SettingController::class, 'updateVat'])->name('settings.vat');
        });

        Route::middleware('feature:loyalty_points')->group(function () {
            Route::patch('settings/loyalty', [SettingController::class, 'updateLoyalty'])->name('settings.loyalty');
        });

        Route::middleware('feature:restaurant_tables')->group(function () {
            Route::patch('settings/kitchen-whatsapp', [SettingController::class, 'updateKitchenWhatsapp'])->name('settings.kitchenWhatsapp');
            Route::patch('settings/restaurant-prefs', [SettingController::class, 'updateRestaurantPrefs'])->name('settings.restaurantPrefs');
        });
    });

    // cashier management — owner-only, regardless of any permission grant,
    // and additionally an admin-controlled feature (a plan tier can decide
    // whether a shop is allowed a cashier at all)
    Route::middleware(['owner', 'feature:cashier_management'])->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });

    // activity log — owner-only (a cashier reviewing an audit trail of
    // their own actions defeats the point), admin-gated like every other
    // capability
    Route::middleware(['owner', 'feature:activity_log'])->group(function () {
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity');
    });

    // payment gateway credentials — owner-only regardless of any 'settings'
    // permission delegation; a cashier login must never see or configure a
    // merchant API secret, same reasoning as staff/activity-log above
    Route::middleware('owner')->group(function () {
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->name('paymentGateways.index');
        Route::post('payment-gateways/{provider}', [PaymentGatewayController::class, 'store'])->name('paymentGateways.store');
        Route::patch('payment-gateways/{provider}/toggle', [PaymentGatewayController::class, 'toggle'])->name('paymentGateways.toggle');
        Route::delete('payment-gateways/{provider}', [PaymentGatewayController::class, 'destroy'])->name('paymentGateways.destroy');
    });

    Route::middleware(['perm:promotions', 'feature:promotions'])->group(function () {
        Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
    });

    Route::middleware(['perm:purchases', 'feature:suppliers'])->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->name('suppliers.payments.store');
        Route::post('supplier-returns', [SupplierReturnController::class, 'store'])->name('supplierReturns.store');
    });
});

/**
 * Payment gateway webhook/callback — genuinely public: bKash/Nagad/
 * SSLCommerz call these with no session, no CSRF token, and no shop
 * context of their own (see GatewayWebhookController's docblock for how
 * tenancy gets resolved anyway). Both names point at the same handler —
 * SSLCommerz uses the webhook (IPN) and callback (success/fail/cancel
 * redirect) separately, bKash/Nagad only ever hit the callback one — but
 * the handler is idempotent either way, so nothing depends on exactly
 * which route a given provider happens to call.
 */
Route::withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->match(['get', 'post'], 'payment-gateway/{provider}/webhook', [GatewayWebhookController::class, 'webhook'])->name('gateway.webhook');
Route::withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->match(['get', 'post'], 'payment-gateway/{provider}/callback', [GatewayWebhookController::class, 'callback'])->name('gateway.callback');
