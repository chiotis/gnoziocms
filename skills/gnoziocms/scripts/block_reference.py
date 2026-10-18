#!/usr/bin/env python3
"""
Prints the reference of every block type of an installation: its layouts (variants), every field with its kind,
choices, default and limits, and the shared presentation fields. It reads the installation's own block.yaml files
(themes/default/blocks and custom/blocks), so it is always right for the version that is installed, including
blocks a site added itself.

    python3 block_reference.py                      # all blocks, Markdown
    python3 block_reference.py hero faq             # only these
    python3 block_reference.py --root /path/to/site --list    # one line per block
    python3 block_reference.py --json
"""
import argparse
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402

KIND = {
    "text": "text", "textarea": "text (several lines)", "markdown": "Markdown", "email": "email", "url": "web address",
    "link": "link", "image": "image", "video": "video", "file": "file", "location": "position", "color": "colour",
    "number": "whole number", "decimal": "decimal number", "date": "date YYYY-MM-DD", "select": "choice",
    "icon": "icon name", "toggle": "true / false", "repeater": "list of rows",
}



GENERIC = False
SOURCE_WORDS = {"content_types": "a content type of the site", "forms": "the slug of a form of the site (content/forms)", "place_types": "a content type that has places"}


def describe(field):
    t = field["type"]
    parts = [KIND.get(t, t)]
    if t == "select" and field.get("source"):
        options = [o for o in field["options"] if o != ""]
        now = "" if GENERIC or not options else " (now: " + ", ".join("`%s`" % o for o in options) + ")"
        parts.append(SOURCE_WORDS.get(field["source"], "a choice the site offers") + now)
    elif t == "select":
        options = list(field["options"])
        shown = ", ".join("`%s`" % o if o != "" else "``(empty)``" for o in options[:40])
        parts.append("one of " + shown + (" …" if len(options) > 40 else ""))
    if t == "number" and (field.get("min") is not None or field.get("max") is not None):
        parts.append("%s to %s" % (field.get("min"), field.get("max")))
    if t == "repeater" and field.get("max") is not None:
        parts.append("at most %d rows" % field["max"])
    if field.get("translatable"):
        parts.append("a text, or a map `default` + language codes")
    if field["required"]:
        parts.append("required")
    default = field["default"]
    if t not in ("repeater",) and default not in ("", None):
        parts.append("default `%s`" % (str(default).lower() if isinstance(default, bool) else default))
    if field.get("when"):
        parts.append("only when " + " and ".join("`%s` is %s" % (k, " or ".join("`%s`" % x for x in v)) for k, v in field["when"].items()))
    return "; ".join(parts)


def render(block, with_help=True):
    lines = ["### `%s` — %s%s" % (block["type"], block["label"], "" if block["origin"] == "theme" else " (this site's own block)")]
    if block["description"]:
        lines.append(block["description"])
    lines.append("")
    layouts = ", ".join("`%s` (%s)" % (k, v) for k, v in block["variants"].items())
    lines.append("**Layouts** (`variant`): %s. First is the default." % layouts)
    if block["variant_when"]:
        lines.append("Some layouts need a field: " + "; ".join("`%s` only when %s" % (k, v) for k, v in block["variant_when"].items()))
    lines.append("")
    lines.append("| field | kind and choices | notes |")
    lines.append("|---|---|---|")
    for key, field in block["fields"].items():
        if field["hidden"]:
            continue
        notes = field["help"].replace("|", "/") if with_help else ""
        lines.append("| `%s` | %s | %s |" % (key, describe(field).replace("|", "/"), notes))
        if field["type"] == "repeater":
            for sub_key, sub in field["fields"].items():
                sub_notes = sub["help"].replace("|", "/") if with_help else ""
                lines.append("| &nbsp;&nbsp;`%s[].%s` | %s | %s |" % (key, sub_key, describe(sub).replace("|", "/"), sub_notes))
                # a list inside the rows (two levels at most)
                for inner_key, inner in (sub["fields"].items() if sub["type"] == "repeater" else []):
                    inner_notes = inner["help"].replace("|", "/") if with_help else ""
                    lines.append("| &nbsp;&nbsp;&nbsp;&nbsp;`%s[].%s[].%s` | %s | %s |" % (key, sub_key, inner_key, describe(inner).replace("|", "/"), inner_notes))
    lines.append("")
    return "\n".join(lines)


