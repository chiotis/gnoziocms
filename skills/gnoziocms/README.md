# The GnozioCMS skill

A skill is a folder an AI assistant reads before it works: `SKILL.md` (what to do and when), `references/` (the details, read when needed) and `scripts/` (tools it can run). This one teaches an assistant (Claude, ChatGPT, or any agent that reads the same format) to work on **a website that runs GnozioCMS**: create pages with blocks, import content, change settings and the look, translate, build a whole site, and to do it without the silent mistakes the CMS would otherwise let through.

It does not need the CMS source and it carries no site data. What it knows of one GnozioCMS version (the blocks, the theme settings, the ready-made layouts) is in `snapshot/` and the generated `references/blocks.md` and `references/theme-settings.md`; when it can see the installation it reads the installation's own files instead, so it stays right after updates.

The product was previously named FarosCMS, and so was this skill (`faroscms`, with a `faros_lib.py` helper and a `faroscms-skill.zip` archive). It is now `gnoziocms`. If you installed the old one, remove it and install this one in its place, so the assistant does not see two skills for the same CMS. Requests that still say FarosCMS or Faros use this skill too.

## Install it

**Claude** (claude.ai or the desktop app): Settings > Capabilities > Skills > upload `gnoziocms-skill.zip` (see *Build the zip*). **Claude Code**: copy the `gnoziocms` folder to `~/.claude/skills/` (for you everywhere) or `.claude/skills/` in a project. **Codex**: copy the `gnoziocms` folder to `~/.codex/skills/` and start a new Codex session in the site's folder (it needs Python 3 with PyYAML: `pip3 install pyyaml`). In all of them, the skill is picked up when a request is about a GnozioCMS site; you can also name it ("use the gnoziocms skill").

**ChatGPT**: where your plan offers *Skills*, upload the same zip (the format is the same: a folder with `SKILL.md`). Where it does not, use a Project or a Custom GPT: paste the text of `SKILL.md` (without the first lines between `---`) into the instructions, upload everything in `references/` as knowledge files, and turn on the code tool and upload the `scripts/` folder and `snapshot/` too if you want the assistant to run the checks. The settings of ChatGPT change often: if a menu is not where this says, look for "skills", "instructions" and "knowledge files".

## Give the assistant a way to reach the site

The assistant can only do what it can reach. Three ways, from most to least capable:

1. **The site's files.** An agent with a shell on the server or on a copy of the site (Claude Code, Codex, a CI job), or a chat where you upload a zip of the site folder (without `vendor/`): it writes content files and runs the checks. Settings are stored in `storage/db/app.sqlite`, which the scripts edit safely. Work on a copy first, or make a backup (*Admin > Backups > Create full backup*) before big jobs.
2. **A browser signed in to the admin.** An assistant that drives a browser (ChatGPT agent, Claude in Chrome) works through the screens. **Sign in yourself** and hand over the session; do not paste your password into a chat. Use an editor account if you do not want it to touch users and settings.
3. **Only the chat.** It writes finished files (it knows the format) and exact instructions: which screen, which values. You copy the files into `content/` and `public/uploads/`, or upload the CSV it makes in *Admin > Content > type > Import*.

## What to ask

Say what the site is and what you want; it will find out the rest (`site_info.py`) and ask only what it cannot know. Examples:

- *"Φτιάξε τη σελίδα «Υπηρεσίες» στα ελληνικά και στα αγγλικά με hero, τρεις υπηρεσίες, FAQ και κουμπί επικοινωνίας. Τα κείμενα είναι στο συνημμένο."*
- *"Import these 40 articles (CSV attached) as posts in the News category, keep their dates, download the pictures into the library."*
- *"Change the palette to emerald, the header to the split layout, and put this logo (attached) in the header and the footer."*
- *"Here is the old site's address. Move its pages across and give me the list of redirects."* (a WordPress site has its own importer; the assistant will use it)
- *"Build a five-page site for a physiotherapy clinic in Heraklion, Greek and English, draft only. I will give you the facts as you ask."*

It creates pages as drafts unless you say publish, never makes up prices, testimonials or contact details, and does not touch passwords, users, backups or updates.

## What is inside

```text
SKILL.md                what to do first, the rules, the workflows
references/             content-format, blocks (generated), page-recipes, settings, theme-settings (generated), importing, admin-ui, troubleshooting
scripts/                site_info, block_reference, new_entry, validate_content, add_media, entries_to_csv, site_settings (Python 3.8+ and PyYAML)
snapshot/               the blocks, theme settings, ready-made layouts and icon names of one GnozioCMS version, for working without the site
```

## Build the zip

```bash
cd skills && python3 -m zipfile -c gnoziocms-skill.zip gnoziocms     # then upload gnoziocms-skill.zip
```

## Keep it true (maintainers)

The skill describes one version of the theme. When a block, a preset or a theme setting changes in this repository, run

```bash
python3 skills/gnoziocms/scripts/refresh_snapshot.py --root .
```

and commit the result. `tests/unit/skill.php` fails until you do, and it also runs the scripts against the demo site so the skill cannot drift from the CMS. When the content format itself changes (front matter keys, addresses, menus, forms, media), edit `references/content-format.md` by hand.
