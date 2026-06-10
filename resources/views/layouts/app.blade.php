<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Preorder System') — DIElectronics POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 220px;
            --topbar-height: 54px;
            --sidebar-bg: #1e2a3a;
            --sidebar-text: rgba(255,255,255,.72);
            --sidebar-active: rgba(255,255,255,.12);
            --accent: #3b82f6;
        }

        * { box-sizing: border-box; }
        body { background: #f4f6f9; font-size: .9rem; margin: 0; }

        /* ── Sidebar ─────────────────────────────────────── */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            position: fixed;
            top: 0; left: 0;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 300;
            transition: transform .25s ease;
            display: flex;
            flex-direction: column;
        }
        .sidebar .brand {
            padding: .9rem 1.25rem;
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            border-bottom: 1px solid rgba(255,255,255,.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .sidebar .brand .close-sidebar {
            display: none;
            background: none;
            border: none;
            color: rgba(255,255,255,.6);
            font-size: 1.2rem;
            padding: 0;
            cursor: pointer;
        }
        .sidebar nav { flex: 1; padding-bottom: 1rem; }
        .sidebar .nav-link {
            color: var(--sidebar-text);
            padding: .48rem 1.25rem;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: .55rem;
            font-size: .84rem;
            text-decoration: none;
            transition: background .12s, color .12s;
        }
        .sidebar .nav-link i { font-size: .92rem; flex-shrink: 0; width: 16px; text-align: center; }
        .sidebar .nav-link:hover  { color: #fff; background: var(--sidebar-active); }
        .sidebar .nav-link.active { color: #fff; background: var(--accent); }
        .sidebar .nav-section {
            color: rgba(255,255,255,.35);
            font-size: .62rem;
            text-transform: uppercase;
            letter-spacing: .09em;
            padding: .8rem 1.25rem .25rem;
        }

        /* ── Overlay (mobile) ────────────────────────────── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 299;
        }
        .sidebar-overlay.show { display: block; }

        /* ── Topbar ──────────────────────────────────────── */
        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 0 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 200;
            transition: left .25s ease;
        }
        .topbar .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #374151;
            padding: .25rem .35rem;
            margin-right: .5rem;
            cursor: pointer;
            border-radius: 4px;
        }
        .topbar .menu-toggle:hover { background: #f3f4f6; }

        /* ── Main content ────────────────────────────────── */
        .main-content {
            margin-left: var(--sidebar-width);
            padding-top: calc(var(--topbar-height) + 1.25rem);
            padding-left: 1.25rem;
            padding-right: 1.25rem;
            padding-bottom: 2rem;
            min-height: 100vh;
            transition: margin-left .25s ease;
        }

        /* ── Responsive: tablet (≤991px) ────────────────── */
        @media (max-width: 991px) {
            :root { --sidebar-width: 240px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar .brand .close-sidebar { display: block; }
            .topbar { left: 0; }
            .topbar .menu-toggle { display: block; }
            .main-content { margin-left: 0; }
        }

        /* ── Responsive: mobile (≤575px) ────────────────── */
        @media (max-width: 575px) {
            .main-content {
                padding-left: .75rem;
                padding-right: .75rem;
            }
            .topbar { padding: 0 .75rem; }
            .topbar .page-title { font-size: .85rem; }
            /* Stack cards full-width */
            .card { border-radius: 8px; }
            /* Compact tables on mobile */
            .table-responsive { font-size: .8rem; }
        }

        /* ── Utilities ───────────────────────────────────── */
        .badge-status { font-size: .75rem; }
        .table th {
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #6b7280;
            font-weight: 600;
        }
        .card {
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .stat-card { border-left: 4px solid; }
        .stat-card.blue   { border-color: #3b82f6; }
        .stat-card.green  { border-color: #10b981; }
        .stat-card.yellow { border-color: #f59e0b; }
        .stat-card.red    { border-color: #ef4444; }
        /* Prevent Bootstrap Icons SVG fallback blobs */
        .bi::before { max-width: 1em; max-height: 1em; overflow: hidden; }
        /* Alert auto-dismiss animation */
        .alert { animation: slideIn .2s ease; }
        @keyframes slideIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
    </style>
    @stack('styles')
</head>
<body>

{{-- Sidebar overlay (mobile tap-to-close) --}}
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

{{-- Sidebar --}}
<div class="sidebar" id="sidebar">
    <div class="brand">
        <span><i class="bi bi-bag-heart-fill me-2 text-primary"></i>PreOrder System</span>
        <button class="close-sidebar" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="mt-1">
        <div class="nav-section">Main</div>
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>

        <div class="nav-section">Orders</div>
        <a href="{{ route('trips.index') }}" class="nav-link {{ request()->routeIs('trips.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-airplane"></i> Trips
        </a>
        <a href="{{ route('orders.index') }}" class="nav-link {{ request()->routeIs('orders.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-cart3"></i> Orders
        </a>
        <a href="{{ route('purchasing.index') }}" class="nav-link {{ request()->routeIs('purchasing.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-box-seam"></i> Purchasing
        </a>

        <div class="nav-section">Catalog</div>
        <a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-tags"></i> Products
        </a>

        <div class="nav-section">Settings</div>
        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-people"></i> Customers
        </a>
        <a href="{{ route('suppliers.index') }}" class="nav-link {{ request()->routeIs('suppliers.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-building"></i> Suppliers
        </a>
        <a href="{{ route('shipping.index') }}" class="nav-link {{ request()->routeIs('shipping.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-truck"></i> Shipping Areas
        </a>
        <a href="{{ route('promos.index') }}" class="nav-link {{ request()->routeIs('promos.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-percent"></i> Promo Rules
        </a>
        @if(auth()->user()?->isAdmin())
        <a href="{{ route('staff.index') }}" class="nav-link {{ request()->routeIs('staff.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-person-badge"></i> Staff Accounts
        </a>
        <a href="{{ route('settings.index') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" onclick="closeSidebar()">
            <i class="bi bi-shop"></i> Store Settings
        </a>
        @endif
    </nav>
</div>

{{-- Topbar --}}
<div class="topbar">
    <div class="d-flex align-items-center">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <span class="fw-semibold text-dark page-title">@yield('page-title', 'Dashboard')</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-none d-sm-inline">
            <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name }}
            <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem;">{{ ucfirst(auth()->user()->role) }}</span>
        </span>
        <form action="{{ route('logout') }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-box-arrow-right me-1 d-none d-sm-inline"></i>Logout
            </button>
        </form>
    </div>
</div>

{{-- Main content --}}
<div class="main-content">

    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Please fix the following:</strong>
        <ul class="mb-0 mt-1 ps-3">
            @foreach($errors->all() as $error)
                <li class="small">{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $type => $icon)
    @if(session($type))
    <div class="alert alert-{{ $type === 'error' ? 'danger' : $type }} alert-dismissible fade show" role="alert">
        <i class="bi bi-{{ $icon }} me-2"></i>{{ session($type) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @endforeach

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const isOpen  = sidebar.classList.contains('open');
    sidebar.classList.toggle('open', !isOpen);
    overlay.classList.toggle('show', !isOpen);
    document.body.style.overflow = isOpen ? '' : 'hidden';
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.style.overflow = '';
}
// Close sidebar on resize to desktop
window.addEventListener('resize', () => {
    if (window.innerWidth > 991) closeSidebar();
});
// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            bsAlert?.close();
        }, 5000);
    });
});
</script>
@stack('scripts')
</body>
</html>