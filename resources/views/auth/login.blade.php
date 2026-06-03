<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — PreOrder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #1e2a3a; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: #fff; border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <div class="fs-2 mb-2"><i class="bi bi-bag-heart-fill text-primary"></i></div>
        <h4 class="fw-bold mb-0">PreOrder System</h4>
        <p class="text-muted small">Staff & Admin Portal</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label fw-semibold small">Email</label>
            <input type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold small">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="remember" id="remember">
            <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold">Sign In</button>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
