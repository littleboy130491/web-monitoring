# Web Monitor

A comprehensive Laravel 12.x web monitoring application that tracks website status, performance, SSL certificates, and content changes. Features a professional Filament PHP admin panel and public status page with automated screenshot capture.

## Features

### 🌐 Website Monitoring
- **Status Monitoring**: HTTP status codes (up/down/error/warning)
- **Performance Tracking**: Response time measurement
- **SSL Certificates**: Expiration monitoring and validation
- **Content Changes**: SHA256 hash-based change detection
- **Screenshot Capture**: Full-page visual monitoring with Puppeteer

### 🎛️ Admin Panel (Filament PHP)
- **Professional Interface**: Modern admin panel at `/admin`
- **Website Management**: Full CRUD operations with validation
- **Queue-Based Monitoring**: Background job processing for better performance
- **CSV Import**: Professional import functionality with intelligent defaults
- **Real-time Notifications**: Database notifications for monitoring events
- **Dashboard Widgets**: Statistics and trend charts
- **Mobile Responsive**: Optimized for all devices

### 📊 Public Status Page
- **Clean Interface**: Professional status display at `/` or `/status`
- **Real-time Updates**: Auto-refresh every 30 seconds
- **System Health**: Overall status indicators
- **Response Times**: Latest check information
- **Mobile Friendly**: Responsive design

### 🔄 Automation
- **Background Jobs**: Queue-based monitoring system
- **Scheduled Tasks**: Automatic monitoring twice daily
- **Data Pruning**: Automated cleanup of old monitoring data
- **Docker Ready**: Full Laravel Sail integration

## Installation

### Requirements
- PHP ^8.2
- Laravel 12.x
- Node.js & npm
- SQLite/MySQL/PostgreSQL
- Google Chrome/Chromium (for screenshots)

### Quick Start with Laravel Sail (Recommended)

1. **Clone and Setup**
   ```bash
   git clone <repository-url>
   cd web-monitor
   cp .env.example .env
   ```

2. **Install Dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Start with Laravel Sail**
   ```bash
   ./vendor/bin/sail up -d
   ./vendor/bin/sail artisan key:generate
   ./vendor/bin/sail artisan migrate
   ```

4. **Install Chrome for Screenshots**
   ```bash
   ./vendor/bin/sail exec laravel.test apt update
   ./vendor/bin/sail exec laravel.test apt install -y google-chrome-stable
   ```

5. **Create Admin User**
   ```bash
    ./vendor/bin/sail artisan make:filament-user
   ```
   
6. **Start Development Environment**
   ```bash
    ./vendor/bin/sail composer run dev
   ```

### Traditional Installation

1. **Clone and Setup**
   ```bash
   git clone <repository-url>
   cd web-monitor
   cp .env.example .env
   composer install
   npm install
   ```

2. **Database Setup**
   ```bash
   php artisan key:generate
   php artisan migrate
   ```

3. **Start Development**
   ```bash
   composer run dev
   ```

## Usage

### Admin Panel
1. **Access**: Navigate to `/admin`
2. **Login**: Use your created admin credentials
3. **Add Websites**: Click "Create" or use "Import Websites" for bulk upload
4. **Monitor**: Use individual or bulk monitoring buttons
5. **View Results**: Check monitoring results and screenshots

### CSV Import Format
```csv
url,description,is_active,check_interval,headers
https://example.com,My website,true,3600,
https://google.com,,,1,7200,"{""User-Agent"":""Custom-Bot""}"
```
- **Required**: Only `url` field is mandatory
- **Optional**: All other fields have intelligent defaults

### Command Line Usage

**Monitor Websites**
```bash
# Monitor all active websites
php artisan monitor:websites

# Monitor specific website
php artisan monitor:websites --id=1

# Monitor with screenshots
php artisan monitor:websites --screenshot

# Custom timeout
php artisan monitor:websites --timeout=60
```

**Data Management**
```bash
# Prune old data (30 days default)
php artisan monitor:prune

# Custom retention period
php artisan monitor:prune --days=7

# Dry run to preview
php artisan monitor:prune --dry-run
```

**Import Sample Data**
```bash
php artisan db:seed --class=WebsiteSeeder
```

### Queue Processing
For background monitoring jobs:
```bash
# Development
php artisan queue:work

# Production (with Laravel Sail)
./vendor/bin/sail artisan queue:work --daemon
```

### Scheduled Tasks
The application automatically schedules:
- **Website monitoring**: Twice daily at 12:00 AM and 12:00 PM
- **Data pruning**: Once daily

For production, add this cron job:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Configuration

### Environment Variables
```env
# Queue system (recommended: database)
QUEUE_CONNECTION=database

# Database (SQLite default)
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite

# Screenshot settings
BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome
```

### Admin Panel Access
- **URL**: `/admin`
- **Default Login**: Create via `php artisan tinker`
- **Features**: Website management, monitoring, CSV import, notifications

### Public Status Page
- **URL**: `/` or `/status`
- **Features**: Real-time status, auto-refresh, mobile responsive

## Architecture

- **Backend**: Laravel 12.x with PHP ^8.2
- **Admin Panel**: Filament PHP v3.x
- **Database**: SQLite (default), MySQL/PostgreSQL supported
- **Queue System**: Database-backed for reliability
- **Frontend**: Vite + TailwindCSS v4
- **Screenshots**: Spatie Browsershot + Puppeteer
- **Containerization**: Laravel Sail (Docker)

## Key Directories

```
app/
├── Console/Commands/     # Artisan commands
├── Filament/            # Admin panel resources
├── Jobs/                # Queue jobs
└── Models/              # Eloquent models

resources/views/         # Blade templates
storage/app/public/screenshots/  # Screenshot storage
routes/console.php       # Scheduled tasks
```

## Screenshots

The application captures full-page screenshots (1920x1080) with network idle detection. Screenshots are stored in `storage/app/public/screenshots/` and accessible through the admin panel.


## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and linting
5. Submit a pull request

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).