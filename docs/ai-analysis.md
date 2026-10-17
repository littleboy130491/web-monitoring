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
   | `category` | choice | healthy, outage, performance, broken_page, broken_assets, content_change, compromised, expiry |
   | `page_broken` | noul (yes/no) | probability 0–1 |
   | `compromised` | noul (yes/no) | probability 0–1 |

3. **Result.** A page counts as broken or compromised when Jev's probability is at least 0.5 or the screenshot review says so. A likely compromise is always stored as **critical / immediate**.

The outcome is stored on the monitoring result (`ai_severity`, `ai_urgency`, `ai_analysis`, `ai_analyzed_at`).

## Setup

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
| `JEV_MODEL` | `jev-latest` | TypeSafe model; pin a version in production |
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
- **MCP**: the `analyze-website` tool runs an analysis; results from other tools include `ai_severity`, `ai_urgency`, `ai_compromised` and `ai_summary`.
- **Notifications**: admins get a database notification when severity reaches `AI_NOTIFY_MIN_SEVERITY`, or whenever a site looks compromised.

## Jev image input (experimental)

Jev 1.13 and 1.14 accept PNG/JPEG images (256–1568 px per side, up to 16 MiB), but TypeSafe marks this as not production-ready. Each request must opt in with the `TypeSafe-Danger-Zone-Images: true` header. Images go in a top-level `assets` map and are referenced by key from the state.

With `JEV_IMAGES=true` and no OpenRouter key, the app sends the screenshot this way. The per-asset JSON format (`type`, `media_type`, `data` as base64 in `JevClient::imageAsset()`) is a best guess, because it wasn't confirmed against TypeSafe's docs. If Jev rejects the request, the analysis is retried without the image and the error is listed under **Warnings**. You may need `JEV_MODEL=jev-preview` for 1.14.

## Limitations

- Page text and screenshots come from the monitored site, so an attacker controls them. Prompts tell the models to treat that content as data, but injected text can still sway the answers. Treat "not compromised" as a signal, not a guarantee.
- AI analysis adds cost per check: one Jev call, plus one vision call when there is a screenshot.
- The emailed monitoring report doesn't include AI results yet; it may be sent before the analyses finish.
