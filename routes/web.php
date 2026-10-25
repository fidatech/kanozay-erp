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
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class)->only(['index','store','update','destroy']);
    Route::resource('roles', \App\Http\Controllers\Admin\RoleController::class)->only(['index','store','update','destroy']);
});

require __DIR__.'/auth.php';
