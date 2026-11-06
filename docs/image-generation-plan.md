# Main images made by the site: plan

Status: proposal (2026-11-06). Nothing of it is built yet. Decided by the owner: the site makes the pictures itself, from one style set in the settings; there is no template fallback (a title drawn on a background) — when a picture cannot be made, the entry simply has none and says why; pictures can be made for many entries at once from the content list's bulk actions.

## Why

Chats write posts well and send them through MCP (`docs/mcp-plan.md`) without trouble. Pictures are the weak part: ChatGPT rewrites the style instructions into its own prompt for its image tool, loses the details, starts over in every conversation, and then has to pass the file back through `add_image_from_url`. The result is a generic picture with a different look on every post.

So the work is split. **The chat writes; the site paints.** The site calls an image API itself with a prompt it builds from a fixed house style and a short subject for each entry. Nothing between the settings and the image model rewrites the style, so every post gets the same look.

## 1. The image style (Settings > Images, or its own screen)

One style per site, generic (no code for one client site):

- **Provider** and its **API key**. The key is a secret like the others (`SiteSettings::secretPaths`): never sent to the browser, a blank input keeps the stored one, it can be cleared.
- **Model**, from the provider's list, with a sensible default.
- **House style prompt**: the medium (photo, illustration, 3D, flat), palette, composition, light, mood. A textarea with an example to start from.
- **What to avoid** (negative rules): text and letters in the picture, logos, watermarks, recognisable faces, clichés the owner names. Sent as the provider allows (a negative prompt where there is one, otherwise appended as "Do not show…").
- **Style reference pictures**: up to about four from the media library, sent with every request where the provider accepts reference images. These keep the look far better than words do.
- **Size**: the aspect ratio of the theme's main-image crop (16:9 by default), the provider's nearest size, then cut and resized by `Images` like any upload.
- **Quality** (the provider's draft or high setting), which drives cost.
- **Variants** per request in the editor: 1 to 4.
- **Test**: a button that makes one picture from a sample subject, so the owner sees the style before using it on posts.
- Later, if wanted: a style per content type or per category, falling back to the site's.

The screen follows the admin rule (modern and condensed: cards, chips, compact rows) and shows the month's count and an estimated cost. The exact fields, per provider, are in section 1a; how to get a key is in section 1b.

## 1a. The API settings

Three groups: settings every provider has (always on the screen), the provider's own (under **Advanced**, filled with good defaults), and values the site fixes itself (never shown). Parameter names are as of mid-2026 and are checked against each provider's documentation when its class is written.

### Common settings

| Setting | Values | Notes |
| --- | --- | --- |
| Provider | OpenAI, Google, fal.ai (Flux), Ideogram | Decides which other fields show |
| API key | secret | As the other secrets; a *Check key* button makes one cheap call |
| Model | list per provider | A newer model without a code change |
| House style prompt | textarea | Sent word for word |
| Avoid | textarea | A real negative prompt where the provider has one, otherwise "Do not show…" in the prompt |
| Reference pictures | 0–4 from the media library | Where the provider accepts them; hidden otherwise |
| Aspect ratio | 16:9, 3:2, 4:3, 1:1, 4:5 | Mapped to the provider's nearest size, then cropped exactly by `Images` |
| Quality | Draft / Standard / High | Mapped to each provider's own scale; the main cost lever |
| Output format | WebP (default), JPEG, PNG | Other sizes and AVIF come from `Images` as usual |
| People in pictures | Never / Adults only / Allowed | A real parameter on Imagen; elsewhere added to *Avoid* |
| Monthly cap | number of pictures | With the estimated cost |
| Variants in the editor | 1–4 | Bulk and automatic generation always make one |

### Advanced, per provider

- **OpenAI (GPT image models)**: `quality` (low/medium/high), `size` (one of its fixed sizes; 1536×1024 for landscape), `output_format` and `output_compression`, `background` (opaque), `moderation` (auto/low). With reference pictures the request goes to the edits endpoint, with `input_fidelity: high` so the references are followed closely.
- **Google**: *Gemini image* models take `aspectRatio` and the reference pictures as image parts of the request. *Imagen* takes `aspectRatio`, `personGeneration`, `sampleImageSize` (1K/2K), `seed`; **`enhancePrompt` is forced off**.
- **fal.ai (Flux)**: `image_size`/aspect ratio, `guidance_scale` (how strictly the prompt is followed; around 3–4), `num_inference_steps`, `seed`, `safety_tolerance`, `raw` (Pro/Ultra models: less polished, more natural), reference strength (`image_prompt_strength` / Redux), and **LoRA URL and scale** for a trained house style.
- **Ideogram**: `style_type` (general, realistic, design), `style_codes` and style reference images, `negative_prompt`, `rendering_speed`; **`magic_prompt` is forced off**.

### Fixed by the site, not shown

- **Prompt rewriting is always off** (`enhancePrompt`, `magic_prompt` and the like). Rewriting is what breaks the look, the same problem as with ChatGPT.
- "No text, letters or logos in the picture" is always part of *Avoid*.
- No site-wide fixed `seed`: it does not hold a style across subjects, it only makes pictures look oddly alike. Each picture's seed is stored with it, so a regeneration can reuse it.
- Timeouts, retries and backoff stay in code.
- `n` comes from *Variants* (1 for bulk and automatic).
- The subject is written with the same key and the provider's cheapest text model; the model can be changed under Advanced, the instruction it gets cannot.

## 1b. Getting a key: help on the settings screen

When a provider is chosen, a short box under the key field says where to get one, with the links. The texts below are the starting point; the links and steps are checked when each provider is built, since dashboards move.

**OpenAI**
1. Sign in at platform.openai.com.
2. *Settings > Billing*: add a payment method and some credit (the API is billed apart from a ChatGPT subscription).
3. *Settings > Organization > General*: verify the organization. GPT image models need it; the check takes a few minutes with an ID.
4. *API keys* (platform.openai.com/api-keys): **Create new secret key**, ideally in its own project named after the site, with permissions limited to images and the model the subject uses. Copy it at once; it is shown once.
5. Paste it here and press *Check key*.

**Google (Gemini / Imagen)**
1. Sign in at aistudio.google.com with a Google account.
2. **Get API key** > *Create API key* (it creates or picks a Google Cloud project).
3. Image models need a project with billing: from AI Studio, *Set up billing* on that project (Google Cloud console > Billing). Without it the key works only for the free text models.
4. Paste the key here and press *Check key*. Optionally, in the Cloud console, restrict the key to the "Generative Language API".

**fal.ai (Flux)**
1. Sign up at fal.ai (GitHub or Google sign-in).
2. *Billing*: add credit (prepaid).
3. *Dashboard > Keys* (fal.ai/dashboard/keys): **Add key**, scope *API*, name it after the site. Copy the whole key (`id:secret`); it is shown once.
4. Paste it here and press *Check key*.
5. For a trained house style later: train a Flux LoRA on fal (its LoRA trainer, 15–30 pictures in the style), then paste the LoRA's file URL under *Advanced > LoRA*.

**Ideogram**
1. Sign in at ideogram.ai.
2. *Settings > API* (the developer section): accept the API terms and add a payment method; API use is billed apart from an Ideogram subscription.
3. **Create API key**, copy it (shown once).
4. Paste it here and press *Check key*.

Every box ends the same way: "The key stays on this server; it is never shown again, exported or logged. Set a spending limit at the provider too, apart from the monthly cap here."

## 2. How the prompt is built

`prompt = house style + subject + what to avoid`, always in that order, the house style word for word.

The **subject** is one or two sentences about *what* the picture shows, never *how* it looks. It comes from, in order:

1. An `image_brief` the chat gives (MCP, below), or the person types in the editor.
2. Otherwise the site writes one from the title, the excerpt and the start of the body. This needs a text model: the same provider's cheapest text model with the same key, with a fixed instruction ("one sentence, the scene to show, no style words, no text in the picture"). If the provider has no text model, the title and excerpt are used as they are.

The subject is written into the entry's front matter (`image_brief`, admin only) so a regeneration starts from the same words and the person can correct it.

## 3. Where pictures are made

### In the editor

Next to the main image field: **Generate**. It opens a small panel with the subject (filled as above, editable) and makes 1–4 variants. The person picks one; it goes into the media library and into `main_image`. **Regenerate** keeps the subject unless the person changes it. Variants not picked are discarded, not kept in the library.

### In the content list: bulk generation

The list already has bulk actions (`ContentAdmin::bulk`: publish, draft, delete, with "select all matching"). A fourth: **Generate main images**.

- Tick two or more entries (or all matching the filters), choose the action, and confirm. The confirmation says how many entries, how many already have a main image, and the estimated cost.
- An option on the confirmation: **only entries without a main image** (ticked by default) or **replace existing ones**. Replacing keeps the old picture in the library and the old value in the revision history.
- The action does not make the pictures during the request (each takes 10–60 seconds). It **queues** one job per entry and returns at once: "12 pictures queued."
- The list shows a chip on each queued or running entry, and a line at the top ("Generating 7 of 12…") that refreshes itself; a failed entry gets a red chip with the reason on hover, and can be retried from the same bulk action.
- Bulk generation makes one picture per entry (no variant choice); the person can regenerate any of them from the editor afterwards.
- The subject for each entry comes from its `image_brief`, or is written by the site as in section 2.

### Through MCP

A tool **`generate_main_image`** (scope `media:write`, permission `media.manage`, plus `content.manage` to set the field):

- Arguments: `type`, `slug`, `lang`, `brief` (optional; the subject only — the description tells the model that the site supplies the style and that style words are ignored), `replace` (default false).
- It queues the job and returns at once with the job's state; `get_entry` shows `main_image` once it is done, and an `image_status` (queued, running, done, failed with the reason).
- `create_entry` and `update_entry` accept `image_brief`, so a job can write the post and its subject in one call and ask for the picture in the next, or leave it to the automatic step.
- The server instructions say: "Do not make pictures in the chat; give `image_brief` and call `generate_main_image`." `add_image_from_url` stays for pictures the chat really has (photos from a source it may publish).

### Automatically

An option in the style settings: **make a main image when an entry has none**, per content type (ticked for posts, off for pages by default). It queues a job when such an entry is created or published, from the admin or MCP. It never replaces an existing picture.

## 4. The queue

Generation runs in the background, the way scheduled publishing and the picture sizes already do:

- A table `image_jobs` in the system database: entry (type, slug, lang), subject, prompt sent, provider, model, status, attempts, error, cost estimate, created/started/finished, actor (person or MCP connection).
- Worked by the cron script (`src/cron-backup.php`), after a public response where it can be finished first (`App::runScheduledAfterResponse`), and when the admin is open (a small poll from the list's progress line). A time budget per run, so a run never holds a PHP worker for long.
- One job at a time per site by default (a setting allows 2–3 for providers that permit it); retries with backoff on timeouts and rate limits (`Retry-After`), at most three attempts; a refused prompt (the provider's safety filter) is not retried and says so.
- Before writing, the entry is read again: if someone set a main image meanwhile and the job is not "replace", the picture is kept in the library but not set.
- The result goes into the media library through `MediaLibrary::import`, tagged `generated`, with alt text (below), then into `main_image`, as a revision with the actor *Image generator* (and the person or connection that asked), and the page and front caches are purged as a save does.
- The System screen tells sites that generate pictures to run the cron job every 5 minutes, as it does for scheduling.

## 5. What is kept with each picture

In the media item's metadata: provider, model, the full prompt, the subject, size, the style settings' version, the time, who asked, and the estimated cost. So a picture can be audited, and "regenerate with the same subject" means exactly that.

**Alt text** is written at the same step: from the subject (it already says what the picture shows), shortened to one sentence. The person can edit it as any alt text.

## 6. Providers

One small interface (`generate(prompt, negative, references, size, n) → files`, `describe(text) → subject`), one class per provider, all over `SafeHttp` (curl, timeouts, size limits). Phase 1 ships one or two; the others follow if wanted. To compare at build time, with current prices and limits:

| Provider | Reference images | Negative prompt | Text model with the same key | Notes |
| --- | --- | --- | --- | --- |
| OpenAI (GPT image models) | yes (edit with references) | in the prompt | yes | Follows long prompts well; simplest single key. |
| Google (Gemini image / Imagen) | yes (Gemini) | Imagen: yes | yes | Good quality and price; key from AI Studio. |
| Flux via fal.ai or Replicate | yes (Redux / Kontext) | partly | via the same platform's LLMs | The only easy way to a **trained style (LoRA)**, the strongest consistency. |
| Ideogram | style references | yes | no | Best when text in the picture is ever wanted (not planned). |

**Consistency, strongest first**: a trained style (LoRA, 15–30 pictures in the house style, a one-off per site, Flux only) → reference pictures with every request → the style prompt alone. Phase 1 needs only the last two; a LoRA is a model id in the settings once the provider supports it.

Advice for the style itself, shown on the settings screen: illustration and abstract styles stay consistent far better than photographs; never ask for text in the picture.

## 7. Limits and safety

- A **monthly cap** on pictures (and so on cost) in the settings; jobs past it wait and the admin says so. Bulk generation shows the remaining count before confirming.
- A cap per MCP connection per day, next to its publishing limit.
- The subject from a chat is untrusted (the chat reads third-party pages): it is cut to its length limit and sent only as the subject, after the house style; it never changes the provider, model, size or style.
- The API key never leaves the server, is never logged, and is left out of the theme and settings exports.
- Every job is logged (`activity_logs`: who, which entry, outcome, reason); errors as one plain sentence in the admin, details in the PHP log.
- Generated pictures are marked in the library, so the owner can tell them from photos.

## Phases

1. **Style settings, one provider, editor button.** Settings screen with the secret key, *Check key* and the provider's help box (1b), style prompt, avoid list, references, size, Advanced (1a), test button; the provider interface and the first provider; the editor's Generate panel with variants; media metadata and alt text. Useful at once, by hand.
2. **The queue and bulk generation.** `image_jobs`, the worker in cron/after response/admin, the bulk action with its confirmation, progress line and chips, retries, monthly cap.
3. **MCP.** `generate_main_image`, `image_brief` on create/update, `image_status` on get_entry, the instructions changed, the per-connection cap. Tested with ChatGPT and Claude jobs.
4. **Automatic generation** per content type, and subjects written by the site's text model when none is given.
5. **More providers**, a trained style (LoRA) where the provider has one, styles per content type or category, if wanted.

## Open for the owner

- First provider: OpenAI (one key for picture and subject) or Flux via fal.ai (room to grow into a trained style)?
- Should an entry published through MCP wait as a draft until its picture exists, or publish and get the picture a minute later?
- A cost cap per month: what figure?
- Are pictures of people allowed in the house style, or always excluded?
