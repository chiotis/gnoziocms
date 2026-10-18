#!/usr/bin/env python3
"""
Turns a list of entries (JSON or YAML) into the CSV file that Admin > Import (Content > a type > Import) reads. Use it when you
cannot write into the installation's files but a person can upload a file in the admin; when you can write files, use
new_entry.py instead (it needs no upload step and does not depend on the CSV rules).

    python3 entries_to_csv.py entries.yaml --type posts -o posts.csv
    python3 entries_to_csv.py entries.json --type pages --root /path/to/site     # also checks the blocks and languages

Input: a list; each entry is a map with
    language (required, el/en/…), title, slug (made from the title when missing, the way the CMS does it), status, visible,
    date, author, excerpt, main_image, tags (list), categories (list), translation_id, body (Markdown),
    blocks (list of blocks), seo: {title, description}, template, and `meta: {any.other: value}` for further front matter.
Entries of one page in several languages share a `translation_id` (write the same 16 hex characters, or `key: anything` on both
and the script gives them one id).

The import is two steps in the admin: a preview that changes nothing, then Apply. One CSV is one content type. Rows are matched to
existing entries by translation_id or by slug + language and update them; the preview says so. Hand the person the CSV and these steps.
"""
import argparse
import csv
import hashlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402

FIXED = ["content_type", "language", "slug", "title", "status", "visible", "date", "author", "tags", "categories", "translation_id", "main_image", "excerpt", "body"]


def flatten(prefix, value, out):
    if isinstance(value, dict):
        for k, v in value.items():
            flatten(prefix + "." + str(k), v, out)
    elif isinstance(value, (list, tuple)):
        out[prefix] = json.dumps(value, ensure_ascii=False)
    elif isinstance(value, bool):
        out[prefix] = "true" if value else "false"
    elif value is None:
        out[prefix] = ""
    else:
        out[prefix] = str(value)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("entries", help="JSON or YAML file with the list of entries")
    parser.add_argument("--type", required=True, help="the content type these entries belong to (pages, posts, projects, …)")
    parser.add_argument("-o", "--output", help="the CSV file to write (default: <type>.csv next to the input)")
    parser.add_argument("--root", help="an installation folder, to check blocks and languages against (optional)")
    args = parser.parse_args()

    data = f.yaml.safe_load(f.read_text(args.entries))
    if isinstance(data, dict) and "entries" in data:
        data = data["entries"]
    if not isinstance(data, list):
        raise SystemExit("The input must be a list of entries")

    root = f.find_root(args.root, allow_snapshot=True)
    blocks_def = f.load_blocks(root)
    languages = None if f.is_snapshot(root) else f.languages(root)[1]

    rows, extra_columns, problems = [], [], 0
    ids = {}
    for index, entry in enumerate(data, 1):
        if not isinstance(entry, dict):
            raise SystemExit("Entry %d is not a map" % index)
        lang = str(entry.get("language") or entry.get("lang") or "").strip().lower()
        if not lang:
            raise SystemExit("Entry %d has no language" % index)
        if languages and lang not in languages:
            print("ERROR entry %d: language %r is not one of the site's (%s); the import would reject the row" % (index, lang, ", ".join(languages)))
            problems += 1
        title = str(entry.get("title") or "").strip()
        slug = str(entry.get("slug") or "").strip() or f.slugify(title)
        if not slug:
            raise SystemExit("Entry %d has neither a slug nor a title with letters" % index)
        tid = entry.get("translation_id")
        if tid is not None and not (len(str(tid)) == 16 and all(c in "0123456789abcdef" for c in str(tid))):
            ids.setdefault(str(tid), hashlib.sha256(str(tid).encode()).hexdigest()[:16])
            tid = ids[str(tid)]
        row = {
            "content_type": args.type, "language": lang, "slug": slug, "title": title,
            "status": entry.get("status", "published"), "visible": "false" if entry.get("visible") is False else "true",
            "date": entry.get("date", ""), "author": entry.get("author", ""),
            "tags": ", ".join(entry.get("tags") or []), "categories": ", ".join(entry.get("categories") or []),
            "translation_id": tid or "", "main_image": entry.get("main_image", ""), "excerpt": entry.get("excerpt", ""),
            "body": str(entry.get("body") or "").replace("\r\n", "\n"),
        }
        meta = {}
        if entry.get("blocks"):
            meta["blocks"] = entry["blocks"]
            if blocks_def is not None:
                for i, block in enumerate(entry["blocks"]):
                    for message in f.check_block(blocks_def, block, "entry %d blocks[%d]" % (index, i)):
                        print("WARN  " + message)
        if entry.get("seo"):
            meta["seo"] = entry["seo"]
        if entry.get("template"):
            meta["template"] = entry["template"]
        for key, value in (entry.get("meta") or {}).items():
            meta[key] = value
        flat = {}
        for key, value in meta.items():
            flatten("meta." + key, value, flat)
        for key in flat:
            if key not in extra_columns:
                extra_columns.append(key)
        row.update(flat)
        rows.append(row)

    output = args.output or os.path.join(os.path.dirname(os.path.abspath(args.entries)), args.type + ".csv")
    with open(output, "w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIXED + extra_columns, lineterminator="\n")
        writer.writeheader()
        for row in rows:
            writer.writerow({k: row.get(k, "") for k in FIXED + extra_columns})
    print("Wrote %s: %d rows, %d extra columns. Admin > Content > %s > Import: choose it, look at the preview, then Apply." % (output, len(rows), len(extra_columns), args.type))
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
