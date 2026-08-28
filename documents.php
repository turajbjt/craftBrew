<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();
$message = '';
$error = '';

$canManage = can_manage_documents($user);

// Secure file viewer handling with strict path traversal prevention
if (isset($_GET['action']) && $_GET['action'] === 'view' && !empty($_GET['id'])) {
    $docId = sanitize_int($_GET['id']);
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if ($doc) {
        $filename = basename($doc['filename']);
        $filePath = DOC_UPLOAD_DIR . $filename;
        $realPath = realpath($filePath);
        $baseDirReal = realpath(DOC_UPLOAD_DIR);

        if ($realPath && $baseDirReal && strpos($realPath, $baseDirReal) === 0 && file_exists($realPath)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'txt', 'png', 'jpg', 'jpeg', 'gif'];
            
            if (in_array($ext, $allowedExtensions)) {
                if ($ext === 'pdf') {
                    header('Content-Type: application/pdf');
                } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) {
                    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
                } else {
                    header('Content-Type: text/plain; charset=utf-8');
                }
                $displayFilename = !empty($doc['original_filename']) ? $doc['original_filename'] : $filename;
                header('Content-Disposition: inline; filename="' . addslashes($displayFilename) . '"');
                header('X-Content-Type-Options: nosniff');
                readfile($realPath);
                exit;
            }
        }
    }
    http_response_code(404);
    die("File not found or access denied.");
}

// Handle Upload Document (Admin / Permitted Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    if (!$canManage) {
        http_response_code(403);
        die("Access Denied: You do not have permission to upload reference documents.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed (CSRF token error).";
    } else {
        $title = sanitize_text($_POST['title'] ?? '', 255);
        $category = sanitize_text($_POST['category'] ?? 'Reference', 50);
        $description = sanitize_text($_POST['description'] ?? '', 1000);

        if (!empty($_FILES['doc_file']['name']) && !empty($title)) {
            $maxMb = (int)get_site_setting('max_doc_upload_mb', 25);
            $maxBytes = $maxMb * 1024 * 1024;

            if ($_FILES['doc_file']['size'] > $maxBytes) {
                $error = "File size exceeds the configured maximum limit of {$maxMb}MB.";
            } else {
                $tmpFile = $_FILES['doc_file']['tmp_name'];
                $origName = basename($_FILES['doc_file']['name']);
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowedExtensions = ['pdf', 'txt', 'png', 'jpg', 'jpeg', 'gif'];

                if (in_array($ext, $allowedExtensions)) {
                    // Verify binary magic-byte MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = finfo_file($finfo, $tmpFile);
                    finfo_close($finfo);

                    $allowedMimes = [
                        'pdf'  => ['application/pdf'],
                        'txt'  => ['text/plain', 'text/x-c', 'text/x-asm'],
                        'png'  => ['image/png'],
                        'jpg'  => ['image/jpeg', 'image/pjpeg'],
                        'jpeg' => ['image/jpeg', 'image/pjpeg'],
                        'gif'  => ['image/gif']
                    ];

                    $validMime = isset($allowedMimes[$ext]) && in_array($detectedMime, $allowedMimes[$ext]);

                    if ($validMime) {
                        if (!is_dir(DOC_UPLOAD_DIR)) {
                            @mkdir(DOC_UPLOAD_DIR, 0777, true);
                            @chmod(DOC_UPLOAD_DIR, 0777);
                        }
                        $safeDiskFilename = bin2hex(random_bytes(16)) . '.' . $ext;
                        $targetPath = DOC_UPLOAD_DIR . $safeDiskFilename;

                        if (move_uploaded_file($tmpFile, $targetPath)) {
                            @chmod($targetPath, 0666);
                            $fileType = $ext === 'pdf' ? 'PDF Document' : ($ext === 'txt' ? 'Text Note' : 'Image');
                            $originalFilename = sanitize_text($origName, 255);

                            $ins = $db->prepare("INSERT INTO documents (user_id, title, category, filename, original_filename, file_type, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$user['id'], $title, $category, $safeDiskFilename, $originalFilename, $fileType, $description]);
                            $newDocId = (int)$db->lastInsertId();
                            log_admin_action('upload_document', "Uploaded document '{$title}' ({$fileType})", 'document', $newDocId);
                            $message = "Document uploaded securely and verified successfully!";
                        } else {
                            $error = "Failed to save uploaded file to storage.";
                        }
                    } else {
                        $error = "File verification failed: Binary content does not match expected {$ext} format ({$detectedMime}).";
                    }
                } else {
                    $error = "Invalid file type. Allowed formats: PDF, TXT, PNG, JPG, GIF.";
                }
            }
        } else {
            $error = "Please select a valid file and enter a document title.";
        }
    }
}

// Handle Delete Document (Admin / Permitted Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$canManage) {
        http_response_code(403);
        die("Access Denied: You do not have permission to delete reference documents.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed (CSRF token error).";
    } else {
        $docId = sanitize_int($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if ($doc) {
            $filename = basename($doc['filename']);
            $filePath = DOC_UPLOAD_DIR . $filename;
            $fileDeleted = true;

            if (file_exists($filePath)) {
                $fileDeleted = @unlink($filePath);
            }

            $del = $db->prepare("DELETE FROM documents WHERE id = ?");
            $del->execute([$docId]);
            log_admin_action('delete_document', "Deleted document '{$doc['title']}' (ID #{$docId})", 'document', $docId);

            if ($fileDeleted) {
                $message = "Document and physical file permanently deleted!";
            } else {
                $message = "Document record removed from library.";
            }
        } else {
            $error = "Document not found.";
        }
    }
}

