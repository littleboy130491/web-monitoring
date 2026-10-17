# AI Analysis

Each monitoring result can be analyzed by AI to decide how severe and urgent the problem is, and whether the page looks **broken** or **hacked**. It uses [Jev](https://typesafe.ai), TypeSafe's decision model, which returns typed answers with probabilities instead of free text.

## How it works

1. **Screenshot review (when the check has a screenshot)**
   - With an OpenRouter key, a vision model on OpenRouter reads the screenshot and reports whether the page is broken or shows signs of compromise.
   - With only a Jev key and `JEV_IMAGES=true`, the screenshot goes to Jev directly. This is experimental (see below).
2. **Jev decisions.** Jev receives the check data, the last 5 statuses, the start of the page's visible text and the screenshot findings. It answers:

   | Question | Type | Answer |
   |---|---|---|
   | `severity` | score | none, low, medium, high, critical |
   | `urgency` | choice | immediate, today, this_week, none |
   | `category` | choice | healthy, outage, performance, broken_page, broken_layout, broken_assets, content_change, compromised, expiry |
   | `page_broken` | noul (yes/no) | probability 0–1 |
   | `layout_broken` | noul (yes/no) | probability 0–1 |
   | `compromised` | noul (yes/no) | probability 0–1 |

3. **Result.** A page counts as broken or compromised when Jev's probability is at least 0.5 or the screenshot review says so. A likely compromise is always stored as **critical / immediate**.

The outcome is stored on the monitoring result (`ai_severity`, `ai_urgency`, `ai_analysis`, `ai_analyzed_at`).

## Broken layouts (e.g. Elementor CSS cache)

A common failure is a page that loads fine (HTTP 200) but looks broken because its stylesheets are missing. On WordPress sites with Elementor, this usually happens after a cache plugin or CDN keeps serving old HTML that links to Elementor CSS files that have since been regenerated under new names. The files return 404 and sections render unstyled.

How it's detected:

- **Deep scan:** detects the page builder (Elementor, Divi, Oxygen, Beaver Builder, or plain WordPress) and checks page-builder CSS first among the 20 assets it tests. That way Elementor's `/wp-content/uploads/elementor/css/post-*.css` files are always checked.
- **Jev:** gets `layout_signals` (page reachable, page builder, broken stylesheets and scripts, broken page-builder CSS URLs) and answers a dedicated `layout_broken` question.
- **Screenshot review:** reports `layout_broken` with `layout_issues`, e.g. "sections stacked in one unstyled column".
- **Result:** if either source says the layout is broken, the result is at least **high / today** with category `broken_layout`. When the evidence points to a known cause, `likely_cause` and `suggested_fix` are filled in. For missing Elementor CSS, the fix is: regenerate Elementor CSS (Elementor > Tools > Regenerate CSS & Data), then purge the page cache and CDN.

The admin panel has an "AI: Broken Layout" filter; MCP's `list-ai-findings` with `broken_only=true` includes them.

## Setup

For installing and updating the app itself, see [deployment.md](deployment.md).

Set at least one key in `.env`, then run `php artisan migrate`.

```env
# TypeSafe (Jev) directly
JEV_API_KEY=

# OpenRouter: serves Jev too, and its vision model reads screenshots
OPENROUTER_API_KEY=
```

| Keys set | Decisions (Jev) via | Screenshot read by |
|---|---|---|
| Jev only | TypeSafe | Not read, or Jev itself with `JEV_IMAGES=true` |
| OpenRouter only | OpenRouter | OpenRouter vision model |
| Both | TypeSafe (change with `AI_DECISION_PROVIDER`) | OpenRouter vision model |

Screenshots are only taken with `--screenshot` (the scheduled run uses it), so analysis of checks without one is based on data and page text alone.

### All settings

| Variable | Default | Purpose |
|---|---|---|
| `AI_ANALYSIS_ENABLED` | `true` | Master switch (still needs a key) |
| `AI_AUTO_ANALYZE` | `true` | Queue an analysis after every check |
| `AI_DECISION_PROVIDER` | `auto` | `auto`, `jev` or `openrouter` |
| `JEV_MODEL` | `jev-latest` | TypeSafe model; pin a version such as `jev-1.14.0` in production |
| `JEV_API_URL` | `https://api.typesafe.ai/v1/systemone` | TypeSafe endpoint |
| `JEV_IMAGES` | `false` | Send screenshots to Jev (experimental) |
| `OPENROUTER_DECISION_MODEL` | `typesafe/jev-1.13` | Jev model on OpenRouter |
| `OPENROUTER_VISION_MODEL` | `anthropic/claude-haiku-5.5` | Model that reads screenshots |
| `OPENROUTER_BASE_URL` | `https://openrouter.ai/api` | OpenRouter API base |
| `AI_NOTIFY_MIN_SEVERITY` | `high` | Notify admins at or above this severity |
| `AI_TIMEOUT` | `60` | Seconds per AI request |
| `AI_PAGE_TEXT_CHARS` | `4000` | Page text sent for analysis |

## Using it

- **Automatically**: after each check, `AnalyzeMonitoringResultJob` runs on the queue (needs a queue worker).
- **Command**: `php artisan monitor:analyze` (latest result of every active site), `--id=<website id>`, or `--result=<result id>`. Runs immediately, no queue.
- **Admin panel**: the Monitoring Results list has an **AI Severity** column (hover for the summary) and filters for severity and "AI: Possibly Hacked". Each result page has an **AI Analysis** section and an **Analyze with AI** button.
- **MCP / AI agents**: ask your agent things like "what did the AI find?", "is any site hacked?" or "show the AI analysis for example.com".
  - `list-ai-findings`: findings across all sites, most severe first (filter by minimum severity, hacked only, broken only, hours)
  - `get-ai-analysis`: the stored analysis for one site or result, optionally with Jev's raw answers
  - `analyze-website`: runs a new analysis now
  - Other tools' results also include `ai_severity`, `ai_urgency`, `ai_compromised` and `ai_summary`.
- **Notifications**: admins get a database notification when severity reaches `AI_NOTIFY_MIN_SEVERITY`, or whenever a site looks compromised. Broken layouts are titled "AI: Page Layout Broken" and include the suggested fix.

## Jev versions and 1.14

| Model ID | What it is |
|---|---|
| `jev-1.13.0` | First public Jev. Accepts images (experimental). |
| `jev-1.14.0` | Released 2026-10-14. Small improvements across the board, mostly multilingual. Adds the Box question type. Same price as 1.13. |
| `jev-preview` | Alias for 1.14 during its preview week. |
| `jev-latest` | Alias for the current stable version. Moves from 1.13 to 1.14 around 2026-10-21. |

Severity and the 0.5 cut-off for "broken" and "compromised" depend on Jev's probabilities, which can shift between versions. In production, pin a version (`JEV_MODEL=jev-1.14.0`) and switch deliberately. Each stored analysis records the version that answered under `model`, so you can tell which version produced each result.

Jev 1.14 also offers a Box question (one bounding box per question) and a long-context mode (opt-in header). This app doesn't use them yet; Box could later mark where on a screenshot the spam or defacement is.

## Jev image input (experimental)

Jev 1.13 and 1.14 accept images, but TypeSafe marks this "danger zone" feature as not production-ready. With `JEV_IMAGES=true` and no OpenRouter key, the app sends the screenshot to Jev in the documented format:

- The request carries the header `TypeSafe-Danger-Zone-Images: true`.
- The image goes in a top-level `assets` map as `{"type": "image", "format": "jpg", "data_base64": "..."}`: plain base64, no `data:` prefix.
- The state's `screenshot` field holds the asset's key (`screenshot-image`), so Jev knows which image the questions refer to.
- Images must be still PNG or JPEG, 256–1568 px per side, up to 16 MiB. The app checks this before sending; our 1280×720 JPEG screenshots fit.

If the image doesn't meet the limits, or Jev rejects the request, the analysis runs without the image and the reason is listed under **Warnings**.

## Limitations

- Page text and screenshots come from the monitored site, so an attacker controls them. Prompts tell the models to treat that content as data, but injected text can still sway the answers. Treat "not compromised" as a signal, not a guarantee.
- AI analysis adds cost per check: one Jev call, plus one vision call when there is a screenshot.
- The emailed monitoring report doesn't include AI results yet; it may be sent before the analyses finish.
