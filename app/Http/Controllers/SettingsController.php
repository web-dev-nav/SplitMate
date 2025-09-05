<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index()
    {
        $users = User::all();
        return view('settings.index', compact('users'));
    }

    public function updateUsers(Request $request)
    {
        $validated = $request->validate([
            'users' => 'required|array|min:2|max:10',
            'users.*.id' => 'nullable|exists:users,id',
            'users.*.name' => 'required|string|max:255',
            'users.*.email' => 'required|email|max:255',
        ]);

        // Update existing users and create new ones
        foreach ($validated['users'] as $index => $userData) {
            if (isset($userData['id']) && $userData['id']) {
                // Update existing user
                $user = User::find($userData['id']);
                $user->update([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                ]);
            } else {
                // Create new user
                User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => Hash::make('password123'), // Default password
                ]);
            }
        }

        return redirect()->back()->with('success', 'Users updated successfully!');
    }

    public function deleteUser(User $user)
    {
        // Check if user has any expenses or settlements
        if ($user->expenses()->count() > 0 || $user->settlementsGiven()->count() > 0 || $user->settlementsReceived()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete user with existing transactions. Please contact admin.');
        }

        $user->delete();
        return redirect()->back()->with('success', 'User deleted successfully!');
    }
}