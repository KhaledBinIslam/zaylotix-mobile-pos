<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Admins/Index', [
            'admins' => Admin::orderBy('name')->get(['id', 'name', 'email', 'role']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:super_admin,support'],
        ]);

        $admin = Admin::create($data);
        AdminActivity::log('admin.create', "Created admin account '{$admin->name}' ({$admin->role}).", $admin);

        return back()->with('success', 'Admin account created.');
    }

    public function update(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('admins', 'email')->ignore($admin->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'in:super_admin,support'],
        ]);

        // the last remaining super_admin can't demote themselves (or be
        // demoted) — that would leave the platform with no one able to
        // manage business types/features/other admin accounts at all
        if ($admin->isSuperAdmin() && $data['role'] !== 'super_admin' && Admin::where('role', 'super_admin')->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'At least one super admin must remain.']);
        }

        $admin->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            ...(! empty($data['password']) ? ['password' => $data['password']] : []),
        ]);

        AdminActivity::log('admin.update', "Updated admin account '{$admin->name}'.", $admin);

        return back()->with('success', 'Admin account updated.');
    }

    public function destroy(Admin $admin)
    {
        // this route is already super_admin-only (see routes/admin.php), so
        // blocking self-delete is sufficient on its own to guarantee at
        // least one super admin always remains: deleting a DIFFERENT
        // super admin is only reachable while 2+ still exist.
        if ($admin->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['admin' => "You can't delete your own account."]);
        }

        AdminActivity::log('admin.delete', "Deleted admin account '{$admin->name}'.");
        $admin->delete();

        return back()->with('success', 'Admin account deleted.');
    }
}
