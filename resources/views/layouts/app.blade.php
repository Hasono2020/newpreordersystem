<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Preorder System')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 220px; }
        body { background: #f4f6f9; font-size: .9rem; }
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: #1e2a3a;
            position: fixed;
            top: 0; left: 0;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 100;
        }
        .sidebar .brand { padding: 1rem 1.25rem; color: #fff; font-weight: 700; font-size: 1rem; border-bottom: 1px solid rgba(255,255,255,.1); }
        .sidebar .nav-link { color: rgba(255,255,255,.72); padding: .5rem 1.25rem; border-radius: 0; display: flex; align-items: center; gap: .55rem; font-size: .85rem; }
        .sidebar .nav-link i { font-size: .95rem; flex-shrink: 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
        .sidebar .nav-section { color: rgba(255,255,255,.38); font-size: .65rem; text-transform: uppercase; letter-spacing: .08em; padding: .85rem 1.25rem .3rem; }
        .main-content { margin-left: var(--sidebar-width); padding: 1.5rem; }
        .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: .65rem 1.5rem; margin: -1.5rem -1.5rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
        .badge-status { font-size: .75rem; }
        .table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 600; }
        .card { border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .stat-card { border-left: 4px solid; }
        .stat-card.blue { border-color: #3b82f6; }
        .stat-card.green { border-color: #10b981; }
        .stat-card.yellow { border-color: #f59e0b; }
        .stat-card.red { border-color: #ef4444; }
        /* Prevent Bootstrap Icons SVG fallback blobs */
        .bi::before { max-width: 1em; max-height: 1em; overflow: hidden; }
    </style>
    @stack('styles')
</head>
<body>

<div class="sidebar">
    <div class="brand"><i class="bi bi-bag-heart-fill me-2"></i>PreOrder System</div>
    <nav class="mt-2">
        <div class="nav-section">Main</div>
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>

        <div class="nav-section">Orders</div>
        <a href="{{ route('trips.index') }}" class="nav-link {{ request()->routeIs('trips.*') ? 'active' : '' }}">
            <i class="bi bi-airplane"></i> Trips
        </a>
        <a href="{{ route('orders.index') }}" class="nav-link {{ request()->routeIs('orders.*') ? 'active' : '' }}">
            <i class="bi bi-cart3"></i> Orders
        </a>
        <a href="{{ route('purchasing.index') }}" class="nav-link {{ request()->routeIs('purchasing.*') ? 'active' : '' }}">
            <i class="bi bi-box-seam"></i> Purchasing
        </a>

        <div class="nav-section">Catalog</div>
        <a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'active' : '' }}">
            <i class="bi bi-tags"></i> Products
        </a>

        <div class="nav-section">Settings</div>
        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
            <i class="bi bi-people"></i> Customers
        </a>
        <a href="{{ route('suppliers.index') }}" class="nav-link {{ request()->routeIs('suppliers.*') ? 'active' : '' }}">
            <i class="bi bi-building"></i> Suppliers
        </a>
        <a href="{{ route('shipping.index') }}" class="nav-link {{ request()->routeIs('shipping.*') ? 'active' : '' }}">
            <i class="bi bi-truck"></i> Shipping Areas
        </a>
        <a href="{{ route('promos.index') }}" class="nav-link {{ request()->routeIs('promos.*') ? 'active' : '' }}">
            <i class="bi bi-percent"></i> Promo Rules
        </a>
        @if(auth()->user()?->isAdmin())
        <a href="{{ route('staff.index') }}" class="nav-link {{ request()->routeIs('staff.*') ? 'active' : '' }}">
            <i class="bi bi-person-badge"></i> Staff Accounts
        </a>
        <a href="{{ route('settings.index') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}">
            <i class="bi bi-shop"></i> Store Settings
        </a>
        @endif
    </nav>
</div>

<div class="main-content">
    <div class="topbar">
        <div class="fw-semibold text-dark">@yield('page-title', 'Dashboard')</div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small"><i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name }}</span>
            <form action="{{ route('logout') }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Logout</button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
