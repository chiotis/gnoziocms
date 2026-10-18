#!/usr/bin/env python3
"""
Reads and changes the settings of a GnozioCMS site when you have the installation's files (not the browser).

The settings are not files: they are two YAML documents in the SQLite database storage/db/app.sqlite
(`site_settings`: title, languages, mail, backups…; `theme_settings`: look, header, footer, branding…). This script
edits those documents safely: it checks every theme value against the theme's own schema (a wrong value would be
silently replaced by the default), refuses secrets and risky keys, and copies the database first.

    python3 site_settings.py show theme                       # the stored theme settings
    python3 site_settings.py show theme header.layout         # one value (with its choices)
    python3 site_settings.py schema theme appearance          # every field of a section with choices and defaults
    python3 site_settings.py set theme appearance.palette=emerald
    python3 site_settings.py set theme header.layout=split header.sticky=smart footer.layout=bar   # several at once: all or none
    python3 site_settings.py set theme footer.copyright='"© {year} {site}"'
    python3 site_settings.py set theme brand.logo=/uploads/media/0123456789abcdef.png
    python3 site_settings.py set site title="Το site μου"
    python3 site_settings.py show site
    python3 site_settings.py set theme header.layout=split --dry-run

Each assignment is PATH=VALUE. VALUE is read as JSON when it is JSON (true, 42, ["a","b"], "text"), else as plain text.
Site settings that can be set here: title, tagline, base_url, date_format, home_page, menu_locations.<place>,
languages.available and languages.default (these two only with --risky: they change which files are the site's
default language), apis.maps.load|tiles_url|attribution, apis.youtube.cache_hours.
Everything else (mail and backup settings with passwords, users, roles, limits, SEO, analytics) is done in the admin.
Close the browser tab of Settings/Theme first, or reload it afterwards: a form that was open will save its old values over these.
"""
import argparse
import datetime
import json
import os
import re
import shutil
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402

SECRET = re.compile(r"(secret|password|pass$|token|api_?key|\bkey$|access_key|private)", re.I)
SITE_KEYS = {
    "title": "text", "tagline": "text", "base_url": "url", "date_format": "text", "home_page": "slug",
    "apis.maps.load": ("click", "auto"), "apis.maps.tiles_url": "url", "apis.maps.attribution": "text", "apis.youtube.cache_hours": "int",
}
RISKY = {"languages.available", "languages.default"}


def parse_value(text):
    try:
        return json.loads(text)
    except ValueError:
        return text


def get_path(data, dotted):
    for key in dotted.split("."):
        if not isinstance(data, dict) or key not in data:
            return None
        data = data[key]
    return data


def set_path(data, dotted, value):
    keys = dotted.split(".")
    for key in keys[:-1]:
        if not isinstance(data.get(key), dict):
            data[key] = {}
        data = data[key]
    data[keys[-1]] = value


def load_doc(root, key):
    raw = f.meta_get(root, key)
    if raw is None:
        return None, {}
    try:
        data = f.yaml.safe_load(raw) if raw.strip() else {}
    except f.yaml.YAMLError as error:
        raise SystemExit("The stored %s is not valid YAML (%s); fix it in the admin or restore a backup." % (key, error))
    return raw, data if isinstance(data, dict) else {}


def theme_schema(root):
    manifest = f.load_yaml(os.path.join(root, "themes", "default", "theme.yaml"), {}) or {}
    icons = f.icon_names(root)
    sections = {}
    for key, section in (manifest.get("settings") or {}).items():
        if isinstance(section, dict):
            sections[str(key)] = {"label": str(section.get("label") or key), "fields": f.normalize_fields(section.get("fields") or {}, icons, {})}
    return sections


def backup_db(root):
    source = f.db_path(root)
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    target = os.path.join(root, "storage", "db", "app-before-settings-%s.sqlite" % stamp)
    src = sqlite3.connect(source)
    try:
        dst = sqlite3.connect(target)
        try:
            src.backup(dst)
        finally:
            dst.close()
    finally:
        src.close()
    copies = sorted(n for n in os.listdir(os.path.dirname(target)) if n.startswith("app-before-settings-") and n.endswith(".sqlite"))
    for old in copies[:-5]:
        os.remove(os.path.join(os.path.dirname(target), old))
    return os.path.relpath(target, root)


def write_doc(root, key, data):
    text = f.yaml.safe_dump(data, allow_unicode=True, sort_keys=False, default_flow_style=False, width=120)
    con = sqlite3.connect(f.db_path(root), timeout=15)
    try:
        con.execute("BEGIN IMMEDIATE")
        con.execute(
            "INSERT INTO system_meta (key, value, updated_at) VALUES (?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, text, datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")))
        con.commit()
    finally:
        con.close()


