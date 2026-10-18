"""
Shared helpers for the GnozioCMS skill scripts (Python 3.8+, needs PyYAML).

Everything here mirrors what the CMS itself does, read from the installation's own files, so it stays right when
the installation is updated: block definitions come from themes/<theme>/blocks and custom/blocks, theme settings from
themes/<theme>/theme.yaml. When the CMS and this file disagree, the CMS is right: run `php scripts/check-blocks.php`
on a machine that has PHP, or look at the page in the admin.
"""
import json
import os
import re
import sqlite3
import sys

try:
    import yaml
except ImportError:  # pragma: no cover
    sys.stderr.write("PyYAML is needed: pip install pyyaml\n")
    raise SystemExit(2)

# ----------------------------------------------------------------------------------------------- the installation


SNAPSHOT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "snapshot")


def is_snapshot(root):
    return os.path.abspath(root) == SNAPSHOT


def find_root(start=None, allow_snapshot=False):
    """
    The installation folder: the one that holds VERSION and themes/ (looked for here and in the parents).
    `--root snapshot`, or no installation at all when allow_snapshot is true, gives the definitions bundled with the skill
    (the blocks, the theme settings and the ready-made layouts of one GnozioCMS version): enough to write and check content
    for a site you cannot see, not enough to know what that site has (its content types, forms, taxonomies, uploads).
    """
    if start == "snapshot":
        return SNAPSHOT
    path = os.path.abspath(start or os.getcwd())
    while True:
        if os.path.isfile(os.path.join(path, "VERSION")) and os.path.isdir(os.path.join(path, "themes")):
            return path
        parent = os.path.dirname(path)
        if parent == path:
            if allow_snapshot and not start:
                sys.stderr.write("note: no GnozioCMS installation here; using the definitions bundled with the skill (GnozioCMS %s). The site may have a different version or its own blocks.\n" % version(SNAPSHOT))
                return SNAPSHOT
            raise SystemExit("This is not a GnozioCMS installation (no VERSION and themes/ here or above). Pass --root PATH%s." % (", or --root snapshot to use the definitions bundled with the skill" if allow_snapshot else ""))
        path = parent


def read_text(path, default=""):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return handle.read()
    except OSError:
        return default


def load_yaml(path, default=None):
    try:
        data = yaml.safe_load(read_text(path))
    except yaml.YAMLError:
        return default
    return default if data is None else data


def db_path(root):
    return os.path.join(root, "storage", "db", "app.sqlite")


def meta_get(root, key):
    """A value of system_meta (the site settings and the theme settings live there), or None."""
    path = db_path(root)
    if not os.path.isfile(path):
        return None
    try:
        con = sqlite3.connect("file:" + path + "?mode=ro", uri=True)
        try:
            row = con.execute("SELECT value FROM system_meta WHERE key = ?", (key,)).fetchone()
        finally:
            con.close()
    except sqlite3.Error:
        return None
    return row[0] if row else None


def site_settings(root):
    """The site settings document (empty dict when the installation has none yet: the defaults apply)."""
    raw = meta_get(root, "site_settings")
    if raw is None:
        raw = read_text(os.path.join(root, "content", "settings", "site.yaml"))
    try:
        data = yaml.safe_load(raw) if raw else {}
    except yaml.YAMLError:
        data = {}
    return data if isinstance(data, dict) else {}


def languages(root):
    """(default language, [available languages]) as the site has them; el and en are the CMS defaults."""
    langs = site_settings(root).get("languages") or {}
    default = str(langs.get("default") or "el")
    available = [str(x) for x in (langs.get("available") or ["el", "en"])]
    if default not in available:
        available.insert(0, default)
    return default, available


def home_slug(root):
    return str(site_settings(root).get("home_page") or "index")


def version(root):
    return read_text(os.path.join(root, "VERSION")).strip()


# ----------------------------------------------------------------------------------------------- addresses (Slug.php)

