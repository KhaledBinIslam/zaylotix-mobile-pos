<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateShopBackupJob;
use App\Models\ShopBackup;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Owner-only self-service backup of a shop's OWN complete dataset — unlike
 * ExportController (shaped report exports) this is the full, portable
 * "everything since I started using the software" copy the production-
 * readiness audit's brief asked for, scoped to exactly one shop. Owner-
 * only (not gated by perm:export) because it includes every customer's
 * phone/due history — too sensitive for a staff grant.
 */
class ShopBackupController extends Controller
{
    public function index()
    {
        return Inertia::render('App/ShopBackups/Index', [
            'backups' => ShopBackup::with('requestedBy:id,name')
                ->latest()
                ->get()
                ->map(fn (ShopBackup $b) => [
                    'id' => $b->id,
                    'format' => $b->format,
                    'status' => $b->status,
                    'failed_reason' => $b->failed_reason,
                    'requested_by' => $b->requestedBy?->name,
                    'created_at' => $b->created_at->toIso8601String(),
                    'completed_at' => $b->completed_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['sql', 'xlsx'])],
        ]);

        $backup = ShopBackup::create([
            'shop_id' => Tenancy::id(),
            'requested_by' => Auth::guard('web')->id(),
            'format' => $data['format'],
            'status' => 'pending',
        ]);

        GenerateShopBackupJob::dispatch($backup);

        return back()->with('success', 'ব্যাকআপ তৈরি হচ্ছে — কিছুক্ষণ পর এই পেজে রিফ্রেশ করে ডাউনলোড করতে পারবেন।');
    }

    public function download(ShopBackup $shopBackup)
    {
        $this->authorize('view', $shopBackup);

        if ($shopBackup->status !== 'done' || ! $shopBackup->file_path || ! Storage::disk('local')->exists($shopBackup->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($shopBackup->file_path);
    }

    public function destroy(ShopBackup $shopBackup)
    {
        $this->authorize('delete', $shopBackup);

        if ($shopBackup->file_path) {
            Storage::disk('local')->delete($shopBackup->file_path);
        }
        $shopBackup->delete();

        return back()->with('success', 'ব্যাকআপ মুছে ফেলা হয়েছে।');
    }
}
