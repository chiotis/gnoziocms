#!/usr/bin/env python3
"""
A one-screen picture of a GnozioCMS installation, so you start from facts instead of guesses: the version, the
languages, what content exists in which language, the menus, forms, taxonomies, ready-made page layouts, the block
types (including the site's own) and the current look. Read-only.

    python3 site_info.py                  # summary (run this first)
    python3 site_info.py --root /path/to/site
    python3 site_info.py --presets        # the ready-made sections and page layouts, with their blocks
    python3 site_info.py --icons          # the icon names blocks accept
    python3 site_info.py --entries pages  # every entry of a type: address, title, status, languages
    python3 site_info.py --json
"""
import argparse
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402


def label(value, lang="en"):
    if isinstance(value, dict):
        return str(value.get(lang) or value.get("en") or next(iter(value.values()), ""))
    return str(value or "")


def presets(root):
    found = []
    for origin, base in (("theme", os.path.join(root, "themes", "default", "presets")), ("custom", os.path.join(root, "custom", "presets"))):
        if not os.path.isdir(base):
            continue
        for name in sorted(os.listdir(base)):
            if not name.endswith(".yaml"):
                continue
            data = f.load_yaml(os.path.join(base, name), {}) or {}
            blocks = data.get("blocks") or {}
            langs = sorted(blocks) if isinstance(blocks, dict) else ["(any)"]
            found.append({"name": name[:-5], "origin": origin, "kind": data.get("kind", "section"), "template": data.get("template", ""),
                          "label": label(data.get("label")), "description": label(data.get("description")), "languages": langs, "file": os.path.relpath(os.path.join(base, name), root)})
    return found


