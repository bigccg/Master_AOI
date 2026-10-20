# AOI Inspection System - Offline Mode Guide

This project runs **without an internet connection**: every frontend asset is served locally.
It does need a **MySQL server** (the `db` service in docker-compose, or any MySQL 8 that PHP can reach). There is no SQLite fallback.

## ✅ What's Already Offline

All frontend assets are served locally:
- **Fonts**: Poppins, Inter, Prompt, JetBrains Mono, Noto Sans Thai (WOFF2 format)
- **Icons**: Font Awesome 6 Free (webfonts + CSS)
- **CSS Framework**: Tailwind CSS via local JIT compiler (`assets/js/tailwindcss.js`)
- **Data Grid**: Grid.js v6.0.6 (`assets/js/gridjs.umd.js`, `assets/css/gridjs.min.css`)
- **Custom Styles**: `assets/css/{base,theme,components}/` (one file per component), `assets/css/fonts.css`, `assets/css/fonts-ui.css`
- **Application Logic**: `assets/js/{core,components}/` + `assets/js/main.js` (load order: `includes/assets.php`)

## 🗄️ Database: MySQL only

`db()` in `config/database.php` connects to **MySQL only** (PHP extension `pdo_mysql`):
- Host, port, user and password come from `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` (defaults match `docker-compose.yml`).
- The database name comes from `AOI_DB_NAME` (default `Master_AoI`). `DB_NAME` is ignored on purpose, because docker-compose shares it with the other projects in `./src`.
- On first connect the database is created (`CREATE DATABASE IF NOT EXISTS`, needs the CREATE privilege) and `runMigrations()` creates or updates the tables. Migrations are idempotent and run on every request. No seed data is inserted.
- If MySQL cannot be reached, the page stops with `Database connection failed: ...`. Nothing falls back to SQLite.

The `sqlite` branch inside `runMigrations()` exists **only for automated tests**: a test passes an in-memory SQLite PDO to `runMigrations()` / `saveBoardRecord()` directly. The application itself never opens SQLite and writes nothing to `data/`. Do not remove that branch; the tests use it.

## 🚀 Quick Start

### Docker (recommended)
```bash
cd /path/to/my-php-app
docker compose up -d
# App: http://localhost:8080/aoi-web-php/   phpMyAdmin: http://localhost:8081
```

### PHP built-in server (Linux/macOS)
Needs PHP 8.1+ with `pdo_mysql` and a reachable MySQL, e.g. the Docker `db` service published on port 3306:
```bash
cd /path/to/aoi-web-php
DB_HOST=127.0.0.1 ./start-offline.sh
# Or specify a custom port:
DB_HOST=127.0.0.1 ./start-offline.sh 3000
```

### Windows
```cmd
cd C:\path\to\aoi-web-php
set DB_HOST=127.0.0.1
start-offline.bat
# Or specify a custom port:
start-offline.bat 3000
```

> `start-offline.sh` / `start-offline.bat` still print "Database: SQLite" and refuse to start without `pdo_sqlite`. That text is outdated: the app uses MySQL, so `pdo_mysql` is the extension it actually needs. If `pdo_sqlite` is missing, use the manual command below.

### Manual (Any Platform)
```bash
# PHP 8.1+ with pdo_mysql; MySQL reachable at DB_HOST:DB_PORT
DB_HOST=127.0.0.1 php -S 0.0.0.0:8080 -t . index.php
```

Then open **http://localhost:8080** in your browser.

## 📋 Requirements

- **PHP 8.1+** (8.2/8.3 recommended)
- **MySQL 8** reachable from PHP (docker-compose `db` service, or your own server)
- Required PHP extensions:
  - `pdo`
  - `pdo_mysql`
  - `session`
  - `json`
  - `mbstring`

Check with: `php -m | grep -E "pdo|mysql|session|json|mbstring"`

## 🔧 Configuration

### Environment Variables (Optional)

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `db` | MySQL host. `db` is the docker-compose service name; from the host machine use `127.0.0.1` |
| `DB_PORT` | `3306` | MySQL port |
| `DB_USER` | `root` | MySQL username |
| `DB_PASS` | `rootpassword` | MySQL password |
| `AOI_DB_NAME` | `Master_AoI` | This project's database (created automatically when the user may CREATE) |

`DB_NAME` is not read by this project.

### Another MySQL server
```bash
export DB_HOST=your-mysql-host
export DB_PORT=3306
export DB_USER=your-user
export DB_PASS=your-password
export AOI_DB_NAME=Master_AoI
./start-offline.sh 8080
```

## 📁 Project Structure (Offline Assets)

