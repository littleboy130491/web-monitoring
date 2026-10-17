# Deployment

How to install Web Monitor on a production server, configure it, and update it.

- [Requirements](#requirements)
- [Fresh install](#fresh-install)
- [Configure `.env`](#configure-env)
- [Scheduler and queue worker](#scheduler-and-queue-worker)
- [Updating an existing install](#updating-an-existing-install)
- [Upgrading to the MCP and AI release](#upgrading-to-the-mcp-and-ai-release)
- [Verify the deployment](#verify-the-deployment)
- [Rolling back](#rolling-back)
- [Troubleshooting](#troubleshooting)

## Requirements

| Need | Notes |
|---|---|
| PHP 8.2+ | Extensions: `pdo_sqlite` (or `pdo_mysql`/`pdo_pgsql`), `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `intl`, `zip` |
| Composer 2 | |
| Node.js 18+ and npm | Builds assets and runs Puppeteer for screenshots |
| Google Chrome or Chromium | Screenshots; found at `/usr/bin/google-chrome-stable`, `/usr/bin/google-chrome`, `/usr/bin/chromium` or `/usr/bin/chromium-browser` |
| `whois` access (port 43 outbound) | Domain expiry checks |
| Supervisor (or systemd) | Keeps the queue worker running |
| Cron | Runs the scheduler |
| Database | SQLite by default; MySQL or PostgreSQL also work |

Outbound HTTPS must reach the monitored sites and, if AI analysis is used, `api.typesafe.ai` and/or `openrouter.ai`.

## Fresh install

Run these as the user that serves the app (e.g. `www-data`), from the directory you deploy to.

```bash
# 1. Get the code
git clone https://github.com/littleboy130491/web-monitoring.git
cd web-monitoring

# 2. PHP and JS dependencies
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 3. Environment
cp .env.example .env
php artisan key:generate
# edit .env now (see "Configure .env" below)

# 4. Database (SQLite shown; for MySQL/PostgreSQL create the database first)
touch database/database.sqlite
php artisan migrate --force

# 5. Storage
php artisan storage:link
chmod -R ug+rwX storage bootstrap/cache

# 6. Admin user
php artisan make:filament-user

# 7. Caches (rerun after every .env change)
php artisan optimize
php artisan filament:optimize
```

Install Chrome if it isn't on the server yet (Ubuntu/Debian):

```bash
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | sudo gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt update && sudo apt install -y google-chrome-stable
```

Point your web server's document root at `public/` and serve it over HTTPS.

## Configure `.env`

### Core

```env
APP_NAME="Web Monitor"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitor.example.com

DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/database/database.sqlite

QUEUE_CONNECTION=database

# Daily files are easier to read and rotate; the MCP get-error-log tool reads all of them
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
```

### Email report

```env
MAIL_MAILER=smtp            # or mailgun, ses, postmark, resend
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="monitor@example.com"

REPORT_RECIPIENT_EMAIL=you@example.com   # empty = no report email
```

### Monitoring

```env
MONITORING_PRUNE_DAYS=30
MONITORING_SCREENSHOT_TIMEOUT=45
MONITORING_JOB_TIMEOUT=180
```

### MCP server (optional)

The local MCP server (`php artisan mcp:start web-monitoring`) needs nothing here. To expose the HTTP endpoint at `POST /mcp`, set a long random token:

```env
MCP_TOKEN=   # php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Leave it empty to keep `/mcp` disabled (it returns 404). The endpoint can read the error log and source code, so only enable it over HTTPS and keep the token secret. See [mcp.md](mcp.md).

### AI analysis (optional)

```env
JEV_API_KEY=            # TypeSafe (Jev) directly
OPENROUTER_API_KEY=     # Jev via OpenRouter + screenshot reading
JEV_MODEL=jev-1.14.0    # pin a version in production instead of jev-latest
AI_NOTIFY_MIN_SEVERITY=high
```

Nothing runs without a key. See [ai-analysis.md](ai-analysis.md) for all settings.

**After any `.env` change**, run `php artisan optimize` again. With cached config, Laravel ignores `.env` until the cache is rebuilt.

## Scheduler and queue worker

Both are required. The scheduler runs monitoring twice a day and pruning daily. The queue worker runs the monitoring, AI analysis and report jobs.

### Cron

```bash
crontab -e -u www-data
```

```cron
* * * * * cd /var/www/web-monitoring && php artisan schedule:run >> /dev/null 2>&1
```

### Supervisor

`/etc/supervisor/conf.d/web-monitor-worker.conf`:

```ini
[program:web-monitor-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/web-monitoring/artisan queue:work --sleep=3 --tries=3 --timeout=240 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/web-monitoring/storage/logs/worker.log
stopwaitsecs=3600
```

`--timeout` must be higher than `MONITORING_JOB_TIMEOUT` (default 180), or long checks with screenshots get killed.

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start web-monitor-worker:*
```

## Updating an existing install

```bash
cd /var/www/web-monitoring
php artisan down

git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

php artisan optimize
php artisan filament:optimize
php artisan queue:restart     # workers reload the new code after their current job

php artisan up
```

If you use an AI client with the local MCP server (Claude Desktop, Claude Code), restart it so it sees new tools.

## Upgrading to the MCP and AI release

This applies when updating an install from before PR #1 (`laravel/mcp`, MCP server, AI analysis). Follow [Updating an existing install](#updating-an-existing-install), and note:

1. **Framework upgrade.** `composer install` upgrades Laravel from 12.21 to 12.70 (needed by `laravel/mcp`). After updating, check that the admin panel loads.
2. **Migration.** `php artisan migrate` adds `ai_severity`, `ai_urgency`, `ai_analysis` and `ai_analyzed_at` to `monitoring_results`. Existing data is untouched.
3. **New `.env` settings.** All optional; copy what you need from `.env.example`:
   - `MCP_TOKEN` to enable the HTTP MCP endpoint
   - `JEV_API_KEY` / `OPENROUTER_API_KEY` to turn on AI analysis, plus the `AI_*`, `JEV_*` and `OPENROUTER_*` settings
4. **Rebuild caches** (`php artisan optimize`) after editing `.env`, then `php artisan queue:restart`.
5. **Optional:** analyze the current results once with `php artisan monitor:analyze`, instead of waiting for the next scheduled check.

## Verify the deployment

```bash
php artisan about                          # environment, cache and driver status
php artisan migrate:status | tail -3       # newest migrations show "Ran"
php artisan schedule:list                  # monitoring, pruning and report-pruning tasks
sudo supervisorctl status                  # workers RUNNING

php artisan monitor:websites --id=1        # one check, printed to the terminal
php artisan monitor:websites --id=1 --screenshot   # confirms Chrome works
```

If AI analysis is configured:

```bash
php artisan monitor:analyze --id=1         # first live call to Jev/OpenRouter
```

Each site prints `[severity, urgency] summary`. An error here usually means a wrong key or model name; see [Troubleshooting](#troubleshooting).

MCP, local:

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"ping"}' | php artisan mcp:start web-monitoring
```

MCP over HTTP (if `MCP_TOKEN` is set):

```bash
curl -s https://monitor.example.com/mcp \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
```

A JSON-RPC result means it works. 401 means the token is wrong; 404 means `MCP_TOKEN` is empty or the config cache is stale.

Finally, log in at `/admin` and open a monitoring result.

## Rolling back

```bash
php artisan down
git log --oneline -5                 # find the commit to return to
git checkout <commit>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
php artisan queue:restart
php artisan up
```

Roll back migrations only if the older code can't run against the newer schema. For the AI release, the extra columns are harmless to older code, so leave them. If you must remove them: `php artisan migrate:rollback --step=1` (this deletes stored AI analyses).

Back up `database/database.sqlite` (or your database) before any rollback.

## Troubleshooting

| Problem | Likely cause and fix |
|---|---|
| `.env` changes have no effect | Config is cached: run `php artisan optimize` |
| Jobs never run; results don't appear | Queue worker isn't running: `sudo supervisorctl status`; check `storage/logs/worker.log` |
| Old behaviour after updating | Workers still run old code: `php artisan queue:restart` |
| Monitoring jobs killed halfway | Worker `--timeout` is below `MONITORING_JOB_TIMEOUT` |
| No screenshots | Chrome missing or not executable by `www-data`; run a check with `--screenshot` and read the log |
| Screenshot images 404 in admin | `php artisan storage:link` not run |
| Scheduled checks don't happen | Cron entry missing or for the wrong user: `php artisan schedule:list` |
| AI analysis does nothing | No `JEV_API_KEY`/`OPENROUTER_API_KEY`, or config cache is stale |
| AI calls fail with 401/403 | Wrong API key |
| AI calls fail with 400/404 | Wrong model name (`JEV_MODEL`, `OPENROUTER_DECISION_MODEL`, `OPENROUTER_VISION_MODEL`) |
| `/mcp` returns 404 | `MCP_TOKEN` empty, or config cached before it was set |
| `/mcp` returns 401 | Token in the client doesn't match `MCP_TOKEN` |
| Errors you can't explain | Ask your AI agent to "check the error log and recommend fixes" (MCP `diagnose-errors`), or read `storage/logs/` |
