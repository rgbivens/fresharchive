<?php
require __DIR__ . '/includes.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*, g.name AS group_name, c.name AS company_name
    FROM tickets t
    LEFT JOIN `groups` g ON g.id = t.group_id
    LEFT JOIN companies c ON c.id = t.company_id
    WHERE t.id = :id
");
$stmt->execute([':id' => $id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    pageHead('Ticket not found');
    echo '<div class="empty">No ticket found with that ID.</div>';
    pageFoot();
    exit;
}

$tagsStmt = $pdo->prepare("SELECT tg.name FROM tags tg JOIN ticket_tags tt ON tt.tag_id = tg.id WHERE tt.ticket_id = :id");
$tagsStmt->execute([':id' => $id]);
$tags = $tagsStmt->fetchAll(PDO::FETCH_COLUMN);

$notesStmt = $pdo->prepare("SELECT * FROM notes WHERE ticket_id = :id ORDER BY created_at ASC");
$notesStmt->execute([':id' => $id]);
$notes = $notesStmt->fetchAll();

$attStmt = $pdo->prepare("SELECT * FROM attachments WHERE ticket_id = :id ORDER BY note_id IS NULL DESC, id ASC");
$attStmt->execute([':id' => $id]);
$attachments = $attStmt->fetchAll();

$ticketAttachments = array_filter($attachments, fn($a) => $a['note_id'] === null);
$noteAttachments = [];
foreach ($attachments as $a) {
    if ($a['note_id'] !== null) {
        $noteAttachments[$a['note_id']][] = $a;
    }
}

function attachmentLink(array $a): string {
    return '<a href="' . h($a['local_path']) . '" target="_blank">📎 ' . h($a['filename']) . '</a>';
}

pageHead(($ticket['subject'] ?: '(no subject)') . ' — Ticket Archive');
?>

<a class="back-link" href="javascript:history.back()">&larr; Back to search</a>

<div class="ticket-header">
  <span class="ticket-id">#<?= (int)$ticket['id'] ?><?= $ticket['display_id'] ? ' (display #' . (int)$ticket['display_id'] . ')' : '' ?></span>
  <h1><?= h($ticket['subject'] ?: '(no subject)') ?></h1>
</div>

<dl class="meta-grid">
  <div><dt>Status</dt><dd><?= h($ticket['status_name']) ?></dd></div>
  <div><dt>Priority</dt><dd><?= h($ticket['priority_name']) ?></dd></div>
  <div><dt>Requester</dt><dd><?= h($ticket['requester_name']) ?></dd></div>
  <div><dt>Company</dt><dd><?= h($ticket['company_name'] ?: '—') ?></dd></div>
  <div><dt>Agent</dt><dd><?= h($ticket['responder_name'] ?: 'Unassigned') ?></dd></div>
  <div><dt>Group</dt><dd><?= h($ticket['group_name'] ?: '—') ?></dd></div>
  <div><dt>Source</dt><dd><?= h($ticket['source_name']) ?></dd></div>
  <div><dt>Created</dt><dd><?= formatDate($ticket['created_at']) ?></dd></div>
  <div><dt>Updated</dt><dd><?= formatDate($ticket['updated_at']) ?></dd></div>
</dl>

<?php if ($tags): ?>
  <div class="tags-row">
    <?php foreach ($tags as $tag): ?><span class="tag"><?= h($tag) ?></span><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="thread">
  <div class="msg">
    <div class="msg-head">
      <strong><?= h($ticket['requester_name'] ?: 'Requester') ?></strong>
      <span><?= formatDate($ticket['created_at']) ?></span>
    </div>
    <div class="msg-body"><?= nl2br(h($ticket['description'])) ?></div>
    <?php if ($ticketAttachments): ?>
      <div class="attachments"><?php foreach ($ticketAttachments as $a) echo attachmentLink($a); ?></div>
    <?php endif; ?>
  </div>

  <?php foreach ($notes as $note): ?>
    <div class="msg <?= $note['private'] ? 'private' : '' ?>">
      <div class="msg-head">
        <strong><?= $note['incoming'] ? h($ticket['requester_name']) : ($note['private'] ? 'Internal note' : 'Agent reply') ?></strong>
        <span><?= formatDate($note['created_at']) ?></span>
      </div>
      <div class="msg-body"><?= nl2br(h($note['body'])) ?></div>
      <?php if (!empty($noteAttachments[$note['id']])): ?>
        <div class="attachments"><?php foreach ($noteAttachments[$note['id']] as $a) echo attachmentLink($a); ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php pageFoot(); ?>
