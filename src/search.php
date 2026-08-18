<?php
require __DIR__ . '/includes.php';

$pdo = db();

$q         = trim($_GET['q'] ?? '');
$status    = $_GET['status'] ?? '';
$priority  = $_GET['priority'] ?? '';
$group     = $_GET['group'] ?? '';
$agent     = $_GET['agent'] ?? '';
$company   = $_GET['company'] ?? '';
$requester = trim($_GET['requester'] ?? '');
$requesterId = $_GET['requester_id'] ?? '';

if ($requesterId !== '') {
    // Reflect the active filter back into the visible search field, since
    // arriving here is a click, not a form submission.
    $nameStmt = $pdo->prepare("SELECT name FROM requesters WHERE id = :id");
    $nameStmt->execute([':id' => $requesterId]);
    if ($resolvedName = $nameStmt->fetchColumn()) {
        $requester = $resolvedName;
    }
}
$dateFrom  = $_GET['from'] ?? '';
$dateTo    = $_GET['to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;

$where  = ['t.deleted = 0'];
$params = [];

if ($q !== '') {
    $where[] = '(MATCH(t.subject, t.description) AGAINST(:q IN NATURAL LANGUAGE MODE) OR t.id IN (SELECT ticket_id FROM notes WHERE MATCH(body) AGAINST(:q2 IN NATURAL LANGUAGE MODE)))';
    $params[':q'] = $q;
    $params[':q2'] = $q;
}
if ($status !== '') {
    $where[] = 't.status_name = :status';
    $params[':status'] = $status;
}
if ($priority !== '') {
    $where[] = 't.priority_name = :priority';
    $params[':priority'] = $priority;
}
if ($group !== '') {
    $where[] = 't.group_id = :group';
    $params[':group'] = $group;
}
if ($agent !== '') {
    $where[] = 't.responder_id = :agent';
    $params[':agent'] = $agent;
}
if ($company !== '') {
    $where[] = 't.company_id = :company';
    $params[':company'] = $company;
}
if ($requesterId !== '') {
    // Exact match — used when arriving via a "click a requester's name" link.
    $where[] = 't.requester_id = :requester_id';
    $params[':requester_id'] = $requesterId;
} elseif ($requester !== '') {
    $where[] = 't.requester_name LIKE :requester';
    $params[':requester'] = '%' . $requester . '%';
}
if ($dateFrom !== '') {
    $where[] = 't.created_at >= :dfrom';
    $params[':dfrom'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 't.created_at <= :dto';
    $params[':dto'] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT t.id, t.subject, t.requester_id, t.requester_name, t.responder_id, t.responder_name, t.status_name,
               t.priority_name, t.created_at, t.group_id, g.name AS group_name
        FROM tickets t
        LEFT JOIN `groups` g ON g.id = t.group_id
        WHERE $whereSql
        ORDER BY t.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$statuses  = $pdo->query("SELECT DISTINCT status_name FROM tickets WHERE status_name IS NOT NULL ORDER BY status_name")->fetchAll(PDO::FETCH_COLUMN);
$priorities = $pdo->query("SELECT DISTINCT priority_name FROM tickets WHERE priority_name IS NOT NULL ORDER BY priority_name")->fetchAll(PDO::FETCH_COLUMN);
$groups    = $pdo->query("SELECT id, name FROM `groups` ORDER BY name")->fetchAll();
$agentsList = $pdo->query("SELECT id, name FROM agents ORDER BY name")->fetchAll();
$companiesList = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return http_build_query($params);
}

// Windowed page list: first, last, current ± $delta, with '…' for gaps —
// avoids rendering hundreds of page links in a single unbroken row.
function paginationItems(int $current, int $total, int $delta = 2): array {
    $keep = [];
    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || ($i >= $current - $delta && $i <= $current + $delta)) {
            $keep[] = $i;
        }
    }
    $items = [];
    $prev = null;
    foreach ($keep as $i) {
        if ($prev !== null && $i - $prev > 1) {
            $items[] = '…';
        }
        $items[] = $i;
        $prev = $i;
    }
    return $items;
}