_GREEK = {
    "α": "a", "β": "v", "γ": "g", "δ": "d", "ε": "e", "ζ": "z", "η": "i", "θ": "th", "ι": "i", "κ": "k", "λ": "l",
    "μ": "m", "ν": "n", "ξ": "x", "ο": "o", "π": "p", "ρ": "r", "σ": "s", "τ": "t", "υ": "y", "φ": "f", "χ": "ch",
    "ψ": "ps", "ω": "o", "ϊ": "i", "ϋ": "y",
}
_LATIN = {
    "à": "a", "á": "a", "â": "a", "ã": "a", "ä": "a", "å": "a", "æ": "ae", "ç": "c", "è": "e", "é": "e", "ê": "e",
    "ë": "e", "ì": "i", "í": "i", "î": "i", "ï": "i", "ñ": "n", "ò": "o", "ó": "o", "ô": "o", "õ": "o", "ö": "o",
    "ø": "o", "œ": "oe", "ù": "u", "ú": "u", "û": "u", "ü": "u", "ý": "y", "ÿ": "y", "ß": "ss", "š": "s", "ž": "z",
    "č": "c", "ł": "l", "ğ": "g", "ş": "s", "ı": "i",
}
_STRESS = {"ά": "α", "έ": "ε", "ή": "η", "ί": "ι", "ό": "ο", "ύ": "υ", "ώ": "ω", "ς": "σ", "ΐ": "ϊ", "ΰ": "ϋ"}
ROOT_RESERVED = {"admin", "assets", "uploads", "custom", "pages", "search", "tag", "tags", "category", "categories",
                 "sitemap", "sitemap-xml", "robots", "robots-txt", "favicon", "api", "storage", "themes", "vendor"}
MAX_SLUG = 80


def slugify(text):
    """The address GnozioCMS makes from a title (Slug::fromText): Greek to Latin by ELOT 743, accents dropped."""
    text = text.strip().lower()
    text = "".join(_STRESS.get(c, c) for c in text)
    text = "".join(_LATIN.get(c, c) for c in text)
    chars = list(text)
    out = ""
    i = 0
    n = len(chars)
    while i < n:
        c = chars[i]
        nxt = chars[i + 1] if i + 1 < n else ""
        pair = c + nxt
        start = i == 0 or chars[i - 1] not in _GREEK
        third = chars[i + 2] if i + 2 < n else ""
        voiceless = nxt == "" or third == "" or third in ("κ", "ξ", "π", "σ", "τ", "φ", "χ", "ψ") or third not in _GREEK
        digraph = {
            "ου": "ou", "ει": "ei", "οι": "oi", "αι": "ai", "υι": "yi",
            "αυ": "af" if voiceless else "av", "ευ": "ef" if voiceless else "ev", "ηυ": "if" if voiceless else "iv",
            "μπ": "b" if start else "mp", "ντ": "d" if start else "nt", "γκ": "g" if start else "gk",
            "γγ": "ng", "γξ": "nx", "γχ": "nch",
        }.get(pair)
        if digraph is not None:
            out += digraph
            i += 2
            continue
        out += _GREEK.get(c, c)
        i += 1
    out = re.sub(r"[^a-z0-9\-_]+", "-", out)
    out = re.sub(r"-{2,}", "-", out).strip("-_")
    if len(out) > MAX_SLUG:
        cut = out[:MAX_SLUG]
        dash = cut.rfind("-")
        out = (cut[:dash] if dash > 20 else cut).rstrip("-_")
    return out


def filename(slug, lang, default_lang):
    """about.md in the default language, about.en.md in another (ContentPaths::filename)."""
    return slug + ".md" if lang in ("", default_lang) else "%s.%s.md" % (slug, lang)


def public_path(ctype, slug, lang, default_lang, home="index"):
    """The address of an entry, without the leading slash ("" is the home page)."""
    prefix = "" if lang == default_lang else lang + "/"
    if ctype == "pages" and slug == home:
        return prefix.rstrip("/")
    if ctype in ("pages", "posts"):
        return prefix + slug
    return "%s%s/%s" % (prefix, ctype, slug)


# ----------------------------------------------------------------------------------------------- content files

FRONT_MATTER = re.compile(r"\A---[ \t]*\r?\n(.*?)\r?\n---[ \t]*\r?\n(.*)\Z", re.S)
LANG_SUFFIX = re.compile(r"^(.*)\.([a-z]{2})$")


