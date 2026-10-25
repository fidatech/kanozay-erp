<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->can('users.view'), 403);
        return view('admin.users.index', [
            'users' => User::with('roles')->latest()->paginate(20),
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('users.create'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);
        $role = $data['role'];
        if ($role === 'Super Administrator' && !$request->user()->hasRole('Super Administrator')) abort(403);
        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->assignRole($role);
        ActivityLog::record('user.created', 'user', $user->id, 'Created employee account', ['email' => $user->email, 'role' => $role]);
        return back()->with('status', 'User created successfully.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.update'), 403);
        if ($user->hasRole('Super Administrator') && !$request->user()->hasRole('Super Administrator')) abort(403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);
        if ($data['role'] === 'Super Administrator' && !$request->user()->hasRole('Super Administrator')) abort(403);
        if ($user->is($request->user()) && $data['role'] !== 'Super Administrator' && $user->hasRole('Super Administrator')) {
            return back()->withErrors(['role' => 'You cannot remove your own Super Administrator role.']);
        }
        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        if (!empty($data['password'])) $user->update(['password' => Hash::make($data['password'])]);
        $user->syncRoles([$data['role']]);
        ActivityLog::record('user.updated', 'user', $user->id, 'Updated employee account', ['email' => $user->email, 'role' => $data['role']]);
        return back()->with('status', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.delete'), 403);
        if ($user->is($request->user()) || $user->hasRole('Super Administrator')) {
            return back()->withErrors(['user' => 'This account cannot be deleted.']);
        }
        ActivityLog::record('user.deleted', 'user', $user->id, 'Deleted employee account', ['email' => $user->email]);
        $user->delete();
        return back()->with('status', 'User deleted successfully.');
    }
}
