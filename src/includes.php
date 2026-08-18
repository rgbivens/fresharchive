<?php
require __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER, $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}

function pageHead(string $title): void {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  :root {
    --ink: #1c2422;
    --ink-soft: #52605c;
    --line: #dbe0dd;
    --paper: #fafaf8;
    --panel: #ffffff;
    --accent: #2f6f5e;
    --accent-soft: #e7f0ec;
    --closed: #8a8f8c;
    --open: #c4622d;
    --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: var(--sans);
    background: var(--paper);
    color: var(--ink);
    font-size: 15px;
    line-height: 1.5;
  }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }
  header.site {
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    padding: 18px 24px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  header.site .brand {
    font-weight: 700;
    letter-spacing: -0.01em;
    font-size: 18px;
  }
  header.site .brand span {
    color: var(--accent);
    font-family: var(--mono);
    font-size: 13px;
    font-weight: 600;
    margin-left: 4px;
  }
  a.repo-link {
    margin-left: auto;
    color: var(--ink-soft);
    display: inline-flex;
    align-items: center;
    line-height: 0;
  }
  a.repo-link:hover { color: var(--accent); }
  main {
    max-width: 980px;
    margin: 0 auto;
    padding: 28px 24px 60px;
  }
  .panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 20px;
  }
  input[type=text], input[type=date], select {
    font: inherit;
    padding: 8px 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--paper);
    color: var(--ink);
  }
  input[type=text]:focus, input[type=date]:focus, select:focus {
    outline: 2px solid var(--accent);
    outline-offset: 1px;
  }
  button {
    font: inherit;
    font-weight: 600;
    padding: 9px 16px;
    border: none;
    border-radius: 6px;
    background: var(--accent);
    color: white;
    cursor: pointer;
  }
  button:hover { background: #275c4d; }
  form.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
  }
  form.filters .field { display: flex; flex-direction: column; gap: 4px; }
  form.filters .field.actions { flex-direction: row; align-items: center; gap: 12px; }
  form.filters label { font-size: 12px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
  form.filters .search-field { flex: 1 1 280px; }
  form.filters .search-field input { width: 100%; }
  a.clear-link { color: var(--ink-soft); font-size: 13px; }
  a.clear-link:hover { color: var(--accent); }
  .result-count { color: var(--ink-soft); font-size: 13px; margin: 18px 2px 10px; }
  ul.tickets { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  ul.tickets li {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 14px 16px;
  }
  ul.tickets li:hover { border-color: var(--accent); }
  a.ticket-link { color: inherit; text-decoration: none; display: block; }
  .ticket-row { display: flex; justify-content: space-between; gap: 14px; align-items: baseline; }
  .ticket-subject { font-weight: 600; font-size: 15px; }
  a.ticket-link:hover .ticket-subject { color: var(--accent); text-decoration: underline; }
  .ticket-id { font-family: var(--mono); font-size: 12px; color: var(--ink-soft); }
  .ticket-meta { margin-top: 6px; font-size: 13px; color: var(--ink-soft); display: flex; gap: 14px; flex-wrap: wrap; }
  a.meta-link { color: var(--ink-soft); border-bottom: 1px dotted var(--ink-soft); }
  a.meta-link:hover { color: var(--accent); border-bottom-color: var(--accent); text-decoration: none; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
  .badge.status-open { background: #fbeadf; color: var(--open); }
  .badge.status-closed { background: #eceeed; color: var(--closed); }
  .pagination { display: flex; gap: 8px; margin-top: 24px; flex-wrap: wrap; align-items: center; }
  .pagination a, .pagination span { padding: 6px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; }
  .pagination .current { background: var(--accent); color: white; border-color: var(--accent); font-weight: 600; }
  .pagination .ellipsis { border: none; padding: 6px 2px; color: var(--ink-soft); }
  .empty { text-align: center; padding: 50px 20px; color: var(--ink-soft); }

  /* ticket detail */
  .back-link { font-size: 13px; margin-bottom: 16px; display: inline-block; }
  .ticket-header h1 { font-size: 21px; margin: 4px 0 10px; }
  .meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin: 16px 0 24px; padding: 16px; background: var(--accent-soft); border-radius: 8px; }
  .meta-grid dt { font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; color: var(--ink-soft); font-weight: 700; }
  .meta-grid dd { margin: 2px 0 0; font-size: 14px; }
  .tags-row { margin: 10px 0; }
  .tag { display: inline-block; background: var(--accent-soft); color: var(--accent); font-size: 12px; padding: 3px 9px; border-radius: 20px; margin-right: 6px; }
  .thread { display: flex; flex-direction: column; gap: 14px; margin-top: 20px; }
  .msg { border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; background: var(--panel); }
  .msg.private { background: #fff8e8; border-color: #ecd9a0; }
  .msg-head { display: flex; justify-content: space-between; font-size: 13px; color: var(--ink-soft); margin-bottom: 8px; }
  .msg-body { font-size: 14px; white-space: pre-wrap; word-wrap: break-word; }
  .attachments { margin-top: 10px; font-size: 13px; }
  .attachments a { display: inline-flex; align-items: center; gap: 4px; margin-right: 12px; }
</style>
</head>
<body>
<header class="site">
  <div class="brand">Ticket Archive <span>read-only</span></div>
  <a class="repo-link" href="https://github.com/rgbivens/fresharchive" target="_blank" rel="noopener" title="View source on GitHub">
    <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
      <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
    </svg>
  </a>
</header>
<main>
<?php
}

function pageFoot(): void {
?>
</main>
</body>
</html>
<?php
}
