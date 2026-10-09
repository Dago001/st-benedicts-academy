<?php
// public/news-detail.php?id=N - one published news item / event
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$db = db();
$id = (int)($_GET['id'] ?? 0);
$item = $db->getRow("SELECT * FROM news_events WHERE id = ? AND is_published = 1", [$id]);
if (!$item) { http_response_code(404); }

$pageTitle = $item ? $item['title'] : 'Not found';
$pageDescription = $item ? mb_substr(preg_replace('/\s+/', ' ', $item['content']), 0, 160) : '';
include __DIR__ . '/../includes/header.php';

$related = $item ? $db->getRows("SELECT id, title, created_at FROM news_events WHERE is_published = 1 AND type = ? AND id <> ? ORDER BY created_at DESC LIMIT 4", [$item['type'], $id]) : [];
?>
<section class="page-section" style="max-width:860px;margin:0 auto;padding:24px 16px">
<?php if (!$item): ?>
    <h1>Story not found</h1>
    <p>That news item does not exist or is no longer published.</p>
    <a class="btn btn-primary" href="<?php echo BASE_URL; ?>/public/news">Back to News &amp; Events</a>
<?php else: ?>
    <p><a href="<?php echo BASE_URL; ?>/public/news"><i class="fas fa-arrow-left"></i> News &amp; Events</a></p>
    <article>
        <h1 style="margin-bottom:6px"><?php echo e($item['title']); ?></h1>
        <p class="text-muted">
            <i class="far fa-calendar"></i> <?php echo e(formatDate($item['type'] === 'event' && $item['event_date'] ? $item['event_date'] : $item['created_at'], 'F j, Y')); ?>
            <?php if ($item['event_time']): ?> &middot; <i class="far fa-clock"></i> <?php echo e(date('g:i A', strtotime($item['event_time']))); ?><?php endif; ?>
            <?php if ($item['venue']): ?> &middot; <i class="fas fa-map-marker-alt"></i> <?php echo e($item['venue']); ?><?php endif; ?>
        </p>
        <?php if ($item['image_path'] && is_file(UPLOAD_PATH . 'news/' . basename($item['image_path']))): ?>
            <img src="<?php echo e(BASE_URL . '/uploads/news/' . rawurlencode(basename($item['image_path']))); ?>" alt="<?php echo e($item['title']); ?>" style="width:100%;height:auto;border-radius:10px;margin:12px 0">
        <?php endif; ?>
        <div style="line-height:1.7"><?php echo nl2br(e($item['content'])); ?></div>
    </article>
    <?php if ($related): ?>
    <h3 style="margin-top:32px">More <?php echo $item['type'] === 'event' ? 'events' : 'news'; ?></h3>
    <ul>
        <?php foreach ($related as $r): ?><li><a href="news-detail?id=<?php echo (int)$r['id']; ?>"><?php echo e($r['title']); ?></a> <small class="text-muted"><?php echo e(formatDate($r['created_at'], 'M j, Y')); ?></small></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php';
