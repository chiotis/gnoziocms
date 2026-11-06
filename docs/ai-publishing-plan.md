# AI publishing: plan

Status: proposal (2026-11-06). Nothing of it is built yet. It joins two earlier plans (main images made by the site, and publishing schedules) into one module. The safety pieces it reuses (automation accounts, bylines, `source_key`, strict writes, scheduled publishing and its release) are built and described in `docs/mcp-plan.md`.

Decided by the owner:

- One module, **Manage > AI publishing**, next to Content types, Forms and Taxonomies, with everything in it: the providers and keys, the general instructions for text and pictures, and the schedules.
- **One schedule writes one post per run.** A schedule has one time of day and any days of the week. Twenty posts a day means twenty schedules, each with its own instructions, which gives variety.
- **No limit on the number of schedules**, and no daily cap on posts by default (an optional one exists).
- **Writing**: Anthropic, OpenAI and Google, all three. **Pictures**: OpenAI, Google, fal.ai (Flux) and Ideogram. OpenAI and Google use one key for both.
- **OpenAI first, for both writing and pictures.** The whole module (instructions, schedules, pictures by hand, bulk, MCP) is built and working with OpenAI alone; the other providers are added only after that (phase 7). The provider interfaces exist from the start, so adding them changes no other code.
- **Avoid fields everywhere**: the general instructions and each schedule have an *Avoid* field for the text and one for the picture. A schedule's avoid lists are added to the general ones, never in place of them. People in pictures is one of these lines, not a setting of its own.
- **A new schedule publishes automatically at its time.** Each schedule can still choose published, scheduled or draft.
- **No template fallback for pictures**: when a picture cannot be made, the post has none, and the module says why.
- Pictures can also be made **by hand in the editor** and **in bulk from the content list**, and **through MCP**, not only by schedules.

## Why

Chats write posts well and send them through MCP without trouble. Pictures are the weak part: ChatGPT rewrites the style instructions into its own prompt for its image tool, loses the details, starts over in every conversation, and then has to pass the file back. The result is a generic picture with a different look on every post. And a scheduled chat job needs one long prompt that is hard to keep up.

So the site does both itself. It calls the providers' APIs with prompts it builds from fixed general instructions plus a few lines per schedule. Nothing rewrites the style between the settings and the model, so every post keeps the site's voice and every picture its look. MCP stays for jobs that people run from Claude or ChatGPT.

## 1. The module: Manage > AI publishing

One entry in the admin's *Manage* group (`/admin/ai-publishing`), in the condensed admin style (cards, chips, compact rows). Four tabs:

1. **Schedules**: the list of schedules and **Add schedule** (section 4), with a week view.
2. **Instructions**: the general instructions for writing and for pictures (section 3).
3. **Settings**: providers, keys, models, limits (section 2).
4. **Runs**: the log of every run and every picture job (section 8).

A **Pause all** switch sits at the top of every tab. When the providers are not set up yet, the module opens on Settings with a short "start here".

Permissions: a new `ai.manage` permission for schedules, instructions and runs, given to admins by default and assignable to editors; keys and limits on the Settings tab need `settings.manage`. Making a picture in the editor or from the content list needs `media.manage` and `content.manage`, as uploading and editing do.

## 2. Settings tab

### Providers and keys

One card per provider, each with its key **once**, used for whatever that provider does:

| Provider | Writing | Pictures | Built in |
| --- | --- | --- | --- |
| OpenAI | yes | yes | phase 1 |
| Anthropic (Claude) | yes | — | phase 7 |
| Google (Gemini, Imagen) | yes | yes | phase 7 |
| fal.ai (Flux) | — | yes | phase 7 |
| Ideogram | — | yes | phase 7 |

Until phase 7 the Settings tab shows only the OpenAI card, and the writing and picture provider choices are fixed to it.

