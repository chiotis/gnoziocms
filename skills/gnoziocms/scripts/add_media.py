#!/usr/bin/env python3
"""
Adds a picture or a file to the site's media library the way an upload does: the file goes to public/uploads/media/<id>.<ext>
and its note to content/media/<id>.yaml. Prints the address to use in content (`/uploads/media/<id>.<ext>`).

    python3 add_media.py photo.jpg --alt "Η ομάδα στο γραφείο" --tags team
    python3 add_media.py https://example.com/logo.png --alt "Logo"
    python3 add_media.py *.jpg --alt-from-name

The id is made from the file's content, so adding the same file twice finds the first copy instead of making a second one.
SVG drawings are not added here (the admin checks them for scripts before keeping them): upload those in Admin > Media.
The upload size limit and the allowed kinds of file of the site (Settings > Limits) apply; by default 20 MB.
"""
import argparse
import datetime
import hashlib
import mimetypes
import os
import re
import shutil
import sys
import tempfile
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gnozio_lib as f  # noqa: E402

GROUPS = {
    "images": ["jpg", "jpeg", "png", "gif", "webp", "avif", "bmp", "ico"],
    "documents": ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "odt", "ods", "odp", "rtf", "txt", "csv", "md"],
    "archives": ["zip"], "audio": ["mp3", "wav", "ogg", "m4a"], "video": ["mp4", "webm", "mov"],
}
IMAGES = GROUPS["images"]
SIGNATURES = {
    "png": [b"\x89PNG"], "jpg": [b"\xff\xd8\xff"], "jpeg": [b"\xff\xd8\xff"], "gif": [b"GIF8"], "bmp": [b"BM"],
    "webp": [b"RIFF"], "avif": [b"\x00\x00\x00"], "ico": [b"\x00\x00\x01\x00"],
}


def kind_for(mime, ext):
    if ext in ("gpx", "kml", "geojson"):
        return "track"
    if mime.startswith("image/"):
        return "image"
    if mime.startswith("video/"):
        return "video"
    if mime.startswith("audio/"):
        return "audio"
    if ext == "zip":
        return "archive"
    if mime.startswith("text/") or ext in GROUPS["documents"]:
        return "document"
    return "other"


def fetch(source):
    """(local path, original name) of a file or an http(s) address (downloaded to a temporary file)."""
    if re.match(r"^https?://", source, re.I):
        name = os.path.basename(urllib.parse.urlparse(source).path) or "download"
        request = urllib.request.Request(source, headers={"User-Agent": "gnoziocms-skill/1"})
        handle, tmp = tempfile.mkstemp(suffix="-" + name)
        with os.fdopen(handle, "wb") as out, urllib.request.urlopen(request, timeout=30) as response:
            shutil.copyfileobj(response, out)
        return tmp, urllib.parse.unquote(name)
    return source, os.path.basename(source)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("sources", nargs="+", help="file paths or http(s) addresses")
    parser.add_argument("--root")
    parser.add_argument("--alt", default="", help="what the picture shows, for people who cannot see it (used wherever it is placed)")
    parser.add_argument("--alt-from-name", action="store_true", help="use the file name as the alt text (better than none, worse than a description)")
    parser.add_argument("--tags", default="", help="comma separated tags")
    parser.add_argument("--max-mb", type=float, default=0, help="size limit (default: the site's limit, else 20)")
    args = parser.parse_args()

    root = f.find_root(args.root)
    settings = f.site_settings(root)
    limit_mb = args.max_mb or float((settings.get("limits") or {}).get("upload_mb") or 20)
    uploads = os.path.join(root, "public", "uploads", "media")
    meta_dir = os.path.join(root, "content", "media")
    os.makedirs(uploads, exist_ok=True)
    os.makedirs(meta_dir, exist_ok=True)
    status = 0
    for source in args.sources:
        try:
            path, name = fetch(source)
        except Exception as error:  # noqa: BLE001 - say why and go on with the next file
            print("SKIP  %s: %s" % (source, error))
            status = 1
            continue
        ext = os.path.splitext(name)[1].lower().lstrip(".")
        ext = re.sub(r"[^a-z0-9]", "", ext)
        allowed = [e for g in GROUPS.values() for e in g]
        if ext == "svg":
            print("SKIP  %s: SVG drawings are checked for scripts by the admin; upload them in Admin > Media" % name)
            status = 1
            continue
        if ext not in allowed:
            print("SKIP  %s: .%s is not an allowed kind of file (%s)" % (name, ext, ", ".join(allowed)))
            status = 1
            continue
        with open(path, "rb") as handle:
            data = handle.read()
        if not data:
            print("SKIP  %s: the file is empty" % name)
            status = 1
            continue
        if len(data) > limit_mb * 1024 * 1024:
            print("SKIP  %s: %.1f MB is over the limit of %g MB" % (name, len(data) / 1048576, limit_mb))
            status = 1
            continue
        if ext in IMAGES and not any(data.startswith(sig) for sig in SIGNATURES.get(ext, [b""])):
            print("SKIP  %s: the content is not a valid .%s image" % (name, ext))
            status = 1
            continue
        media_id = hashlib.sha256(data).hexdigest()[:16]
        stored = "%s.%s" % (media_id, ext)
        note = os.path.join(meta_dir, media_id + ".yaml")
        address = "/uploads/media/" + stored
        if os.path.isfile(note):
            print("%s  (already in the library)  %s" % (address, name))
            continue
        with open(os.path.join(uploads, stored), "wb") as handle:
            handle.write(data)
        mime = mimetypes.guess_type(stored)[0] or "application/octet-stream"
        now = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")
        alt = args.alt or (re.sub(r"[-_]+", " ", os.path.splitext(name)[0]).strip() if args.alt_from_name else "")
        record = {
            "id": media_id, "path": "media/" + stored, "stored_name": stored, "original_name": name, "extension": ext,
            "mime_type": mime, "kind": kind_for(mime, ext), "size_bytes": len(data),
            "tags": ",".join(t.strip().lower().replace(" ", "-") for t in args.tags.split(",") if t.strip()),
            "uploaded_by": "", "created_at": now, "updated_at": now,
        }
        if alt:
            record["alt"] = re.sub(r"\s+", " ", alt)[:300]
        with open(note, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(f.yaml.safe_dump(record, allow_unicode=True, sort_keys=False))
        print("%s  %s  (%s, %.0f KB)" % (address, name, record["kind"], len(data) / 1024))
        if path != source and os.path.exists(path):
            os.remove(path)
    return status


if __name__ == "__main__":
    sys.exit(main())
