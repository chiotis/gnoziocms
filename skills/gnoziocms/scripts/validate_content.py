#!/usr/bin/env python3
"""
Checks GnozioCMS content files before (or after) they are put on a site, using the installation's own block
definitions, languages and taxonomies. Run it on every file you wrote or changed.

    python3 validate_content.py                     # every content file of the installation in the current folder
    python3 validate_content.py content/pages/about.md content/pages/about.en.md
    python3 validate_content.py --root /path/to/site --json

Why it exists: the CMS never refuses a hand-written file. A block with a wrong option, a misspelled field or a
broken front matter is not an error on the site, it is silently changed to the default, ignored, or (for YAML the
CMS cannot read) the whole page becomes an unpublished draft. This script says what the CMS would do.

Exit status: 0 when there is no ERROR (warnings are advice), 1 otherwise.
"""
import argparse
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402

BUILT_IN_TEMPLATES = {"default", "landing", "sidebar", "standard"}
STATUSES = {"published", "draft"}
HEX16 = re.compile(r"^[0-9a-f]{16}$")
UPLOAD = re.compile(r"""(?<![A-Za-z0-9])/uploads/[^\s"'()<>]+""")


def page_templates(root):
    names = set(BUILT_IN_TEMPLATES)
    names.update(str(k) for k in (f.load_yaml(os.path.join(root, "custom", "page-templates.yaml"), {}) or {}))
    manifest = f.load_yaml(os.path.join(root, "themes", "default", "theme.yaml"), {}) or {}
    names.update(str(k) for k in (manifest.get("page_templates") or {}))
    return names


def taxonomy_terms(root):
    terms = {}
    base = os.path.join(root, "content", "taxonomies")
    if os.path.isdir(base):
        for name in os.listdir(base):
            if name.endswith(".yaml"):
                data = f.load_yaml(os.path.join(base, name), {}) or {}
                terms[name[:-5]] = {str(t.get("id")) for t in (data.get("terms") or []) if isinstance(t, dict)}
    return terms


def uploads_in(value):
    """Every /uploads/... address inside a nested value."""
    found = []
    if isinstance(value, str):
        found.extend(UPLOAD.findall(value))
    elif isinstance(value, dict):
        for v in value.values():
            found.extend(uploads_in(v))
    elif isinstance(value, list):
        for v in value:
            found.extend(uploads_in(v))
    return found


