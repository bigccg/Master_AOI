#!/bin/bash
# ==============================================================================
# AOI Inspection System - Offline Mode Startup Script
# ==============================================================================
# This script starts the PHP built-in web server with SQLite database.
# No Docker, MySQL, or external services required!
#
# Usage: ./start-offline.sh [port]
# Default port: 8080
# ==============================================================================

set -e

PORT="${1:-8080}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=============================================================================="
echo "  AOI Inspection & Shelf Management System - OFFLINE MODE"
echo "=============================================================================="
echo ""
echo "Project directory: $PROJECT_DIR"
echo "Port: $PORT"
echo "Database: SQLite (file: data/aoi.sqlite)"
echo ""

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "PHP Version: $PHP_VERSION"

# Check required extensions
REQUIRED_EXTENSIONS=("pdo" "pdo_sqlite" "session" "json" "mbstring")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -qi "^${ext}$"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    echo ""
    echo "❌ Missing required PHP extensions:"
    for ext in "${MISSING_EXTENSIONS[@]}"; do
        echo "   - $ext"
    done
    echo ""
    echo "Please install missing extensions and try again."
    exit 1
fi

echo "✅ All required PHP extensions are available"
echo ""

# Create data directory if it doesn't exist
DATA_DIR="$PROJECT_DIR/data"
if [ ! -d "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR"
    echo "Created data directory: $DATA_DIR"
fi

# Check if SQLite database exists, if not it will be created automatically
DB_FILE="$DATA_DIR/aoi.sqlite"
if [ -f "$DB_FILE" ]; then
    echo "✅ SQLite database found: $DB_FILE"
else
    echo "ℹ️  SQLite database will be created on first run: $DB_FILE"
fi

echo ""
echo "=============================================================================="
echo "  Starting PHP built-in server on http://localhost:$PORT"
echo "  Press Ctrl+C to stop"
echo "=============================================================================="
echo ""

# Start PHP built-in server
# -t sets the document root
# index.php is the default entry point
cd "$PROJECT_DIR"
exec php -S "0.0.0.0:$PORT" -t . index.php