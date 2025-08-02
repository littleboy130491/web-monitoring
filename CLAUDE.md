# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12.x web monitoring application with a complete web interface that checks website status, health, content changes, and SSL certificate information. Features a Filament PHP admin panel for management and a public status page for monitoring display. Built with modern frontend stack using Vite, TailwindCSS v4, and SQLite as the default database.

## Development Commands

### Full Development Environment
```bash
composer dev
```
This runs a complete development stack with:
- PHP development server (`php artisan serve`)
- Queue worker (`php artisan queue:listen --tries=1`)
- Log monitoring (`php artisan pail --timeout=0`)
- Vite frontend build (`npm run dev`)

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

Screenshots are taken using Spatie Browsershot with Puppeteer/Chrome:
- **Requirements**: Node.js, npm, Google Chrome/Chromium, and Puppeteer
- **Storage**: Screenshots saved to `storage/app/public/screenshots/`
- **Naming**: `{website_id}_{timestamp}.png` format
- **Resolution**: 1920x1080 full-page screenshots
- **Timeout**: 60 seconds per screenshot with network idle wait

## Web Interface

### Admin Panel (Filament PHP)
- **URL**: `/admin` 
- **Login**: admin@example.com / password
- **Features**:
  - Website management with full CRUD operations
  - URL validation and custom header configuration
  - Real-time monitoring triggers from admin interface
  - Read-only monitoring results with detailed info lists
  - Advanced filtering and search capabilities
  - Dashboard with overview statistics and trend charts
  - Screenshot integration and viewing
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
- **CSV Import**: Websites can be imported via CSV files in `storage/app/websites.csv`
- **JSON Storage**: Headers stored as JSON strings with copy functionality
- **Read-only Results**: Monitoring results cannot be manually created/edited
- **Screenshot Storage**: Screenshots saved to `storage/app/public/screenshots/`