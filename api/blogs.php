<?php
/**
 * MarketMoke - Blog Management API (AJAX CRUD, Search, Filter & Pagination)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

// Categories are editorial data in their own right: keeping them in a small
// table means a category added from the editor is available before a post is
// published and remains available even when its last post is removed.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS blog_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO blog_categories (name)
        SELECT DISTINCT TRIM(category) FROM blog_posts
        WHERE category IS NOT NULL AND TRIM(category) != ''");
} catch (Throwable $e) {
    error_log('[Blog categories setup] ' . $e->getMessage());
}

if ($method === 'GET') {
    // The full editor needs its select options together so that author details
    // and category choices always reflect the data currently in the dashboard.
    if (!empty($_GET['editor_context'])) {
        try {
            $categories = $db->query("SELECT name FROM blog_categories ORDER BY name ASC")
                ->fetchAll(PDO::FETCH_COLUMN);
            if (!$categories) {
                $categories = ['General'];
            }

            $authors = [];
            $authorStmt = $db->query("SELECT name, company FROM users
                WHERE status = 'active' AND role IN ('admin', 'editor', 'author')
                ORDER BY name ASC");
            foreach ($authorStmt->fetchAll() as $author) {
                $name = trim((string)$author['name']);
                if ($name === '') continue;
                $authors[$name] = [
                    'name' => $name,
                    'publication' => trim((string)$author['company']) ?: 'Independent editorial team'
                ];
            }
            foreach ($db->query("SELECT DISTINCT author FROM blog_posts WHERE TRIM(author) != '' ORDER BY author ASC")->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = trim((string)$name);
                if ($name !== '' && !isset($authors[$name])) {
                    $authors[$name] = ['name' => $name, 'publication' => 'MarketMoke contributor'];
                }
            }
            if (!$authors) {
                $authors['Admin'] = ['name' => 'Admin', 'publication' => 'MarketMoke Editorial'];
            }

            jsonResponse(['success' => true, 'data' => [
                'categories' => array_values($categories),
                'authors' => array_values($authors)
            ]]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Unable to load editor settings: ' . $e->getMessage()], 500);
        }
    }

    // 1. Single Blog Post Retrieval by Slug or ID
    if (!empty($_GET['slug']) || !empty($_GET['id'])) {
        $slug = trim($_GET['slug'] ?? '');
        $id = (int)($_GET['id'] ?? 0);

        try {
            $where = [];
            $params = [];

            if (!empty($slug)) {
                $where[] = "slug = :slug";
                $params[':slug'] = $slug;
            } elseif ($id > 0) {
                $where[] = "id = :id";
                $params[':id'] = $id;
            }

            // Public access restriction: Draft, unpublished, or archived posts must not be exposed to non-staff
            if (!hasRole('admin') && !hasRole('editor') && !hasRole('author')) {
                $where[] = "status = 'published'";
            }

            $sql = "SELECT * FROM blog_posts WHERE " . implode(" AND ", $where) . " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $post = $stmt->fetch();

            if (!$post) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Article not found or unpublished.'
                ], 404);
            }

            // Fetch related published posts (same category first, latest next, excluding current post)
            $cat = $post['category'] ?? '';
            $currentId = (int)$post['id'];
            $relStmt = $db->prepare("
                SELECT id, title, slug, featured_image, category, publish_date, author, meta_description, created_at 
                FROM blog_posts 
                WHERE status = 'published' AND id != :cid 
                ORDER BY (category = :cat) DESC, id DESC 
                LIMIT 3
            ");
            $relStmt->execute([':cid' => $currentId, ':cat' => $cat]);
            $relatedPosts = $relStmt->fetchAll();

            jsonResponse([
                'success' => true,
                'data' => $post,
                'related_posts' => $relatedPosts
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Error retrieving article: ' . $e->getMessage()], 500);
        }
    }

    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(5, min(50, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    // For public users without admin/editor session, hide unpublished posts by default unless explicitly permitted
    if (!hasRole('admin') && !hasRole('editor') && empty($status)) {
        $where[] = "status = 'published'";
    }

    if (!empty($search)) {
        $where[] = "(title LIKE :search OR author LIKE :search OR category LIKE :search OR content LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if (!empty($status)) {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }

    if (!empty($category)) {
        $where[] = "category = :category";
        $params[':category'] = $category;
    }

    $whereClause = implode(" AND ", $where);

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM blog_posts WHERE $whereClause");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM blog_posts WHERE $whereClause ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        $categories = $db->query("SELECT DISTINCT category FROM blog_posts ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

        jsonResponse([
            'success' => true,
            'message' => 'Blog posts loaded',
            'data' => [
                'posts' => $posts,
                'pagination' => [
                    'current_page' => $page,
                    'limit' => $limit,
                    'total_records' => $totalRecords,
                    'total_pages' => ceil($totalRecords / $limit)
                ],
                'categories' => $categories
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!hasRole('admin') && !hasRole('editor') && !hasRole('author')) {
        // Fallback for session or author payload
        if (empty($_SESSION['user_id']) && empty($input['author']) && empty($_POST['author'])) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized. Editorial privileges required.'], 403);
        }
    }

    $action = $input['action'] ?? '';

    if ($action === 'add_category') {
        $categoryName = trim((string)($input['name'] ?? ''));
        if ($categoryName === '' || mb_strlen($categoryName) > 100) {
            jsonResponse(['success' => false, 'message' => 'Enter a category name up to 100 characters.'], 422);
        }
        try {
            $stmt = $db->prepare('INSERT INTO blog_categories (name) VALUES (:name) ON DUPLICATE KEY UPDATE name = VALUES(name)');
            $stmt->execute([':name' => $categoryName]);
            jsonResponse(['success' => true, 'message' => 'Category is ready to use.', 'data' => ['name' => $categoryName]]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Unable to add category: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'add') {
        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $featuredImage = trim($input['featured_image'] ?? '');
        $author = trim($input['author'] ?? 'Admin');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? 'General');
        $tags = is_array($input['tags'] ?? null) ? implode(', ', $input['tags']) : trim($input['tags'] ?? '');
        $metaTitle = trim($input['meta_title'] ?? $title);
        $metaDesc = trim($input['meta_description'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? '');
        $publishDate = !empty($input['publish_date']) ? $input['publish_date'] : date('Y-m-d');
        $status = in_array($input['status'] ?? '', ['published', 'draft', 'archived']) ? $input['status'] : 'published';
        $canonicalUrl = trim($input['canonical_url'] ?? '');
        $isFeatured = !empty($input['is_featured']) ? 1 : 0;

        if (empty($title) || empty($content)) {
            jsonResponse(['success' => false, 'message' => 'Post title and content are required.'], 422);
        }

        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO blog_posts 
                (title, slug, featured_image, author, content, category, tags, meta_title, meta_description, seo_keywords, publish_date, status, canonical_url, is_featured)
                VALUES
                (:title, :slug, :image, :author, :content, :category, :tags, :meta_title, :meta_desc, :keywords, :pub_date, :status, :canonical, :featured)
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':image' => $featuredImage,
                ':author' => $author,
                ':content' => $content,
                ':category' => $category,
                ':tags' => $tags,
                ':meta_title' => $metaTitle,
                ':meta_desc' => $metaDesc,
                ':keywords' => $seoKeywords,
                ':pub_date' => $publishDate,
                ':status' => $status,
                ':canonical' => $canonicalUrl,
                ':featured' => $isFeatured
            ]);

            $newId = (int)$db->lastInsertId();
            $newPost = [
                'id' => $newId,
                'title' => $title,
                'slug' => $slug,
                'featured_image' => $featuredImage,
                'author' => $author,
                'content' => $content,
                'category' => $category,
                'tags' => $tags,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'seo_keywords' => $seoKeywords,
                'publish_date' => $publishDate,
                'status' => $status,
                'canonical_url' => $canonicalUrl,
                'is_featured' => (bool)$isFeatured,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            jsonResponse(['success' => true, 'message' => 'Blog post published successfully!', 'data' => $newPost], 201);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to save blog post: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'edit') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid post ID.'], 400);
        }

        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $featuredImage = trim($input['featured_image'] ?? '');
        $author = trim($input['author'] ?? 'Admin');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? 'General');
        $tags = is_array($input['tags'] ?? null) ? implode(', ', $input['tags']) : trim($input['tags'] ?? '');
        $metaTitle = trim($input['meta_title'] ?? $title);
        $metaDesc = trim($input['meta_description'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? '');
        $publishDate = !empty($input['publish_date']) ? $input['publish_date'] : date('Y-m-d');
        $status = in_array($input['status'] ?? '', ['published', 'draft', 'archived']) ? $input['status'] : 'published';
        $canonicalUrl = trim($input['canonical_url'] ?? '');
        $isFeatured = !empty($input['is_featured']) ? 1 : 0;

        try {
            $stmt = $db->prepare("
                UPDATE blog_posts 
                SET title = :title, slug = :slug, featured_image = :image, author = :author, content = :content,
                    category = :category, tags = :tags, meta_title = :meta_title, meta_description = :meta_desc,
                    seo_keywords = :keywords, publish_date = :pub_date, status = :status,
                    canonical_url = :canonical, is_featured = :featured
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':image' => $featuredImage,
                ':author' => $author,
                ':content' => $content,
                ':category' => $category,
                ':tags' => $tags,
                ':meta_title' => $metaTitle,
                ':meta_desc' => $metaDesc,
                ':keywords' => $seoKeywords,
                ':pub_date' => $publishDate,
                ':status' => $status,
                ':canonical' => $canonicalUrl,
                ':featured' => $isFeatured,
                ':id' => $id
            ]);

            $updatedPost = [
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'featured_image' => $featuredImage,
                'author' => $author,
                'content' => $content,
                'category' => $category,
                'tags' => $tags,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'seo_keywords' => $seoKeywords,
                'publish_date' => $publishDate,
                'status' => $status,
                'canonical_url' => $canonicalUrl,
                'is_featured' => (bool)$isFeatured,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            jsonResponse(['success' => true, 'message' => 'Blog post updated successfully!', 'data' => $updatedPost]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update post: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($input['id'] ?? 0);
        $newStatus = in_array($input['status'] ?? '', ['published', 'draft', 'archived']) ? $input['status'] : 'published';
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid post ID.'], 400);
        }

        try {
            $stmt = $db->prepare("UPDATE blog_posts SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $id]);
            jsonResponse(['success' => true, 'message' => 'Post status updated to ' . $newStatus]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'upload_image') {
        // Support base64 image payload (from JSON fetch)
        if (!empty($input['image_data']) && strpos($input['image_data'], 'data:image/') === 0) {
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $input['image_data'], $matches)) {
                $ext = $matches[1] === 'jpeg' ? 'jpg' : strtolower($matches[1]);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (in_array($ext, $allowedExts)) {
                    $uploadDir = __DIR__ . '/../uploads/blog';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $safeName = 'blog_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
                    file_put_contents($uploadDir . '/' . $safeName, base64_decode($matches[2]));
                    $webUrl = '/uploads/blog/' . $safeName;
                    jsonResponse([
                        'success' => true,
                        'url' => $webUrl,
                        'message' => 'Image uploaded successfully',
                        'data' => ['url' => $webUrl, 'filename' => $safeName]
                    ]);
                }
            }
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error occurred.'], 400);
        }

        $file = $_FILES['file'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $allowedExts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $maxBytes     = 5 * 1024 * 1024; // 5MB max

        if ($file['size'] > $maxBytes) {
            jsonResponse(['success' => false, 'message' => 'File size exceeds maximum 5MB limit.'], 422);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            jsonResponse(['success' => false, 'message' => 'Invalid file extension. Only JPG, PNG, WEBP, and GIF allowed.'], 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes)) {
            jsonResponse(['success' => false, 'message' => 'MIME type verification failed. Upload rejected.'], 422);
        }

        $uploadDir = __DIR__ . '/../uploads/blog';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeName = 'blog_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . '/' . $safeName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $webUrl = '/uploads/blog/' . $safeName;
            jsonResponse([
                'success' => true,
                'message' => 'Blog featured image uploaded securely!',
                'data'    => ['url' => $webUrl, 'filename' => $safeName]
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to store uploaded image on server.'], 500);
        }
    } elseif ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid post ID.'], 400);
        }

        try {
            $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            jsonResponse(['success' => true, 'message' => 'Blog post deleted successfully.']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