def check_file(root, path, ctx):
    problems = []  # (level, message)
    snapshot = f.is_snapshot(root)
    inside = os.path.abspath(path).startswith(os.path.abspath(root) + os.sep)
    rel = os.path.relpath(path, root) if inside else path
    parts = rel.split(os.sep)
    if inside and len(parts) >= 3 and parts[0] == "content":
        ctype = parts[1]
    elif not inside:
        # a file checked without its installation: the folder it sits in names the type (…/posts/x.md), else it is a page
        ctype = ctx.get("type") or os.path.basename(os.path.dirname(os.path.abspath(path))) or "pages"
        if ctype not in ("pages", "posts", "projects", "forms", "books", "points", "routes", "businesses"):
            ctype = "pages"
    else:
        ctype = None
    slug, lang = f.split_name(path)
    default_lang, available = ctx["languages"]

    def add(level, message):
        problems.append((level, message))

    base = os.path.basename(path)
    if re.match(r"^.+\.[A-Za-z]{2,3}(-[A-Za-z0-9]+)?\.md$", base) and lang is None:
        add("ERROR", "the language in the file name must be two lowercase letters (about.en.md); the CMS reads this file as the default language with the slug %r" % slug)
    if lang is not None and lang == default_lang:
        add("WARN", "%s is the default language, so its file has no language in the name (%s.md); the CMS would read this as a second page" % (lang, slug))
    if lang is not None and lang not in available:
        add("WARN", "language `%s` is not one of the site's languages (%s): the page exists but is not linked from anywhere" % (lang, ", ".join(available)))
    if not re.match(r"^[a-z0-9][a-z0-9_-]*$", slug):
        add("ERROR", "the file name %r is not a clean address (lowercase Latin letters, digits, - and _); use gnozio_lib.slugify() / new_entry.py" % slug)
    if len(slug) > f.MAX_SLUG:
        add("WARN", "the address is longer than %d characters" % f.MAX_SLUG)
    if ctype in ("pages", "posts") and slug in f.ROOT_RESERVED:
        add("ERROR", "`%s` is a word the site already uses at its root; choose another address" % slug)
    if ctype in ("pages", "posts") and slug in ctx["types"] and slug not in ("pages", "posts"):
        add("ERROR", "`%s` is also the name of a content type, so the address would clash" % slug)
    if ctype in ("pages", "posts") and slug in ctx["languages"][1] and slug != default_lang:
        add("ERROR", "`%s` is a language code; it would clash with that language's home address" % slug)

    text = f.read_text(path)
    if text.startswith("﻿"):
        add("WARN", "the file starts with a byte order mark; save it as UTF-8 without one")
    if "\r\n" in text:
        add("WARN", "the file has Windows line endings (CRLF); the CMS writes LF, so Git shows every line as changed once it is edited in the admin")
    meta, body, error = f.parse_content(text)
    if error:
        add("ERROR", "the front matter cannot be read (%s): the CMS makes this file an unpublished draft" % error)
        return rel, problems, None
    if not f.FRONT_MATTER.match(text) and ctype != "forms":
        add("WARN", "no front matter (--- … ---): the page gets its title from the file name and is published")

    front_text = (f.FRONT_MATTER.match(text) or [None, ""])[1] if f.FRONT_MATTER.match(text) else ""
    for line in front_text.splitlines():
        if re.search(r":\s+(yes|no|on|off|y|n|Yes|No|On|Off|YES|NO|ON|OFF)\s*(#.*)?$", line):
            add("WARN", "`%s`: write true or false; the CMS reads yes/no/on/off as text, not as a yes or no" % line.strip())
    title = meta.get("title")
    if not title:
        add("WARN", "no `title` (the CMS makes one from the address)")
    status = str(meta.get("status", "published"))
    if status not in STATUSES:
        add("WARN", "status %r: only `published` and `draft` are used (anything else is not shown to visitors)" % status)
    if "visible" in meta and not isinstance(meta["visible"], bool):
        add("ERROR", "`visible` must be true or false, not %r" % (meta["visible"],))
    if "date" in meta and not re.match(r"^(\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?|\d{9,11})$", str(meta["date"])):
        add("WARN", "`date` %r should be YYYY-MM-DD" % (meta["date"],))
    tid = meta.get("translation_id")
    if tid is None:
        add("WARN", "no `translation_id`: the editor adds one on the next save, but translations are only linked when both files share it")
    elif not HEX16.match(str(tid)):
        add("WARN", "`translation_id` %r is not 16 hex characters (it still links translations that use the same value)" % (tid,))
    template = meta.get("template")
    if template and str(template) not in ctx["templates"]:
        add("WARN", "template %r is not one the site has (%s)" % (template, ", ".join(sorted(ctx["templates"]))))
    seo = meta.get("seo") if isinstance(meta.get("seo"), dict) else {}
    if len(str(seo.get("title", ""))) > 70:
        add("WARN", "seo.title is %d characters; search results show about 60" % len(str(seo.get("title"))))
    if len(str(seo.get("description", ""))) > 170:
        add("WARN", "seo.description is %d characters; search results show about 155" % len(str(seo.get("description"))))
    for taxonomy, known in ctx["taxonomies"].items():
        values = meta.get(taxonomy)
        if values is None:
            continue
        if ctype in ("pages", "forms"):
            add("WARN", "`%s` has no effect on %s" % (taxonomy, ctype))
        if not isinstance(values, list):
            add("ERROR", "`%s` must be a list of term ids" % taxonomy)
            continue
        for term in values:
            if str(term) not in known:
                add("WARN", "`%s` term %r is not defined in content/taxonomies/%s.yaml (it will not appear in any list)" % (taxonomy, term, taxonomy))

    blocks = meta.get("blocks")
    if blocks is not None:
        if ctype == "forms":
            add("WARN", "forms have fields, not blocks")
        if not isinstance(blocks, list):
            add("ERROR", "`blocks` must be a list")
        else:
            anchors = {}
            for index, block in enumerate(blocks):
                where = "blocks[%d]" % index
                for message in f.check_block(ctx["blocks"], block, where):
                    add("ERROR" if "unknown block type" in message or "must be a map" in message else "WARN", message)
                if isinstance(block, dict):
                    anchor = block.get("anchor")
                    if anchor:
                        if str(anchor) in anchors:
                            add("WARN", "%s: anchor %r is already used by blocks[%d]" % (where, anchor, anchors[str(anchor)]))
                        anchors[str(anchor)] = index
            first = blocks[0] if blocks and isinstance(blocks[0], dict) else {}
            hero_count = sum(1 for b in blocks if isinstance(b, dict) and b.get("type") == "hero")
            if hero_count > 1:
                add("WARN", "more than one Hero block: only the first one is the page's <h1> and opening section")
            if first.get("type") not in ("hero", "hero-slider", None) and meta.get("template") == "landing":
                add("WARN", "a landing page has no title area: start it with a hero block so the page has an <h1>")
    for address in ([] if snapshot or not inside else sorted(set(uploads_in(meta) + uploads_in(body)))):
        clean_address = address.split("?")[0].split("#")[0].rstrip(".,;")
        if "/_v/" in clean_address:
            continue
        if not os.path.isfile(os.path.join(root, "public", clean_address.lstrip("/"))):
            add("WARN", "%s does not exist in public/uploads (add the file with add_media.py, or fix the address)" % clean_address)
    if ctype == "forms":
        fields = meta.get("fields")
        if not isinstance(fields, list) or not fields:
            add("ERROR", "a form needs a list of `fields`")
        else:
            names = [str(x.get("name")) for x in fields if isinstance(x, dict) and x.get("type") not in ("heading", "text_block", "paragraph")]
            seen = set()
            for name in names:
                if name in seen:
                    add("ERROR", "two form fields share the name %r" % name)
                seen.add(name)
    if re.search(r"<script\b", body, re.I) or any("<script" in json.dumps(b) for b in (blocks or []) if isinstance(b, dict)):
        add("WARN", "contains <script>: only administrators with the raw HTML permission keep HTML when saving in the admin")
    return rel, problems, {"type": ctype, "slug": slug, "lang": lang, "tid": tid}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("files", nargs="*", help="content files to check (default: every .md file under content/)")
    parser.add_argument("--root", help="the installation folder (default: found from the current folder)")
    parser.add_argument("--json", action="store_true", help="print the result as JSON")
    parser.add_argument("--type", help="the content type of the files, when they are checked outside an installation")
    args = parser.parse_args()

    root = f.find_root(args.root, allow_snapshot=True)
    ctx = {
        "blocks": f.load_blocks(root),
        "languages": f.languages(root),
        "taxonomies": taxonomy_terms(root),
        "templates": page_templates(root),
        "types": f.content_types(root),
        "type": args.type,
    }
    files = []
    for given in args.files:
        # a path is taken from the current folder, else from the installation folder (content/pages/about.md)
        files.append(os.path.abspath(given) if os.path.exists(given) or os.path.isabs(given) else os.path.join(root, given))
    if not files and f.is_snapshot(root):
        raise SystemExit("Name the files to check (there is no installation to find them in).")
    if not files:
        for ctype in ctx["types"]:
            files.extend(p for _s, _l, p in f.list_entries(root, ctype))

    results = []
    entries = []
    for path in files:
        if not os.path.isfile(path):
            results.append({"file": path, "problems": [{"level": "ERROR", "message": "no such file"}]})
            continue
        rel, problems, info = check_file(root, path, ctx)
        results.append({"file": rel, "problems": [{"level": l, "message": m} for l, m in problems]})
        if info and info["type"]:
            entries.append((rel, info))

    # Translations are linked by one translation_id shared by the files of the same page in each language.
    by_tid = {}
    for rel, info in entries:
        if info["tid"]:
            by_tid.setdefault((info["type"], str(info["tid"])), []).append((rel, info))
    for (_type, tid), group in by_tid.items():
        langs = [i["lang"] for _r, i in group]
        if len(langs) != len(set(langs)):
            for rel, _info in group:
                next(r for r in results if r["file"] == rel)["problems"].append({"level": "ERROR", "message": "translation_id %s is shared by two files of the same language" % tid})
    slugs = {}
    for rel, info in entries:
        if info["type"] in ("pages", "posts"):
            key = (info["slug"], info["lang"])
            if key in slugs and slugs[key][1] != info["type"]:
                for r in (rel, slugs[key][0]):
                    next(x for x in results if x["file"] == r)["problems"].append({"level": "ERROR", "message": "the address /%s is used by both %s and %s" % (info["slug"], slugs[key][1], info["type"])})
            slugs[key] = (rel, info["type"])

    errors = sum(1 for r in results for p in r["problems"] if p["level"] == "ERROR")
    warns = sum(1 for r in results for p in r["problems"] if p["level"] == "WARN")
    if args.json:
        f.out({"files": len(results), "errors": errors, "warnings": warns, "results": [r for r in results if r["problems"]]})
    else:
        for r in results:
            for p in r["problems"]:
                print("%-5s %s: %s" % (p["level"], r["file"], p["message"]))
        print("%d files, %d errors, %d warnings" % (len(results), errors, warns))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