COMMON = """## Fields every block has

| field | meaning |
|---|---|
| `type` | the block type (required) |
| `variant` | the layout; each block lists its own, the first is the default |
| `tone` | background: `default`, `muted`, `contrast`, `accent` (a block can have another default, see its entry) |
| `spacing` | space around it: `default`, `compact`, `spacious`, `none` |
| `width` | how much of the row it takes: `25`, `33`, `50`, `66`, `75` (a number; `100`, the whole width, is the default and is not written). Blocks under 100% that follow each other sit side by side and wrap when the row is full (`33 + 33 + 33`, `25 + 25 + 50`, `66 + 33`, `75 + 25`); a 100% block starts a new row. On a tablet 25 and 33 become 50, 66 and 75 become 100, on a phone every block is full width. The block that opens a page (a hero) is always full width |
| `anchor` | an id for in-page links (`services` makes `#services`) |
| `hidden` | `true` keeps the block in the file but not on the site |

Kinds of value: **link** is a page (`contact`, relative to the page's language), a path (`/en/contact`), a full URL, `#anchor`, `mailto:` or `tel:`. **image**, **video** and **file** are `/uploads/media/<id>.<ext>` (a library file) or a full https URL; no spaces, quotes or brackets. **icon** is a name from the icon set (`python3 scripts/site_info.py --icons`). **Markdown** fields take Markdown. A **choice** must be exactly one of the listed values. A **list of rows** (repeater) is a YAML list of maps; a row may hold a list of its own (`slides[].buttons[]`), never deeper. Values equal to the default can be left out. A value the CMS does not accept is replaced by the default without any message, so check with `validate_content.py`.

## Containers: rows with columns

A **container** is an entry of `blocks:` that is not a block: a section with one to six columns and blocks inside each column. Use it for what a block's `width` cannot do: a text beside a picture beside a form, a 25 / 50 / 25 row, a band with a picture behind its columns. **One level only**: a container inside a column is dropped. A page without containers is not affected.

```yaml
blocks:
  - type: container
    settings: {gap: l, justify: center}        # only what differs from the default
    columns:
      - settings: {width: 1/3, padding: m}     # 1/3 of the row
        blocks:
          - {type: text, heading: One}
      - settings: {width: 2/3}
        blocks:
          - {type: image, image: /uploads/media/<id>.jpg}
          - {type: cta, heading: Go on, actions: [{label: Contact, url: contact}]}
```

Container `settings` (all optional): `direction` (`row`, `row-reverse`), `justify` (`start center end between around evenly`), `align` (`stretch start center end baseline`), `align_content`, `wrap` (`wrap nowrap wrap-reverse`), `gap` and `row_gap` (`none xs s m l xl`, default `m`), `stack_desktop`, `stack_tablet`, `stack_phone` (how columns stack on that screen: `row`, `two` = wrap to 2, `one` = stack to 1; tablet default `auto`, phone default `one`), `reverse_phone` (`true` reverses the order when stacked), `tone` (`default muted contrast accent`), `image` + `overlay` (`none light dark`), `inner` (`container narrow full`), `spacing`, `min_height` (`none s m l screen`), `anchor`, `class`, `hidden`.

Column `settings` (all optional): `width` (`fill` = an equal share, the default; `auto`; a fraction like `1/3` or `2/3`; a percentage like `37%`; a fixed size like `20rem` or `320px`), `min_width`, `max_width`, `grow`, `shrink`, `basis` (set any of these and the column is `flex: grow shrink basis`, replacing `width`), `align_self` (`auto start center end stretch baseline`), `valign` (`top center bottom`), `order` (-6 to 6), `padding` (`none xs s m l xl`), `tone`, `radius` (`none s m l`). Gaps and paddings are the theme's spacing steps, never free sizes.

A block inside a column takes the room of the column: its own `width` is ignored there (and not shown in the editor). A `content` block inside a column places the page text. Presets in the editor: 50/50 (`1/2 1/2`), 33/67 (`1/3 2/3`), 67/33, 3×33 (`1/3 1/3 1/3`), 25/50/25 (`1/4 1/2 1/4`), 4×25. `validate_content.py` checks containers (settings, one to six columns, the blocks inside).
"""


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("blocks", nargs="*", help="block types to show (default: all)")
    parser.add_argument("--root", help="the installation folder (default: found from the current folder)")
    parser.add_argument("--list", action="store_true", help="one line per block: type, layouts, what it is for")
    parser.add_argument("--json", action="store_true", help="the definitions as JSON")
    parser.add_argument("--generic", action="store_true", help="do not list the content types and forms this particular site has (for the shipped reference)")
    parser.add_argument("--no-help", action="store_true", help="leave out the help texts of the fields")
    args = parser.parse_args()

    global GENERIC
    GENERIC = args.generic
    root = f.find_root(args.root, allow_snapshot=True)
    blocks = f.load_blocks(root)
    chosen = [blocks[b] for b in (args.blocks or blocks) if b in blocks]
    missing = [b for b in args.blocks if b not in blocks]
    if missing:
        sys.stderr.write("No such block: %s. Known: %s\n" % (", ".join(missing), ", ".join(blocks)))
        return 1
    if args.json:
        f.out({b["type"]: {"label": b["label"], "variants": list(b["variants"]),
                           "fields": {k: {"type": v["type"], "default": v["default"], **({"options": list(v["options"])} if "options" in v else {})} for k, v in b["fields"].items()}} for b in chosen})
    elif args.list:
        for b in chosen:
            print("%-14s %-9s %s | layouts: %s" % (b["type"], b["category"] or "-", b["label"], ", ".join(b["variants"])))
    else:
        print("# Blocks of this installation (GnozioCMS %s)\n" % f.version(root))
        print(COMMON)
        for b in chosen:
            print(render(b, not args.no_help))
    return 0


if __name__ == "__main__":
    sys.exit(main())
