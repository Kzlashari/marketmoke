<?php
/**
 * MarketMoke - Page Management API with Custom Code Editor (HTML/CSS/JS)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

if ($method === 'GET') {
    if (!empty($_GET['slug']) || !empty($_GET['id'])) {
        $slugOrId = trim($_GET['slug'] ?? $_GET['id']);
        try {
            $stmt = $db->prepare("SELECT * FROM pages WHERE slug = :slug OR id = :id LIMIT 1");
            $stmt->execute([':slug' => $slugOrId, ':id' => is_numeric($slugOrId) ? (int)$slugOrId : 0]);
            $singlePage = $stmt->fetch();
            if ($singlePage) {
                jsonResponse(['success' => true, 'data' => ['page' => $singlePage]]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Page not found'], 404);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Query error: ' . $e->getMessage()], 500);
        }
    }

    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(5, min(50, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(title LIKE :search OR slug LIKE :search OR content LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if (!empty($status)) {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }

    $whereClause = implode(" AND ", $where);

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM pages WHERE $whereClause");
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM pages WHERE $whereClause ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $pages = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'message' => 'Pages loaded',
            'data' => [
                'pages' => $pages,
                'pagination' => [
                    'current_page' => $page,
                    'limit' => $limit,
                    'total_records' => $totalRecords,
                    'total_pages' => ceil($totalRecords / $limit)
                ]
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Query error: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'POST') {
    if (!hasRole('admin')) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Admin privileges required.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';

    // Sanitize function to strip any server-side execution tags like <?php, <?, <%=
    $sanitizeCode = function($code) {
        if (empty($code)) return '';
        // Ban PHP opening tags to prevent server execution
        $sanitized = preg_replace('/<\?(=|php)?/i', '&lt;?php', $code);
        return $sanitized;
    };

    if ($action === 'add') {
        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $content = trim($input['content'] ?? '');
        $customHtml = $sanitizeCode($input['custom_html'] ?? '');
        $customCss = $input['custom_css'] ?? '';
        $customJs = $input['custom_js'] ?? '';
        $metaTitle = trim($input['meta_title'] ?? $title);
        $metaDesc = trim($input['meta_description'] ?? '');
        $canonicalUrl = trim($input['canonical_url'] ?? '');
        $publishDate = !empty($input['publish_date']) ? $input['publish_date'] : date('Y-m-d');
        $status = in_array($input['status'] ?? '', ['published', 'draft']) ? $input['status'] : 'published';

        if (empty($title)) {
            jsonResponse(['success' => false, 'message' => 'Page title is required.'], 422);
        }

        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO pages 
                (title, slug, content, custom_html, custom_css, custom_js, meta_title, meta_description, canonical_url, publish_date, status)
                VALUES
                (:title, :slug, :content, :html, :css, :js, :meta_title, :meta_desc, :canonical, :pub_date, :status)
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':content' => $content,
                ':html' => $customHtml,
                ':css' => $customCss,
                ':js' => $customJs,
                ':meta_title' => $metaTitle,
                ':meta_desc' => $metaDesc,
                ':canonical' => $canonicalUrl,
                ':pub_date' => $publishDate,
                ':status' => $status
            ]);

            $newId = (int)$db->lastInsertId();
            $fetchStmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
            $fetchStmt->execute([':id' => $newId]);
            $createdPage = $fetchStmt->fetch();

            jsonResponse(['success' => true, 'message' => 'Custom page created successfully!', 'data' => ['page' => $createdPage]], 201);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to save page: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'edit') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid page ID.'], 400);
        }

        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $content = trim($input['content'] ?? '');
        $customHtml = $sanitizeCode($input['custom_html'] ?? '');
        $customCss = $input['custom_css'] ?? '';
        $customJs = $input['custom_js'] ?? '';
        $metaTitle = trim($input['meta_title'] ?? $title);
        $metaDesc = trim($input['meta_description'] ?? '');
        $canonicalUrl = trim($input['canonical_url'] ?? '');
        $publishDate = !empty($input['publish_date']) ? $input['publish_date'] : date('Y-m-d');
        $status = in_array($input['status'] ?? '', ['published', 'draft']) ? $input['status'] : 'published';

        try {
            $stmt = $db->prepare("
                UPDATE pages 
                SET title = :title, slug = :slug, content = :content, custom_html = :html,
                    custom_css = :css, custom_js = :js, meta_title = :meta_title,
                    meta_description = :meta_desc, canonical_url = :canonical,
                    publish_date = :pub_date, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':slug' => $slug,
                ':content' => $content,
                ':html' => $customHtml,
                ':css' => $customCss,
                ':js' => $customJs,
                ':meta_title' => $metaTitle,
                ':meta_desc' => $metaDesc,
                ':canonical' => $canonicalUrl,
                ':pub_date' => $publishDate,
                ':status' => $status,
                ':id' => $id
            ]);

            $fetchStmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
            $fetchStmt->execute([':id' => $id]);
            $updatedPage = $fetchStmt->fetch();

            jsonResponse(['success' => true, 'message' => 'Page updated successfully!', 'data' => ['page' => $updatedPage]]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update page: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid page ID.'], 400);
        }

        try {
            $stmt = $db->prepare("DELETE FROM pages WHERE id = :id");
            $stmt->execute([':id' => $id]);
            jsonResponse(['success' => true, 'message' => 'Page deleted successfully.']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
