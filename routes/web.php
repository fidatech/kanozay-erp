<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::post('/language', function (\Illuminate\Http\Request $request) {
    $data = $request->validate(['locale' => ['required', \Illuminate\Validation\Rule::in(['en', 'fa', 'ps'])]]);
    session(['locale' => $data['locale']]);
    return back();
})->middleware('auth')->name('language.update');

Route::middleware('auth')->group(function () {
    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals', [\App\Http\Controllers\ApprovalController::class, 'store'])->name('approvals.store');
    Route::patch('/approvals/{approval}/decide', [\App\Http\Controllers\ApprovalController::class, 'decide'])->name('approvals.decide');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('company', [\App\Http\Controllers\Admin\CompanySettingController::class, 'edit'])->name('company.edit');
    Route::put('company', [\App\Http\Controllers\Admin\CompanySettingController::class, 'update'])->name('company.update');
    Route::get('/', function () { abort_unless(auth()->user()->can('administration.view') || auth()->user()->can('users.view') || auth()->user()->can('roles.view'), 403); return view('admin.index'); })->name('index');
    Route::get('activity', function () { abort_unless(auth()->user()->can('administration.view'), 403); return view('admin.activity.index', ['activities' => \App\Models\ActivityLog::with('actor')->latest()->paginate(30)]); })->name('activity.index');
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class)->only(['index','store','update','destroy']);
    Route::resource('roles', \App\Http\Controllers\Admin\RoleController::class)->only(['index','store','update','destroy']);
});

require __DIR__.'/auth.php';
