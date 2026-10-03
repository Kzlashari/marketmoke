<?php
/**
 * MarketMoke - Single Page Admin Dashboard (SPA Architecture with AJAX)
 * Protected by auth_check.php: Role 'admin' required
 */

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/csrf.php';

// Check access: only role 'admin' permitted
$currentUser = checkAuth(['admin']);
$db = Database::getInstance()->getConnection();

// Fetch initial badge count for pending approvals
$pendingApprovalsCount = 0;
try {
    $pendingApprovalsCount = (int)$db->query("SELECT COUNT(*) FROM user_edit_requests WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

$basePath = function_exists('getAppBasePath') ? getAppBasePath() : '';
$adminSpaVersion = @filemtime(__DIR__ . '/../assets/js/admin-spa.js') ?: time();
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>Admin Overview | MarketMoke</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
    .font-mono { font-family: 'JetBrains Mono', monospace; }
    /* Subtle custom scrollbar */
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
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 md:w-72 bg-[#1c3558] text-slate-300 flex flex-col transition-transform duration-300 -translate-x-full md:translate-x-0 md:static md:inset-0 shadow-2xl md:shadow-none shrink-0 h-full border-r border-slate-700/30">
      
      <!-- Brand Logo Header -->
      <div class="h-16 px-5 flex items-center justify-between border-b border-slate-700/40 shrink-0">
        <a href="/admin/overview" onclick="navigate('overview', event)" class="flex items-center gap-2.5">
          <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-xs">
            <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M5 19h3V10H5v9zm6 0h3V5h-3v14zm6 0h3v-7h-3v7z"/></svg>
          </div>
          <div>
            <div class="text-base font-black text-white tracking-tight leading-none">Market<span class="text-blue-400">Moke</span></div>
            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Admin Portal</div>
          </div>
        </a>
        <button id="closeSidebarBtn" class="md:hidden text-slate-400 hover:text-white p-1" onclick="toggleSidebar(false)">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Navigation Menu -->
      <nav class="flex-1 overflow-y-auto p-4 space-y-1.5" id="sidebar-nav">
        
        <a href="/admin/overview" data-module="overview" onclick="navigate('overview', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all bg-[#2c6ecb] text-white shadow-sm">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" stroke-width="2"/><path d="M7 16l3-6 4 4 3-6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Overview</span>
          </div>
        </a>

        <a href="/admin/marketplace" data-module="marketplace" onclick="navigate('marketplace', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="2"/><path d="M3.6 9h16.8M3.6 15h16.8" stroke-width="2"/><path d="M11.5 3a17 17 0 0 0 0 18M12.5 3a17 17 0 0 1 0 18" stroke-width="2"/></svg>
            <span>Marketplace</span>
          </div>
          <span class="px-2 py-0.5 rounded-full bg-sky-400/20 text-sky-300 text-[10px] font-bold lowercase">new</span>
        </a>

        <a href="/admin/blog" data-module="blog" onclick="navigate('blog', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span>Blog Management</span>
          </div>
        </a>

        <a href="/admin/ai-automation" data-module="ai-automation" onclick="navigate('ai-automation', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0 text-emerald-400 group-hover:text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span class="flex items-center gap-1.5">
              <span>AI Content Agent</span>
            </span>
          </div>
          <span class="px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 text-[10px] font-extrabold tracking-wide uppercase">AI</span>
        </a>

        <a href="/admin/pages" data-module="pages" onclick="navigate('pages', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Page Management</span>
          </div>
        </a>

        <a href="/admin/users" data-module="users" onclick="navigate('users', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span>User Management</span>
          </div>
          <span id="pendingApprovalsBadge" class="w-5 h-5 rounded-full bg-rose-500 text-white font-bold text-[10px] flex items-center justify-center">1</span>
        </a>

        <a href="/admin/settings" data-module="settings" onclick="navigate('settings', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Site &amp; SEO Settings</span>
          </div>
        </a>

        <a href="/admin/seo" data-module="seo" onclick="navigate('seo', event)" class="nav-item group flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all text-slate-300 hover:text-white hover:bg-white/10">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <span>PHPMailer (SMTP)</span>
          </div>
        </a>

      </nav>

      <!-- Sidebar Exit / Back to Marketplace Footer -->
      <div class="p-4 border-t border-slate-700/60 bg-slate-900/30 shrink-0">
        <a href="/" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-white/10 transition-all">
          <div class="flex items-center gap-2.5">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <span>Exit to Website</span>
          </div>
          <span class="text-[11px] text-slate-500">→</span>
        </a>
      </div>

    </aside>

    <!-- Overlay for Mobile Drawer -->
    <div id="sidebarOverlay" class="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-xs hidden md:hidden" onclick="toggleSidebar(false)"></div>

    <!-- MAIN BODY CONTENT AREA -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
      
      <!-- STICKY TOP HEADER matching image -->
      <header class="px-6 sm:px-8 pt-6 pb-4 flex items-center justify-between shrink-0 bg-[#f4f7fb]">
        
        <!-- Left: Title & Subtitle -->
        <div class="flex items-center gap-3">
          <button id="mobileMenuBtn" class="md:hidden p-2 rounded-xl bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" onclick="toggleSidebar(true)">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>

          <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">Welcome back, Admin!</h1>
            <p class="text-xs text-slate-500 font-medium mt-0.5">Date 27, 2023, 11:35 AM GMT</p>
          </div>
        </div>

        <!-- Right: Action Buttons & Avatar -->
        <div class="flex items-center gap-3 sm:gap-4 relative">
          <a href="/" title="Exit Admin Dashboard" class="hidden sm:flex items-center gap-1.5 px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200/80 rounded-xl text-xs font-bold transition-all shadow-2xs">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Exit UI</span>
          </a>

          <!-- Notification Bell with Red Dot -->
          <div class="relative">
            <button class="w-10 h-10 rounded-full bg-white hover:bg-slate-50 flex items-center justify-center text-slate-600 border border-slate-200/80 shadow-2xs relative transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
              <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-white"></span>
            </button>
          </div>

          <!-- Profile Avatar -->
          <div class="flex items-center gap-2 cursor-pointer select-none">
            <img 
              src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150&auto=format&fit=crop&q=80" 
              alt="Admin" 
              class="w-10 h-10 rounded-full object-cover ring-2 ring-white shadow-xs"
            />
          </div>
        </div>
      </header>

      <!-- DYNAMIC AJAX CONTENT CONTAINER -->
      <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-slate-50 relative" id="main-scroll-container">
        <!-- Toast notifications container -->
        <div id="toast-container" class="fixed top-20 right-6 z-50 flex flex-col gap-2 pointer-events-none"></div>

        <!-- The Single Page Content Area -->
        <div id="dashboard-content" class="max-w-7xl mx-auto space-y-6">
          <!-- Initial loading skeleton placeholder -->
          <div class="animate-pulse space-y-6">
            <div class="h-10 bg-slate-200 rounded-xl w-1/4"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
              <div class="h-28 bg-slate-200 rounded-2xl"></div>
            </div>
            <div class="h-96 bg-slate-200 rounded-2xl"></div>
          </div>
        </div>
      </main>

    </div>
  </div>

  <!-- REUSABLE MODAL CONTAINER -->
  <div id="modalContainer" class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop hidden">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
      <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <h3 id="modalTitle" class="text-base font-black text-slate-900">Modal Window</h3>
        <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div id="modalBody" class="p-6 overflow-y-auto flex-1"></div>
    </div>
  </div>

  <!-- Subfolder-aware Fetch Interceptor & Configuration -->
  <script>
    window.APP_BASE_PATH = (<?= json_encode($basePath) ?>) || "";
    window.INITIAL_ADMIN_MODULE = <?= json_encode($_GET['module'] ?? null) ?>;
    (function() {
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

  <!-- SHEETJS SPREADSHEET PARSER FOR SCAN SHEETS -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

  <!-- VANILLA JS AJAX SINGLE PAGE DASHBOARD ENGINE -->
  <script src="<?= htmlspecialchars($basePath) ?>/assets/js/admin-spa.js?v=<?= (int)$adminSpaVersion ?>"></script>
</body>
</html>
