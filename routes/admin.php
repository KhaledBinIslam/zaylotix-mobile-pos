<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BusinessTypeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopDataController;
use App\Http\Controllers\Admin\ShopExportController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SystemHealthController;
use Illuminate\Support\Facades\Route;

// NOTE: this file is included under the `admin.` name prefix + `/admin` path
// prefix by bootstrap/app.php. Every route here runs on the `web` middleware
// stack; the `admin` alias additionally enforces the separate admin guard.

Route::middleware('guest:admin')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('admin')->group(function () {
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('shops', [ShopController::class, 'index'])->name('shops.index');
    Route::get('shops/create', [ShopController::class, 'create'])->name('shops.create');
    Route::post('shops', [ShopController::class, 'store'])->name('shops.store');
    Route::get('shops/{shop}/edit', [ShopController::class, 'edit'])->name('shops.edit');
    Route::put('shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
    Route::post('shops/{shop}/toggle-status', [ShopController::class, 'toggleStatus'])->name('shops.toggleStatus');
    Route::post('shops/{shop}/branches', [ShopController::class, 'createBranch'])->name('shops.branches.store');
    Route::get('shops/{shop}', [ShopController::class, 'show'])->name('shops.show');
    Route::get('shops/{shop}/export', [ShopExportController::class, 'download'])->name('shops.export');
    Route::get('shops/{shop}/export-sql', [ShopExportController::class, 'downloadSql'])->name('shops.exportSql');

    // support tooling — remove one bad record inside a shop on request,
    // without wiping the whole shop (see ShopDataController)
    Route::delete('shops/{shop}/products/{product}', [ShopDataController::class, 'destroyProduct'])->name('shops.products.destroy');
    Route::delete('shops/{shop}/customers/{customer}', [ShopDataController::class, 'destroyCustomer'])->name('shops.customers.destroy');
    Route::delete('shops/{shop}/sales/{sale}', [ShopDataController::class, 'destroySale'])->name('shops.sales.destroy');

    // any admin (including 'support') can impersonate for troubleshooting —
    // every start is written to the audit log, which is the real safeguard
    Route::post('shops/{shop}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate.start');
    Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');

    // free-text notification to one shop's owner, or every active shop's owner (shop_id omitted = broadcast)
    Route::post('notifications', [NotificationController::class, 'store'])->name('notifications.store');

    Route::get('activity-log', [AdminActivityLogController::class, 'index'])->name('activityLog.index');
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('system-health', [SystemHealthController::class, 'index'])->name('systemHealth.index');

    // reshaping the platform itself (deleting a shop, business types,
    // feature catalog, other admin accounts) is super_admin-only
    Route::middleware('super.admin')->group(function () {
        Route::delete('shops/{shop}', [ShopController::class, 'destroy'])->name('shops.destroy');

        Route::get('business-types', [BusinessTypeController::class, 'index'])->name('businessTypes.index');
        Route::post('business-types', [BusinessTypeController::class, 'store'])->name('businessTypes.store');
        Route::put('business-types/{businessType}', [BusinessTypeController::class, 'update'])->name('businessTypes.update');

        Route::get('features', [FeatureController::class, 'index'])->name('features.index');
        Route::post('features', [FeatureController::class, 'store'])->name('features.store');
        Route::put('features/{feature}', [FeatureController::class, 'update'])->name('features.update');

        Route::get('site-settings', [SiteSettingController::class, 'edit'])->name('siteSettings.edit');
        Route::post('site-settings', [SiteSettingController::class, 'update'])->name('siteSettings.update');
        Route::delete('site-settings', [SiteSettingController::class, 'destroy'])->name('siteSettings.destroy');
        Route::patch('site-settings/reminder-days', [SiteSettingController::class, 'updateReminderDays'])->name('siteSettings.reminderDays');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{filename}/download', [BackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{filename}', [BackupController::class, 'destroy'])->name('backups.destroy');
        Route::post('backups/{filename}/restore', [BackupController::class, 'restore'])->name('backups.restore');

        Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
        Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
        Route::put('admins/{admin}', [AdminUserController::class, 'update'])->name('admins.update');
        Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->name('admins.destroy');
    });
});
