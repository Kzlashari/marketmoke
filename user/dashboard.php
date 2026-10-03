<?php
/**
 * MarketMoke - Single Page User Dashboard (SPA Architecture with AJAX)
 * Protected by auth_check.php: Any authenticated user
 */

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';

// Check access: any authenticated user permitted
$currentUser = checkAuth();
$db = Database::getInstance()->getConnection();
$basePath = function_exists('getAppBasePath') ? getAppBasePath() : '';
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>User Dashboard | MarketMoke</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
    .font-mono { font-family: 'JetBrains Mono', monospace; }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #f1f5f9; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    .modal-backdrop { background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); }
  </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased selection:bg-blue-600 selection:text-white flex flex-col">

  <!-- Main Shell Wrapper -->
  <div class="flex h-screen overflow-hidden">

    <!-- DESKTOP & MOBILE SIDEBAR -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 bg-slate-900 text-slate-300 flex flex-col transition-transform duration-300 -translate-x-full md:translate-x-0 md:static md:inset-0 shadow-2xl md:shadow-none border-r border-slate-800">
      
      <!-- Brand Logo Header -->
      <div class="h-16 px-5 flex items-center justify-between border-b border-slate-800 bg-slate-950/50">
        <a href="#dashboard" onclick="loadUserModule('dashboard'); return false;" class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-blue-600 text-white font-black text-lg flex items-center justify-center shadow-lg shadow-blue-600/30">M</div>
          <div>
            <div class="text-white font-black text-base tracking-tight leading-none">Market<span class="text-blue-500">Moke</span></div>
            <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase mt-1">Buyer Dashboard</div>
          </div>
        </a>
        <button id="closeSidebarBtn" class="md:hidden text-slate-400 hover:text-white p-1" onclick="toggleUserSidebar(false)">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Navigation Menu -->
      <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1.5" id="user-sidebar-nav">
        
        <a href="#dashboard" data-module="dashboard" onclick="navigateUser('dashboard', event)" class="user-nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800/80">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            <span>Dashboard</span>
          </div>
        </a>

        <a href="#marketplace" data-module="marketplace" onclick="navigateUser('marketplace', event)" class="user-nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800/80">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Marketplace</span>
          </div>
          <span class="text-[10px] bg-blue-500/20 text-blue-400 font-bold px-1.5 py-0.5 rounded-md">Browse</span>
        </a>

        <a href="#profile" data-module="profile" onclick="navigateUser('profile', event)" class="user-nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800/80">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>My Profile</span>
          </div>
        </a>

        <a href="#settings" data-module="settings" onclick="navigateUser('settings', event)" class="user-nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-400 hover:text-white hover:bg-slate-800/80">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Settings</span>
          </div>
        </a>

      </nav>

      <!-- Sidebar User Profile Footer -->
      <div class="p-3 border-t border-slate-800 bg-slate-950/40">
        <div class="p-2.5 rounded-xl bg-slate-800/60 border border-slate-700/50 flex items-center justify-between">
          <div class="flex items-center gap-2.5 min-w-0">
            <div class="w-8 h-8 rounded-full bg-blue-600 text-white font-black text-xs flex items-center justify-center shrink-0 shadow-xs">
              <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($currentUser['name']) ?></p>
              <p class="text-[10px] text-slate-400 font-mono truncate"><?= htmlspecialchars($currentUser['email']) ?></p>
            </div>
          </div>
          <a href="/logout.php" title="Sign Out" class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          </a>
        </div>
      </div>

    </aside>

    <!-- Overlay for Mobile Drawer -->
    <div id="userSidebarOverlay" class="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-xs hidden md:hidden" onclick="toggleUserSidebar(false)"></div>

    <!-- MAIN BODY CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
      
      <!-- STICKY TOP HEADER -->
      <header class="h-16 bg-white border-b border-slate-200 sticky top-0 z-20 flex items-center justify-between px-4 sm:px-6 lg:px-8 shadow-xs">
        
        <div class="flex items-center gap-3">
          <button id="mobileMenuBtn" class="md:hidden p-2 text-slate-600 hover:text-slate-900 rounded-lg hover:bg-slate-100" onclick="toggleUserSidebar(true)">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>

          <nav class="flex items-center gap-2 text-xs font-semibold text-slate-500" aria-label="Breadcrumb">
            <span class="text-slate-400">Account</span>
            <span>/</span>
            <span id="userBreadcrumbTitle" class="text-slate-900 font-bold">Dashboard Overview</span>
          </nav>
        </div>

        <div class="flex items-center gap-3">
          <button onclick="refreshUserModule()" title="Refresh Data via AJAX" class="p-2 text-slate-500 hover:text-blue-600 rounded-xl hover:bg-slate-100 transition-colors flex items-center gap-1.5 text-xs font-bold">
            <svg id="userRefreshSpinner" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span class="hidden sm:inline">Refresh</span>
          </button>

          <a href="/" target="_blank" class="text-xs font-bold text-slate-700 hover:text-blue-600 px-3 py-2 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            <span class="hidden sm:inline">Marketplace</span>
          </a>

          <a href="/logout.php" class="text-xs font-bold text-red-600 hover:text-red-700 px-3 py-2 rounded-xl border border-red-200 bg-red-50 hover:bg-red-100 transition-all">
            Logout
          </a>
        </div>
      </header>

      <!-- DYNAMIC AJAX CONTENT CONTAINER -->
      <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-slate-50 relative" id="user-main-scroll-container">
        <!-- Toast notifications container -->
        <div id="user-toast-container" class="fixed top-20 right-6 z-50 flex flex-col gap-2 pointer-events-none"></div>

        <!-- The Single Page Content Area -->
        <div id="user-dashboard-content" class="max-w-7xl mx-auto space-y-6">
          <div class="animate-pulse space-y-6">
            <div class="h-10 bg-slate-200 rounded-xl w-1/4"></div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
            </div>
            <div class="h-80 bg-slate-200 rounded-2xl"></div>
          </div>
        </div>
      </main>

    </div>
  </div>

  <!-- REUSABLE MODAL CONTAINER -->
  <div id="userModalContainer" class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop hidden">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
      <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <h3 id="userModalTitle" class="text-base font-black text-slate-900">Modal Window</h3>
        <button onclick="closeUserModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div id="userModalBody" class="p-6 overflow-y-auto flex-1"></div>
    </div>
  </div>

  <!-- Subfolder-aware Fetch Interceptor & Configuration -->
  <script>
    window.APP_BASE_PATH = <?= json_encode($basePath) ?>;
    (function() {
      var origFetch = window.fetch;
      window.fetch = function(url, init) {
        init = init || {};
        var method = String(init.method || 'GET').toUpperCase();
        var token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
          var headers = new Headers(init.headers || {});
          headers.set('X-CSRF-Token', token);
          init.headers = headers;
        }
        return origFetch.call(this, url, init);
      };
      if (window.APP_BASE_PATH) {
        var origFetch = window.fetch;
        window.fetch = function(url, init) {
          if (typeof url === 'string') {
            if (url.startsWith('/api/')) {
              url = window.APP_BASE_PATH + url;
            } else if (url.startsWith('/assets/')) {
              url = window.APP_BASE_PATH + url;
            } else if (url.startsWith('/uploads/')) {
              url = window.APP_BASE_PATH + url;
            }
          }
          return origFetch.call(this, url, init);
        };
      }
    })();
  </script>

  <!-- VANILLA JS AJAX USER DASHBOARD ENGINE -->
  <script src="<?= htmlspecialchars($basePath) ?>/assets/js/user-spa.js"></script>
</body>
</html>
