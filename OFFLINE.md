# AOI Inspection System - Offline Mode Guide

This project is designed to run **100% offline** - no internet connection, Docker, or external services required!

## ✅ What's Already Offline

All frontend assets are served locally:
- **Fonts**: Poppins, Inter, Prompt, JetBrains Mono, Noto Sans Thai (WOFF2 format)
- **Icons**: Font Awesome 6 Free (webfonts + CSS)
- **CSS Framework**: Tailwind CSS via local JIT compiler (`assets/js/tailwindcss.js`)
- **Data Grid**: Grid.js v6.0.6 (`assets/js/gridjs.umd.js`, `assets/css/gridjs.min.css`)
- **Custom Styles**: `assets/css/style.css`, `assets/css/fonts.css`, `assets/css/fonts-ui.css`
- **Application Logic**: `assets/js/app.js`

## 🗄️ Database: SQLite (Offline) / MySQL (Production)

The system automatically detects the environment:
- **MySQL**: Used when `DB_HOST` environment variable is set (Docker/production)
- **SQLite**: Automatic fallback for offline/development mode (zero-config)

The SQLite database file is created at `data/aoi.sqlite` on first run.

## 🚀 Quick Start (Offline Mode)

### Linux/macOS
```bash
cd /path/to/aoi-web-php
./start-offline.sh
# Or specify a custom port:
./start-offline.sh 3000
```

### Windows
```cmd
cd C:\path\to\aoi-web-php
start-offline.bat
# Or specify a custom port:
start-offline.bat 3000
```

### Manual (Any Platform)
```bash
# Ensure you have PHP 8.1+ with pdo_sqlite extension
php -S 0.0.0.0:8080 -t . index.php
```

Then open **http://localhost:8080** in your browser.

## 📋 Requirements

- **PHP 8.1+** (8.2/8.3 recommended)
- Required PHP extensions (usually built-in):
  - `pdo`
  - `pdo_sqlite`
  - `session`
  - `json`
  - `mbstring`

Check with: `php -m | grep -E "pdo|sqlite|session|json|mbstring"`

## 🔧 Configuration

### Environment Variables (Optional)

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | (unset) | MySQL host. If set, uses MySQL instead of SQLite |
| `DB_NAME` | `smt_machine_request` | MySQL database name |
| `DB_USER` | `root` | MySQL username |
| `DB_PASS` | `rootpassword` | MySQL password |
| `DB_PORT` | `3306` | MySQL port |

### For Production (MySQL)
```bash
export DB_HOST=your-mysql-host
export DB_NAME=your-database
export DB_USER=your-user
export DB_PASS=your-password
./start-offline.sh 8080
```

## 📁 Project Structure (Offline Assets)

```
aoi-web-php/
├── index.php                 # Main entry point
├── config/
│   └── database.php          # DB config (MySQL + SQLite auto-fallback)
├── api/
│   ├── boards.php            # Data API (GET) - works with both DBs
│   └── delete.php            # Delete API (POST) - works with both DBs
├── assets/
│   ├── css/
│   │   ├── style.css         # Custom styles
│   │   ├── fonts.css         # @font-face for Inter, JetBrains Mono, Noto Sans Thai
│   │   ├── fonts-ui.css      # @font-face for Poppins, Inter, Prompt (UI fonts)
│   │   ├── fontawesome.min.css  # Font Awesome 6 Free CSS
│   │   └── gridjs.min.css    # Grid.js Mermaid theme
│   ├── js/
│   │   ├── tailwindcss.js    # Tailwind CSS JIT compiler (Play CDN build)
│   │   ├── gridjs.umd.js     # Grid.js v6.0.6 UMD build
│   │   └── app.js            # Custom table logic
│   ├── webfonts/             # Font Awesome webfonts (woff2, ttf)
│   ├── fonts/                # Inter, JetBrains Mono, Noto Sans Thai (woff)
│   └── fonts/ui/             # Poppins, Inter, Prompt (woff2)
├── data/                     # SQLite database (auto-created)
│   └── aoi.sqlite
├── start-offline.sh          # Linux/macOS startup script
├── start-offline.bat         # Windows startup script
└── setup.php                 # One-time Grid.js downloader (already run)
```

## 🔄 Switching Between Modes

### Offline → Online (MySQL)
```bash
# Set MySQL environment variables
export DB_HOST=db
export DB_NAME=smt_machine_request
export DB_USER=root
export DB_PASS=rootpassword

# Restart server
./start-offline.sh
```

### Online → Offline
```bash
# Unset MySQL variables
unset DB_HOST

# Restart server (will use SQLite)
./start-offline.sh
```

## 🐳 Docker vs Offline

| Feature | Docker (MySQL) | Offline (SQLite) |
|---------|----------------|------------------|
| Setup time | ~30 sec | ~2 sec |
| Persistence | Docker volume | Local file `data/aoi.sqlite` |
| Concurrency | High | Low-Medium (WAL mode enabled) |
| Backup | `mysqldump` | Copy `data/aoi.sqlite` |
| Portability | Requires Docker | Single PHP binary |

## 📦 Backing Up Data (Offline Mode)

```bash
# Simple file copy
cp data/aoi.sqlite data/aoi.sqlite.backup.$(date +%Y%m%d)

# Or export to SQL
sqlite3 data/aoi.sqlite .dump > backup.sql
```

## 🛠️ Troubleshooting

### "Could not find driver" error
```bash
# Ubuntu/Debian
sudo apt-get install php-sqlite3 php-pdo

# CentOS/RHEL/Fedora
sudo dnf install php-pdo php-sqlite3

# macOS (Homebrew)
brew install php  # includes sqlite3
```

### "Permission denied" on SQLite file
```bash
chmod 755 data/
chmod 644 data/aoi.sqlite
# Or run server as same user who created the file
```

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
- [ ] Table loads with seed data (22 rows)
- [ ] Search/filter works
- [ ] Add/Edit modal opens
- [ ] Save new record works
- [ ] Delete record works
- [ ] Export CSV works
- [ ] Rack map displays
- [ ] Pagination works
- [ ] Dark mode toggle (if implemented)

## 📝 Notes

1. **First run**: SQLite database and tables are created automatically with seed data
2. **Session storage**: Uses PHP file-based sessions (default) - works offline
3. **CSRF protection**: Enabled by default, works offline
4. **No build step**: All assets are pre-compiled and ready to use
5. **Grid.js**: Already downloaded via `setup.php` (run once during initial setup)

---

**Enjoy your fully offline AOI Inspection System!** 🎉