pageHead('Search — Ticket Archive');
?>

<form class="panel filters" method="get">
  <div class="field search-field">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" placeholder="Subject, description, or reply text…" value="<?= h($q) ?>">
  </div>
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">Any</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= h($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="priority">Priority</label>
    <select id="priority" name="priority">
      <option value="">Any</option>
      <?php foreach ($priorities as $p): ?>
        <option value="<?= h($p) ?>" <?= $p === $priority ? 'selected' : '' ?>><?= h($p) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="group">Group</label>
    <select id="group" name="group">
      <option value="">Any</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int)$g['id'] ?>" <?= (string)$g['id'] === $group ? 'selected' : '' ?>><?= h($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="agent">Agent</label>
    <select id="agent" name="agent">
      <option value="">Any</option>
      <?php foreach ($agentsList as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (string)$a['id'] === $agent ? 'selected' : '' ?>><?= h($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="company">Company</label>
    <select id="company" name="company">
      <option value="">Any</option>
      <?php foreach ($companiesList as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $company ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="requester">Requester</label>
    <input type="text" id="requester" name="requester" placeholder="Name contains…" value="<?= h($requester) ?>">
  </div>
  <div class="field">
    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= h($dateFrom) ?>">
  </div>
  <div class="field">
    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= h($dateTo) ?>">
  </div>
  <div class="field actions">
    <button type="submit">Search</button>
    <a href="search.php" class="clear-link">Clear</a>
  </div>
</form>

<div class="result-count"><?= number_format($total) ?> ticket<?= $total === 1 ? '' : 's' ?> found</div>

<?php if (empty($tickets)): ?>
  <div class="empty">No tickets match those filters.</div>
<?php else: ?>
  <ul class="tickets">
    <?php foreach ($tickets as $t): ?>
      <li>
        <a class="ticket-link" href="ticket.php?id=<?= (int)$t['id'] ?>">
          <div class="ticket-row">
            <span class="ticket-subject"><?= h($t['subject'] ?: '(no subject)') ?></span>
            <span class="ticket-id">#<?= (int)$t['id'] ?></span>
          </div>
        </a>
        <div class="ticket-meta">
          <span class="badge <?= $t['status_name'] === 'Closed' ? 'status-closed' : 'status-open' ?>"><?= h($t['status_name'] ?: 'Unknown') ?></span>
          <?php if ($t['requester_id']): ?>
            <a class="meta-link" href="?<?= qs(['requester_id' => $t['requester_id'], 'requester' => null, 'page' => null]) ?>"><?= h($t['requester_name'] ?: 'Unknown requester') ?></a>
          <?php else: ?>
            <span><?= h($t['requester_name'] ?: 'Unknown requester') ?></span>
          <?php endif; ?>
          <?php if ($t['responder_name']): ?>
            <span>Agent:
              <?php if ($t['responder_id']): ?>
                <a class="meta-link" href="?<?= qs(['agent' => $t['responder_id'], 'page' => null]) ?>"><?= h($t['responder_name']) ?></a>
              <?php else: ?>
                <?= h($t['responder_name']) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <?php if ($t['group_name']): ?><span>Group: <?= h($t['group_name']) ?></span><?php endif; ?>
          <span><?= formatDate($t['created_at']) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?<?= qs(['page' => $page - 1]) ?>">&larr; Prev</a>
      <?php endif; ?>
      <?php foreach (paginationItems($page, $totalPages) as $item): ?>
        <?php if ($item === '…'): ?>
          <span class="ellipsis">…</span>
        <?php elseif ($item === $page): ?>
          <span class="current"><?= $item ?></span>
        <?php else: ?>
          <a href="?<?= qs(['page' => $item]) ?>"><?= $item ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?<?= qs(['page' => $page + 1]) ?>">Next &rarr;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php pageFoot(); ?>