def split_name(path):
    """('about', 'en') or ('about', None) from .../about.en.md: the CMS reads only two-letter language codes."""
    base = os.path.basename(path)[: -len(".md")] if path.endswith(".md") else os.path.basename(path)
    match = LANG_SUFFIX.match(base)
    return (match.group(1), match.group(2)) if match else (base, None)


def parse_content(text):
    """(meta dict, body, error). A file the CMS cannot read as YAML is treated as an unpublished draft by the CMS."""
    match = FRONT_MATTER.match(text)
    if not match:
        return {}, text, None
    try:
        meta = yaml.safe_load(match.group(1)) or {}
    except yaml.YAMLError as error:
        return {}, match.group(2), str(error).splitlines()[0]
    if not isinstance(meta, dict):
        return {}, match.group(2), "the front matter is not a list of fields"
    return meta, match.group(2), None


class _Dumper(yaml.SafeDumper):
    def increase_indent(self, flow=False, indentless=False):  # lists are indented under their key, like the CMS writes them
        return super().increase_indent(flow, False)


def _str_presenter(dumper, data):
    if "\n" in data:
        return dumper.represent_scalar("tag:yaml.org,2002:str", data, style="|")
    return dumper.represent_scalar("tag:yaml.org,2002:str", data)


_Dumper.add_representer(str, _str_presenter)


def dump_content(meta, body):
    """A content file: front matter, a blank line, the Markdown body, one final newline."""
    front = yaml.dump(meta, Dumper=_Dumper, allow_unicode=True, sort_keys=False, default_flow_style=False, width=120).rstrip()
    return "---\n%s\n---\n\n%s\n" % (front, body.strip("\n"))


def new_translation_id():
    return os.urandom(8).hex()


def content_types(root):
    """The content type folders in content/ (everything that is not one of the CMS's own folders)."""
    skip = {"settings", "users", "media", "menus", "taxonomies", "forms-submissions"}
    base = os.path.join(root, "content")
    if not os.path.isdir(base):
        return []
    return sorted(d for d in os.listdir(base) if os.path.isdir(os.path.join(base, d)) and not d.startswith(".") and d not in skip)


def list_entries(root, ctype):
    """[(slug, lang or None, path)] of the Markdown files of one type."""
    base = os.path.join(root, "content", ctype)
    entries = []
    if os.path.isdir(base):
        for name in sorted(os.listdir(base)):
            if name.endswith(".md") and not name.startswith("."):
                slug, lang = split_name(name)
                entries.append((slug, lang, os.path.join(base, name)))
    return entries


# ----------------------------------------------------------------------------------------------- fields (FieldSchema.php)

TYPES = ["text", "textarea", "markdown", "email", "url", "link", "image", "video", "file", "location", "color", "number",
         "decimal", "date", "select", "icon", "toggle", "repeater"]
TONES = {"default": "Default", "muted": "Muted", "contrast": "Contrast", "accent": "Accent"}
WIDTHS = {"100": "100%", "75": "75%", "66": "66%", "50": "50%", "33": "33%", "25": "25%"}
SPACINGS = {"default": "Default", "compact": "Compact", "spacious": "Spacious", "none": "None"}
KEY = re.compile(r"^[a-z][a-z0-9_]*$")


def _options(raw):
    if not isinstance(raw, (dict, list)):
        return {}
    if isinstance(raw, list):
        return {str(v): str(v).replace("_", " ").replace("-", " ").capitalize() for v in raw if str(v) != ""}
    return {str(k): (v if isinstance(v, str) else str(v)) for k, v in raw.items()}


def _truthy(value):
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in ("1", "true", "yes", "on")


def _num(value):
    try:
        float(value)
        return True
    except (TypeError, ValueError):
        return False


