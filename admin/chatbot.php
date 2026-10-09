<?php
// admin/chatbot.php - what visitors ask the assistant (and what it could not answer)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)$_GET['days'] : 30;
$since = date('Y-m-d H:i:s', strtotime("-$days days"));
$totals = $db->getRow('SELECT COUNT(*) total, COALESCE(SUM(matched),0) answered FROM chatbot_logs WHERE created_at >= ?', [$since]);
$rate = $totals['total'] > 0 ? round($totals['answered'] / $totals['total'] * 100) : null;
$topics = $db->getRows('SELECT intent, COUNT(*) n FROM chatbot_logs WHERE created_at >= ? AND intent IS NOT NULL GROUP BY intent ORDER BY n DESC LIMIT 10', [$since]);
$unanswered = $db->getRows('SELECT question, COUNT(*) n, MAX(created_at) last_asked FROM chatbot_logs WHERE created_at >= ? AND matched = 0 GROUP BY question ORDER BY n DESC, last_asked DESC LIMIT 50', [$since]);

$pageTitle = 'Chatbot Insights';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Chatbot Insights');
?>
<form method="GET" class="card"><div class="card-body form-inline"><label for="days">Period</label>
    <select id="days" name="days" class="form-control" onchange="this.form.submit()">
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $l): ?><option value="<?php echo $d; ?>" <?php echo $d === $days ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?>
    </select></div></form>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-content"><h3><?php echo (int)$totals['total']; ?></h3><p>Questions asked</p></div></div>
    <div class="stat-card"><div class="stat-content"><h3><?php echo $rate === null ? 'N/A' : $rate . '%'; ?></h3><p>Answered confidently</p></div></div>
    <div class="stat-card"><div class="stat-content"><h3><?php echo count($unanswered); ?></h3><p>Distinct unanswered</p></div></div>
</div>
<div class="card"><div class="card-header"><h3>Questions the assistant could not answer</h3></div><div class="card-body">
<p class="text-muted">Use these to publish missing information (fees, term dates, uniforms...) or to extend the assistant. Emails and phone numbers are removed before saving.</p>
<?php if ($unanswered): ?><div class="table-responsive"><table class="table"><thead><tr><th>Question</th><th>Times</th><th>Last asked</th></tr></thead><tbody>
<?php foreach ($unanswered as $u): ?><tr><td><?php echo e($u['question']); ?></td><td><?php echo (int)$u['n']; ?></td><td><?php echo e(formatDate($u['last_asked'], 'd M Y, h:i A')); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p>Nothing unanswered in this period.</p><?php endif; ?></div></div>
<div class="card"><div class="card-header"><h3>Most requested topics</h3></div><div class="card-body">
<?php if ($topics): ?><ul class="simple-list"><?php foreach ($topics as $t): ?><li><strong><?php echo e(ucfirst($t['intent'])); ?></strong> <span class="text-muted">- <?php echo (int)$t['n']; ?> questions</span></li><?php endforeach; ?></ul>
<?php else: ?><p class="text-muted">No data yet.</p><?php endif; ?></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
