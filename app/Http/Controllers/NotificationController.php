<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View { return view('notifications.index', ['notifications' => $request->user()->notifications()->paginate(20)]); }
    public function read(Request $request, string $notification): RedirectResponse {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        return back()->with('status', 'Notification marked as read.');
    }
    public function readAll(Request $request): RedirectResponse {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        return back()->with('status', 'All notifications marked as read.');
    }
}
