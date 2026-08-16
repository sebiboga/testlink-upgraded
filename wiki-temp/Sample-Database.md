# TestLink v1.9.20 Sample Database

Complete sample database for development, testing, and training purposes.

## Overview

This guide covers the TestLink sample database - a comprehensive, pre-configured database with realistic test data for immediate use in development and testing scenarios.

**Includes:**
- 3 fully functional test projects
- 3 test suites with 6 test cases
- 2 test plans with linked test cases
- 10+ test execution results
- 7 sample user accounts with different roles
- 5 keywords for test organization
- 2 custom fields
- Complete user assignments and permissions

## Quick Start

### 1. Automated Setup (Recommended)

**Linux/Mac/WSL:**
```bash
cd docs/db_sample
chmod +x restore_sample.sh
./restore_sample.sh
```

**Windows:**
```batch
cd docs\db_sample
restore_sample.bat
```

### 2. Manual Setup

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE testlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Load schema
mysql -u root -p testlink < install/sql/mysql/testlink_create_tables.sql

# 3. Load default data
mysql -u root -p testlink < install/sql/mysql/testlink_create_default_data.sql

# 4. Load sample data
mysql -u root -p testlink < docs/db_sample/testlink_sample_data.sql
```

## Sample Login Credentials

| User | Login | Password | Role |
|------|-------|----------|------|
| System Administrator | admin | admin | Admin |
| Test Manager | manager | manager | Leader |
| Senior Tester | senior_tester | senior | Senior Tester |
| Tester 1 | tester1 | tester1 | Tester |
| Tester 2 | tester2 | tester2 | Tester |
| Test Designer | designer | designer | Test Designer |
| Guest User | guest | guest | Guest |

## Sample Data Structure

### Test Projects

#### 1. Web Application (Prefix: WEB)
A comprehensive project for testing a web application:

**Test Suites:**
- **Authentication**
  - Valid Login with Correct Credentials
  - Login with Invalid Password
  - Session Timeout After Inactivity

- **User Management**
  - Update User Profile Information
  - Change User Password

- **Dashboard**
  - Dashboard Loads Successfully

#### 2. Mobile Application (Prefix: MOB)
Sample project for mobile app testing

#### 3. API Testing (Prefix: API)
Sample project for REST API testing

### Test Execution Results

Shows realistic execution workflows:

**Build 1.0 Results:**
- TC1 (Valid Login): ✅ PASSED
- TC2 (Invalid Password): ❌ FAILED
- TC3 (Session Timeout): 🚫 BLOCKED

**Build 1.1 Results (Retest):**
- TC1 (Valid Login): ✅ PASSED
- TC2 (Invalid Password): ✅ PASSED (bug fixed)
- TC3 (Session Timeout): ✅ PASSED

**Full Regression Suite:**
- Mixed execution results showing comprehensive testing

### Keywords

Test case organization with keywords:
- `smoke` - Smoke testing (critical path)
- `regression` - Regression testing
- `critical` - Critical functionality
- `ui` - User interface testing
- `security` - Security testing

### Custom Fields

Extended metadata for test cases:
- **Priority:** High, Medium, Low
- **Complexity:** Simple, Moderate, Complex

### Builds

Version management example:
- **Build 1.0** - Initial release candidate
- **Build 1.1** - Release candidate with bug fixes

## Use Cases

### Development & Testing
Perfect for:
- Testing new TestLink features
- API development and testing
- Database schema verification
- UI component testing

### Training & Onboarding
Ideal for:
- New user training
- Feature demonstrations
- Workflow learning
- Best practices practice

### Demo & Evaluation
Great for:
- Product demonstrations
- Customer evaluation
- Capability showcase
- Feature comparison

### CI/CD Integration
Suitable for:
- Automated test environments
- Docker container setup
- Development environment provisioning
- Integration testing

## Important Test Cases

### Test Case 1: Valid Login
**Objective:** Verify successful login with correct credentials

**Steps:**
1. Enter username: admin
2. Enter password: admin
3. Click Login button

**Expected:** User redirected to dashboard

**Status:** Can be PASSED or FAILED in different executions

### Test Case 2: Invalid Password
**Objective:** Verify system rejects invalid password

**Steps:**
1. Enter username: admin
2. Enter password: wrongpass
3. Click Login button

**Expected:** Error message: "Invalid credentials"

**Status:** Initially FAILED, then PASSED after bug fix

### Test Case 3: Session Timeout
**Objective:** Verify session timeout after inactivity

**Steps:**
1. Login to application
2. Wait for 30 minutes without activity
3. Try to access dashboard

**Expected:** User redirected to login page

**Status:** Initially BLOCKED, then PASSED

## Database Statistics

| Item | Count |
|------|-------|
| Test Projects | 3 |
| Test Suites | 3 |
| Test Cases | 6 |
| Test Versions | 6 |
| Test Plans | 2 |
| Builds | 2 |
| Test Executions | 10+ |
| Users | 7 |
| Keywords | 5 |
| Custom Fields | 2 |

## Features Demonstrated

✅ **Project Management** - Multiple projects with organization
✅ **Test Hierarchies** - Suites containing test cases
✅ **Test Planning** - Plans with linked test cases
✅ **Test Execution** - Complete execution workflows
✅ **Result Tracking** - Pass/fail/blocked status tracking
✅ **User Roles** - Multiple roles with different permissions
✅ **Keywords** - Test case organization and filtering
✅ **Custom Fields** - Extended test case metadata
✅ **Builds** - Version management and tracking
✅ **User Assignments** - Complex project/plan user relationships

## Files Included

| File | Purpose |
|------|---------|
| testlink_sample_data.sql | SQL INSERT statements for sample data |
| restore_sample.sh | Automated restoration script (Linux/Mac/WSL) |
| restore_sample.bat | Automated restoration script (Windows) |
| README.md | Detailed documentation |

## Verification

### Check Database Creation

```sql
SELECT COUNT(*) as 'Total Test Cases' FROM testcases;
SELECT COUNT(*) as 'Total Test Plans' FROM testplans;
SELECT COUNT(*) as 'Total Users' FROM users;
SELECT COUNT(*) as 'Total Executions' FROM executions;
```

### Test Login

1. Open TestLink: `http://localhost/testlink`
2. Login as: `admin` / `admin`
3. Navigate to Web Application project
4. Explore sample data

