#!/usr/bin/env python3
"""
Reports how much attachment data your Freshdesk export contains — without
downloading anything. Every attachment record already carries the file size
Freshdesk recorded at export time (content_file_size), so this just sums
that across all your Tickets*.json files.

USAGE:
    python3 estimate_attachment_size.py /path/to/folder/with/Tickets*.json
"""

import json
import sys
from pathlib import Path
from collections import defaultdict


def human_size(n: float) -> str:
    for unit in ["B", "KB", "MB", "GB", "TB"]:
        if n < 1024:
            return f"{n:.1f} {unit}"
        n /= 1024
    return f"{n:.1f} PB"


def iter_attachments(ticket: dict):
    for att in ticket.get("attachments", []) or []:
        yield att
    for note in ticket.get("notes", []) or []:
        for att in note.get("attachments", []) or []:
            yield att


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 estimate_attachment_size.py /path/to/folder/with/Tickets*.json")
        sys.exit(1)

    src_dir = Path(sys.argv[1])
    files = sorted(src_dir.glob("Tickets*.json"))
    if not files:
        print(f"No Tickets*.json files found in {src_dir}")
        sys.exit(1)

    total_bytes = 0
    total_count = 0
    by_type = defaultdict(lambda: [0, 0])  # content_type -> [count, bytes]
    largest = []

    for f in files:
        with open(f, encoding="utf-8") as fh:
            data = json.load(fh)
        for item in data:
            t = item.get("helpdesk_ticket", {})
            for att in iter_attachments(t):
                size = att.get("content_file_size") or 0
                ctype = att.get("content_content_type") or "unknown"
                fname = att.get("content_file_name") or "?"
                total_bytes += size
                total_count += 1
                by_type[ctype][0] += 1
                by_type[ctype][1] += size
                largest.append((size, fname, t.get("id")))

    print(f"Files scanned:      {len(files)}")
    print(f"Total attachments:  {total_count:,}")
    print(f"Total size:         {human_size(total_bytes)}  ({total_bytes:,} bytes)")
    print()
    print("By content type:")
    for ctype, (count, size) in sorted(by_type.items(), key=lambda x: -x[1][1])[:15]:
        print(f"  {ctype:35s} {count:6,d} files   {human_size(size):>10s}")
    print()
    largest.sort(reverse=True)
    print("10 largest individual attachments:")
    for size, fname, tid in largest[:10]:
        print(f"  {human_size(size):>10s}   ticket #{tid}   {fname}")


if __name__ == "__main__":
    main()