def normalize_fields(defs, icon_names=None, sources=None):
    """FieldSchema::normalize, plus the icon set and the option sources (content_types, forms) that BlockRegistry fills in."""
    fields = {}
    for key, d in (defs or {}).items():
        key = str(key)
        if not KEY.match(key) or not isinstance(d, dict):
            continue
        ftype = str(d.get("type", "text")).lower()
        source = str(d.get("options_from", "") or "")
        if source and sources is None:
            d = {k: v for k, v in d.items() if k != "options"}
            d["type"] = "text"
            ftype = "text"
            source = ""
        elif source and source in sources:
            options = dict(sources[source])
            if isinstance(d.get("options"), dict):
                options = {**_options(d["options"]), **options}
            d = {**d, "options": options}
            ftype = "select"
        if ftype not in TYPES:
            ftype = "text"
        field = {
            "key": key, "type": ftype, "label": str(d.get("label") or key.replace("_", " ").capitalize()),
            "help": str(d.get("help") or ""), "required": d.get("required") is True, "hidden": d.get("hidden") is True,
            "translatable": ftype in ("text", "textarea") and d.get("translatable") is True,
            "when": d.get("when") if isinstance(d.get("when"), dict) else {},
            "source": source,
        }
        if ftype in ("select", "icon"):
            field["options"] = _options(d.get("options"))
            if ftype == "icon" and icon_names is not None:
                field["options"] = {"": "None", **{n: n for n in icon_names}}
        if ftype == "decimal":
            field["min"] = float(d["min"]) if _num(d.get("min")) else None
            field["max"] = float(d["max"]) if _num(d.get("max")) else None
        if ftype in ("number", "repeater"):
            field["min"] = int(float(d["min"])) if _num(d.get("min")) else None
            field["max"] = int(float(d["max"])) if _num(d.get("max")) else None
        if ftype == "number":
            field["blank"] = d.get("blank") is True
        if ftype == "color":
            field["hex"] = d.get("hex") is True
        if ftype == "file":
            field["kind"] = "track" if d.get("kind") == "track" else "all"
        if ftype == "repeater":
            subs = normalize_fields(d.get("fields") if isinstance(d.get("fields"), dict) else {}, icon_names, sources)
            field["fields"] = {k: v for k, v in subs.items() if v["type"] != "repeater"}
            field["item_label"] = str(d.get("item_label") or "Item")
        field["default"] = clean(field, d.get("default"), True)
        fields[key] = field
    return fields


_COLOR = re.compile(r"^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s/deg]+\)|var\(--[a-z0-9-]+\)|[a-z]{3,30})$", re.I)


def safe_url(value):
    if re.search(r"""[\s"'()<>\\]""", value):
        return False
    if re.match(r"^[a-z][a-z0-9+.-]*:", value, re.I):
        return bool(re.match(r"^https?://", value, re.I))
    return True


def safe_link(value):
    if re.search(r"""[\s"'<>\\]""", value):
        return False
    if re.match(r"^[a-z][a-z0-9+.-]*:", value, re.I):
        return bool(re.match(r"^(https?://|mailto:|tel:)", value, re.I))
    return True


def normalize_hex(value):
    value = value.strip().lstrip("#")
    if re.match(r"^[0-9a-f]{3}$", value, re.I):
        value = "".join(c * 2 for c in value)
    return "#" + value.lower() if re.match(r"^[0-9a-f]{6}$", value, re.I) else None


def _empty(field):
    t = field["type"]
    if t == "toggle":
        return False
    if t == "number":
        return "" if field.get("blank") else (field.get("min") or 0)
    if t == "repeater":
        return []
    return ""


def clean(field, value, is_default=False):
    """What the CMS makes of a stored value: itself when valid, else the field's default (FieldSchema::clean)."""
    t = field["type"]
    if is_default:
        if value is None and t in ("select", "icon"):
            return next(iter(field["options"]), "")
        value = _sanitize(field, value, _empty(field))
        if t in ("select", "icon") and value not in field["options"] and not (t == "icon" and not field["options"] and value != ""):
            return next(iter(field["options"]), "")
        return value
    return _sanitize(field, value, field["default"])


def _scalar(value):
    return isinstance(value, (str, int, float)) and not isinstance(value, bool) or isinstance(value, bool)


