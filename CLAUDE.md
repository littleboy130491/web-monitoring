# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12.x web monitoring application with a complete web interface that checks website status, health, content changes, and SSL certificate information. Features a Filament PHP admin panel for management and a public status page for monitoring display. Built with modern frontend stack using Vite, TailwindCSS v4, and SQLite as the default database.

## Development Commands

### Full Development Environment
```bash
composer dev-simple
```
This runs a stable development stack with:
- PHP development server (`php artisan serve`)
- Vite frontend build (`npm run dev`)

**Alternative (with queue and logs)**:
```bash
composer dev
```
This runs a complete development stack with:
- PHP development server (`php artisan serve`)
- Queue worker (`php artisan queue:listen --tries=1`)
- Vite frontend build (`npm run dev`)

Note: The `composer dev-simple` command is recommended for stable operation, as the full stack can sometimes cause process conflicts.

### Individual Commands
- **Start PHP server**: `php artisan serve`
- **Run tests**: `composer test` (clears config and runs PHPUnit)
- **Build frontend assets**: `npm run build`
- **Development frontend**: `npm run dev`
- **Code formatting**: `vendor/bin/pint` (Laravel Pint)
- **Queue worker**: `php artisan queue:listen`
- **Database migrations**: `php artisan migrate`
- **Generate app key**: `php artisan key:generate`

### Web Monitoring Commands
- **Monitor all active websites**: `php artisan monitor:websites`
- **Monitor specific website**: `php artisan monitor:websites --id=1`
- **Monitor with screenshots**: `php artisan monitor:websites --screenshot`
- **Custom timeout**: `php artisan monitor:websites --timeout=60`
- **Seed sample websites**: `php artisan db:seed --class=WebsiteSeeder`
- **Create admin user**: Use tinker to create users for admin panel access

### Scheduled Tasks
- **Website Monitoring**: Automatically runs twice daily at 12:00 AM and 12:00 PM
- **Data Pruning**: Automatically runs daily to clean old monitoring data
- **Run scheduler**: `php artisan schedule:work` (for development) or configure cron job for production

### Data Management Commands
- **Prune old data (30 days)**: `php artisan monitor:prune`
- **Prune with custom retention**: `php artisan monitor:prune --days=7`
- **Dry run (preview only)**: `php artisan monitor:prune --dry-run`
- **Keep screenshots**: `php artisan monitor:prune --keep-screenshots`
- **Combined options**: `php artisan monitor:prune --days=14 --dry-run`

### Testing
- Run all tests: `composer test`
- Run specific test suite: `php artisan test --testsuite=Feature` or `--testsuite=Unit`
- Tests use SQLite in-memory database for speed
- PHPUnit configuration in `phpunit.xml`

## Architecture

### Backend (Laravel)
- **Framework**: Laravel 12.x with PHP ^8.2
- **Database**: SQLite (default), with support for MySQL/PostgreSQL
- **Queue**: Database-backed queue system
- **Authentication**: Standard Laravel auth with User model
- **Caching**: Database-backed cache
- **Logging**: Stack-based logging with Laravel Pail for monitoring

### Frontend
- **Build Tool**: Vite 7.x with Laravel plugin
- **CSS Framework**: TailwindCSS v4 with Vite plugin
- **JavaScript**: Modern ES modules
- **Entry Points**: `resources/css/app.css`, `resources/js/app.js`

### Key Directories
- `app/Http/Controllers/` - HTTP controllers (StatusController for public pages)
- `app/Models/` - Eloquent models (Website, MonitoringResult, User)
- `app/Console/Commands/` - Artisan commands including MonitorWebsites
- `app/Filament/Resources/` - Filament admin panel resources
- `app/Filament/Widgets/` - Dashboard widgets for statistics and charts
- `routes/web.php` - Web routes definition
- `resources/views/` - Blade templates including status page
- `resources/css/` and `resources/js/` - Frontend assets
- `database/migrations/` - Database schema migrations
- `database/seeders/` - Database seeders including WebsiteSeeder
- `tests/Feature/` and `tests/Unit/` - Test suites

### Web Monitoring Models
- **Website**: Stores website configuration (URL, name, check intervals, headers)
- **MonitoringResult**: Stores monitoring results (status, response time, SSL info, content changes)
- **User**: Authentication for admin panel access

## Environment Setup

1. Copy `.env.example` to `.env`
2. Generate application key: `php artisan key:generate`
3. SQLite database file is auto-created at `database/database.sqlite`
4. Run migrations: `php artisan migrate`
5. Install dependencies: `composer install && npm install`
6. Install Puppeteer for screenshots: `npm install puppeteer`