```
aoi-web-php/
├── index.php                 # Main entry point (requires includes/ + components/)
├── includes/                 # request state, POST actions, page data, CSV export, asset load order
├── components/               # page sections: header, kpi-cards, filters, data-table, rack-map, board-modal/, popups/ ...
├── config/
│   └── database.php          # MySQL connection + idempotent migrations (sqlite branch: automated tests only)
├── api/
│   ├── boards.php            # Data API (GET)
│   └── delete.php            # Delete API (POST)
├── scripts/
│   └── check-duplicate-codes.php  # Read-only CLI report: duplicate Number Codes, zone mismatches, index status
├── tests/
│   └── number-code-test.php  # CLI regression tests on throwaway MySQL databases (see README.md)
├── assets/
│   ├── css/
│   │   ├── base/ theme/ components/   # Custom styles, one file per component
│   │   ├── fonts.css         # @font-face for Inter, JetBrains Mono, Noto Sans Thai
│   │   ├── fonts-ui.css      # @font-face for Poppins, Inter, Prompt (UI fonts)
│   │   ├── fontawesome.min.css  # Font Awesome 6 Free CSS
│   │   └── gridjs.min.css    # Grid.js Mermaid theme
│   ├── js/
│   │   ├── tailwindcss.js    # Tailwind CSS JIT compiler (Play CDN build)
│   │   ├── gridjs.umd.js     # Grid.js v6.0.6 UMD build
│   │   ├── core/ components/ # App logic, one script per component
│   │   └── main.js           # Boots the page (loaded last)
│   ├── webfonts/             # Font Awesome webfonts (woff2, ttf)
│   ├── fonts/                # Inter, JetBrains Mono, Noto Sans Thai (woff)
│   └── fonts/ui/             # Poppins, Inter, Prompt (woff2)
├── start-offline.sh          # Linux/macOS startup script (PHP built-in server)
├── start-offline.bat         # Windows startup script (PHP built-in server)
└── setup.php                 # One-time Grid.js downloader (already run)
```

## 🔄 Docker vs PHP built-in server

Both use the same MySQL database; only the web server differs.

| Feature | Docker (Apache) | PHP built-in server |
|---------|-----------------|---------------------|
| Start | `docker compose up -d` | `DB_HOST=127.0.0.1 ./start-offline.sh` |
| URL | http://localhost:8080/aoi-web-php/ | http://localhost:8080 |
| Database | MySQL (`db` service) | MySQL (`DB_HOST`, e.g. `127.0.0.1`) |
| Concurrency | High | Low (single process) |
| Backup | `mysqldump` | `mysqldump` |

## 📦 Backing Up Data

```bash
# Docker: dump the project database from the host
docker exec php_app_db mysqldump -uroot -prootpassword Master_AoI > backup_$(date +%Y%m%d).sql

# Restore
docker exec -i php_app_db mysql -uroot -prootpassword Master_AoI < backup_YYYYMMDD.sql
```

## 🛠️ Troubleshooting

### "Could not find driver" error
```bash
# Ubuntu/Debian
sudo apt-get install php-mysql

# CentOS/RHEL/Fedora
sudo dnf install php-mysqlnd

# macOS (Homebrew)
brew install php  # includes pdo_mysql
```

### "Database connection failed"
MySQL is not reachable at `DB_HOST:DB_PORT`:
- Docker: run `docker compose up -d db` and wait until the container is healthy.
- From the host machine use `DB_HOST=127.0.0.1`; the name `db` only resolves inside Docker.

### Port already in use
```bash
# Use different port
./start-offline.sh 3000
```

### Blank page / 500 error
Check PHP error log:
```bash
# Run with error display
php -S 0.0.0.0:8080 -t . index.php 2>&1 | tee server.log
```

## 🎯 Verification Checklist

After starting offline mode, verify:
- [ ] Page loads at http://localhost:8080
- [ ] Thai fonts render correctly (Prompt font)
- [ ] Font Awesome icons display (microchip, plus, trash, etc.)
- [ ] Table loads (empty on a new database: no seed data)
- [ ] Search/filter works
- [ ] Add/Edit modal opens
- [ ] Save new record works
- [ ] Delete record works
- [ ] Export CSV works
- [ ] Rack map displays
- [ ] Pagination works
- [ ] Dark mode toggle (if implemented)

## 📝 Notes

1. **First run**: the MySQL database (`Master_AoI` by default) and its tables are created automatically; no seed data
2. **Session storage**: Uses PHP file-based sessions (default) - works offline
3. **CSRF protection**: Enabled by default, works offline
4. **No build step**: All assets are pre-compiled and ready to use
5. **Grid.js**: Already downloaded via `setup.php` (run once during initial setup)

---

**Enjoy your fully offline AOI Inspection System!** 🎉
