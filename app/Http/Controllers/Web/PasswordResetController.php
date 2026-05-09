<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function show(Request $request)
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        $token = trim((string) $request->query('token', ''));

        $isValid = $this->findValidResetRecord($email, $token) !== null;

        return view('auth.reset-password', [
            'email' => $email,
            'token' => $token,
            'isValid' => $isValid,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'token' => 'required|string|min:16',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = strtolower(trim($validated['email']));
        $token = trim($validated['token']);

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        $record = $this->findValidResetRecord($email, $token);

        if (!$user || !$record) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired reset link.'],
            ]);
        }

        $record->update(['used_at' => now()]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $user->tokens()->delete();

        return view('auth.reset-password', [
            'email' => $email,
            'token' => $token,
            'isValid' => false,
            'successMessage' => 'Password reset successfully. You can now sign in to Splitmate.',
        ]);
    }

    private function findValidResetRecord(string $email, string $token): ?PasswordResetCode
    {
        if ($email === '' || $token === '') {
            return null;
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            return null;
        }

        return PasswordResetCode::where('user_id', $user->id)
            ->where('token', $token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