Each card: the key (a secret like the site's others, `SiteSettings::secretPaths`: never sent to the browser, never exported or logged, a blank input keeps it, it can be cleared), **Check key** (one cheap call that also says whether the account can use the chosen models), and the help box for getting a key (section 2c). A site needs one card filled for OpenAI or Google alone, or two (for example Anthropic for writing, fal.ai for pictures).

Below the cards, two choices made from the providers that have a key:

- **Writing provider** and **writing model** (a list per provider, with a mid-sized default; a schedule can choose another).
- **Picture provider** and **picture model**.

### Limits

- **Monthly cap** on writing runs and on pictures (empty = none), with the estimated cost so far, from the token counts and picture counts the providers return.
- **Daily cap on posts** for the whole site: empty (none) by default; there as a safety net if wanted.
- **Missed slot window**: how late a slot may still run after the site or cron was down. **1 hour** by default (0 to 24). Older slots are skipped and logged, never run in a burst.
- **Pictures at a time**: 1 by default, 2–3 for providers that permit it.
- **Keep run logs**: 30 days by default.

### 2a. Common picture settings (on the Instructions tab, with the image style)

| Setting | Values | Notes |
| --- | --- | --- |
| Reference pictures | 0–4 from the media library | Where the provider accepts them; hidden otherwise |
| Aspect ratio | 16:9, 3:2, 4:3, 1:1, 4:5 | Mapped to the provider's nearest size, then cropped exactly by `Images` |
| Quality | Draft / Standard / High | Mapped to each provider's own scale; the main cost lever |
| Output format | WebP (default), JPEG, PNG | Other sizes and AVIF come from `Images` as usual |
| Variants in the editor | 1–4 | Schedules and bulk always make one |

### 2b. Advanced, per provider (folded, with good defaults)

All parameter names are as of mid-2026 and are checked against each provider's API documentation when its class is written; the plan names the documented features each one relies on.

**Writing**

- **Anthropic** (Messages API): `model`, `max_tokens`, `temperature` (low, fixed), the answer as a JSON schema (structured outputs, or a forced tool call whose input schema is the entry), the **web search tool** when a schedule uses web search. Key in the `x-api-key` header.
- **OpenAI** (Responses API): `model`, `max_output_tokens`, the answer through a strict `json_schema` text format, the **`web_search` tool** when a schedule uses web search. Reasoning effort low for reasoning models.
- **Google** (Gemini API, `generateContent`): `model`, `maxOutputTokens`, `responseMimeType: application/json` with a `responseSchema`, **grounding with Google Search** when a schedule uses web search (where the model allows grounding together with a schema; otherwise the search runs first and the schema-bound answer second).

**Pictures**

- **OpenAI (GPT image models)**: `quality` (low/medium/high), `size` (one of its fixed sizes; 1536×1024 for landscape), `output_format` and `output_compression`, `background` (opaque), `moderation` (auto/low). With reference pictures the request goes to the edits endpoint, with `input_fidelity: high`.
- **Google**: *Gemini image* models take the aspect ratio and the reference pictures as image parts of the request. *Imagen* takes `aspectRatio`, `personGeneration` (set to "don't allow" when the avoid lists exclude people), `sampleImageSize` (1K/2K), `seed`; **`enhancePrompt` is forced off**.
- **fal.ai (Flux)**: image size or aspect ratio, `guidance_scale` (around 3–4), `num_inference_steps`, `seed`, `safety_tolerance`, `raw` (Pro/Ultra: less polished), reference strength (Redux), and **LoRA URL and scale** for a trained house style.
- **Ideogram**: `style_type` (general, realistic, design), style codes and style reference images, `negative_prompt`, `rendering_speed`; **`magic_prompt` is forced off**.

**Fixed by the site, never shown**

- **Prompt rewriting is always off** (`enhancePrompt`, `magic_prompt` and the like). Rewriting is what breaks the look.
- "No text, letters or logos in the picture" is always part of *Avoid*.
- No site-wide fixed `seed`: it does not hold a style across subjects, it only makes pictures look oddly alike. Each picture's seed is stored with it, so a regeneration can reuse it.
- Low temperature for writing; the answer always in the JSON schema (section 6).
- Timeouts, retries and backoff stay in code.

### 2c. Getting a key: the help box on each card

A short box under each key field, with links. The steps are checked when each provider is built, since dashboards move. Every box ends: "The key stays on this server; it is never shown again, exported or logged. Set a spending limit at the provider too, apart from the caps here."

**Anthropic**
1. Sign in at console.anthropic.com.
2. *Settings > Billing*: add credit (the API is billed apart from a Claude subscription).
3. Optionally *Settings > Workspaces*: a workspace named after the site, with its own spending limit.
4. *Settings > API keys* > **Create key** in that workspace. Copy it at once; it is shown once.
5. Paste it here and press *Check key*.

**OpenAI**
1. Sign in at platform.openai.com.
2. *Settings > Billing*: add a payment method and credit (billed apart from ChatGPT).
3. *Settings > Organization > General*: **verify the organization**. GPT image models need it; it takes a few minutes with an ID.
4. *API keys* (platform.openai.com/api-keys): **Create new secret key**, ideally in its own project named after the site, limited to the models it needs. Copy it at once.
5. Paste it here and press *Check key*. The same key serves writing and pictures.

**Google**
1. Sign in at aistudio.google.com.
2. **Get API key** > *Create API key* (it creates or picks a Google Cloud project).
3. Picture models and higher limits need **billing on that project**: *Set up billing* in AI Studio (Google Cloud console > Billing).
4. Paste the key here and press *Check key*. Optionally restrict it in the Cloud console to the Generative Language API. The same key serves writing and pictures.

**fal.ai**
1. Sign up at fal.ai.
2. *Billing*: add credit (prepaid).
3. *Dashboard > Keys*: **Add key**, scope *API*, named after the site. Copy the whole key (`id:secret`); it is shown once.
4. Paste it here and press *Check key*.
5. For a trained house style later: train a Flux LoRA on fal (15–30 pictures in the style), then paste its file URL under *Advanced > LoRA*.

**Ideogram**
1. Sign in at ideogram.ai.
2. *Settings > API*: accept the API terms and add a payment method (billed apart from a subscription).
3. **Create API key**, copy it (shown once).
4. Paste it here and press *Check key*.

## 3. Instructions tab

Sent in every run of every schedule, and used by every picture made anywhere on the site (editor, bulk, MCP, schedules).

**Writing**
- **Writing instructions** (textarea): who reads the site, voice and tone, language, typical length, structure (headings, lists, short paragraphs), SEO habits (a clear title, a description of about 150 characters). A starting example is filled in.
- **Avoid in the text** (textarea, one line each): what no post may contain. Starting lines: invented facts, quotes, prices or statistics; medical, legal or financial advice; competitor names; clichés such as "in today's fast-paced world". Sent as its own block, "Never do any of these:".
- **Facts about the site** (textarea): what the business is and offers, its place, its name as it must be written. Used only where they fit.
- **Defaults for new schedules**: content type, language, byline, length.

**Pictures**
- **House style** (textarea): medium (photo, illustration, 3D, flat), palette, composition, light, mood. Sent word for word.
- **Avoid in the picture** (textarea, one line each): text, logos, watermarks, recognisable faces, people if the site wants none, clichés (handshakes, light bulbs). A real negative prompt where the provider has one; with OpenAI it is a closing "Do not show:" block of the prompt.

**How well avoid lists work.** Writing models follow them well. Picture models follow them less reliably: naming a thing, even to forbid it, can pull it into the picture. So the settings screen advises saying what the picture *does* show where possible ("an empty street at dawn" rather than "no people"), and keeping the avoid list short. A picture that breaks the list is regenerated from the editor or the bulk action.
- The common picture settings of section 2a (reference pictures, aspect ratio, quality, format, people, variants).
- **Test picture**: one picture from a sample subject, so the style is seen before it is used.
- Advice shown beside the style: illustration and abstract styles stay consistent far better than photographs; never ask for text in the picture; reference pictures do more than words.

**Consistency, strongest first**: a trained style (LoRA, fal.ai only) → reference pictures with every request → the house style alone. The first version needs only the last two.

## 4. Schedules tab

The list: one compact row per schedule with its name, on/off switch, days as chips, time, content type, next run, last run with its result chip, and posts this month. Filters: on, off, failed lately. A **week view** shows the next seven days as columns, each run a chip with the schedule's name, so gaps and crowds are easy to see.

**Add schedule** (and opening one) shows four groups.

**When**
- **Days of the week**: seven chips, any combination.
- **Time of day**: one time (`08:00`), in the site's time zone (Settings > Localization). A second post at another time is another schedule (*Duplicate* makes that quick).
- **Start and end date** (optional), so a series can run for a season.
- **Prepare ahead**: how long before the time the post is written, 30 minutes by default, so it goes live on the minute even though writing and painting take a few minutes.

**What**
- **Content type**, **language**, **taxonomy terms**: fixed terms (always *Tips*), or "let the model choose from the existing terms" (never new ones), or both.
- **Content instructions** (a few lines): the topic, the angle, length or structure if they differ from the general instructions.
- **Avoid in the text** (optional, one line each): what this schedule's posts must not contain, on top of the general list ("no tips that need paid software").
- **Image instructions** (one or two lines): what the picture shows for this schedule. Added to the house style as the subject's guide, never in place of it.
- **Avoid in the picture** (optional, one line each): on top of the general list ("no laptops", "no people").
- **No picture**: a switch for posts that do not need one.
- **Writing model** (optional): another model than the module's default.

**Material**
- **Nothing**: evergreen posts from the model's knowledge (tips, explainers). The general instructions must forbid invented facts.
- **Feeds**: one or more RSS/Atom addresses. Each run takes the newest item not used before by this schedule.
- **Pages**: fixed addresses read every run (an offers page, an events page).
- **Web search**: the writing provider's own search tool, with what to look for. Off by default (it costs more and is less predictable).

**Result**
- **Status**: *published at its time* (the default: the post is saved as scheduled for the exact time and released by the existing scheduled publishing), *published as soon as it is ready*, or *draft* for review.
- **Publish only with a picture** (on by default): if the picture fails, the post stays a draft and the module says why.
- **Byline** (default from the instructions), written as `author`; `generated_by: schedule:<id>` in the front matter, shown only in the admin, where the content list filters by it.

**Buttons**: *Run now* (a real run outside the timetable), *Preview* (writes the post and shows it without saving, with a draft-quality picture, to tune the instructions), *Duplicate*, *Pause*, *Delete* (the posts it made stay).

Example schedules:

- "Tip of the day": Mon–Fri, 08:00, posts, *Tips*. "One practical tip for small-business owners about online marketing, 300–400 words." Picture: "A single everyday object that stands for the tip."
- "Weekend reading": Sat, 10:00. "A longer piece, 900 words, on a theme from the week's items in our feeds." Picture: "A wide calm scene, morning light."
- "Monday roundup": Mon, 07:30, three RSS feeds. "The five most important stories of the past week, a paragraph each, linked to their sources."

Schedules can be **exported and imported** (JSON, with the general instructions if wanted, never the keys), on the theme transfer's pattern, so a set that works on one site can start another.

## 5. Pictures outside schedules

### In the editor

Next to the main image field: **Generate**. A small panel with the subject (filled from `image_brief`, or written by the site from the title, excerpt and start of the body; editable) and an optional *Avoid* for this picture only (added to the general list) makes 1–4 variants. The person picks one; it goes into the media library and into `main_image`. **Regenerate** keeps the subject unless the person changes it. Variants not picked are discarded. The subject is written to the front matter as `image_brief` (admin only) so a regeneration starts from the same words.

### In the content list: bulk

A fourth bulk action next to publish, draft and delete (`ContentAdmin::bulk`): **Generate main images**.

- Tick two or more entries, or *select all matching* the filters, choose the action, confirm. The confirmation says how many entries, how many already have a main image, and the estimated cost.
- **Only entries without a main image** (default) or **replace existing ones**. Replacing keeps the old picture in the library and the old value in the revision history.
- It queues one job per entry and returns at once ("12 pictures queued"). The list shows a chip on each queued or running entry and a line at the top ("Generating 7 of 12…") that refreshes itself; a failed entry gets a red chip with the reason, and can be retried with the same action.
- One picture per entry, no variants; any of them can be regenerated from the editor.

### Through MCP

- **`generate_main_image`** (scope `media:write`; permissions `media.manage` and `content.manage`): `type`, `slug`, `lang`, `brief` (the subject only; the description says the site supplies the style and ignores style words), `replace` (default false). It queues the job and returns at once.
- `create_entry` and `update_entry` accept `image_brief`; `get_entry` returns `image_status` (queued, running, done, failed with the reason).
- The server instructions say: "Do not make pictures in the chat; give `image_brief` and call `generate_main_image`." `add_image_from_url` stays for real photos a job may publish.

### Automatically

An option per content type on the Instructions tab: **make a main image when an entry has none** (on for posts, off for pages by default), when it is created or published from the admin or MCP. It never replaces an existing picture.

## 6. A schedule's run, step by step

1. **Due.** The cron job (every 5 minutes, `src/cron-backup.php`, which already releases scheduled entries) finds runs whose *time − prepare ahead* has passed. Each run has a key (`schedule id + date + time`), unique in the run table, with a lock, so it runs once however often cron fires.
2. **Missed.** Up to the missed slot window late (1 hour by default) it still runs; later it is skipped and logged.
3. **Material.** Feeds and pages are fetched through `SafeHttp` (size and time limits, no private addresses), unused items picked. Fetched text is untrusted: passed as quoted material, never as instructions, and it can never change the status, type, byline, terms outside the allowed list, or any setting.
4. **Memory.** The titles (and for feeds the source addresses) of the schedule's last 30 posts go with the request, with "do not repeat these topics". A feed item already used is never used again (`source_key`, as MCP keeps them in `mcp_sources`, which gains a column for the schedule).
5. **The request**, always in this order: general writing instructions, facts about the site, the schedule's instructions, the avoid block (general lines, then the schedule's), what a valid entry is (the content type's fields from `FieldSchema`, the allowed terms, the blocks allowed in a body, the language), the material, the memory.
6. **Structured answer** in a JSON schema: `title`, `excerpt`, `body` (Markdown), `terms`, `seo_title`, `seo_description`, `image_brief` (the picture's subject, following the schedule's image instructions, no style words), `sources`.
7. **Checked** with `ContentEditor::save` in `dryRun`, strict as MCP writes are (unknown fields, terms or blocks are errors). On errors, they are sent back once for a corrected answer; a second failure ends the run as failed.
8. **Saved as a draft** through `ContentEditor::save` as the automation account, with the byline and `generated_by`, so slugs, the index, relations and revisions behave as in the admin.
9. **Picture.** A picture job is queued (section 7), with the prompt built as house style + subject (`image_brief`, guided by the schedule's image instructions) + "Do not show:" (the general picture avoid lines, then the schedule's). A schedule's job goes ahead of bulk jobs, so a long bulk run never makes a post late.
10. **Final status.** When the picture is set (or at once with *No picture*), the entry gets the schedule's status. If the post's time comes while the picture is still being made, it is released as soon as the picture is there; if the picture fails and *Publish only with a picture* is on, it stays a draft.
11. **Told.** A notice in the admin for each failed run; optionally an email to the editors for failures, or a daily one for posts waiting as drafts.

## 7. The picture queue

All pictures (editor variants aside, which are made while the person waits) go through one queue:

- `ai_image_jobs` in the system database: entry, subject, the full prompt sent, provider, model, status, attempts, error, cost estimate, created/started/finished, priority (schedule before bulk), who asked (person, MCP connection or schedule).
- Worked by the cron script, after a public response where it can be finished first (`App::runScheduledAfterResponse`), and while the admin is open (the content list's progress line polls). A time budget per run, so no PHP worker is held long.
- Retries with backoff on timeouts and rate limits (`Retry-After`), at most three attempts; a prompt refused by the provider's safety filter is not retried and says so.
- Before writing, the entry is read again: if someone set a main image meanwhile and the job is not "replace", the picture goes into the library but is not set.
- The result enters the library through `MediaLibrary::import`, tagged `generated`, then `main_image`, as a revision with the actor *AI publishing* (and who asked), and the page and front caches are purged as a save does.
- **Kept with each picture** in its media metadata: provider, model, the full prompt, the subject, size, seed, the version of the instructions, time, who asked, cost estimate. **Alt text** is written from the subject, one sentence, editable.

## 8. Runs tab

One row per schedule run and per picture job: what (schedule name or "Bulk", "Editor", "MCP"), when, duration, result (published, scheduled, draft, failed, skipped), the entry (link), tokens and estimated cost, the reason for a failure. Opening a run shows the request and answer (kept 30 days, never the key). Filters by schedule, result and date.

Also:
- The dashboard's attention list (`DashboardAttention`): "2 schedule runs failed today", "Monthly AI cap at 90%".
- The System screen says the cron job must run every 5 minutes when any schedule is on or pictures are queued, and warns when it has not run for 15 minutes.
- Every run and job is in `activity_logs`.

## 9. Safety

- Everything written goes in as an **automation account** (a role, no sign-in, built for MCP), one shared account by default, so the history reads "AI publishing: Tip of the day".
- Material and MCP subjects are untrusted data, cut to their length limits and sent only where data belongs. Raw HTML never comes through (`HtmlGuard`).
- Keys never leave the server, are never logged, and are left out of every export.
- Caps as in section 2; **Pause all** stops schedules and the picture queue at once.
- Generated pictures are marked in the library (tag `generated`), so they are told apart from photos; generated posts carry `generated_by`.

## 10. Storage

In the system database:

- `ai_schedules`: id, name, enabled, days, time, start/end, prepare ahead, type, language, terms (fixed and allowed), content instructions, text avoid, image instructions, picture avoid, material (JSON), status, picture required, no picture, byline, model, created/updated.
- `ai_runs`: id, schedule, run key (unique), status, started/finished, entry, tokens, cost, error, request and answer (purged after the kept days).
- `ai_image_jobs`: as in section 7.
- The instructions, provider choices and limits in the site settings; the keys among its secrets.

## 11. Code shape

- One interface per job: `TextProvider::write(request, schema) → answer + usage` and `ImageProvider::generate(prompt, avoid, references, size, n) → files + usage`, with `check()` for the key. One class per provider (`AnthropicProvider`, `OpenAiProvider` implementing both, `GoogleProvider` implementing both, `FalProvider`, `IdeogramProvider`), all over `SafeHttp`.
- A fake provider of each kind for tests, so no test calls a real API.
- `AiPublishingAdmin` for the module's screens, `AiSchedules` for slots and runs, `AiImageQueue` for the queue, each small, as the rest of `src/` is.

## 12. Tests

- Unit: due runs for days, time, the site's zone, daylight-saving changes, start/end dates; a run never done twice (two workers at once); missed runs inside and past the window; prompt order; the JSON answer checked strictly, one retry with errors, failure after it; feed items used once; caps; *Publish only with a picture* when the picture fails; the final status for each choice; the picture queue's priorities, retries, refusals, and "set meanwhile".
- HTTP: the module's tabs (permissions `ai.manage` and `settings.manage`), add/edit/duplicate/pause/delete a schedule, run now, preview, the bulk action and its progress, the editor's Generate, `generate_main_image` through `/mcp`.
- Every wait with a deadline, as `AGENTS.md` asks.

## Phases

Phases 1–6 use **OpenAI only**, for writing and pictures.

1. **The module and pictures by hand.** Manage > AI publishing with Settings (the OpenAI card, *Check key*, help box, limits) and Instructions (picture half: house style, avoid, references, test picture); `OpenAiProvider` for pictures (GPT image models, the edits endpoint with references); the editor's Generate with variants; media metadata and alt text.
2. **The picture queue and bulk.** `ai_image_jobs`, the worker, the bulk action with confirmation, progress and chips, retries, caps, the Runs tab for pictures.
3. **Writing and schedules without material.** `OpenAiProvider` for writing (Responses API, strict JSON schema), the writing half of Instructions with its avoid list, the Schedules tab with both avoid fields, runs from cron, strict checking, picture then status, notices. Evergreen schedules work end to end.
4. **Material**: feeds and pages with `source_key` memory; web search through OpenAI's `web_search` tool.
5. **MCP and automatic pictures**: `generate_main_image`, `image_brief`, `image_status`, the instructions changed; pictures for entries without one, per content type.
6. **Week view, preview, export/import, emails.** The complete AI content schedule is done here.
7. **The other providers**: Anthropic and Google for writing; Google, fal.ai and Ideogram for pictures; the provider choices on the Settings tab, their cards, help boxes and advanced settings.
8. Later, if wanted: a translation into the site's other languages after each post (`translation_of`), a *Review* status with Publish/Reject from an email, schedules triggered by a new feed item instead of a time, a trained style (LoRA) on fal.ai.

## Open for the owner

Nothing at the moment. The provider order and the avoid fields were decided on 2026-11-06.
