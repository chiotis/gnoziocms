#!/usr/bin/env python3
"""
Maintainer tool: refreshes what the skill carries of one GnozioCMS version, from a checkout or an installation of that version.

    python3 skills/gnoziocms/scripts/refresh_snapshot.py --root /path/to/gnoziocms

It rewrites
  snapshot/             VERSION, themes/default/theme.yaml, every blocks/*/block.yaml, every presets/*.yaml and the names of the icons
                        (empty placeholder files): the definitions the scripts use when there is no installation to read
  references/blocks.md and references/theme-settings.md       generated from them

Run it whenever a block, a preset or the theme settings change; tests/unit/skill.php fails until it has been run.
"""
import argparse
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SKILL = os.path.dirname(HERE)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root", required=True, help="a GnozioCMS checkout or installation")
    args = parser.parse_args()
    root = os.path.abspath(args.root)
    theme = os.path.join(root, "themes", "default")
    if not os.path.isfile(os.path.join(root, "VERSION")) or not os.path.isdir(theme):
        raise SystemExit("%s is not a GnozioCMS installation" % root)

    target = os.path.join(SKILL, "snapshot")
    shutil.rmtree(target, ignore_errors=True)
    os.makedirs(os.path.join(target, "themes", "default", "blocks"))
    shutil.copy(os.path.join(root, "VERSION"), os.path.join(target, "VERSION"))
    shutil.copy(os.path.join(theme, "theme.yaml"), os.path.join(target, "themes", "default", "theme.yaml"))
    for name in sorted(os.listdir(os.path.join(theme, "blocks"))):
        source = os.path.join(theme, "blocks", name, "block.yaml")
        if os.path.isfile(source):
            os.makedirs(os.path.join(target, "themes", "default", "blocks", name))
            shutil.copy(source, os.path.join(target, "themes", "default", "blocks", name, "block.yaml"))
    os.makedirs(os.path.join(target, "themes", "default", "presets"))
    for name in sorted(os.listdir(os.path.join(theme, "presets"))):
        if name.endswith(".yaml"):
            shutil.copy(os.path.join(theme, "presets", name), os.path.join(target, "themes", "default", "presets", name))
    os.makedirs(os.path.join(target, "themes", "default", "icons"))
    for name in sorted(os.listdir(os.path.join(theme, "icons"))):
        if name.endswith(".svg"):
            open(os.path.join(target, "themes", "default", "icons", name), "w").close()

    python = sys.executable
    for script, args_, out in (
        ("block_reference.py", ["--root", root, "--generic"], "blocks.md"),
        ("site_settings.py", ["--root", root, "schema", "theme", "--markdown"], "theme-settings.md"),
    ):
        # the options of a subcommand come after it; --root is a global option of site_settings.py
        result = subprocess.run([python, os.path.join(HERE, script)] + args_, capture_output=True, text=True)
        if result.returncode != 0:
            raise SystemExit(result.stderr or "%s failed" % script)
        with open(os.path.join(SKILL, "references", out), "w", encoding="utf-8", newline="\n") as handle:
            handle.write(result.stdout)
    print("Snapshot of GnozioCMS %s written; references/blocks.md and references/theme-settings.md regenerated." % open(os.path.join(root, "VERSION")).read().strip())
    return 0


if __name__ == "__main__":
    sys.exit(main())
