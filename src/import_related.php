<?php
/**
 * Imports the supporting Freshdesk export files: agents, companies, groups,
 * and the full contact (requester) list.
 *
 * Usage:
 *   php import_related.php /path/to/folder
 *
 * Looks for: AllAgents*.json, Companies*.json, Groups.json, Users*.json
 * in that folder. Any that are missing are skipped with a note — run this
 * before or after import_tickets.php, order doesn't matter.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/config.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php import_related.php /path/to/folder\n");
    exit(1);
}

$srcDir = rtrim($argv[1], '/');

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function loadJson(string $path): ?array {
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

// --- Companies -----------------------------------------------------------

$companyFiles = glob("$srcDir/Companies*.json");
$stmtCompany = $pdo->prepare("
    INSERT INTO companies (id, name) VALUES (:id, :name)
    ON DUPLICATE KEY UPDATE name = VALUES(name)
");
$companyCount = 0;
foreach ($companyFiles as $file) {
    $data = loadJson($file);
    if (!$data) continue;
    foreach ($data as $item) {
        $c = $item['company'] ?? null;
        if (!$c || empty($c['id'])) continue;
        $stmtCompany->execute([':id' => $c['id'], ':name' => $c['name'] ?? '(unnamed)']);
        $companyCount++;
    }
}
echo "Companies: $companyCount imported from " . count($companyFiles) . " file(s)\n";

// --- Groups ----------------------------------------------------------------

$groupFile = file_exists("$srcDir/Groups.json") ? "$srcDir/Groups.json" : null;
$stmtGroup = $pdo->prepare("
    INSERT INTO `groups` (id, name) VALUES (:id, :name)
    ON DUPLICATE KEY UPDATE name = VALUES(name)
");
$groupCount = 0;
if ($groupFile) {
    $data = loadJson($groupFile);
    foreach ($data ?? [] as $item) {
        $g = $item['group'] ?? null;
        if (!$g || empty($g['id'])) continue;
        $stmtGroup->execute([':id' => $g['id'], ':name' => $g['name'] ?? '(unnamed)']);
        $groupCount++;
    }
    echo "Groups: $groupCount imported\n";
} else {
    echo "Groups: Groups.json not found, skipped\n";
}

// --- Agents ------------------------------------------------------------

$agentFiles = glob("$srcDir/AllAgents*.json");
$stmtAgent = $pdo->prepare("
    INSERT INTO agents (id, name, email) VALUES (:id, :name, :email)
    ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email)
");
$agentCount = 0;
foreach ($agentFiles as $file) {
    $data = loadJson($file);
    if (!$data) continue;
    foreach ($data as $item) {
        $u = $item['user'] ?? null;
        if (!$u || empty($u['id']) || empty($u['helpdesk_agent'])) continue;
        $stmtAgent->execute([':id' => $u['id'], ':name' => $u['name'] ?? '(unnamed)', ':email' => $u['email'] ?? null]);
        $agentCount++;
    }
}
echo "Agents: $agentCount imported from " . count($agentFiles) . " file(s)\n";

// --- Requesters / contacts (Users*.json) ------------------------------

$userFiles = glob("$srcDir/Users*.json");
$stmtRequester = $pdo->prepare("
    INSERT INTO requesters (id, name, email, company_id, phone) VALUES (:id, :name, :email, :company_id, :phone)
    ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email),
        company_id = VALUES(company_id), phone = VALUES(phone)
");
$userCount = 0;
foreach ($userFiles as $file) {
    $data = loadJson($file);
    if (!$data) continue;
    foreach ($data as $item) {
        $u = $item['user'] ?? null;
        if (!$u || empty($u['id'])) continue;
        $stmtRequester->execute([
            ':id' => $u['id'],
            ':name' => $u['name'] ?? null,
            ':email' => $u['email'] ?? null,
            ':company_id' => $u['company_id'] ?? null,
            ':phone' => $u['phone'] ?? null,
        ]);
        $userCount++;
    }
}
echo "Contacts: $userCount imported from " . count($userFiles) . " file(s)\n";

// --- Backfill tickets.company_id now that requesters have company_id ------

$updated = $pdo->exec("
    UPDATE tickets t
    JOIN requesters r ON r.id = t.requester_id
    SET t.company_id = r.company_id
    WHERE r.company_id IS NOT NULL
");
echo "Backfilled company_id on $updated ticket(s) from contact records\n";

echo "\nDone.\n";
