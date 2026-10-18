# Building pages with blocks

A page is its front matter `blocks:` list (and optionally a Markdown body). The editor never hand-writes layout: each block brings its own markup, spacing and responsive behaviour, and the **layout** (`variant`) and **background** (`tone`) change how it looks. Blocks can sit side by side: `width: 25|33|50|66|75` on consecutive blocks makes a row (three `text` blocks at `width: 33`, `33`, `33`); leave it out for a full-width block. Your job is to choose blocks, write the content, and keep the values valid. The exact fields of every block are in `blocks.md` (or `python3 scripts/block_reference.py <type>` for the installed version).

## How a page is put together

1. **Opening.** Make the first block a `hero` (it becomes the page's `<h1>` and the header can sit transparent over it). A `hero-slider` (every slide is a hero with its own layout; the old `slider` block is gone and a saved one is read as a hero slider) can open a home page instead. Without a hero the template prints the page title in a header band. For a campaign page set `template: landing`: no title header, the page is its blocks, so start with a hero.
2. **Rhythm.** 4 to 8 blocks is a full page. Alternate backgrounds (`tone: muted` on every second section), alternate `text-image` between `image-right` and `image-left`, end with one `cta`. Do not put two heroes on a page; do not stack two call-to-action blocks.
3. **Body text.** The Markdown body appears after an opening hero (or first) unless a `content` block places it. Use `{type: content}` to put the body *between* blocks. Long legal or guide text: body + `template: sidebar` (contents list) or a `text` block with `variant: contents`.
4. **Headings.** Never write `<h1>`/`# ` in the body or blocks; blocks choose their own heading level. Each block has `eyebrow` (small label), `heading`, often `intro`.
5. **Links.** `url: contact` is a page of the same language (`/en/contact` on English pages); `/path` is from the site root; full URLs, `#anchor`, `mailto:`, `tel:` as given. Give a block `anchor: pricing` to link to `#pricing`.
6. **Pictures.** Upload first (`add_media.py`, or the admin's Media), then use `/uploads/media/<id>.<ext>` in `image:` fields and always fill `image_alt` (what it shows; leave empty only for pure decoration). A block with no picture still works; presets ship without pictures.
7. **Dynamic blocks** need no content of their own: `latest` (newest posts/projects, `source:` a content type, optional `term:`), `cards` (`source: manual` for hand-written cards), `portfolio`, `playlist`, `map`, `form`. They draw nothing when there is nothing to show.
8. **Both languages.** One file per language with the same `translation_id` and the same block structure; translate texts only, keep `type`, `variant`, `tone`, `url` values (links are relative to the language), images and icons identical.
9. **The page is not in the menu automatically.** Add it to `content/menus/main.yaml` (or Admin > Menus) if it should be linked.

## Do not invent the client's facts

Prices, client names, testimonials, statistics, addresses, phone numbers, team members, certifications and legal text must come from the person or from the material they gave you. If something is missing, write an obvious placeholder (`TODO: …`), keep the page `status: draft`, and list what you need. A confident-looking invented testimonial on a live page is worse than an empty section. The theme's ready-made layouts (`--preset`) already hold neutral placeholder text and no pictures, so they are a safe start.

**Create new pages as `draft` unless told to publish.** A signed-in person sees drafts at their normal address (Admin > the entry > View), so they can review the real page, then change `status: published` (or use the Publish card).

## Start from a ready-made layout

```bash
python3 scripts/site_info.py --presets                                  # 18 page layouts and the sections
python3 scripts/new_entry.py --type pages --title "Τιμές" --preset page-pricing --status draft
python3 scripts/new_entry.py --type pages --lang en --title "Pricing" --slug times --preset page-pricing --translation-of times
```

Page layouts: home, about, contact, service, campaign, lead-generation, pricing, portfolio, case-study, careers, blog-home, help-center, resources, event, services-overview, legal, coming-soon, link-in-bio. Sections: hero + logos, alternating text/image, before/after, comparison, FAQ + CTA, contact + map, latest posts or projects. Each holds Greek and English blocks; read the result and replace the placeholder text and links. The same layouts are in the editor under *Add block > Page layouts / Ready-made sections*.

FAQ or Accordion: use `faq` for a page of questions (large titles, FAQ structured data for search engines). Use `accordion` for compact details inside a narrow column (`width: 25|33|50`, or a column of a container): small titles, no structured data. Both take `initial`: `closed` (default) or `first` (the first item open); only the Accordion has `multiple`, `icon` (`chevron`, `plus`) and `style` (`lines`, `cards`), in the Design tab.

## Worked examples (valid for this version; `validate_content.py` passes on them)

### A services page: hero, three features, text with picture, FAQ, call to action

```yaml
blocks:
  - type: hero
    variant: split
    eyebrow: Υπηρεσίες
    heading: Μια ομάδα, από τη στρατηγική ως την παράδοση
    text: "Σχεδιάζουμε και χτίζουμε ιστοσελίδες που οι πελάτες μας διαχειρίζονται μόνοι τους."
    image: /uploads/media/0123456789abcdef.jpg
    image_alt: Η ομάδα στο γραφείο
    actions:
      - { label: Ζητήστε προσφορά, url: contact }
      - { label: Δείτε έργα, url: projects, style: secondary }
  - type: features
    variant: cards
    tone: muted
    heading: Τι κάνουμε
    columns: "3"
    items:
      - { icon: chart, title: Στρατηγική, text: "Καθαροί στόχοι πριν από κάθε σχέδιο." }
      - { icon: code, title: Ανάπτυξη, text: "Γρήγορα και προσβάσιμα sites." }
      - { icon: check, title: Υποστήριξη, text: "Μηνιαίες βελτιώσεις και αναφορές." }
  - type: text-image
    variant: image-left
    heading: Πώς δουλεύουμε
    body: |
      Ξεκινάμε με μια συζήτηση για τους στόχους σας και καταλήγουμε σε ένα
      πλάνο με ημερομηνίες.

      - Ανακάλυψη και προτεραιότητες
      - Σχεδιασμός και έγκριση
      - Υλοποίηση και παράδοση
    image: /uploads/media/0123456789abcdef.jpg
    image_alt: Συνάντηση ομάδας
  - type: faq
    variant: stacked
    heading: Συχνές ερωτήσεις
    items:
      - question: "Πόσο διαρκεί ένα έργο;"
        answer: "Συνήθως έξι έως δέκα εβδομάδες."
  - type: cta
    variant: band
    heading: Έτοιμοι να ξεκινήσουμε;
    actions:
      - { label: Επικοινωνία, url: contact }
```

(`icon` names must be in the icon set: `python3 scripts/site_info.py --icons`; an unknown name draws no icon and `validate_content.py` reports it.)

### A landing page: `template: landing` in the front matter, then

```yaml
blocks:
  - type: hero
    variant: cover
    align: center
    heading: Ένα μήνυμα, μία ενέργεια
    text: "Εξηγήστε σε μία πρόταση τι προσφέρετε."
    background: image
    image: /uploads/media/0123456789abcdef.jpg
    image_alt: ""
    actions:
      - { label: Κλείστε ραντεβού, url: "#contact" }
  - type: stats
    variant: row
    items:
      - { value: "12", label: χρόνια εμπειρίας }
      - { value: "140+", label: έργα }
  - type: logos
    heading: Μας εμπιστεύονται
    items:
      - { name: Alpha }
      - { name: Beta }
  - type: form
    anchor: contact
    heading: Μιλήστε μαζί μας
    form: contact
```

### A home page that lists the newest content

```yaml
  - type: latest
    variant: magazine
    heading: Τα τελευταία νέα
    source: posts
    limit: 4
    link_label: Όλα τα νέα
    link_url: news
```

## Posts and projects

Posts: `--type posts` with `--date`, `--excerpt`, `--categories news`, `--main-image`, and the article as the Markdown body (headings from `##`). Add `blocks` only for a call to action or a related-content block at the end. Projects (and books, points, routes, businesses) carry their extra fields under `custom_fields` (see `content-format.md`); their page layout is the theme's.

## Checking your work

```bash
python3 scripts/validate_content.py content/pages/services.md content/pages/services.en.md   # what the CMS would silently change
```

If you can run PHP, `php scripts/check-blocks.php content/pages/services.md` is the CMS's own check. If the site is running, open the page and look: no heading missing, no empty section, links work, both languages. Show the person the address of each page you created (and say which ones are drafts).