def _sanitize(field, value, fallback):
    if value is None:
        return fallback
    t = field["type"]
    if field.get("translatable") and (isinstance(value, dict) or (isinstance(fallback, dict) and _scalar(value))):
        return _translations(field, value, fallback)
    if t == "toggle":
        return value if isinstance(value, bool) else _truthy(value)
    if t == "number":
        if field.get("blank") and isinstance(value, str) and value.strip() == "":
            return ""
        if isinstance(value, bool) or not _num(value):
            return fallback
        number = int(float(value))
        if field.get("min") is not None:
            number = max(field["min"], number)
        if field.get("max") is not None:
            number = min(field["max"], number)
        return number
    if t == "decimal":
        if value == "" or isinstance(value, bool) or not _num(value):
            return "" if value == "" else fallback
        decimal = round(float(value), 7)
        if field.get("min") is not None and decimal < field["min"]:
            return fallback
        if field.get("max") is not None and decimal > field["max"]:
            return fallback
        return decimal
    if t == "date":
        text = str(value).strip() if _scalar(value) else ""
        if text == "":
            return ""
        return text if re.match(r"^\d{4}-\d{2}-\d{2}$", text) and _valid_date(text) else fallback
    if t == "icon":
        text = str(value) if _scalar(value) else ""
        if len(field["options"]) <= 1 and re.match(r"^[a-z0-9-]{0,40}$", text):
            return text
        return text if text in field["options"] else fallback
    if t == "select":
        text = ("true" if value is True else "false" if value is False else str(value)) if _scalar(value) else ""
        # YAML turns a bare yes/no/on/off or 2 into a bool or an int; the CMS reads those as the strings they were typed as.
        return text if text in field["options"] else fallback
    if t == "color":
        text = str(value).strip() if _scalar(value) else ""
        if field.get("hex") and text != "":
            return normalize_hex(text) or fallback
        return text if text == "" or _COLOR.match(text) else fallback
    if t in ("image", "video", "file", "url"):
        text = str(value).strip() if _scalar(value) else ""
        return text if text == "" or safe_url(text) else fallback
    if t == "link":
        text = str(value).strip() if _scalar(value) else ""
        return text if text == "" or safe_link(text) else fallback
    if t == "location":
        text = str(value).strip() if _scalar(value) else ""
        if text == "":
            return ""
        match = re.match(r"^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$", text)
        if match and abs(float(match.group(1))) <= 90 and abs(float(match.group(2))) <= 180:
            return "%s, %s" % (match.group(1), match.group(2))
        return fallback
    if t == "repeater":
        if value == "":
            return []
        if not isinstance(value, list):
            return fallback
        items = []
        for item in value:
            if isinstance(item, dict):
                kept = {k: v for k, v in item.items() if k in field["fields"]}
                if any(not (v == "" or v is None or v is False or v == []) for v in kept.values()):
                    items.append({k: clean(field["fields"][k], kept.get(k)) for k in field["fields"]})
        return items[: max(0, field["max"])] if field.get("max") is not None else items
    if t in ("markdown", "textarea"):
        if not _scalar(value):
            return fallback
        return str(value).replace("\r\n", "\n").replace("\r", "\n").strip()[:10000]
    if not _scalar(value):
        return fallback
    return re.sub(r"[\x00-\x1f\x7f]+", " ", str(value)).strip()[:2000]


def _valid_date(text):
    import datetime
    try:
        datetime.date(int(text[:4]), int(text[5:7]), int(text[8:10]))
        return True
    except ValueError:
        return False


def _translations(field, value, fallback):
    plain = {k: v for k, v in field.items() if k != "translatable"}
    plain["translatable"] = False
    if not isinstance(value, dict):
        value = {"default": value, **{k: v for k, v in (fallback or {}).items() if k != "default"}} if isinstance(fallback, dict) else {"default": value}
    texts = {}
    for lang, text in value.items():
        lang = str(lang)
        if lang != "default" and not re.match(r"^[a-z]{2,3}(-[a-z0-9]{2,8})?$", lang, re.I):
            continue
        cleaned = _sanitize(plain, text if _scalar(text) else "", "")
        if isinstance(cleaned, str) and cleaned != "":
            texts[lang] = cleaned
    if not texts:
        return ""
    return texts["default"] if list(texts) == ["default"] else texts


# ----------------------------------------------------------------------------------------------- blocks


