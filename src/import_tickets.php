<?php
/**
 * Imports Freshdesk Tickets*.json export files into MySQL.
 *
 * Usage:
 *   php import_tickets.php /path/to/folder/with/Tickets*.json
 *
 * Reads DB connection info from config.php in the same directory.
 * Safe to re-run: uses INSERT ... ON DUPLICATE KEY UPDATE throughout.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('memory_limit', '512M');

require __DIR__ . '/config.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php import_tickets.php /path/to/folder/with/Tickets*.json\n");
    exit(1);
}

$srcDir = rtrim($argv[1], '/');
$files = glob($srcDir . '/Tickets*.json');
sort($files);

if (empty($files)) {
    fwrite(STDERR, "No Tickets*.json files found in $srcDir\n");
    exit(1);
}

echo "Found " . count($files) . " ticket export file(s).\n";

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- prepared statements -------------------------------------------------

$stmtTicket = $pdo->prepare("
    INSERT INTO tickets
        (id, display_id, subject, description, description_html, status, status_name,
         priority, priority_name, source_name, requester_id, requester_name,
         responder_id, responder_name, group_id, company_id, to_email,
         urgent, spam, deleted, created_at, updated_at, due_by)
    VALUES
        (:id, :display_id, :subject, :description, :description_html, :status, :status_name,
         :priority, :priority_name, :source_name, :requester_id, :requester_name,
         :responder_id, :responder_name, :group_id, :company_id, :to_email,
         :urgent, :spam, :deleted, :created_at, :updated_at, :due_by)
    ON DUPLICATE KEY UPDATE
        subject=VALUES(subject), description=VALUES(description),
        description_html=VALUES(description_html), status=VALUES(status),
        status_name=VALUES(status_name), priority=VALUES(priority),
        priority_name=VALUES(priority_name), requester_name=VALUES(requester_name),
        responder_name=VALUES(responder_name), updated_at=VALUES(updated_at)
");

$stmtRequester = $pdo->prepare("
    INSERT INTO requesters (id, name, email, company_id, phone)
    VALUES (:id, :name, :email, :company_id, :phone)
    ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email)
");

$stmtNote = $pdo->prepare("
    INSERT INTO notes (id, ticket_id, user_id, body, body_html, private, incoming, created_at)
    VALUES (:id, :ticket_id, :user_id, :body, :body_html, :private, :incoming, :created_at)
    ON DUPLICATE KEY UPDATE body=VALUES(body), body_html=VALUES(body_html)
");

$stmtTag = $pdo->prepare("INSERT IGNORE INTO tags (name) VALUES (:name)");
$stmtTagId = $pdo->prepare("SELECT id FROM tags WHERE name = :name");
$stmtTicketTag = $pdo->prepare("INSERT IGNORE INTO ticket_tags (ticket_id, tag_id) VALUES (:ticket_id, :tag_id)");

$stmtAttachment = $pdo->prepare("
    INSERT INTO attachments (id, ticket_id, note_id, filename, content_type, file_size, local_path)
    VALUES (:id, :ticket_id, :note_id, :filename, :content_type, :file_size, :local_path)
    ON DUPLICATE KEY UPDATE local_path=VALUES(local_path)
");

// --- helpers ---------------------------------------------------------------

function toDatetime(?string $iso): ?string {
    if (!$iso) return null;
    try {
        return (new DateTime($iso))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

// download_attachments.py sanitizes filenames before saving to disk (keeps
// letters/numbers/._- and spaces, replaces everything else with "_") — this
// has to produce the exact same result or the two won't agree on a filename.
function sanitizeFilename(string $name): string {
    return preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $name);
}

// Attachments were downloaded by download_attachments.py into ./attachments/{ticket_id}/{filename}
function localAttachmentPath(int $ticketId, string $filename): string {
    return "attachments/$ticketId/" . sanitizeFilename($filename);
}

function importAttachments(PDO $pdo, $stmt, array $attachments, int $ticketId, ?int $noteId): void {
    foreach ($attachments as $att) {
        if (empty($att['id']) || empty($att['content_file_name'])) continue;
        $stmt->execute([
            ':id' => $att['id'],
            ':ticket_id' => $ticketId,
            ':note_id' => $noteId,
            ':filename' => $att['content_file_name'],
            ':content_type' => $att['content_content_type'] ?? null,
            ':file_size' => $att['content_file_size'] ?? null,
            ':local_path' => localAttachmentPath($ticketId, $att['content_file_name']),
        ]);
    }
}

// --- main import loop --------------------------------------------------

$totalTickets = 0;
$totalNotes = 0;
$totalAttachments = 0;
$startTime = microtime(true);

foreach ($files as $file) {
    echo "Processing " . basename($file) . "...\n";
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if ($data === null) {
        fwrite(STDERR, "  Failed to parse JSON in $file — skipping\n");
        continue;
    }

    $pdo->beginTransaction();
    try {
        foreach ($data as $item) {
            $t = $item['helpdesk_ticket'] ?? null;
            if (!$t) continue;

            $requesterId = null;
            $requesterName = null;
            if (!empty($t['requester']) && is_array($t['requester'])) {
                $req = $t['requester'];
                $requesterId = $req['id'] ?? null;
                $requesterName = $req['name'] ?? null;
                if ($requesterId) {
                    $stmtRequester->execute([
                        ':id' => $requesterId,
                        ':name' => $req['name'] ?? null,
                        ':email' => $req['email'] ?? null,
                        ':company_id' => $req['company_id'] ?? null,
                        ':phone' => $req['phone'] ?? null,
                    ]);
                }
            } else {
                $requesterId = $t['requester_id'] ?? null;
                $requesterName = $t['requester_name'] ?? null;
            }

            $stmtTicket->execute([
                ':id' => $t['id'],
                ':display_id' => $t['display_id'] ?? null,
                ':subject' => $t['subject'] ?? null,
                ':description' => $t['description'] ?? null,
                ':description_html' => $t['description_html'] ?? null,
                ':status' => $t['status'] ?? null,
                ':status_name' => $t['status_name'] ?? null,
                ':priority' => $t['priority'] ?? null,
                ':priority_name' => $t['priority_name'] ?? null,
                ':source_name' => $t['source_name'] ?? null,
                ':requester_id' => $requesterId,
                ':requester_name' => $requesterName,
                ':responder_id' => $t['responder_id'] ?? null,
                ':responder_name' => $t['responder_name'] ?? null,
                ':group_id' => $t['group_id'] ?? null,
                ':company_id' => $t['requester']['company_id'] ?? null,
                ':to_email' => $t['to_email'] ?? null,
                ':urgent' => !empty($t['urgent']) ? 1 : 0,
                ':spam' => !empty($t['spam']) ? 1 : 0,
                ':deleted' => !empty($t['deleted']) ? 1 : 0,
                ':created_at' => toDatetime($t['created_at'] ?? null),
                ':updated_at' => toDatetime($t['updated_at'] ?? null),
                ':due_by' => toDatetime($t['due_by'] ?? null),
            ]);
            $totalTickets++;

            // Tags (each entry is an object like {"name": "Website"})
            foreach (($t['tags'] ?? []) as $tagEntry) {
                $tagName = is_array($tagEntry) ? ($tagEntry['name'] ?? null) : $tagEntry;
                if (!$tagName) continue;
                $stmtTag->execute([':name' => $tagName]);
                $stmtTagId->execute([':name' => $tagName]);
                $tagId = $stmtTagId->fetchColumn();
                if ($tagId) {
                    $stmtTicketTag->execute([':ticket_id' => $t['id'], ':tag_id' => $tagId]);
                }
            }

            // Ticket-level attachments
            if (!empty($t['attachments'])) {
                importAttachments($pdo, $stmtAttachment, $t['attachments'], $t['id'], null);
                $totalAttachments += count($t['attachments']);
            }

            // Notes (conversation thread) + their attachments
            foreach (($t['notes'] ?? []) as $note) {
                if (empty($note['id'])) continue;
                $stmtNote->execute([
                    ':id' => $note['id'],
                    ':ticket_id' => $t['id'],
                    ':user_id' => $note['user_id'] ?? null,
                    ':body' => $note['body'] ?? null,
                    ':body_html' => $note['body_html'] ?? null,
                    ':private' => !empty($note['private']) ? 1 : 0,
                    ':incoming' => !empty($note['incoming']) ? 1 : 0,
                    ':created_at' => toDatetime($note['created_at'] ?? null),
                ]);
                $totalNotes++;

                if (!empty($note['attachments'])) {
                    importAttachments($pdo, $stmtAttachment, $note['attachments'], $t['id'], $note['id']);
                    $totalAttachments += count($note['attachments']);
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fwrite(STDERR, "  ERROR in $file: " . $e->getMessage() . "\n");
        continue;
    }

    echo "  ...running totals: $totalTickets tickets, $totalNotes notes, $totalAttachments attachments\n";
}

$elapsed = round(microtime(true) - $startTime, 1);
echo "\nDone in {$elapsed}s. Imported $totalTickets tickets, $totalNotes notes, $totalAttachments attachment records.\n";