def theme_state(root):
    """The current look: stored theme settings over the defaults (only the choices people usually ask about)."""
    manifest = f.load_yaml(os.path.join(root, "themes", "default", "theme.yaml"), {}) or {}
    raw = f.meta_get(root, "theme_settings")
    try:
        stored = f.yaml.safe_load(raw) if raw else {}
    except f.yaml.YAMLError:
        stored = {}
    stored = stored if isinstance(stored, dict) else {}
    state = {}
    for section, key in (("appearance", "palette"), ("appearance", "mode"), ("appearance", "font"), ("appearance", "shape"), ("header", "layout"), ("footer", "layout")):
        schema = (((manifest.get("settings") or {}).get(section) or {}).get("fields") or {}).get(key) or {}
        value = (stored.get(section) or {}).get(key, schema.get("default"))
        state["%s.%s" % (section, key)] = value
    return state


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root")
    parser.add_argument("--presets", action="store_true")
    parser.add_argument("--icons", action="store_true")
    parser.add_argument("--entries", metavar="TYPE")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    root = f.find_root(args.root, allow_snapshot=bool(args.presets or args.icons))
    default_lang, available = f.languages(root)

    if args.icons:
        print("\n".join(f.icon_names(root)) if not args.json else "")
        if args.json:
            f.out(f.icon_names(root))
        return 0
    if args.presets:
        data = presets(root)
        if args.json:
            f.out(data)
        else:
            for p in data:
                print("%-24s %-8s %-7s %-14s %s" % (p["name"], p["origin"], p["kind"], ",".join(p["languages"]), p["label"]))
            print("\nA preset file holds `blocks:` per language; copy the blocks you need into a page (see references/recipes.md).")
        return 0
    if args.entries:
        rows = {}
        for slug, lang, path in f.list_entries(root, args.entries):
            meta, _body, error = f.parse_content(f.read_text(path))
            row = rows.setdefault(slug, {"slug": slug, "langs": {}, "title": ""})
            row["langs"][lang or default_lang] = {"status": "UNREADABLE" if error else meta.get("status", "published"), "title": meta.get("title", "")}
        if args.json:
            f.out(list(rows.values()))
        else:
            for row in rows.values():
                print("%-40s %s" % (row["slug"], "  ".join("%s:%s \"%s\"" % (l, v["status"], v["title"]) for l, v in sorted(row["langs"].items()))))
        return 0

    settings = f.site_settings(root)
    types = {}
    for ctype in f.content_types(root):
        entries = f.list_entries(root, ctype)
        per_lang = {}
        for _slug, lang, _path in entries:
            per_lang[lang or default_lang] = per_lang.get(lang or default_lang, 0) + 1
        types[ctype] = per_lang
    menus_dir = os.path.join(root, "content", "menus")
    menus = {}
    if os.path.isdir(menus_dir):
        for name in sorted(os.listdir(menus_dir)):
            if name.endswith(".yaml"):
                data = f.load_yaml(os.path.join(menus_dir, name), {}) or {}
                menus[name[:-5]] = len(data.get("items") or [])
    tax_dir = os.path.join(root, "content", "taxonomies")
    taxonomies = {}
    if os.path.isdir(tax_dir):
        for name in sorted(os.listdir(tax_dir)):
            if name.endswith(".yaml"):
                data = f.load_yaml(os.path.join(tax_dir, name), {}) or {}
                taxonomies[name[:-5]] = [str(t.get("id")) for t in (data.get("terms") or []) if isinstance(t, dict)]
    forms = sorted({s for s, _l, _p in f.list_entries(root, "forms")})
    media_dir = os.path.join(root, "content", "media")
    blocks = f.load_blocks(root)
    info = {
        "root": root,
        "version": f.version(root),
        "system_database": os.path.isfile(f.db_path(root)),
        "settings_found": bool(settings),
        "site_title": settings.get("title", "(defaults: open /admin once, or the database does not exist yet)"),
        "languages": {"default": default_lang, "available": available},
        "home_page": f.home_slug(root),
        "base_url": settings.get("base_url", ""),
        "content": types,
        "menus": menus,
        "menu_locations": settings.get("menu_locations", {"header": "main", "footer": "footer"}),
        "forms": forms,
        "taxonomies": taxonomies,
        "media_files": len([n for n in os.listdir(media_dir) if n.endswith(".yaml")]) if os.path.isdir(media_dir) else 0,
        "blocks": sorted(blocks),
        "custom_blocks": sorted(b for b, d in blocks.items() if d["origin"] == "custom"),
        "custom_files": sorted(os.listdir(os.path.join(root, "custom"))) if os.path.isdir(os.path.join(root, "custom")) else [],
        "look": theme_state(root),
        "ready_made_layouts": len(presets(root)),
        "writable": {d: os.access(os.path.join(root, d), os.W_OK) for d in ("content", "public/uploads", "custom", "storage") if os.path.isdir(os.path.join(root, d))},
    }
    if args.json:
        f.out(info)
        return 0
    print("GnozioCMS %s at %s" % (info["version"], root))
    print("Site: %s   languages: default %s, available %s   home page: %s.md" % (info["site_title"], default_lang, ", ".join(available), info["home_page"]))
    if not info["system_database"]:
        print("! storage/db/app.sqlite does not exist: settings are the defaults. Open /admin once (it creates the database and asks for the first administrator).")
    print("Content (entries per language):")
    for ctype, per in types.items():
        print("  %-12s %s" % (ctype, ", ".join("%s %d" % (l, n) for l, n in sorted(per.items())) or "empty"))
    print("Menus: %s   (header=%s, footer=%s)" % (", ".join("%s (%d items)" % kv for kv in menus.items()) or "none", info["menu_locations"].get("header"), info["menu_locations"].get("footer")))
    print("Forms: %s" % (", ".join(forms) or "none"))
    print("Taxonomies: %s" % ("; ".join("%s: %s" % (k, ", ".join(v) or "-") for k, v in taxonomies.items()) or "none"))
    print("Media: %d files in the library" % info["media_files"])
    print("Look: %s" % ", ".join("%s=%s" % kv for kv in info["look"].items()))
    print("Blocks (%d): %s%s" % (len(blocks), ", ".join(sorted(blocks)), ("   own: " + ", ".join(info["custom_blocks"])) if info["custom_blocks"] else ""))
    print("Ready-made page layouts and sections: %d (python3 site_info.py --presets)" % info["ready_made_layouts"])
    bad = [k for k, v in info["writable"].items() if not v]
    if bad:
        print("! not writable by this user: %s" % ", ".join(bad))
    return 0


if __name__ == "__main__":
    sys.exit(main())