## Development Workflow

The project is optimized for concurrent development with the `composer dev` command that runs all necessary services simultaneously. The setup includes:
- Hot module replacement via Vite
- Real-time log monitoring with Pail
- Background queue processing
- Automatic server restart on code changes

Frontend assets are processed through Vite with TailwindCSS v4, providing modern CSS features and fast build times.

## Screenshot Functionality

Screenshots are taken using Spatie Browsershot with Puppeteer/Chrome via queue jobs:
- **Requirements**: Node.js, npm, Google Chrome, and Puppeteer (pre-installed in Laravel Sail)
- **Docker compatibility**: Configured with `--no-sandbox`, `--disable-dev-shm-usage`, `--disable-gpu` flags
- **Smart capture**: Only takes screenshots when website status is "up" (saves resources)
- **Storage**: Screenshots saved to `storage/app/public/screenshots/`
- **Naming**: `{website_id}_{timestamp}.png` format
- **Resolution**: 800x600 default screenshots
- **Chrome path**: Uses system Chrome at `/usr/bin/google-chrome`
- **Queue-based**: Background processing prevents UI blocking

## Queue System & Notifications

### Background Job Processing
- **MonitorWebsiteJob**: Handles individual website monitoring via Laravel queues
- **Database queue**: Uses `QUEUE_CONNECTION=database` for reliable job processing
- **Performance optimized**: Non-blocking UI, scalable processing, automatic retries
- **Error handling**: Comprehensive logging and graceful failure handling

### Real-time Notifications
- **Database notifications**: Persistent notifications stored in `notifications` table
- **Status change alerts**: Notifies when websites go up/down/error/warning
- **Error notifications**: Immediate alerts for monitoring failures with error details
- **Content change detection**: Alerts when website content changes (SHA256 hash comparison)
- **Screenshot confirmations**: Success notifications when screenshots are captured
- **User targeting**: Notifications sent to all admin users
- **Color coding**: Success (green), danger (red), info (blue), warning (yellow)
- **Rich icons**: Contextual Heroicons for each notification type

## Web Interface

### Admin Panel (Filament PHP)
- **URL**: `/admin` 
- **Login**: admin@example.com / password
- **Features**:
  - Website management with full CRUD operations
  - URL validation and custom header configuration
  - **Queue-based monitoring system**: Individual and bulk monitoring via background jobs
  - **Header actions**: "Monitor All" and "Monitor All (+ Screenshots)" buttons
  - **Individual monitoring**: "Monitor" and "Monitor + Screenshot" buttons per website
  - Real-time Filament notifications for monitoring events
  - Database notifications for status changes, errors, content changes, and screenshots
  - Read-only monitoring results with detailed info lists
  - Advanced filtering and search capabilities
  - Dashboard with overview statistics and trend charts
  - Screenshot integration and viewing (Docker-compatible)
  - Copy functionality for debugging data
  - Color-coded status indicators throughout
  - Mobile-responsive admin interface

### Public Status Page
- **URL**: `/` or `/status`
- **Features**:
  - Clean, professional status page design
  - Real-time service status display with color indicators
  - Overall system health summary
  - Response time and last check information
  - Auto-refresh every 30 seconds
  - Mobile-responsive design
  - Direct link to admin panel

### Data Management
- **Filament CSV Importer**: Professional CSV import functionality via admin panel
  - **Access**: Available through "Import Websites" button in admin panel header
  - **Required field**: `url` (only mandatory field with validation)
  - **Optional fields with intelligent defaults**:
    - `name`: Auto-generated from URL hostname if empty
    - `description`: Optional text description
    - `is_active`: Defaults to `true` using Filament's `castStateUsing()`
    - `check_interval`: Defaults to `3600` seconds (1 hour) using casting
    - `headers`: Optional JSON for custom HTTP headers
  - **CSV Format**: `url,name,description,is_active,check_interval,headers`
  - **Example**: `https://example.com,,My website,,` (only URL required)
  - **Duplicate Prevention**: Uses `firstOrNew()` to prevent duplicate URLs
  - **Progress Tracking**: Built-in import progress and completion notifications
- **Legacy CSV Seeder**: Original CSV import via `storage/app/websites.csv` still available
- **JSON Storage**: Headers stored as JSON strings with copy functionality
- **Read-only Results**: Monitoring results cannot be manually created/edited
- **Screenshot Storage**: Screenshots saved to `storage/app/public/screenshots/`