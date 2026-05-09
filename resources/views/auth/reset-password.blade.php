<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Splitmate Password Reset</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f6f7fb; color: #1f2937; margin: 0; }
        .wrap { max-width: 440px; margin: 48px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 8px 28px rgba(0,0,0,.08); padding: 24px; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { color: #4b5563; margin: 0 0 16px; }
        label { display:block; font-size: 13px; margin: 12px 0 6px; color: #6b7280; }
        input { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; }
        button { margin-top: 16px; width: 100%; border: 0; border-radius: 12px; padding: 12px; background: #2563eb; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .error { background: #fef2f2; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .ok { background: #ecfdf5; color: #065f46; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Reset Password</h1>

        @if(!empty($successMessage))
            <div class="ok">{{ $successMessage }}</div>
        @endif

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        @if(!$isValid)
            <p>This reset link is invalid or expired. Please request a new one from the app.</p>
        @else
            <p>Set a new password for <strong>{{ $email }}</strong>.</p>
            <form method="POST" action="{{ route('password.reset.update') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <input type="hidden" name="token" value="{{ $token }}">

                <label>New Password</label>
                <input type="password" name="password" required minlength="8" autocomplete="new-password">

                <label>Confirm New Password</label>
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">

                <button type="submit">Reset Password</button>
            </form>
        @endif
    </div>
</div>
</body>
</html>
