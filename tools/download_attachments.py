#!/usr/bin/env python3
"""
Downloads every ticket/note attachment referenced in your Freshdesk JSON export,
before the signed S3 URLs expire (they're valid for 7 days from export time).

USAGE:
    python3 download_attachments.py /path/to/folder/with/Tickets*.json

Run this on your own machine (not in a sandboxed environment) since it needs
to reach s3.amazonaws.com directly.

Requires: pip install requests
"""

import json
import sys
import time
from pathlib import Path
from urllib.parse import urlparse
import requests

def sanitize_filename(name: str) -> str:
    return "".join(c if c.isalnum() or c in "._- " else "_" for c in name)

def iter_attachments(ticket: dict):
    """Yield (ticket_id, filename, url) for every attachment on a ticket and its notes."""
    ticket_id = ticket["id"]
    for att in ticket.get("attachments", []) or []:
        url = att.get("attachment_url_for_export") or att.get("attachment_url")
        fname = att.get("content_file_name")
        if url and fname:
            yield ticket_id, fname, url
    for note in ticket.get("notes", []) or []:
        for att in note.get("attachments", []) or []:
            url = att.get("attachment_url_for_export") or att.get("attachment_url")
            fname = att.get("content_file_name")
            if url and fname:
                yield ticket_id, fname, url

def main():
    if len(sys.argv) != 2:
        print("Usage: python3 download_attachments.py /path/to/folder/with/Tickets*.json")
        sys.exit(1)

    src_dir = Path(sys.argv[1])
    out_dir = Path("attachments")
    out_dir.mkdir(exist_ok=True)

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
            for ticket_id, fname, url in iter_attachments(ticket):
                total += 1
                ticket_dir = out_dir / str(ticket_id)
                ticket_dir.mkdir(exist_ok=True)
                dest = ticket_dir / sanitize_filename(fname)

                if dest.exists() and dest.stat().st_size > 0:
                    skipped_existing += 1
                    continue

                for attempt in range(3):
                    try:
                        resp = requests.get(url, timeout=30)
                        resp.raise_for_status()
                        dest.write_bytes(resp.content)
                        downloaded += 1
                        if downloaded % 25 == 0:
                            print(f"  ...{downloaded} downloaded so far")
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
