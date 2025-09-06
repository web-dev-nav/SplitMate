<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index()
    {
        $users = User::where('is_active', true)->get();
        $inactiveUsers = User::where('is_active', false)->get();
        return view('settings.index', compact('users', 'inactiveUsers'));
    }

    public function updateUsers(Request $request)
    {
        $validated = $request->validate([
            'users' => 'required|array|min:2|max:10',
            'users.*.id' => 'nullable|exists:users,id',
            'users.*.name' => 'required|string|max:255',
        ]);

        // Update existing users and create new ones
        foreach ($validated['users'] as $index => $userData) {
            if (isset($userData['id']) && $userData['id']) {
                // Update existing user
                $user = User::find($userData['id']);
                $user->update([
                    'name' => $userData['name'],
                ]);
            } else {
                // Create new user
                User::create([
                    'name' => $userData['name'],
                    'password' => Hash::make('password123'), // Default password
                ]);
            }
        }

        return redirect()->back()->with('success', 'Users updated successfully!');
    }

    public function deleteUser(User $user)
    {
        // Soft delete: mark as inactive instead of hard delete
        $user->update(['is_active' => false]);
        return redirect()->back()->with('success', 'User removed successfully! They can be reactivated if needed.');
    }

    public function reactivateUser(User $user)
    {
        // Reactivate a soft-deleted user
        $user->update(['is_active' => true]);
        return redirect()->back()->with('success', 'User reactivated successfully!');
    }
}