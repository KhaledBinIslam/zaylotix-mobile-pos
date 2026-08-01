<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Inertia\Inertia;

class AdminActivityLogController extends Controller
{
    public function index()
    {
        $logs = AdminActivityLog::with('admin')->latest('id')->paginate(50)->withQueryString();
        $logs->getCollection()->transform(fn (AdminActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'admin' => $log->admin ? ['name' => $log->admin->name] : null,
            'created_at' => $log->created_at->diffForHumans(),
        ]);

        return Inertia::render('Admin/ActivityLog/Index', ['logs' => $logs]);
    }
}
