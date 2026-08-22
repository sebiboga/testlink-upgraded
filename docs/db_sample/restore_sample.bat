@echo off
REM TestLink v1.9.20 Sample Database Restoration Script (Windows)
REM
REM This script automates the restoration of a complete TestLink database
REM with sample data for development and testing purposes.
REM
REM Usage:
REM   restore_sample.bat [database_name] [username] [host]
REM
REM Examples:
REM   restore_sample.bat testlink root localhost
REM   restore_sample.bat testlink root 192.168.1.10
REM   restore_sample.bat                          (Interactive mode)
REM
REM Requirements:
REM   - MySQL/MariaDB server running and accessible
REM   - mysql.exe and mysqladmin.exe in PATH or MySQL installed with MySQL Shell
REM   - User has CREATE and DROP DATABASE privileges
REM

setlocal enabledelayedexpansion

color 0A
title TestLink Sample Database Restoration

REM Color codes (limited in batch)
REM - Just use echo with some formatting

echo.
echo ============================================================
echo  TestLink v1.9.20 Sample Database Restoration
echo ============================================================
echo.

REM Get the script directory
set SCRIPT_DIR=%~dp0

REM Get database configuration from parameters or prompt user
if "%1"=="" (
    echo Interactive Database Configuration
    echo.
    set /p DB_NAME="Database name [testlink]: " || set DB_NAME=testlink
    set /p DB_USER="Database user [root]: " || set DB_USER=root
    set /p DB_HOST="Database host [localhost]: " || set DB_HOST=localhost
) else (
    set DB_NAME=%1
    set DB_USER=%2
    set DB_HOST=%3
)

echo.
echo Database Configuration:
echo   Database: %DB_NAME%
echo   User: %DB_USER%
echo   Host: %DB_HOST%
echo.

REM Prompt for password
echo Note: Password will not be echoed to screen for security
set /p DB_PASS="MySQL Password: "

REM Test database connection
echo.
echo [*] Testing database connection...
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Failed to connect to MySQL server
    echo Please verify your connection parameters
    pause
    exit /b 1
)
echo [OK] Database connection successful

echo.

REM Check if database exists
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% -e "SHOW DATABASES LIKE '%DB_NAME%';" >nul 2>&1
if errorlevel 0 (
    echo [WARNING] Database '%DB_NAME%' might already exist
    set /p CONFIRM="Drop and recreate? (y/n) [n]: "
    if /i "!CONFIRM!"=="y" (
        echo [*] Dropping existing database...
        mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% -e "DROP DATABASE IF EXISTS `%DB_NAME%`;"
        echo [OK] Database dropped
    ) else (
        echo [WARNING] Aborted by user
        pause
        exit /b 0
    )
)

echo.

REM Create database
echo [*] Creating database...
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% -e "CREATE DATABASE `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo [ERROR] Failed to create database
    pause
    exit /b 1
)
echo [OK] Database created

REM Load schema
echo [*] Loading database schema...
set SCHEMA_FILE=%SCRIPT_DIR%..\..\install\sql\mysql\testlink_create_tables.sql
if not exist "%SCHEMA_FILE%" (
    echo [ERROR] Schema file not found: %SCHEMA_FILE%
    pause
    exit /b 1
)
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% < "%SCHEMA_FILE%"
if errorlevel 1 (
    echo [ERROR] Failed to load schema
    pause
    exit /b 1
)
echo [OK] Schema loaded

REM Load default data
echo [*] Loading default data...
set DEFAULT_DATA_FILE=%SCRIPT_DIR%..\..\install\sql\mysql\testlink_create_default_data.sql
if not exist "%DEFAULT_DATA_FILE%" (
    echo [ERROR] Default data file not found: %DEFAULT_DATA_FILE%
    pause
    exit /b 1
)
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% < "%DEFAULT_DATA_FILE%"
if errorlevel 1 (
    echo [ERROR] Failed to load default data
    pause
    exit /b 1
)
echo [OK] Default data loaded

REM Provision stored function UDFStripHTMLTags() required by advanced search
REM (issue #547) - plain schema+data dumps do not carry stored functions.
echo [*] Provisioning stored function UDFStripHTMLTags...
set UDF_FILE=%SCRIPT_DIR%..\..\install\sql\mysql\testlink_create_udf0.sql
if not exist "%UDF_FILE%" (
    echo [ERROR] UDF file not found: %UDF_FILE%
    pause
    exit /b 1
)
set TMP_UDF_SQL=%TEMP%\tl_udf_%RANDOM%.sql
powershell -NoProfile -Command "(Get-Content -LiteralPath '%UDF_FILE%') -replace 'YOUR_TL_DBNAME','%DB_NAME%' | Set-Content -LiteralPath '%TMP_UDF_SQL%'"
type "%TMP_UDF_SQL%" | mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME%
if errorlevel 1 (
    del "%TMP_UDF_SQL%" >nul 2>&1
    echo [ERROR] Failed to provision stored function UDFStripHTMLTags
    pause
    exit /b 1
)
del "%TMP_UDF_SQL%" >nul 2>&1
echo [OK] Stored function UDFStripHTMLTags provisioned

REM Load sample data
echo [*] Loading sample data...
set SAMPLE_DATA_FILE=%SCRIPT_DIR%testlink_sample_data.sql
if not exist "%SAMPLE_DATA_FILE%" (
    echo [ERROR] Sample data file not found: %SAMPLE_DATA_FILE%
    pause
    exit /b 1
)
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% < "%SAMPLE_DATA_FILE%"
if errorlevel 1 (
    echo [ERROR] Failed to load sample data
    pause
    exit /b 1
)
echo [OK] Sample data loaded

echo.
echo ============================================================
echo [SUCCESS] Sample database restoration complete!
echo ============================================================
echo.
echo Database Information:
echo   Database: %DB_NAME%
echo   Host: %DB_HOST%
echo   User: %DB_USER%
echo.
echo Sample Login Credentials:
echo   Admin:        admin / admin
echo   Manager:      manager / manager
echo   Tester 1:     tester1 / tester1
echo   Tester 2:     tester2 / tester2
echo   Senior:       senior_tester / senior
echo   Designer:     designer / designer
echo   Guest:        guest / guest
echo.
echo Sample Data Included:
echo   - 3 Test Projects
echo   - 3 Test Suites
echo   - 6 Test Cases with detailed steps
echo   - 2 Test Plans
echo   - 2 Builds
echo   - 10+ Test Executions with results
echo   - 7 Sample Users with different roles
echo   - 5 Keywords
echo   - 2 Custom Fields
echo.
echo Next Steps:
echo   1. Update config.inc.php with database connection details
echo   2. Access TestLink at: http://localhost/testlink
echo   3. Login with sample credentials above
echo   4. Explore sample projects and test data
echo.
echo Documentation:
echo   See docs\db_sample\README.md for more information
echo.

pause
