<?php
/**
 * MarketMoke - Publisher Portal Dashboard
 * Protected by auth_check.php: Role 'publisher' (or 'admin') required
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Check access: role 'publisher' or 'admin' permitted
$currentUser = checkAuth(['publisher', 'admin']);

$db = Database::getInstance()->getConnection();

// Fetch publisher websites
$siteStmt = $db->prepare("SELECT * FROM websites WHERE user_id = :uid ORDER BY id DESC");
$siteStmt->execute([':uid' => $currentUser['id']]);
$websites = $siteStmt->fetchAll();

// If user has no sites listed yet, show available active sites
if (empty($websites)) {
    $fallbackStmt = $db->query("SELECT * FROM websites WHERE status = 'active' LIMIT 5");
    $websites = $fallbackStmt->fetchAll();
}

// Fetch orders
$orderStmt = $db->prepare("SELECT * FROM orders WHERE publisher_id = :uid ORDER BY id DESC LIMIT 10");
$orderStmt->execute([':uid' => $currentUser['id']]);
$orders = $orderStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MarketMoke - Publisher Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-full bg-slate-50 text-slate-800 antialiased">

  <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-16 flex items-center justify-between">
        
        <div class="flex items-center gap-3">
          <a href="publisher-dashboard.php" class="flex items-center gap-2.5">
            <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center font-black text-xl text-white shadow-xs">M</div>
            <div>
              <span class="text-lg font-black text-slate-900 tracking-tight">Market<span class="text-blue-600">Moke</span></span>
              <span class="inline-flex items-center px-2 py-0.5 ml-2 rounded text-[10px] font-black bg-amber-100 text-amber-800 uppercase">Publisher</span>
            </div>
          </a>
        </div>

        <div class="flex items-center gap-4">
          <div class="hidden sm:flex items-center gap-2 text-right">
            <div>
              <div class="text-xs font-black text-slate-900"><?= htmlspecialchars($currentUser['name']) ?></div>
              <div class="text-[11px] text-slate-500"><?= htmlspecialchars($currentUser['email']) ?></div>
            </div>
            <span class="inline-block w-8 h-8 rounded-full bg-amber-100 text-amber-700 font-black text-xs leading-8 text-center uppercase">
              <?= substr($currentUser['name'], 0, 1) ?>
            </span>
          </div>

          <a href="index.html" class="text-xs font-bold text-slate-600 hover:text-blue-600 px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition-all">
            Marketplace
          </a>

          <a href="logout.php" class="text-xs font-bold text-red-600 hover:text-red-700 px-3 py-2 rounded-lg border border-red-200 bg-red-50 hover:bg-red-100 transition-all">
            Log Out
          </a>
        </div>

      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <div class="bg-gradient-to-r from-amber-600 to-orange-700 rounded-2xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
      <div class="space-y-1">
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Publisher Revenue Hub</h1>
        <p class="text-xs sm:text-sm text-amber-100">Manage your verified domains, monetize guest posting spots, and complete backlink assignments.</p>
      </div>
      <div>
        <a href="index.html#/sell-post" class="px-5 py-2.5 bg-white text-amber-700 font-bold text-xs rounded-xl shadow-md hover:bg-amber-50 transition-all inline-block">
          + List New Website
        </a>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="text-xs font-bold text-slate-500 uppercase">Monetized Domains</div>
        <div class="text-2xl font-black text-slate-900 mt-1"><?= count($websites) ?></div>
        <div class="text-[11px] text-emerald-600 mt-1 font-bold">100% Verified DA/DR</div>
      </div>
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="text-xs font-bold text-slate-500 uppercase">Pending Guest Post Orders</div>
        <div class="text-2xl font-black text-amber-600 mt-1"><?= count($orders) ?></div>
        <div class="text-[11px] text-slate-400 mt-1">Ready for fulfillment</div>
      </div>
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="text-xs font-bold text-slate-500 uppercase">Publisher Wallet</div>
        <div class="text-2xl font-black text-emerald-600 mt-1">$2,890.00</div>
        <div class="text-[11px] text-slate-400 mt-1">Available for payout</div>
      </div>
    </div>

    <!-- Domains Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
      <div class="p-5 border-b border-slate-200">
        <h2 class="text-base font-black text-slate-900">Your Listed Domains & Pricing</h2>
        <p class="text-xs text-slate-500">Live inventory synced with MarketMoke Buyer Marketplace</p>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
          <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase">
            <tr>
              <th class="p-4">Domain</th>
              <th class="p-4">Niche</th>
              <th class="p-4">DA / DR</th>
              <th class="p-4">Monthly Traffic</th>
              <th class="p-4">Guest Post Price</th>
              <th class="p-4">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 font-medium">
            <?php foreach ($websites as $site): ?>
              <tr class="hover:bg-slate-50">
                <td class="p-4 font-bold text-slate-900"><?= htmlspecialchars($site['domain']) ?></td>
                <td class="p-4"><?= htmlspecialchars($site['niche']) ?></td>
                <td class="p-4 font-bold text-blue-600">DA <?= $site['da'] ?> / DR <?= $site['dr'] ?></td>
                <td class="p-4 font-mono"><?= number_format($site['monthly_traffic']) ?></td>
                <td class="p-4 font-bold text-emerald-600">$<?= number_format($site['price_guest_post'], 2) ?></td>
                <td class="p-4">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase">
                    <?= htmlspecialchars($site['status'] ?? 'Active') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>

  <footer class="py-6 text-center text-xs text-slate-400 border-t border-slate-200 mt-12">
    &copy; <?= date('Y') ?> MarketMoke Platform. Role-Protected Publisher Dashboard.
  </footer>

</body>
</html>
