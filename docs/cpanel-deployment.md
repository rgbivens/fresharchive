# Deploying on cPanel shared hosting

## 1. Create the database (cPanel UI)

In cPanel: **MySQL Databases**
1. Create a database (e.g. `yourcpaneluser_archive`)
2. Create a database user with a strong password
3. Add that user to the database with **All Privileges**

cPanel prefixes both the database and username with your cPanel account
name — note the full prefixed names, you'll need them in `config.php`.

## 2. Load the schema

Via **phpMyAdmin** (in cPanel): open the new database, go to the **Import**
tab, and upload `sql/schema.sql`. This creates all the tables and indexes.

## 3. Download attachments (before you upload anything)

On your own machine — not on the server — run:

```bash
python3 tools/download_attachments.py /path/to/your/export/folder src/attachments
```

This has to point at your local `src/attachments`, not some other folder:
`ticket.php` links to attachments by a path relative to wherever it's
served from, so the `attachments/` folder needs to travel with `src/` in
the next step and land in the exact same directory as `search.php` and
`ticket.php` on the server. If it ends up anywhere else, the download
links on ticket pages will 404.

## 4. Upload the app

Via SSH or FTP, upload the contents of `src/` — **including the
`attachments/` folder from step 3** — to a folder on the server, either
`public_html/archive/` to make it reachable directly, or somewhere private
if you're locking it down with Directory Privacy (see step 8):

```
config.php       (copied from config.example.php, filled in — see step 5)
includes.php
search.php
ticket.php
import_tickets.php
import_related.php
attachments/     (from step 3)
```

## 5. Configure credentials

On the server, copy `config.example.php` to `config.php` and fill in the
real database host/name/user/password from step 1. `$DB_HOST` is almost
always `localhost` on cPanel. `config.php` should never be committed to
git — this step happens only on the server.

## 6. Upload your export data

Upload all your export JSON files (`Tickets*.json`, `Companies*.json`,
`Groups.json`, `AllAgents*.json`, `Users*.json`) to a folder on the
server — this one's only read once, during the import, so it doesn't need
to be web-accessible and doesn't need to be anywhere near where you put
`src/`, e.g. `~/importdata/`.

> **For a large export:** FTP can be slow and doesn't resume cleanly after
> a dropped connection. If your host gives you SSH access (most cPanel
> hosts do — you're already using it in step 7), `rsync` over SSH handles
> big transfers better and picks back up where it left off instead of
> starting over:
> ```bash
> rsync -avz --progress /path/to/your/export/folder/ user@yourdomain.com:~/importdata/
> ```
> The trailing slash on the source path matters — it copies the folder's
> *contents* into `~/importdata/`, rather than nesting the folder itself
> inside it. The same trick works for uploading `src/attachments/` in step
> 4 if your attachments folder turns out to be large too.

## 7. Run the import over SSH

```bash
cd ~/public_html/archive        # wherever you uploaded the src/ files
php import_tickets.php ~/importdata
php import_related.php ~/importdata
```

`import_tickets.php` will take a few minutes for a large export. Both
scripts are safe to re-run — they upsert rather than duplicate.

## 8. Lock it down

In cPanel: **Directory Privacy**, select the archive folder, enable
password protection, and add the people who need access as users. This
puts a login prompt in front of the whole thing at the web server level —
no PHP session/login code needed, and it keeps customer PII from being
reachable by anyone who happens to find the URL.

## 9. Visit it

`https://yourdomain.com/archive/search.php`