def describe_field(field):
    out = {"type": field["type"], "default": field["default"], "label": field["label"]}
    if "options" in field:
        out["choices"] = list(field["options"])
    if field.get("when"):
        out["only_when"] = field["when"]
    if field.get("translatable"):
        out["translatable"] = "a text, or {default: …, <lang>: …}"
    if field["help"]:
        out["help"] = field["help"]
    return out


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root")
    sub = parser.add_subparsers(dest="cmd", required=True)
    show = sub.add_parser("show")
    show.add_argument("which", choices=["site", "theme"])
    show.add_argument("path", nargs="?")
    schema = sub.add_parser("schema")
    schema.add_argument("which", choices=["theme"])
    schema.add_argument("section", nargs="?")
    schema.add_argument("--markdown", action="store_true", help="every section and field as a Markdown reference")
    setter = sub.add_parser("set")
    setter.add_argument("which", choices=["site", "theme"])
    setter.add_argument("assignments", nargs="+", metavar="PATH=VALUE")
    setter.add_argument("--dry-run", action="store_true")
    setter.add_argument("--risky", action="store_true", help="allow keys that change how files are read (languages)")
    args = parser.parse_args()
    root = f.find_root(args.root)

    if args.cmd == "schema":
        sections = theme_schema(root)
        if args.markdown:
            print("# Theme settings of this installation (GnozioCMS %s)\n" % f.version(root))
            print("Set with `python3 scripts/site_settings.py set theme section.field=value`. A text marked *translatable* takes a plain text or a map `{default: …, en: …}`.\n")
            for key, section in sections.items():
                print("## `%s` — %s\n" % (key, section["label"]))
                print("| field | kind and choices | default | notes |")
                print("|---|---|---|---|")
                for name, field in section["fields"].items():
                    if field["hidden"]:
                        continue
                    kind = field["type"] + ("; one of " + ", ".join("`%s`" % o if o != "" else "`(empty)`" for o in field["options"]) if field.get("options") and field["type"] == "select" else "")
                    if field["type"] == "number" and (field.get("min") is not None or field.get("max") is not None):
                        kind += "; %s to %s" % (field.get("min"), field.get("max"))
                    if field.get("translatable"):
                        kind += "; translatable"
                    default = field["default"]
                    default = "(empty)" if default in ("", None, {}, []) else (str(default).lower() if isinstance(default, bool) else str(default))
                    notes = field["label"] + (". " + field["help"] if field["help"] else "")
                    if field.get("when"):
                        notes += " (only when " + " and ".join("`%s` is %s" % (k, " or ".join("`%s`" % x for x in v)) for k, v in field["when"].items()) + ")"
                    print("| `%s` | %s | %s | %s |" % (name, kind.replace("|", "/"), default.replace("|", "/"), notes.replace("|", "/").replace("\n", " ")))
                print()
            print("## Stored with the theme settings, edited only in the admin\n\n`single_layouts.<type>` (Theme > Single Layouts: page layout, title area, sidebar for each content type), `search_page` (Theme > Archive Layouts > Search) and `footer_blocks` (Theme > Footer Blocks: blocks above the footer, a set per language).")
            return 0
        if not args.section:
            for key, s in sections.items():
                print("%-18s %s (%d fields)" % (key, s["label"], len(s["fields"])))
            print("\nAlso stored under theme settings, edited only in the admin: single_layouts, search_page, footer_blocks.")
            return 0
        if args.section not in sections:
            raise SystemExit("No section %r. Sections: %s" % (args.section, ", ".join(sections)))
        f.out({k: describe_field(v) for k, v in sections[args.section]["fields"].items() if not v["hidden"]})
        return 0

    key = "site_settings" if args.which == "site" else "theme_settings"
    raw, data = load_doc(root, key)
    if args.cmd == "show":
        if raw is None and not os.path.isfile(f.db_path(root)):
            print("No database yet: open /admin once so the CMS creates it. The defaults apply.")
            return 0
        shown = {k: v for k, v in data.items()}

        def hide(node, trail=""):
            for k in list(node):
                p = (trail + "." + k) if trail else k
                if isinstance(node[k], dict):
                    hide(node[k], p)
                elif SECRET.search(k) and node[k]:
                    node[k] = "(set, hidden)"
        hide(shown)
        if args.path:
            value = get_path(shown, args.path)
            out = {"path": args.path, "stored": value}
            if args.which == "theme" and "." in args.path:
                section, field = args.path.split(".", 1)
                schema_field = (theme_schema(root).get(section) or {}).get("fields", {}).get(field.split(".")[0])
                if schema_field:
                    out["definition"] = describe_field(schema_field)
                    if value is None:
                        out["stored"] = "(not stored: the default applies)"
            f.out(out)
        else:
            f.out(shown)
        return 0

    # set
    if not os.path.isfile(f.db_path(root)):
        raise SystemExit("There is no storage/db/app.sqlite yet. Open /admin once so the CMS creates it, then run this again.")
    sections = theme_schema(root) if args.which == "theme" else None
    changes = []
    for assignment in args.assignments:
        if "=" not in assignment:
            raise SystemExit("Write each change as PATH=VALUE (got %r)" % assignment)
        path, text = assignment.split("=", 1)
        changes.append((path.strip(), check_value(root, args, sections, path.strip(), parse_value(text))))
    for path, value in changes:
        before = get_path(data, path)
        set_path(data, path, value)
        print("%s: %s -> %s" % (path, json.dumps(before, ensure_ascii=False), json.dumps(value, ensure_ascii=False)))
    if args.dry_run:
        print("(dry run: nothing written)")
        return 0
    if raw is None:
        print("note: no %s stored yet; only these values are stored, the defaults apply to the rest" % key)
    saved = backup_db(root)
    write_doc(root, key, data)
    print("Written. Database copy before the change: %s (the last 5 copies are kept)" % saved)
    return 0