def icon_names(root):
    names = set()
    for base in (os.path.join(root, "themes", "default", "icons"), os.path.join(root, "custom", "icons")):
        if os.path.isdir(base):
            names.update(f[:-4] for f in os.listdir(base) if f.endswith(".svg"))
    return sorted(names)


def option_sources(root):
    """The choices that block fields get from the site itself (options_from)."""
    types = [t for t in content_types(root) if t not in ("pages", "forms")]
    forms = {"": "—"}
    for slug, lang, path in list_entries(root, "forms"):
        if lang is None or lang == languages(root)[0]:
            meta, _body, _err = parse_content(read_text(path))
            forms[slug] = str(meta.get("title") or slug)
    places = [t for t in types if t in ("points", "routes", "businesses")]
    place_options = {t: t for t in places}
    if len(place_options) > 1:
        place_options = {"all": "Everything with a place", **place_options}
    return {"content_types": {t: t for t in types}, "forms": forms, "place_types": place_options}


def load_blocks(root):
    """{type: definition} from the theme and custom/blocks (a theme block cannot be replaced from custom/)."""
    icons = icon_names(root)
    sources = option_sources(root)
    if is_snapshot(root):
        sources = None  # the site's content types and forms are unknown: those fields are free text here
    blocks = {}
    for origin, base in (("theme", os.path.join(root, "themes", "default", "blocks")), ("custom", os.path.join(root, "custom", "blocks"))):
        if not os.path.isdir(base):
            continue
        for type_ in sorted(os.listdir(base)):
            file = os.path.join(base, type_, "block.yaml")
            if type_ in blocks or not re.match(r"^[a-z][a-z0-9-]*$", type_) or not os.path.isfile(file):
                continue
            raw = load_yaml(file, None)
            if not isinstance(raw, dict):
                continue
            variants = {str(k): str(v) for k, v in (raw.get("variants") or {}).items() if re.match(r"^[a-z0-9-]+$", str(k))} or {"default": "Default"}
            tone = str(raw.get("tone") or "default")
            spacing = str(raw.get("spacing") or "default")
            fields = normalize_fields(raw.get("fields") or {}, icons, sources)
            common = normalize_fields({
                "variant": {"type": "select", "options": variants, "default": next(iter(variants))},
                "tone": {"type": "select", "options": TONES, "default": tone if tone in TONES else "default"},
                "spacing": {"type": "select", "options": SPACINGS, "default": spacing if spacing in SPACINGS else "default"},
                "width": {"type": "select", "options": WIDTHS, "default": "100"},
                "anchor": {"type": "text"},
                "hidden": {"type": "toggle"},
            })
            blocks[type_] = {
                "type": type_, "origin": origin, "label": str(raw.get("label") or type_.capitalize()),
                "description": str(raw.get("description") or ""), "category": str(raw.get("category") or ""),
                "variants": variants, "fields": fields, "common": common,
                "variant_when": raw.get("variant_when") or {},
                "width_moved_to": str(raw.get("width_moved_to") or "") if str(raw.get("width_moved_to") or "") in fields else "",
            }
    return blocks


_SPACES = ["none", "xs", "s", "m", "l", "xl"]
_ALIGNS = ["stretch", "start", "center", "end", "baseline"]
_JUSTIFY = ["start", "center", "end", "between", "around", "evenly"]
# The settings of a container (rows with columns) and of one column, as the CMS checks them (src/BlockContainer.php).
CONTAINER_SETTINGS = {
    "direction": ["row", "row-reverse"], "justify": _JUSTIFY, "align": _ALIGNS, "align_content": ["stretch"] + _JUSTIFY, "wrap": ["wrap", "nowrap", "wrap-reverse"],
    "gap": _SPACES, "row_gap": _SPACES, "stack_desktop": ["row", "two", "one"], "stack_tablet": ["auto", "row", "two", "one"], "stack_phone": ["row", "two", "one"],
    "reverse_phone": bool, "tone": list(TONES), "image": str, "overlay": ["none", "light", "dark"], "inner": ["container", "narrow", "full"],
    "spacing": list(SPACINGS), "min_height": ["none", "s", "m", "l", "screen"], "anchor": str, "class": str, "hidden": bool,
}
CONTAINER_COLUMN = {
    "width": "width", "min_width": "length", "max_width": "length", "basis": "basis", "grow": (0, 12), "shrink": (0, 12), "order": (-6, 6),
    "align_self": ["auto"] + _ALIGNS, "valign": ["top", "center", "bottom"], "padding": _SPACES, "tone": list(TONES), "radius": ["none", "s", "m", "l"],
}


