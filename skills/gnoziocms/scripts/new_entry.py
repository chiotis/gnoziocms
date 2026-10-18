#!/usr/bin/env python3
"""
Writes one content file (a page, a post, a project, any content type) the way the CMS would, and checks it.

    python3 new_entry.py --type pages --title "Σχετικά" --blocks about-blocks.yaml --body-file about.md
    python3 new_entry.py --type pages --lang en --title "About" --translation-of about --blocks about-blocks-en.yaml
    python3 new_entry.py --type posts --title "Νέα του μήνα" --date 2026-10-06 --categories news --body-file post.md
    python3 new_entry.py --type pages --title "Contact" --status draft --dry-run

--blocks takes a YAML or JSON file holding the list of blocks (see references/blocks.md). --body-file / --body is the
Markdown text (`-` reads the standard input). --meta key=value adds any other front matter field (`--meta template=landing`,
`--meta custom_fields.author=Anna`; values that look like true/false/numbers/JSON are stored as such).

The address is made from the title like the CMS does (Greek becomes Latin), unless --slug is given. An existing file is never
overwritten without --force, and the file is only kept when it has no ERROR from validate_content.py.
"""
import argparse
import datetime
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402
import validate_content as v  # noqa: E402


def read_arg(value, label):
    if value == "-":
        return sys.stdin.read()
    if not os.path.isfile(value):
        raise SystemExit("%s: no such file: %s" % (label, value))
    return f.read_text(value)


def parse_value(text):
    text = text.strip()
    if text[:1] in "[{":
        try:
            return json.loads(text)
        except ValueError:
            return text
    low = text.lower()
    if low in ("true", "false"):
        return low == "true"
    if text.lstrip("-").isdigit() and not text.startswith("0") or text == "0":
        return int(text)
    return text


def set_path(data, dotted, value):
    keys = dotted.split(".")
    for key in keys[:-1]:
        data = data.setdefault(key, {})
    data[keys[-1]] = value


