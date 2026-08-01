<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    public function index()
    {
        $logs = ActivityLog::with('user')->latest('id')->paginate(50)->withQueryString();
        $logs->getCollection()->transform(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'user' => $log->user ? ['name' => $log->user->name] : null,
            'created_at' => $log->created_at->diffForHumans(),
        ]);

        return Inertia::render('App/Activity/Index', ['logs' => $logs]);
    }
}