def _container_value_problem(spec, key, value):
    """Why the CMS would change a container setting, or '' when it keeps it as written."""
    if spec is bool:
        return "" if isinstance(value, bool) else "must be true or false"
    if spec is str:
        return "" if isinstance(value, str) else "must be text"
    if isinstance(spec, list):
        return "" if str(value) in spec else "must be one of %s" % ", ".join(spec)
    if isinstance(spec, tuple):
        return "" if isinstance(value, int) and not isinstance(value, bool) and spec[0] <= value <= spec[1] else "must be a whole number from %d to %d" % spec
    text = str(value).strip().lower()
    length = re.fullmatch(r"(\d{1,4}(?:\.\d{1,2})?)(px|rem|em|ch|%)", text)
    ok_length = bool(length) and float(length.group(1)) > 0 and (length.group(2) != "%" or float(length.group(1)) <= 100)
    if spec == "width":
        ok = text in ("fill", "auto") or ok_length or bool(re.fullmatch(r"([1-9])/([2-9])", text) and int(text[0]) < int(text[2]))
        return "" if ok and text == str(value) else "must be fill, auto, a fraction (1/3), a percentage (37%) or a size (20rem)"
    if spec == "basis":
        return "" if (text == "auto" or ok_length) and text == str(value) else "must be auto, a size (20rem) or a percentage"
    return "" if ok_length and text == str(value) else "must be a size (12rem) or a percentage"


def check_container(blocks, block, where):
    """Problems of a container: its settings, its columns (one to six), and the blocks inside (no container among them)."""
    problems = []
    for key in block:
        if key not in ("type", "settings", "columns"):
            problems.append("%s (container): unknown key `%s` (the CMS drops it)" % (where, key))
    settings = block.get("settings", {})
    if not isinstance(settings, dict):
        problems.append("%s (container): `settings` must be a map" % where)
        settings = {}
    for key, value in settings.items():
        if key not in CONTAINER_SETTINGS:
            problems.append("%s (container): unknown setting `%s` (the CMS ignores it)" % (where, key))
        else:
            why = _container_value_problem(CONTAINER_SETTINGS[key], key, value)
            if why:
                problems.append("%s (container.%s): %s" % (where, key, why))
    columns = block.get("columns")
    if not isinstance(columns, list) or not 1 <= len(columns) <= 6:
        problems.append("%s (container): `columns` must be a list of 1 to 6 columns" % where)
        return problems
    for c, column in enumerate(columns):
        here = "%s.columns[%d]" % (where, c)
        if not isinstance(column, dict):
            problems.append("%s: a column must be a map with `settings` and `blocks`" % here)
            continue
        for key in column:
            if key not in ("settings", "blocks"):
                problems.append("%s: unknown key `%s` (the CMS drops it)" % (here, key))
        own = column.get("settings", {})
        for key, value in (own.items() if isinstance(own, dict) else []):
            if key not in CONTAINER_COLUMN:
                problems.append("%s (column settings): unknown setting `%s` (the CMS ignores it)" % (here, key))
            else:
                why = _container_value_problem(CONTAINER_COLUMN[key], key, value)
                if why:
                    problems.append("%s (column.%s): %s" % (here, key, why))
        inside = column.get("blocks", [])
        if not isinstance(inside, list):
            problems.append("%s: `blocks` must be a list" % here)
            continue
        for k, inner in enumerate(inside):
            if isinstance(inner, dict) and inner.get("type") == "container":
                problems.append("%s.blocks[%d]: a container cannot be inside a column (one level only; the CMS drops it)" % (here, k))
            else:
                problems.extend(check_block(blocks, inner, "%s.blocks[%d]" % (here, k)))
    return problems


