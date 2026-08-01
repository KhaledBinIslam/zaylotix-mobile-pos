<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function markRead(string $id)
    {
        $notification = Auth::guard('web')->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return back();
    }

    public function markAllRead()
    {
        Auth::guard('web')->user()->unreadNotifications->markAsRead();

        return back();
    }
}