def load_preset(root, name, lang, default_lang):
    """(blocks, template) of a ready-made layout, in the page's language (else the site's, else English, else the first)."""
    for base in (os.path.join(root, "custom", "presets"), os.path.join(root, "themes", "default", "presets")):
        path = os.path.join(base, name + ".yaml")
        if os.path.isfile(path):
            data = f.load_yaml(path, {}) or {}
            blocks = data.get("blocks") or []
            if isinstance(blocks, dict):
                blocks = blocks.get(lang) or blocks.get(default_lang) or blocks.get("en") or next(iter(blocks.values()), [])
            return list(blocks), str(data.get("template") or "")
    raise SystemExit("No preset %r. List them with: python3 site_info.py --presets" % name)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root")
    parser.add_argument("--type", default="pages", help="content type folder (pages, posts, projects, …)")
    parser.add_argument("--title", required=True)
    parser.add_argument("--lang", help="language code (default: the site's default language)")
    parser.add_argument("--slug", help="address; made from the title when omitted. For the home page use the site's home slug (index)")
    parser.add_argument("--status", default="published", choices=["published", "draft"])
    parser.add_argument("--hidden", action="store_true", help="visible: false (published but not listed or linked)")
    parser.add_argument("--date", help="YYYY-MM-DD (posts and projects)")
    parser.add_argument("--excerpt")
    parser.add_argument("--seo-title")
    parser.add_argument("--seo-description")
    parser.add_argument("--main-image", help="/uploads/media/<id>.<ext>")
    parser.add_argument("--template", help="landing, sidebar, or standard")
    parser.add_argument("--categories", help="comma separated term ids (not for pages)")
    parser.add_argument("--tags", help="comma separated term ids (not for pages)")
    parser.add_argument("--blocks", help="YAML or JSON file with the list of blocks")
    parser.add_argument("--preset", help="start from a ready-made page layout or section (python3 site_info.py --presets); --blocks are added after it")
    parser.add_argument("--body", help="Markdown text, or - for stdin")
    parser.add_argument("--body-file", help="a file with the Markdown text")
    parser.add_argument("--meta", action="append", default=[], help="extra front matter: key=value (dots go deeper)")
    parser.add_argument("--translation-of", help="slug of the entry this is a translation of: shares its translation_id")
    parser.add_argument("--force", action="store_true", help="overwrite an existing file")
    parser.add_argument("--dry-run", action="store_true", help="print the file, write nothing")
    parser.add_argument("--out", help="without an installation: the folder to write content/<type>/<file> under (give it to the person to copy into the site)")
    args = parser.parse_args()

    root = f.find_root(args.root, allow_snapshot=bool(args.out))
    default_lang, available = f.languages(root)
    if f.is_snapshot(root) and not args.out:
        raise SystemExit("There is no installation to write into: pass --out DIR to write the file under DIR/content/<type>/")
    lang = args.lang or default_lang
    if lang not in available:
        sys.stderr.write("note: %s is not one of the site's languages (%s); add it in Settings > General first\n" % (lang, ", ".join(available)))
    slug = args.slug or f.slugify(args.title)
    if not slug:
        raise SystemExit("The title gives no address (it has no letters or digits): pass --slug")
    if args.type in ("pages", "posts") and slug in f.ROOT_RESERVED:
        raise SystemExit("`%s` is a word the site already uses at its root: choose another address" % slug)

    base = os.path.abspath(args.out) if args.out else root
    folder = os.path.join(base, "content", args.type)
    path = os.path.join(folder, f.filename(slug, lang, default_lang))
    if os.path.exists(path) and not args.force:
        raise SystemExit("%s already exists. Use another --slug, or --force to replace it (the old version is then kept in storage/overwritten/)." % os.path.relpath(path, root))

    tid = None
    if args.translation_of:
        for s, l, p in f.list_entries(base, args.type):
            if s == args.translation_of:
                meta, _b, _e = f.parse_content(f.read_text(p))
                tid = meta.get("translation_id")
                if tid:
                    break
        if not tid:
            raise SystemExit("No entry %r of type %s with a translation_id to translate. Create the original first." % (args.translation_of, args.type))
    meta = {"title": args.title, "status": args.status, "visible": not args.hidden}
    if args.date:
        meta["date"] = args.date
    elif args.type == "posts":
        meta["date"] = datetime.date.today().isoformat()
    meta["translation_id"] = str(tid) if tid else f.new_translation_id()
    if args.excerpt:
        meta["excerpt"] = args.excerpt
    seo = {}
    if args.seo_title:
        seo["title"] = args.seo_title
    if args.seo_description:
        seo["description"] = args.seo_description
    if seo:
        meta["seo"] = seo
    if args.categories and args.type not in ("pages", "forms"):
        meta["categories"] = [c.strip() for c in args.categories.split(",") if c.strip()]
    if args.tags and args.type not in ("pages", "forms"):
        meta["tags"] = [c.strip() for c in args.tags.split(",") if c.strip()]
    if args.main_image:
        meta["main_image"] = args.main_image
    if args.template:
        meta["template"] = args.template
    for item in args.meta:
        if "=" not in item:
            raise SystemExit("--meta needs key=value, got %r" % item)
        key, value = item.split("=", 1)
        set_path(meta, key.strip(), parse_value(value))
    blocks = []
    if args.preset:
        blocks, preset_template = load_preset(root, args.preset, lang, default_lang)
        if preset_template and "template" not in meta:
            meta["template"] = preset_template
    if args.blocks:
        try:
            extra = f.yaml.safe_load(read_arg(args.blocks, "--blocks"))
        except f.yaml.YAMLError as error:
            raise SystemExit("--blocks is not valid YAML: %s\nUsual causes: a text containing `: `, `,`, `?`, `#`, `{` or starting with a quote/asterisk inside {flow: style}. Put such texts in double quotes, or write the block in the long style (one field per line)." % str(error).replace("\n", " | ")[:400])
        if isinstance(extra, dict) and "blocks" in extra:
            extra = extra["blocks"]
        if not isinstance(extra, list):
            raise SystemExit("--blocks must hold a list of blocks")
        blocks = blocks + extra
    if blocks:
        meta["blocks"] = blocks
    body = ""
    if args.body_file:
        body = read_arg(args.body_file, "--body-file")
    elif args.body is not None:
        body = read_arg(args.body, "--body") if args.body == "-" else args.body
    body = body.replace("\r\n", "\n")

    text = f.dump_content(meta, body)
    if args.dry_run:
        sys.stdout.write(text)
        return 0

    os.makedirs(folder, exist_ok=True)
    existed = os.path.exists(path)
    previous = f.read_text(path) if existed else None
    if existed:
        # --force: keep what was there, the History of the admin only knows versions saved from the admin
        saved = os.path.join(base, "storage", "overwritten", datetime.datetime.now().strftime("%Y%m%d-%H%M%S"), args.type)
        os.makedirs(saved, exist_ok=True)
        with open(os.path.join(saved, os.path.basename(path)), "w", encoding="utf-8", newline="\n") as handle:
            handle.write(previous)
        print("note: the file that was there is kept in %s" % os.path.relpath(saved, base))
    with open(path, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)
    ctx = {"blocks": f.load_blocks(root), "languages": (default_lang, available), "taxonomies": v.taxonomy_terms(root),
           "templates": v.page_templates(root), "types": f.content_types(root)}
    ctx["type"] = args.type
    rel, problems, _info = v.check_file(root if not args.out else base, path, ctx)
    for level, message in problems:
        print("%-5s %s: %s" % (level, rel, message))
    if any(level == "ERROR" for level, _m in problems):
        if existed:
            with open(path, "w", encoding="utf-8", newline="\n") as handle:
                handle.write(previous)
        else:
            os.remove(path)
        print("Not written: fix the errors above.")
        return 1
    print("Wrote %s -> /%s (%s)" % (rel, f.public_path(args.type, slug, lang, default_lang, f.home_slug(root)), meta["status"]))
    if f.is_snapshot(root):
        print("Checked against the bundled definitions only (the site's own content types, forms, taxonomies and uploads were not checked).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