## Customization

### Add Custom Test Cases

Edit `testlink_sample_data.sql` and add:

```sql
INSERT INTO testcases (name, parent_id, testproject_id, summary)
VALUES ('My Test Case', @suite_id, @project_id, 'My description');
```

### Add Custom Users

```sql
INSERT INTO users (login, email, first, last, password, is_active)
VALUES ('myuser', 'user@example.com', 'First', 'Last', PASSWORD('pass'), 1);
```

### Add Test Executions

```sql
INSERT INTO executions (tcversion_id, tplan_id, build_id, status, execution_ts, tester_id)
VALUES (@tcv_id, @plan_id, @build_id, 'p', NOW(), @user_id);
```

## Resetting the Database

### Option 1: Drop and Recreate

```bash
mysql -u root -p -e "DROP DATABASE testlink;"
./restore_sample.sh
```

### Option 2: Clear Data Only

```sql
DELETE FROM executions;
DELETE FROM test_plan_test_cases;
DELETE FROM testplans;
ALTER TABLE executions AUTO_INCREMENT = 1;
ALTER TABLE testplans AUTO_INCREMENT = 1;
```

## System Requirements

- **MySQL:** 5.7.24 or later
- **MariaDB:** 10.3 or later
- **PHP:** 7.4 or later
- **Disk Space:** ~50 MB for database
- **Tools:** mysql, mysqladmin (or MySQL Workbench)

## Performance

- **Uncompressed SQL:** ~2.5 MB
- **Loaded Database:** ~8-15 MB
- **Import Time:** ~30-60 seconds
- **Query Performance:** Excellent with sample data size

## Troubleshooting

### Connection Error

Verify MySQL is running and accessible:

```bash
mysql -u root -p -e "SELECT VERSION();"
```

### Database Already Exists

Drop existing database:

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS testlink;"
```

### Character Set Issues

Ensure database is created with UTF-8:

```sql
CREATE DATABASE testlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### File Not Found

Verify scripts are in correct location: `docs/db_sample/`

## Related Documentation

- [[Developer-Guide]] - Development guide and best practices
- [[Test-Execution]] - Execution workflow documentation
- [[Test-Plan-Linking]] - Test plan management details
- [[API-Documentation]] - API reference and examples

## Next Steps

1. **Import the Database** using one of the methods above
2. **Update config.inc.php** with your database credentials
3. **Login to TestLink** with sample credentials
4. **Explore the Sample Projects** to understand TestLink workflow
5. **Read Developer Guide** for development guidance

## Support

- **GitHub Issues:** Report bugs or suggest improvements
- **GitHub Discussions:** Ask questions and share ideas
- **Wiki:** Check other documentation pages
- **Developer Guide:** See development guidelines

---

**Version:** TestLink 1.9.20  
**Database Version:** DB 1.9.20  
**Last Updated:** August 2026
