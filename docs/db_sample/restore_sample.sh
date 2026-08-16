#!/bin/bash
#
# TestLink v1.9.20 Sample Database Restoration Script
#
# This script automates the restoration of a complete TestLink database
# with sample data for development and testing purposes.
#
# Usage:
#   ./restore_sample.sh [database_name] [username] [host]
#
# Examples:
#   ./restore_sample.sh testlink root localhost
#   ./restore_sample.sh testlink root 192.168.1.10
#   ./restore_sample.sh                          # Interactive mode
#
# Requirements:
#   - MySQL/MariaDB server running and accessible
#   - mysql and mysqldump commands available
#   - User has CREATE and DROP DATABASE privileges
#

set -e

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to prompt user for input with default value
prompt_input() {
    local prompt="$1"
    local default="$2"
    local input

    if [ -z "$default" ]; then
        read -p "$prompt: " input
    else
        read -p "$prompt [$default]: " input
        input="${input:-$default}"
    fi

    echo "$input"
}

# Check for required commands
print_info "Checking requirements..."

if ! command_exists mysql; then
    print_error "mysql command not found. Please install MySQL client."
    exit 1
fi

if ! command_exists mysqladmin; then
    print_error "mysqladmin command not found. Please install MySQL utilities."
    exit 1
fi

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Get database configuration
if [ $# -eq 3 ]; then
    DB_NAME="$1"
    DB_USER="$2"
    DB_HOST="$3"
else
    print_info "Interactive Database Configuration"
    DB_NAME=$(prompt_input "Database name" "testlink")
    DB_USER=$(prompt_input "Database user" "root")
    DB_HOST=$(prompt_input "Database host" "localhost")
fi

print_info "Database Configuration:"
echo "  Database: $DB_NAME"
echo "  User: $DB_USER"
echo "  Host: $DB_HOST"
echo ""

# Prompt for password
read -sp "MySQL Password: " DB_PASS
echo ""

# Test database connection
print_info "Testing database connection..."
if ! mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" >/dev/null 2>&1; then
    print_error "Failed to connect to MySQL server"
    print_error "Please verify your connection parameters"
    exit 1
fi

print_success "Database connection successful"
echo ""

# Check if database exists
print_info "Checking if database '$DB_NAME' exists..."
DB_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SHOW DATABASES LIKE '$DB_NAME';" | wc -l)

if [ $DB_EXISTS -eq 2 ]; then
    print_warning "Database '$DB_NAME' already exists"
    read -p "Drop and recreate? (y/n) [n]: " -n 1 -r CONFIRM
    echo ""
    if [[ $CONFIRM =~ ^[Yy]$ ]]; then
        print_info "Dropping existing database..."
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;"
        print_success "Database dropped"
    else
        print_warning "Aborted by user"
        exit 0
    fi
fi

echo ""

# Create database
print_info "Creating database..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
print_success "Database created"

# Load schema
print_info "Loading database schema..."
SCHEMA_FILE="$SCRIPT_DIR/../install/sql/mysql/testlink_create_tables.sql"
if [ ! -f "$SCHEMA_FILE" ]; then
    print_error "Schema file not found: $SCHEMA_FILE"
    exit 1
fi

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SCHEMA_FILE"
print_success "Schema loaded"

# Load default data
print_info "Loading default data..."
DEFAULT_DATA_FILE="$SCRIPT_DIR/../install/sql/mysql/testlink_create_default_data.sql"
if [ ! -f "$DEFAULT_DATA_FILE" ]; then
    print_error "Default data file not found: $DEFAULT_DATA_FILE"
    exit 1
fi

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$DEFAULT_DATA_FILE"
print_success "Default data loaded"

# Load sample data
print_info "Loading sample data..."
SAMPLE_DATA_FILE="$SCRIPT_DIR/testlink_sample_data.sql"
if [ ! -f "$SAMPLE_DATA_FILE" ]; then
    print_error "Sample data file not found: $SAMPLE_DATA_FILE"
    exit 1
fi

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SAMPLE_DATA_FILE"
print_success "Sample data loaded"

echo ""
echo "=============================================================="
print_success "Sample database restoration complete!"
echo "=============================================================="
echo ""
echo "Database Information:"
echo "  Database: $DB_NAME"
echo "  Host: $DB_HOST"
echo "  User: $DB_USER"
echo ""
echo "Sample Login Credentials:"
echo "  Admin:        admin / admin"
echo "  Manager:      manager / manager"
echo "  Tester 1:     tester1 / tester1"
echo "  Tester 2:     tester2 / tester2"
echo "  Senior:       senior_tester / senior"
echo "  Designer:     designer / designer"
echo "  Guest:        guest / guest"
echo ""
echo "Sample Data Included:"
echo "  - 3 Test Projects"
echo "  - 3 Test Suites"
echo "  - 6 Test Cases with detailed steps"
echo "  - 2 Test Plans"
echo "  - 2 Builds"
echo "  - 10+ Test Executions with results"
echo "  - 7 Sample Users with different roles"
echo "  - 5 Keywords"
echo "  - 2 Custom Fields"
echo ""
echo "Next Steps:"
echo "  1. Update config.inc.php with database connection details"
echo "  2. Access TestLink at: http://localhost/testlink"
echo "  3. Login with sample credentials above"
echo "  4. Explore sample projects and test data"
echo ""
echo "Documentation:"
echo "  See docs/db_sample/README.txt for more information"
echo ""
