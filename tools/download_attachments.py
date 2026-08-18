#!/usr/bin/env python3
"""
Downloads every ticket/note attachment referenced in your Freshdesk JSON export,
before the signed S3 URLs expire (they're valid for 7 days from export time).

USAGE:
    python3 download_attachments.py /path/to/folder/with/Tickets*.json [output_folder]

    output_folder defaults to ./attachments (relative to wherever you run this).
    Point it at src/attachments so the files land where the web app expects them —
    see the note on linking below.

Run this on your own machine (not in a sandboxed environment) since it needs
to reach s3.amazonaws.com directly.

IMPORTANT — how these files get linked to tickets:
import_tickets.php records each attachment's path as "attachments/{ticket_id}/{filename}",
relative to wherever search.php/ticket.php are served from. That means this
output_folder needs to end up inside your deployed src/ directory (i.e.
src/attachments/) — not just anywhere on the server — or the download links on
the ticket page will 404. Simplest: run this script with output_folder set to
your repo's src/attachments before you deploy, so it's already in place.

No dependencies beyond Python 3's standard library — nothing to pip install.
"""

import json
import sys
import time
import urllib.request
import urllib.error
from pathlib import Path

def sanitize_filename(name: str) -> str:
    return "".join(c if c.isalnum() or c in "._- " else "_" for c in name)

def iter_attachments(ticket: dict):
    """Yield (ticket_id, filename, url, problem) for every attachment on a
    ticket and its notes. problem is None for normal entries, or a short
    string describing why an entry can't be downloaded (missing url/filename)
    — these used to be silently dropped, which hid real data gaps."""
    ticket_id = ticket["id"]
    for att in ticket.get("attachments", []) or []:
        yield from _check_attachment(ticket_id, att)
    for note in ticket.get("notes", []) or []:
        for att in note.get("attachments", []) or []:
            yield from _check_attachment(ticket_id, att)

def _check_attachment(ticket_id, att):
    url = att.get("attachment_url_for_export") or att.get("attachment_url")
    fname = att.get("content_file_name")
    if not fname:
        yield ticket_id, att.get("content_file_name") or "(no filename in export)", None, "missing content_file_name in export data"
    elif not url:
        yield ticket_id, fname, None, "no attachment_url_for_export or attachment_url in export data"
    else:
        yield ticket_id, fname, url, None

def download(url: str, dest: Path, timeout: int = 30) -> None:
    req = urllib.request.Request(url, headers={"User-Agent": "fresharchive-downloader/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        dest.write_bytes(resp.read())

def main():
    if len(sys.argv) not in (2, 3):
        print("Usage: python3 download_attachments.py /path/to/folder/with/Tickets*.json [output_folder]")
        sys.exit(1)

    src_dir = Path(sys.argv[1])
    out_dir = Path(sys.argv[2]) if len(sys.argv) == 3 else Path("attachments")
    out_dir.mkdir(parents=True, exist_ok=True)
    print(f"Writing attachments to: {out_dir.resolve()}")

    ticket_files = sorted(src_dir.glob("Tickets*.json"))
    if not ticket_files:
        print(f"No Tickets*.json files found in {src_dir}")
        sys.exit(1)

    print(f"Found {len(ticket_files)} ticket export files.")

    total = 0
    downloaded = 0
    failed = []
    skipped_existing = 0

    for jf in ticket_files:
        print(f"\nProcessing {jf.name}...")
        with open(jf, encoding="utf-8") as f:
            data = json.load(f)

        for item in data:
            ticket = item.get("helpdesk_ticket", {})
            for ticket_id, fname, url, problem in iter_attachments(ticket):
                total += 1

                if problem:
                    # Data gap in the export itself — nothing to download,
                    # not a network failure. Report it rather than dropping
                    # it silently, since these used to vanish with no trace.
                    failed.append((ticket_id, fname, problem))
                    print(f"  SKIPPED (no usable URL in export): ticket {ticket_id} / {fname} — {problem}")
                    continue

                ticket_dir = out_dir / str(ticket_id)
                ticket_dir.mkdir(exist_ok=True)
                dest = ticket_dir / sanitize_filename(fname)

                if dest.exists() and dest.stat().st_size > 0:
                    skipped_existing += 1
                    continue

                for attempt in range(3):
                    try:
                        download(url, dest)
                        downloaded += 1
                        if downloaded % 25 == 0:
                            print(f"  ...{downloaded} downloaded so far")
                        break
                    except urllib.error.HTTPError as e:
                        # 4xx/5xx from S3 — most commonly a 403 from an expired signed URL.
                        # Retrying won't help an expired link, so fail fast on this one.
                        failed.append((ticket_id, fname, f"HTTP {e.code}: {e.reason}"))
                        print(f"  FAILED: ticket {ticket_id} / {fname}: HTTP {e.code} {e.reason}")
                        break
                    except Exception as e:
                        if attempt == 2:
                            failed.append((ticket_id, fname, str(e)))
                            print(f"  FAILED: ticket {ticket_id} / {fname}: {e}")
                        else:
                            time.sleep(2)

    print(f"\nDone. {total} attachments found, {downloaded} downloaded, "
          f"{skipped_existing} already present, {len(failed)} failed.")

    if failed:
        fail_log = out_dir / "_failed_downloads.json"
        fail_log.write_text(json.dumps(failed, indent=2))
        print(f"Failed downloads logged to {fail_log} — re-run this script to retry them.")

if __name__ == "__main__":
    main()
