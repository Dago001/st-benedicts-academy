<?php
// public/page.php?slug=... - a page created by the administrator under Admin > Extra Pages
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$slug = cms_slugify((string)($_GET['slug'] ?? ''));
$pg = null;
try {
    $pg = db()->getRow('SELECT * FROM site_pages WHERE slug = ? AND is_published = 1', [$slug]);
    if (!$pg && Security::hasRole('admin')) $pg = db()->getRow('SELECT * FROM site_pages WHERE slug = ?', [$slug]); // admins may preview drafts
} catch (Throwable $e) { /* not migrated */ }
if (!$pg) { http_response_code(404); }

$pageTitle = $pg['title'] ?? 'Page not found';
if (!empty($pg['summary'])) $pageDescription = $pg['summary'];
include __DIR__ . '/../includes/header.php';
?>
<main id="main-content">
<section class="page-header"><div class="container"><h1><?php echo e($pageTitle); ?></h1>
    <div class="breadcrumb"><a href="<?php echo BASE_URL; ?>/">Home</a> / <?php echo e($pageTitle); ?></div></div></section>
<section class="section" style="padding:50px 0 70px"><div class="container" style="max-width:860px">
<?php if ($pg): ?>
    <?php if (!$pg['is_published']): ?><div class="alert alert-warning">Draft - only administrators can see this page.</div><?php endif; ?>
    <?php if ($pg['image']): ?><img src="<?php echo e(BASE_URL . '/uploads/site/' . rawurlencode($pg['image'])); ?>" alt="" style="width:100%;max-height:380px;object-fit:cover;border-radius:12px;margin-bottom:24px"><?php endif; ?>
    <div class="cms-content"><?php echo cms_rich($pg['content']); ?></div>
<?php else: ?>
    <p>Sorry, we couldn't find that page.</p><p><a class="btn btn-primary" href="<?php echo BASE_URL; ?>/">Back to the home page</a></p>
<?php endif; ?>
</div></section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