def check_value(root, args, sections, path, value):
    """The value as it will be stored, or a SystemExit saying why not."""
    if SECRET.search(path.split(".")[-1]) or "secret" in path or "password" in path:
        raise SystemExit("%s looks like a secret or a key. Passwords and keys are never set from a script: use the admin (Settings), where they are stored without being shown again." % path)

    if args.which == "theme":
        parts = path.split(".")
        if len(parts) != 2:
            raise SystemExit("A theme setting is section.field (for example header.layout). Sections: %s. single_layouts, search_page and footer_blocks are edited in the admin." % ", ".join(sections))
        section, name = parts
        if section not in sections or name not in sections[section]["fields"]:
            known = ", ".join(sections.get(section, {}).get("fields", {})) if section in sections else ", ".join(sections)
            raise SystemExit("There is no theme setting %s. %s: %s" % (path, "Fields of " + section if section in sections else "Sections", known))
        field = sections[section]["fields"][name]
        if field["hidden"]:
            raise SystemExit("%s is retired (kept for old sites); it has no effect." % path)
        cleaned = f.clean(field, value)
        if not f._same(cleaned, value):
            raise SystemExit("%s: %s" % (path, f._why(field, value)))
        value = cleaned
        if field.get("when"):
            print("note: %s only shows while %s" % (path, "; ".join("%s is %s" % (k, " or ".join(v)) for k, v in field["when"].items())))
    else:
        rule = SITE_KEYS.get(path)
        if path in RISKY:
            if not args.risky:
                raise SystemExit("%s changes which files are the default language (about.md vs about.en.md) and which addresses the site has. Do it in Settings > General, or repeat with --risky if you have planned the move of the files." % path)
            value = value if isinstance(value, list) or path == "languages.default" else [value]
            for code in (value if isinstance(value, list) else [value]):
                if not re.match(r"^[a-z]{2}$", str(code)):
                    raise SystemExit("Language codes are two lowercase letters (the CMS reads only those from file names): %r" % (code,))
        elif path.startswith("menu_locations."):
            menu = str(value)
            if not os.path.isfile(os.path.join(root, "content", "menus", menu + ".yaml")):
                raise SystemExit("There is no menu %r in content/menus." % menu)
        elif rule is None:
            raise SystemExit("%s cannot be set from here. Settable: %s, menu_locations.<place>, languages.* (--risky). The rest is done in the admin." % (path, ", ".join(SITE_KEYS)))
        elif isinstance(rule, tuple):
            if value not in rule:
                raise SystemExit("%s must be one of: %s" % (path, ", ".join(rule)))
        elif rule == "url" and value != "" and not re.match(r"^https?://[^\s\"'<>]+$", str(value)):
            raise SystemExit("%s must be a full address like https://example.com (or empty)" % path)
        elif rule == "int" and not (isinstance(value, int) and not isinstance(value, bool) and 1 <= value <= 168):
            raise SystemExit("%s must be a whole number from 1 to 168" % path)
        elif rule == "slug" and not re.match(r"^[a-z0-9][a-z0-9_-]*$", str(value)):
            raise SystemExit("%s must be the file name (without .md) of a page, like index" % path)
        elif rule == "text" and (not isinstance(value, str) or not value.strip()):
            raise SystemExit("%s must be a non-empty text" % path)

    return value


if __name__ == "__main__":
    sys.exit(main())