def check_block(blocks, block, where):
    """Problems of one block of a page, as a list of strings (empty means the CMS would keep every value as written)."""
    problems = []
    if not isinstance(block, dict):
        return ["%s: a block must be a map with a `type`" % where]
    type_ = block.get("type")
    if type_ == "container":
        return check_container(blocks, block, where)
    definition = blocks.get(type_) if isinstance(type_, str) else None
    if definition is None:
        return ["%s: unknown block type %r (the CMS skips it)" % (where, type_)]
    fields = {**definition["common"], **definition["fields"]}
    moved = definition.get("width_moved_to") or ""
    original_width = block.get("width")
    if moved and "width" in block and str(block["width"]) not in WIDTHS:
        # the block's own old `width` (the divider's line) is read as its new field, as the CMS does
        block = {k: v for k, v in block.items() if k != "width"}
        block.setdefault(moved, original_width)
    for key, value in block.items():
        if key == "type":
            continue
        if key not in fields:
            problems.append("%s (%s): unknown field `%s` (the CMS ignores it)" % (where, type_, key))
            continue
        field = fields[key]
        cleaned = clean(field, value)
        if field["type"] == "repeater":
            if not isinstance(value, list):
                problems.append("%s (%s.%s): must be a list of rows" % (where, type_, key))
                continue
            if field.get("max") is not None and len(value) > field["max"]:
                problems.append("%s (%s.%s): %d rows, only the first %d are kept" % (where, type_, key, len(value), field["max"]))
            for index, row in enumerate(value):
                if not isinstance(row, dict):
                    problems.append("%s (%s.%s[%d]): a row must be a map" % (where, type_, key, index))
                    continue
                for sub, subvalue in row.items():
                    if sub not in field["fields"]:
                        problems.append("%s (%s.%s[%d]): unknown field `%s` (the CMS drops it)" % (where, type_, key, index, sub))
                        continue
                    if not _same(clean(field["fields"][sub], subvalue), subvalue):
                        problems.append("%s (%s.%s[%d].%s): %s" % (where, type_, key, index, sub, _why(field["fields"][sub], subvalue)))
        elif not _same(cleaned, value):
            problems.append("%s (%s.%s): %s" % (where, type_, key, _why(field, value)))
    for key, field in definition["fields"].items():
        if field["required"] and not block.get(key) and block.get("hidden") is not True:
            problems.append("%s (%s): `%s` is required (the editor marks it, the page looks empty without it)" % (where, type_, key))
    return problems


def _same(cleaned, value):
    if cleaned == value:
        return True
    if isinstance(value, str) and cleaned == value.strip():
        return True
    if isinstance(value, str) and isinstance(cleaned, str) and cleaned == value.replace("\r\n", "\n").strip():
        return True
    # a number typed as 3 for a select whose option is "3", or an unquoted yes/no
    if isinstance(cleaned, str) and str(value) == cleaned:
        return True
    if isinstance(value, bool) and isinstance(cleaned, str) and cleaned == str(value).lower():
        return True
    return False


def _why(field, value):
    t = field["type"]
    if t in ("select", "icon"):
        options = list(field["options"])
        shown = ", ".join(options[:12]) + (" …" if len(options) > 12 else "")
        return "%r is not one of: %s (the CMS uses the default %r)" % (value, shown, field["default"])
    if t == "toggle":
        return "must be true or false"
    if t == "link":
        return "%r is not a safe link (a path, a full http(s) address, #anchor, mailto: or tel:, no spaces or quotes)" % (value,)
    if t in ("image", "video", "file", "url"):
        return "%r is not a safe address (http(s) or a /path, no spaces, quotes or brackets)" % (value,)
    if t == "number":
        return "%r is not a whole number in the allowed range (%s to %s)" % (value, field.get("min"), field.get("max"))
    if t == "date":
        return "%r is not a date written YYYY-MM-DD" % (value,)
    if t == "color":
        return "%r is not a colour (hex, rgb(), hsl(), a name or var(--token))" % (value,)
    if t == "location":
        return "%r is not a position like 35.2012, 26.2744" % (value,)
    return "%r is changed by the CMS to %r (too long, or the wrong kind of value)" % (value, clean(field, value))


# ----------------------------------------------------------------------------------------------- output


def out(obj):
    print(json.dumps(obj, ensure_ascii=False, indent=2))
