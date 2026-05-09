<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationCode;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\ApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const RESET_CODE_EXPIRY_MINUTES = 15;
    private const APPLE_ISSUER = 'https://appleid.apple.com';

    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'uuid' => Str::uuid(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ApiPayload::user($user),
        ], 201);
    }

    /**
     * Login user and return token.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && !empty($user->google_id)) {
            throw ValidationException::withMessages([
                'email' => ['This email is associated with a Google account. Please sign in with Google.'],
            ]);
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are invalid.'],
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ApiPayload::user($user),
        ]);
    }

    /**
     * Login/register using Google ID token.
     */
    public function googleLogin(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        $googleUser = $this->verifyGoogleIdToken($validated['id_token']);

        if (!$googleUser || empty($googleUser['sub']) || empty($googleUser['email'])) {
            throw ValidationException::withMessages([
                'id_token' => ['Invalid Google token.'],
            ]);
        }

        $email = strtolower(trim((string) $googleUser['email']));
        $googleId = (string) $googleUser['sub'];
        $name = trim((string) ($googleUser['name'] ?? 'Google User'));
        if ($name === '') {
            $name = 'Google User';
        }

        $user = User::where('google_id', $googleId)->first();
        if (!$user) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($user && !empty($user->google_id) && $user->google_id !== $googleId) {
            throw ValidationException::withMessages([
                'email' => ['This email is already linked to a different Google account.'],
            ]);
        }

        if ($user) {
            $existingEmailOwner = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existingEmailOwner && $existingEmailOwner->id !== $user->id) {
                throw ValidationException::withMessages([
                    'email' => ['Another account already uses this email address.'],
                ]);
            }
        }

        if (!$user) {
            $user = User::create([
                'uuid' => Str::uuid(),
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'password' => Hash::make(Str::random(40)),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->forceFill([
                'google_id' => $googleId,
                'name' => $name,
                'email' => $email,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ApiPayload::user($user->fresh()),
        ]);
    }

    /**
     * Login/register using Apple identity token.
     */
    public function appleLogin(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
            'name' => 'sometimes|nullable|string|max:255',
        ]);

        $verificationError = null;
        $appleUser = $this->verifyAppleIdentityToken($validated['id_token'], $verificationError);
        if (!$appleUser || empty($appleUser['sub'])) {
            throw ValidationException::withMessages([
                'id_token' => [$verificationError ?: 'Invalid Apple token.'],
            ]);
        }

        $email = strtolower(trim((string) ($appleUser['email'] ?? '')));
        $appleId = (string) $appleUser['sub'];
        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($appleUser['name'] ?? ''));
        }
        if ($name === '') {
            $emailLocalPart = trim((string) strstr($email, '@', true));
            if ($emailLocalPart !== '') {
                $name = ucwords(str_replace(['.', '_', '-'], ' ', $emailLocalPart));
            }
        }
        if ($name === '') {
            $name = 'Apple User';
        }

        $user = User::where('apple_id', $appleId)->first();
        if (!$user && $email !== '') {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($user && !empty($user->apple_id) && $user->apple_id !== $appleId) {
            throw ValidationException::withMessages([
                'email' => ['This email is already linked to a different Apple account.'],
            ]);
        }

        if ($user && $email !== '') {
            $existingEmailOwner = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existingEmailOwner && $existingEmailOwner->id !== $user->id) {
                throw ValidationException::withMessages([
                    'email' => ['Another account already uses this email address.'],
                ]);
            }
        }

        if (!$user && $email === '') {
            throw ValidationException::withMessages([
                'id_token' => ['Apple did not provide an email for this sign-in. Please revoke Splitmate access in Apple ID settings and try again.'],
            ]);
        }

        if (!$user) {
            $user = User::create([
                'uuid' => Str::uuid(),
                'name' => $name,
                'email' => $email,
                'apple_id' => $appleId,
                'password' => Hash::make(Str::random(40)),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        } else {
            $updates = [
                'apple_id' => $appleId,
                'is_active' => true,
                'email_verified_at' => now(),
            ];
            if ($email !== '') {
                $updates['email'] = $email;
            }
            if (
                trim((string) $user->name) === ''
                || trim((string) $user->name) === 'Apple User'
                || ($validated['name'] ?? null) !== null
            ) {
                $updates['name'] = $name;
            }
            $user->forceFill($updates)->save();
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ApiPayload::user($user->fresh()),
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json(ApiPayload::user($user));
    }

    /**
     * Update authenticated user profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $updates = [];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = trim((string) $validated['name']);
        }

        if (array_key_exists('email', $validated)) {
            $email = strtolower(trim((string) $validated['email']));
            if ($email !== strtolower((string) $user->email)) {
                $updates['email'] = $email;
                $updates['email_verified_at'] = null;
            }
        }

        if (!empty($updates)) {
            $user->forceFill($updates)->save();
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => ApiPayload::user($user->fresh()),
        ]);
    }

    /**
     * Delete account and purge personally identifiable fields.
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            EmailVerificationCode::where('user_id', $user->id)->delete();
            PasswordResetCode::where('user_id', $user->id)->delete();
            $user->groups()->detach();

            $anonymizedEmail = 'deleted+'.Str::uuid().'@splitmate.invalid';

            $user->forceFill([
                'name' => 'Deleted User',
                'email' => $anonymizedEmail,
                'google_id' => null,
                'apple_id' => null,
                'password' => Hash::make(Str::random(40)),
                'is_active' => false,
                'email_verified_at' => null,
            ])->save();

            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }

    /**
     * Logout user (revoke token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Send a one-time email verification code.
     */
    public function sendVerificationCode(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
            ]);
        }

        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            Mail::raw(
                "Your SplitMate verification code is: {$code}. It expires in 15 minutes.",
                function ($message) use ($user) {
                    $message->to($user->email)->subject('SplitMate Email Verification');
                }
            );
        } catch (\Throwable $e) {
            // Allow local/dev flows even when mail is not configured.
        }

        $response = [
            'message' => 'Verification code sent.',
        ];

        if (app()->environment('local')) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    /**
     * Verify current user's email using a one-time code.
     */
    public function verifyEmailCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
                'user' => ApiPayload::user($user),
            ]);
        }

        $record = EmailVerificationCode::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        $record->update([
            'used_at' => now(),
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => ApiPayload::user($user->fresh()),
        ]);
    }

    /**
     * Send a one-time password reset code to the user's email.
     */
    public function sendPasswordResetCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (!$user) {
            return response()->json([
                'message' => 'If the account exists, a password reset code has been sent.',
            ]);
        }

        $code = (string) random_int(100000, 999999);

        PasswordResetCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        PasswordResetCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::RESET_CODE_EXPIRY_MINUTES),
        ]);

        try {
            Mail::raw(
                "Your SplitMate password reset code is: {$code}. It expires in ".self::RESET_CODE_EXPIRY_MINUTES." minutes.",
                function ($message) use ($user) {
                    $message->to($user->email)->subject('SplitMate Password Reset');
                }
            );
        } catch (\Throwable $e) {
            // Allow local/dev flows even when mail is not configured.
        }

        $response = [
            'message' => 'If the account exists, a password reset code has been sent.',
        ];

        if (app()->environment('local')) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    /**
     * Reset password using one-time code.
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or reset code.'],
            ]);
        }

        $record = PasswordResetCode::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired reset code.'],
            ]);
        }

        $record->update([
            'used_at' => now(),
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. Please sign in again.',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifyGoogleIdToken(string $idToken): ?array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return null;
        }

        $emailVerified = strtolower((string) ($payload['email_verified'] ?? 'false'));
        if (!in_array($emailVerified, ['true', '1'], true)) {
            return null;
        }

        $configuredClientId = trim((string) env('GOOGLE_CLIENT_ID', ''));
        if ($configuredClientId !== '') {
            $audience = (string) ($payload['aud'] ?? '');
            if ($audience !== $configuredClientId) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifyAppleIdentityToken(string $idToken, ?string &$error = null): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            $error = 'Invalid Apple token format.';
            return null;
        }

        $header = $this->decodeJwtPart($parts[0]);
        $payload = $this->decodeJwtPart($parts[1]);
        if (!is_array($header) || !is_array($payload)) {
            $error = 'Invalid Apple token payload.';
            return null;
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            $error = 'Unsupported Apple token algorithm.';
            return null;
        }

        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            $error = 'Apple token key identifier missing.';
            return null;
        }

        $publicKey = $this->applePublicKeyForKid($kid);
        if (!$publicKey) {
            $error = 'Unable to load Apple public key.';
            return null;
        }

        $signedPart = $parts[0].'.'.$parts[1];
        $signature = $this->decodeBase64Url($parts[2]);
        if ($signature === null) {
            $error = 'Invalid Apple token signature format.';
            return null;
        }

        $verified = openssl_verify($signedPart, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            $error = 'Apple token signature verification failed.';
            return null;
        }

        if ((string) ($payload['iss'] ?? '') !== self::APPLE_ISSUER) {
            $error = 'Apple token issuer mismatch.';
            return null;
        }

        $configuredAudienceRaw = trim((string) env('APPLE_CLIENT_ID', ''));
        if ($configuredAudienceRaw !== '') {
            $allowedAudiences = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', $configuredAudienceRaw)
            )));

            if (!in_array((string) ($payload['aud'] ?? ''), $allowedAudiences, true)) {
                $aud = (string) ($payload['aud'] ?? '');
                $error = "Apple token audience mismatch. Token aud='{$aud}'.";
                return null;
            }
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            $error = 'Apple token has expired.';
            return null;
        }

        return $payload;
    }

    private function applePublicKeyForKid(string $kid): mixed
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://appleid.apple.com/auth/keys');
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $keys = $response->json('keys');
        if (!is_array($keys)) {
            return null;
        }

        foreach ($keys as $key) {
            if (!is_array($key) || (string) ($key['kid'] ?? '') !== $kid) {
                continue;
            }

            $publicKeyFromJwk = $this->publicKeyFromJwk($key);
            if ($publicKeyFromJwk !== null) {
                return $publicKeyFromJwk;
            }

            $x5c = $key['x5c'][0] ?? null;
            if (!is_string($x5c) || trim($x5c) === '') {
                continue;
            }

            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($x5c, 64, "\n")."-----END CERTIFICATE-----\n";
            $publicKey = openssl_pkey_get_public($pem);
            if ($publicKey !== false) {
                return $publicKey;
            }
        }

        return null;
    }

    private function publicKeyFromJwk(array $jwk): mixed
    {
        $n = $jwk['n'] ?? null;
        $e = $jwk['e'] ?? null;

        if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
            return null;
        }

        $modulus = $this->decodeBase64Url($n);
        $exponent = $this->decodeBase64Url($e);
        if ($modulus === null || $exponent === null) {
            return null;
        }

        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus).$this->asn1Integer($exponent)
        );

        $algorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithmIdentifier === false) {
            return null;
        }

        $subjectPublicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier.$this->asn1BitString($rsaPublicKey)
        );

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $publicKey = openssl_pkey_get_public($pem);
        return $publicKey === false ? null : $publicKey;
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Integer(string $value): string
    {
        if ($value === '') {
            $value = "\x00";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1BitString(string $value): string
    {
        $payload = "\x00".$value;
        return "\x03".$this->asn1Length(strlen($payload)).$payload;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $temp = '';
        while ($length > 0) {
            $temp = chr($length & 0xff).$temp;
            $length >>= 8;
        }

        return chr(0x80 | strlen($temp)).$temp;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtPart(string $part): ?array
    {
        $decoded = $this->decodeBase64Url($part);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    private function decodeBase64Url(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }
}
