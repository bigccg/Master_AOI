@echo off
REM ==============================================================================
REM AOI Inspection System - Offline Mode Startup Script (Windows)
REM ==============================================================================
REM This script starts the PHP built-in web server with SQLite database.
REM No Docker, MySQL, or external services required!
REM
REM Usage: start-offline.bat [port]
REM Default port: 8080
REM ==============================================================================

setlocal enabledelayedexpansion

set PORT=%~1
if "%PORT%"=="" set PORT=8080

set PROJECT_DIR=%~dp0
set PROJECT_DIR=%PROJECT_DIR:~0,-1%

echo ==============================================================================
echo   AOI Inspection & Shelf Management System - OFFLINE MODE
echo ==============================================================================
echo.
echo Project directory: %PROJECT_DIR%
echo Port: %PORT%
echo Database: SQLite (file: data\aoi.sqlite)
echo.

REM Check PHP version
for /f "tokens=*" %%i in ('php -r "echo PHP_VERSION;"') do set PHP_VERSION=%%i
echo PHP Version: %PHP_VERSION%

REM Check required extensions
set MISSING=0
for %%e in (pdo pdo_sqlite session json mbstring) do (
    php -m | find /i "%%e" >nul
    if errorlevel 1 (
        echo ❌ Missing required PHP extension: %%e
        set MISSING=1
    )
)

if %MISSING%==1 (
    echo.
    echo Please install missing extensions and try again.
    pause
    exit /b 1
)

echo ✅ All required PHP extensions are available
echo.

REM Create data directory if it doesn't exist
set DATA_DIR=%PROJECT_DIR%\data
if not exist "%DATA_DIR%" (
    mkdir "%DATA_DIR%"
    echo Created data directory: %DATA_DIR%
)

REM Check if SQLite database exists
set DB_FILE=%DATA_DIR%\aoi.sqlite
if exist "%DB_FILE%" (
    echo ✅ SQLite database found: %DB_FILE%
) else (
    echo ℹ️  SQLite database will be created on first run: %DB_FILE%
)

echo.
echo ==============================================================================
echo   Starting PHP built-in server on http://localhost:%PORT%
echo   Press Ctrl+C to stop
echo ==============================================================================
echo.

cd /d "%PROJECT_DIR%"
php -S 0.0.0.0:%PORT% -t . index.php