# fresharchive

A self-hosted, searchable archive for a Freshdesk account you're leaving —
built for organizations who exported their Freshdesk data as JSON and want
to keep old tickets searchable and readable after their subscription ends,
without paying to keep Freshdesk itself around.

Freshdesk's JSON export gives you tickets, conversations, contacts, agents,
companies, and groups as flat files. This tool loads them into MySQL and
gives you a small, fast, filterable search UI in front of them — status,
priority, agent, company, date range, and full-text search across subjects,
descriptions, and replies.

**What this is not:** a live helpdesk. There's no ticket creation, no
replying, no notifications — this is read-only, for looking things up.

## ⚠️ Before you do anything with real data

Your Freshdesk export contains customer names, emails, phone numbers, and
the full text of every support conversation your organization has ever had.
**Never commit your actual export files, downloaded attachments, or a
filled-in `config.php` to git** — see [Handling your data safely](#handling-your-data-safely)
below. This repo, as published, contains none of that — only the code.

## How it works

1. **`tools/download_attachments.py`** — pulls every attachment out of your
   export before Freshdesk's signed download URLs expire (they're valid for
   about a week from export time, independent of your account status).
2. **`sql/schema.sql`** — the MySQL table structure.
3. **`src/import_tickets.php`** — loads all your `Tickets*.json` files into
   MySQL: tickets, the full conversation thread on each, tags, and
   attachment metadata.
4. **`src/import_related.php`** — loads `Companies*.json`, `Groups.json`,
   `AllAgents*.json`, and `Users*.json` to resolve agent and company names
   for filtering, and backfills any gaps in the ticket data.
5. **`src/search.php`** and **`src/ticket.php`** — the actual browsable,
   filterable, full-text-searchable web UI.

## Getting your Freshdesk export

In Freshdesk: **Admin → Workflows → Data Export** (older accounts: **Admin →
Data Export**), then request a full export. Freshdesk emails you a link to a
zip once it's ready — for a large account this can take a while to generate.
Unzip it and you'll have the flat JSON files this tool reads: `Tickets*.json`
(one or more, numbered), `Users*.json`, `Companies0.json`, `Groups.json`,
`AllAgents0.json`, etc.

The attachment URLs inside those `Tickets*.json` files are signed S3 links
that expire about a week after export — see step 3 below, and don't sit on
the export too long before running it.

## Quick start

Requires PHP 8+ with `pdo_mysql`, and a MySQL/MariaDB database.

```bash
git clone https://github.com/<you>/fresharchive.git
cd fresharchive

# 1. Set up your database
mysql -u root -e "CREATE DATABASE fresharchive CHARACTER SET utf8mb4"
mysql -u root fresharchive < sql/schema.sql

# 2. Configure credentials
cp src/config.example.php src/config.php
# edit src/config.php with your real database host/name/user/password

# 3. Download attachments — straight into src/attachments, which is where
# the web app expects to find them (see "Handling your data safely" below).
# Optional first: tools/estimate_attachment_size.py /path/to/your/export/folder
# tells you the total download size without fetching anything, if you want
# to know what you're in for first.
python3 tools/download_attachments.py /path/to/your/export/folder src/attachments

# 4. Import
php src/import_tickets.php /path/to/your/export/folder
php src/import_related.php /path/to/your/export/folder

# 5. Serve it (for local testing — see docs/cpanel-deployment.md for real hosting)
php -S localhost:8000 -t src
```

Then visit `http://localhost:8000/search.php`.

> Both import scripts are safe to re-run — they upsert rather than
> duplicate — so if step 3 or 4 fails partway through (e.g. the network
> drops mid-download), just run it again.

## Handling your data safely

- Keep your exported `Tickets*.json`, `Users*.json`, etc. and the downloaded
  `attachments/` folder **outside git entirely**, or in a local `data/`
  folder — both are already excluded in `.gitignore`.
- `src/config.php` (your real DB credentials) is gitignored by name. Only
  `src/config.example.php`, the placeholder template, is committed.
- Before you push this repo anywhere public, run `git log --stat` and
  confirm none of the above were ever committed. `.gitignore` only stops
  *future* commits — if real data or credentials already got committed,
  even locally, they need to be scrubbed from git history (or start the
  repo over from a clean state) before making it public.
- The web UI itself should sit behind some form of access control in
  production (e.g. HTTP basic auth) since it's showing customer PII — see
  the deployment doc for one way to do this on shared hosting.

## Deployment

See [`docs/cpanel-deployment.md`](docs/cpanel-deployment.md) for a full
walkthrough on shared cPanel hosting, including database setup through the
cPanel UI, running the import over SSH, and password-protecting the
archive.

## License

MIT — see [LICENSE](LICENSE).
