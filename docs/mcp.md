# MCP Server

Web Monitor includes an [MCP (Model Context Protocol)](https://modelcontextprotocol.io) server, built with `laravel/mcp`. It lets AI clients such as Claude Desktop and Claude Code check website status and manage monitored websites.

- **Server class**: `app/Mcp/Servers/WebMonitoringServer.php`
- **Registered in**: `routes/ai.php`
- **Tools**: `app/Mcp/Tools/`
- **Tests**: `tests/Feature/WebMonitoringMcpServerTest.php`

## Tools

| Tool | Purpose |
|---|---|
| `get-status-summary` | Counts by status, average response time, and sites needing attention (down, domain or SSL expiring within 14 days, broken assets) |
| `list-websites` | Websites with their latest result; filter by search text, active state or status |
| `get-website-status` | Full latest result for one website, looked up by id or URL |
| `get-monitoring-history` | Recent results with uptime %; filter by website, status or hours back |
| `get-latest-report` | Recent monitoring email reports and their summaries |
| `run-monitoring` | Queues checks for one website or all active ones |
| `analyze-website` | Runs AI analysis (severity, urgency, broken/hacked) on a website's latest result; see [ai-analysis.md](ai-analysis.md) |
| `create-website` | Adds a website to monitor |
| `update-website` | Changes a website; `is_active=false` pauses monitoring |
| `delete-website` | Deletes a website and its monitoring results (marked destructive) |

The server also offers a read-only resource, `monitoring://status/overview`, with the same data as `get-status-summary`.

`run-monitoring` only adds jobs to the queue. The checks run only if a queue worker (`php artisan queue:work`) is running on the same machine as the server.

## Transports

| Transport | How it starts | Auth |
|---|---|---|
| Local (stdio) | `php artisan mcp:start web-monitoring` | None; runs as the local user |
| HTTP | `POST /mcp` | `Authorization: Bearer <MCP_TOKEN>` |

The HTTP endpoint is off (returns 404) until `MCP_TOKEN` is set in `.env`:

```env
MCP_TOKEN=a-long-random-string
```

Generate a token with, for example, `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

## Connecting Claude Desktop

Claude Desktop starts the MCP server itself through a config file.

### 1. Open the config file

In Claude Desktop, go to **Settings → Developer → Edit Config**. That opens the file at:

- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

### 2. Add the server

Pick the option that matches where your monitoring data lives. The server reads whichever database belongs to the copy of the app it runs from.

#### Option A: the app runs on the same computer as Claude Desktop

```json
{
  "mcpServers": {
    "web-monitoring": {
      "command": "php",
      "args": ["/full/path/to/web-monitoring/artisan", "mcp:start", "web-monitoring"]
    }
  }
}
```

- Use absolute paths. Claude Desktop doesn't load your shell `PATH`, so if `php` isn't found, use its full path (`which php` on macOS, `where php` on Windows).
- On Windows, double the backslashes, e.g. `"C:\\Users\\you\\web-monitoring\\artisan"`.
- That copy of the app needs `composer install`, a `.env` file and `php artisan migrate` done first.

#### Option B: the app runs on a remote server, over SSH

This runs the server on the remote machine, so it uses that machine's database:

```json
{
  "mcpServers": {
    "web-monitoring": {
      "command": "ssh",
      "args": ["user@your-server", "php /var/www/web-monitoring/artisan mcp:start web-monitoring"]
    }
  }
}
```

This needs key-based SSH login, because Claude Desktop can't answer a password prompt. Check it first in a terminal: `ssh user@your-server echo ok` should print `ok` without asking for anything.

#### Option C: the app runs on a remote server, over HTTP

Set `MCP_TOKEN` in the server's `.env`. Then connect through the `mcp-remote` bridge, which needs Node.js on your computer:

```json
{
  "mcpServers": {
    "web-monitoring": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote", "https://your-domain.com/mcp",
        "--header", "Authorization:Bearer YOUR_MCP_TOKEN"
      ]
    }
  }
}
```

Claude Desktop's built-in **Connectors → Add custom connector** screen can't send a bearer token, so it can't connect to this endpoint directly.

### 3. Restart and check

Quit Claude Desktop fully and reopen it. "web-monitoring" should appear under the tools icon in the chat box. Try asking "What's the status of my websites?"

## Connecting Claude Code

From the project directory:

```bash
claude mcp add web-monitoring -- php artisan mcp:start web-monitoring
```

Or over HTTP:

```bash
claude mcp add --transport http web-monitoring https://your-domain.com/mcp \
  --header "Authorization: Bearer YOUR_MCP_TOKEN"
```

## Troubleshooting

- **Server doesn't appear in Claude Desktop**: check the logs. On macOS: `~/Library/Logs/Claude/mcp-server-web-monitoring.log`. On Windows: `%APPDATA%\Claude\logs\`.
- **Test the command by hand**: run the command from your config in a terminal. It should wait for input without printing errors (Ctrl+C to exit).
- **`php` or `ssh` not found**: use the full path to the executable in `command`.
- **Empty or wrong data**: the server is reading a different database from the one you expect; see "Pick the option" above.
- **HTTP returns 404**: `MCP_TOKEN` isn't set, or the config is cached; run `php artisan config:clear`.
- **HTTP returns 401**: the token in your client doesn't match `MCP_TOKEN`.
- **`run-monitoring` results never appear**: no queue worker is running on that machine.
