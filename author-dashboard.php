<?php
/**
 * MarketMoke - Author & Content Writer Portal
 * Protected by auth_check.php: Role 'author' (or 'admin') required
 * Strictly dedicated to Article & Content Publishing
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Check access: role 'author', 'editor', or 'admin' permitted
$currentUser = checkAuth(['author', 'editor', 'admin']);
$db = Database::getInstance()->getConnection();

// Fetch articles written by this author or all articles
$authorName = $currentUser['name'] ?? 'Author';
$articles = [];
try {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE author = :author OR :role IN ('admin', 'editor') ORDER BY id DESC");
    $stmt->execute([':author' => $authorName, ':role' => $currentUser['role']]);
    $articles = $stmt->fetchAll();
} catch (Exception $e) {
    // If database table not initialized in PDO, fallback to empty array
    $articles = [];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Author Portal | Article Publishing Studio - MarketMoke</title>
  <meta name="description" content="Dedicated Author Dashboard for writing and publishing articles on MarketMoke." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-full bg-slate-50 text-slate-800 antialiased">

  <!-- Header -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-16 flex items-center justify-between">
        
        <div class="flex items-center gap-3">
          <a href="dashboard" class="flex items-center gap-2.5">
            <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center font-black text-xl text-white shadow-xs">M</div>
            <div>
              <span class="text-lg font-black text-slate-900 tracking-tight">Market<span class="text-indigo-600">Moke</span></span>
              <span class="inline-flex items-center px-2 py-0.5 ml-2 rounded text-[10px] font-black bg-indigo-100 text-indigo-800 uppercase">✍️ Author Studio</span>
            </div>
          </a>
        </div>

        <div class="flex items-center gap-4">
          <div class="hidden sm:flex items-center gap-2 text-right">
            <div>
              <div class="text-xs font-black text-slate-900"><?= htmlspecialchars($currentUser['name']) ?></div>
              <div class="text-[11px] text-slate-500"><?= htmlspecialchars($currentUser['email']) ?></div>
            </div>
            <span class="inline-block w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 font-black text-xs leading-8 text-center uppercase">
              <?= substr($currentUser['name'] ?? 'A', 0, 1) ?>
            </span>
          </div>

          <a href="index.html#/blog" class="text-xs font-bold text-slate-600 hover:text-indigo-600 px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition-all">
            Public Blog
          </a>

          <a href="index.html#/author-dashboard" class="text-xs font-bold text-indigo-600 hover:text-indigo-700 px-3 py-2 rounded-lg border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 transition-all">
            SPA Studio
          </a>

          <a href="logout.php" class="text-xs font-bold text-red-600 hover:text-red-700 px-3 py-2 rounded-lg border border-red-200 bg-red-50 hover:bg-red-100 transition-all">
            Log Out
          </a>
        </div>

      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    
    <!-- Hero Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
      <div class="space-y-1.5">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-400/30 text-xs font-bold">
          <span>✍️ Author & Content Creator Portal</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Welcome, <?= htmlspecialchars($currentUser['name']) ?></h1>
        <p class="text-xs sm:text-sm text-slate-300 max-w-2xl">Your account has exclusive publishing rights to write, format, and publish articles on the MarketMoke Blog.</p>
      </div>
      <div>
        <a href="#article-composer" class="px-5 py-2.5 bg-indigo-600 text-white font-bold text-xs rounded-xl shadow-md hover:bg-indigo-500 transition-all inline-flex items-center gap-2">
          <span>+</span> Write New Article
        </a>
      </div>
    </div>

    <!-- Permission Scope Notice -->
    <div class="bg-indigo-50/80 border border-indigo-200 rounded-2xl p-4 flex items-center justify-between gap-4 shadow-xs">
      <div class="flex items-center gap-3">
        <span class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold text-base shadow-xs">✍️</span>
        <div class="text-xs">
          <div class="font-extrabold text-slate-900">Author Scope & Role Policy</div>
          <div class="text-slate-600 mt-0.5">Author accounts are exclusively permitted to write, format, and publish articles to the MarketMoke Blog. Marketplace website sales and buyer transactions are restricted.</div>
        </div>
      </div>
      <span class="px-3 py-1 bg-emerald-100 text-emerald-800 font-extrabold rounded-full text-[10px] uppercase shrink-0">
        ✓ Article Publishing Authorized
      </span>
    </div>

    <!-- Alert / Toast Banner -->
    <div id="authorAlert" class="hidden p-4 rounded-2xl text-xs font-bold transition-all"></div>

    <!-- Article Publishing Studio Form -->
    <div id="article-composer" class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-xl space-y-6">
      <div class="flex items-center justify-between pb-4 border-b border-slate-100">
        <div>
          <span class="px-2.5 py-0.5 rounded-md text-[10px] font-black uppercase bg-indigo-100 text-indigo-800">New Article</span>
          <h2 class="text-xl sm:text-2xl font-black text-slate-900 mt-1">Publish Blog Article</h2>
          <p class="text-xs text-slate-500">Draft, format, and release your article directly to the public blog.</p>
        </div>
        <a href="index.html#/author-dashboard" class="text-xs font-extrabold text-indigo-600 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 px-3.5 py-2 rounded-xl border border-indigo-200 transition-all flex items-center gap-1">
          <span>Launch SPA Studio</span> ➔
        </a>
      </div>

      <form id="authorPublishForm" class="space-y-5">
        <!-- Title -->
        <div class="space-y-1.5">
          <label for="postTitle" class="block text-xs font-black text-slate-700 uppercase">Article Title *</label>
          <input 
            type="text" 
            id="postTitle" 
            required 
            placeholder="e.g., The Ultimate Guide to Modern Backlink Strategy & Guest Posting" 
            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-bold text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white transition-all shadow-2xs"
          />
        </div>

        <!-- Slug & Category -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <label for="postSlug" class="block text-xs font-black text-slate-700 uppercase">Permalink Slug</label>
            <input 
              type="text" 
              id="postSlug" 
              placeholder="auto-generated-from-title" 
              class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white"
            />
          </div>
          <div class="space-y-1.5">
            <label for="postCategory" class="block text-xs font-black text-slate-700 uppercase">Category</label>
            <select id="postCategory" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white">
              <option value="SEO & Link Building">SEO & Link Building</option>
              <option value="Guest Posting">Guest Posting</option>
              <option value="Content Strategy">Content Strategy</option>
              <option value="SaaS Growth">SaaS Growth</option>
              <option value="Digital PR">Digital PR</option>
              <option value="Marketing Tips">Marketing Tips</option>
              <option value="General">General</option>
            </select>
          </div>
        </div>

        <!-- Cover Image -->
        <div class="space-y-1.5">
          <label for="postImage" class="block text-xs font-black text-slate-700 uppercase">Featured Cover Image (URL)</label>
          <div class="flex items-center gap-2">
            <input 
              type="url" 
              id="postImage" 
              placeholder="https://images.unsplash.com/photo-..." 
              value="https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1200&q=80"
              class="flex-1 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white"
            />
            <button type="button" onclick="document.getElementById('postImage').value='https://images.unsplash.com/photo-1551836022-d5d88e9218df?w=1200&q=80'" class="px-2.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold">
              Preset 1
            </button>
            <button type="button" onclick="document.getElementById('postImage').value='https://images.unsplash.com/photo-1455390582262-044cdead277a?w=1200&q=80'" class="px-2.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold">
              Preset 2
            </button>
          </div>
        </div>

        <!-- Excerpt -->
        <div class="space-y-1.5">
          <label for="postExcerpt" class="block text-xs font-black text-slate-700 uppercase">Summary Excerpt</label>
          <textarea id="postExcerpt" rows="2" placeholder="Short description for blog index cards..." class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white"></textarea>
        </div>

        <!-- Article Content -->
        <div class="space-y-1.5">
          <div class="flex items-center justify-between">
            <label for="postContent" class="block text-xs font-black text-slate-700 uppercase">Article Content (Markdown or HTML) *</label>
            <span id="wordCounter" class="text-[11px] text-slate-400 font-semibold">0 words</span>
          </div>
          <textarea 
            id="postContent" 
            required 
            rows="10" 
            placeholder="Write your article body here... Use ## for subheadings, **bold** for emphasis, and paragraphs separated by blank lines."
            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-normal text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:bg-white transition-all shadow-inner leading-relaxed"
          ></textarea>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-slate-100">
          <div class="text-xs text-slate-500">
            Author: <strong><?= htmlspecialchars($currentUser['name']) ?></strong>
          </div>
          <div class="flex items-center gap-3">
            <button type="button" onclick="submitAuthorPost('draft')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs rounded-xl transition-all">
              Save as Draft
            </button>
            <button type="button" onclick="submitAuthorPost('published')" id="publishSubmitBtn" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-extrabold text-xs rounded-xl shadow-md transition-all flex items-center gap-1.5">
              <span>🚀 Publish Article Live</span>
            </button>
          </div>
        </div>

      </form>
    </div>

    <!-- Recent Authored Articles Section -->
    <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-xl space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-black text-slate-900">Articles Directory</h3>
        <button onclick="loadArticles()" class="text-xs font-bold text-indigo-600 hover:underline">Refresh List</button>
      </div>
      <div id="articlesListContainer" class="text-xs text-slate-500">Loading articles...</div>
    </div>

    <script>
      const authorName = "<?= htmlspecialchars($currentUser['name']) ?>";
      
      // Auto slug
      document.getElementById('postTitle').addEventListener('input', function(e) {
        const slugInput = document.getElementById('postSlug');
        if (!slugInput.dataset.manual) {
          slugInput.value = e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }
      });
      document.getElementById('postSlug').addEventListener('input', function() {
        this.dataset.manual = "true";
      });

      // Word counter
      document.getElementById('postContent').addEventListener('input', function(e) {
        const words = e.target.value.trim().split(/\s+/).filter(Boolean).length;
        document.getElementById('wordCounter').textContent = words + ' words';
      });

      function showAlert(msg, isSuccess) {
        const el = document.getElementById('authorAlert');
        el.className = isSuccess 
          ? 'p-4 rounded-2xl text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 block'
          : 'p-4 rounded-2xl text-xs font-bold bg-rose-50 text-rose-800 border border-rose-200 block';
        el.innerHTML = msg;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }

      async function submitAuthorPost(targetStatus) {
        const title = document.getElementById('postTitle').value.trim();
        const content = document.getElementById('postContent').value.trim();
        if (!title) {
          showAlert('⚠️ Please enter an article title.', false);
          return;
        }
        if (!content) {
          showAlert('⚠️ Please enter article content.', false);
          return;
        }

        const slug = document.getElementById('postSlug').value.trim() || title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        const category = document.getElementById('postCategory').value;
        const image = document.getElementById('postImage').value.trim();
        const excerpt = document.getElementById('postExcerpt').value.trim() || content.substring(0, 160) + '...';

        const btn = document.getElementById('publishSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Publishing...';

        try {
          const headers = { 'Content-Type': 'application/json' };
          const token = localStorage.getItem('marketmoke_token');
          if (token) headers['Authorization'] = 'Bearer ' + token;

          const res = await fetch('/api/blogs', {
            method: 'POST',
            headers: headers,
            credentials: 'include',
            body: JSON.stringify({
              action: 'add',
              title: title,
              slug: slug,
              category: category,
              author: authorName,
              featured_image: image,
              excerpt: excerpt,
              content: content,
              status: targetStatus
            })
          });
          const data = await res.json();
          btn.disabled = false;
          btn.textContent = '🚀 Publish Article Live';

          if (res.ok && data.success) {
            showAlert('🎉 Article "' + title + '" successfully published live! <a href="index.html#/blog" class="underline ml-2 font-black">View on Blog ➔</a>', true);
            document.getElementById('postTitle').value = '';
            document.getElementById('postSlug').value = '';
            document.getElementById('postContent').value = '';
            document.getElementById('postExcerpt').value = '';
            loadArticles();
          } else {
            showAlert('❌ ' + (data.message || 'Failed to publish article.'), false);
          }
        } catch (err) {
          btn.disabled = false;
          btn.textContent = '🚀 Publish Article Live';
          showAlert('❌ Server error: ' + err.message, false);
        }
      }

      async function loadArticles() {
        const container = document.getElementById('articlesListContainer');
        try {
          const res = await fetch('/api/blogs?limit=50');
          const data = await res.json();
          const posts = data.data ? (data.data.posts || data.data) : (Array.isArray(data) ? data : (data.posts || []));
          if (!posts || posts.length === 0) {
            container.innerHTML = '<p class="text-slate-400 italic">No articles published yet.</p>';
            return;
          }
          container.innerHTML = `
            <div class="overflow-x-auto">
              <table class="w-full text-left text-xs">
                <thead>
                  <tr class="border-b border-slate-200 text-slate-400 uppercase font-black text-[10px]">
                    <th class="py-2">Article</th>
                    <th class="py-2">Category</th>
                    <th class="py-2">Author</th>
                    <th class="py-2">Status</th>
                    <th class="py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  \${posts.map(p => \`
                    <tr class="hover:bg-slate-50">
                      <td class="py-2.5 font-bold text-slate-800">\${p.title}</td>
                      <td class="py-2.5 text-slate-500">\${p.category || 'General'}</td>
                      <td class="py-2.5 text-slate-500">\${p.author || 'Author'}</td>
                      <td class="py-2.5">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold \${p.status === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}">
                          \${p.status}
                        </span>
                      </td>
                      <td class="py-2.5 text-right">
                        <a href="index.html#/blog-detail" onclick="localStorage.setItem('selected_blog_slug', '\${p.slug}')" class="text-indigo-600 font-bold hover:underline">View</a>
                      </td>
                    </tr>
                  \`).join('')}
                </tbody>
              </table>
            </div>
          `;
        } catch (e) {
          container.innerHTML = '<p class="text-rose-500">Error loading articles.</p>';
        }
      }

      loadArticles();
    </script>

  </main>

</body>
</html>
