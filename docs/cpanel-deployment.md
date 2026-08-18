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

## 3. Upload the app

Via SSH or FTP, upload the contents of `src/` to a folder — either
`public_html/archive/` to make it reachable directly, or somewhere private
if you're locking it down with Directory Privacy (see step 7):

```
config.php       (copied from config.example.php, filled in — see step 4)
includes.php
search.php
ticket.php
import_tickets.php
import_related.php
```

## 4. Configure credentials

On the server, copy `config.example.php` to `config.php` and fill in the
real database host/name/user/password from step 1. `$DB_HOST` is almost
always `localhost` on cPanel. `config.php` should never be committed to
git — this step happens only on the server.

## 5. Upload your export data

Upload attachments and all your export JSON files (`Tickets*.json`,
`Companies*.json`, `Groups.json`, `AllAgents*.json`, `Users*.json`) to a
folder on the server — it doesn't need to be web-accessible, e.g.
`~/importdata/` and `~/importdata/attachments/`.

## 6. Run the import over SSH

```bash
cd ~/public_html/archive        # wherever you uploaded the src/ files
php import_tickets.php ~/importdata
php import_related.php ~/importdata
```

`import_tickets.php` will take a few minutes for a large export. Both
scripts are safe to re-run — they upsert rather than duplicate.

## 7. Lock it down

In cPanel: **Directory Privacy**, select the archive folder, enable
password protection, and add the people who need access as users. This
puts a login prompt in front of the whole thing at the web server level —
no PHP session/login code needed, and it keeps customer PII from being
reachable by anyone who happens to find the URL.

## 8. Visit it

`https://yourdomain.com/archive/search.php`