// Handle Scan & Fix Missing Files (Admin / Permitted Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_fix') {
    if (!$canManage) {
        http_response_code(403);
        die("Access Denied: You do not have permission to scan library storage.");
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed (CSRF token error).";
    } else {
        if (!is_dir(DOC_UPLOAD_DIR)) {
            @mkdir(DOC_UPLOAD_DIR, 0777, true);
            @chmod(DOC_UPLOAD_DIR, 0777);
        }

        $allowed = ['pdf', 'txt', 'png', 'jpg', 'jpeg', 'gif'];
        $filesOnDisk = glob(DOC_UPLOAD_DIR . '*.*');
        $addedCount = 0;
        $cleanedCount = 0;

        foreach ($filesOnDisk as $filePath) {
            $filename = basename($filePath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;

            $chk = $db->prepare("SELECT id FROM documents WHERE filename = ?");
            $chk->execute([$filename]);
            if (!$chk->fetch()) {
                $fileType = $ext === 'pdf' ? 'PDF Document' : ($ext === 'txt' ? 'Text Note' : 'Image');
                $title = pathinfo($filename, PATHINFO_FILENAME);
                $title = preg_replace('/^[a-f0-9]{32}\./i', '', $title);
                $title = ucwords(str_replace(['_', '-'], ' ', $title));
                
                $ins = $db->prepare("INSERT INTO documents (user_id, title, category, filename, original_filename, file_type, description) VALUES (?, ?, 'Reference', ?, ?, ?, ?)");
                $ins->execute([$user['id'], $title, $filename, $filename, $fileType, 'Restored from upload storage']);
                $addedCount++;
            }
        }

        $allDocs = $db->query("SELECT id, filename FROM documents")->fetchAll();
        foreach ($allDocs as $docRow) {
            $docFilePath = DOC_UPLOAD_DIR . basename($docRow['filename']);
            if (!file_exists($docFilePath)) {
                $delStmt = $db->prepare("DELETE FROM documents WHERE id = ?");
                $delStmt->execute([$docRow['id']]);
                $cleanedCount++;
            }
        }

        if ($addedCount > 0 || $cleanedCount > 0) {
            $message = "Storage scan complete: Restored {$addedCount} missing file(s) and cleaned up {$cleanedCount} orphaned record(s).";
        } else {
            $message = "Storage scan complete: All uploaded files and library records are in sync!";
        }
    }
}

// Fetch all reference documents
$stmt = $db->prepare("SELECT * FROM documents ORDER BY created_at DESC");
$stmt->execute();
$documents = $stmt->fetchAll();
$csrfToken = generate_csrf_token();

$pageTitle = "Reference Library - " . APP_NAME;
$activePage = 'documents';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>📚 Brewing Document & Reference Library</h1>
        <p style="color: var(--text-muted);">
            <?= $canManage ? 'Upload, view, and organize brewing guides, cider handbooks, recipe notes, and PDF references.' : 'Browse and view reference brewing guides, cider handbooks, and PDF documentation.' ?>
        </p>
    </div>
    <?php if ($canManage): ?>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <button type="button" class="btn btn-primary" onclick="openDocModal()">📤 Upload Document</button>
            <form method="POST" action="documents.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="scan_fix">
                <button type="submit" class="btn btn-secondary">🔍 Scan & Fix Missing Files</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bbf7d0;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (empty($documents)): ?>
    <div class="card" style="text-align: center; color: var(--text-muted); padding: 3rem;">
        No reference documents in your library yet.
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($documents as $d): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span class="badge badge-primary"><?= e($d['file_type']) ?></span>
                    <small style="color: var(--text-muted);"><?= date('M d, Y', strtotime($d['created_at'])) ?></small>
                </div>
                <h3 class="card-title"><?= e($d['title']) ?></h3>
                <p class="card-subtitle"><?= e($d['description'] ?: 'Reference file') ?></p>
                <p><small style="color: var(--text-muted);">File: <code><?= e($d['original_filename'] ?: $d['filename']) ?></code></small></p>

                <div style="margin-top: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                    <a href="documents.php?action=view&id=<?= (int)$d['id'] ?>" class="btn btn-primary btn-sm" target="_blank">📖 View File</a>
                    <?php if ($canManage): ?>
                        <form method="POST" action="documents.php" onsubmit="return confirm('Are you sure you want to delete this document?');" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <button type="submit" class="btn btn-logout btn-sm">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Modal for Document Upload -->
<div id="docModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom: 1rem;">📤 Upload Reference Document</h3>
        <form method="POST" action="documents.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="upload">

            <div class="form-group">
                <label class="form-label">Document Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. BJCP Style Guidelines 2021" required>
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <option value="Guide">Brewing Guide / Manual</option>
                    <option value="Recipe Notes">Recipe Notes</option>
                    <option value="Style Specification">Style Specification</option>
                    <option value="Reference">Reference</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Select File (.pdf, .txt, .png, .jpg)</label>
                <input type="file" name="doc_file" class="form-control" accept=".pdf,.txt,.png,.jpg,.jpeg,.gif" required>
                <small style="color: var(--text-muted);">Maximum file size: <?= (int)get_site_setting('max_doc_upload_mb', 25) ?>MB</small>
            </div>

            <div class="form-group">
                <label class="form-label">Description / Summary</label>
                <input type="text" name="description" class="form-control" placeholder="Short description of this guide or reference...">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeDocModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload File</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDocModal() {
    document.getElementById('docModal').style.display = 'flex';
}
function closeDocModal() {
    document.getElementById('docModal').style.display = 'none';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
