# TestLink v1.9.20 Sample Database

Complete sample database for development and testing purposes.

## Overview

This directory contains a comprehensive sample database setup for TestLink v1.9.20, including:

- **Pre-populated test database** with realistic sample data
- **Restoration scripts** for automated setup
- **Complete documentation** on usage and data structure
- **Multiple test projects** with varied test scenarios
- **Test execution results** demonstrating workflow
- **Sample user accounts** with different roles and permissions

## Contents

### Files

- `testlink_sample_data.sql` - Sample data SQL script (insert statements)
- `restore_sample.sh` - Automated restoration script (Linux/Mac/WSL)
- `restore_sample.bat` - Automated restoration script (Windows batch)
- `README.md` - This file

## Quick Start

### Method 1: Using Restoration Script (Recommended)

#### Linux/Mac/WSL:

```bash
cd docs/db_sample
chmod +x restore_sample.sh
./restore_sample.sh [database_name] [username] [host]
```

**Examples:**

```bash
# Interactive mode (prompts for configuration)
./restore_sample.sh

# With parameters
./restore_sample.sh testlink root localhost
./restore_sample.sh testlink root 192.168.1.10
```

#### Windows (CMD):

```batch
cd docs\db_sample
restore_sample.bat
```

### Method 2: Manual Import

#### Step 1: Create Database

```bash
mysql -u root -p -e "CREATE DATABASE testlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

#### Step 2: Load Schema

```bash
mysql -u root -p testlink < install/sql/mysql/testlink_create_tables.sql
```

#### Step 3: Load Default Data

```bash
mysql -u root -p testlink < install/sql/mysql/testlink_create_default_data.sql
```

#### Step 4: Load Sample Data

```bash
mysql -u root -p testlink < docs/db_sample/testlink_sample_data.sql
```

### Method 3: Docker Compose

```yaml
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: testlink
    volumes:
      - ./install/sql/mysql/testlink_create_tables.sql:/docker-entrypoint-initdb.d/01-schema.sql
      - ./install/sql/mysql/testlink_create_default_data.sql:/docker-entrypoint-initdb.d/02-default.sql
      - ./docs/db_sample/testlink_sample_data.sql:/docker-entrypoint-initdb.d/03-sample.sql
    ports:
      - "3306:3306"

  testlink:
    image: php:7.4-apache
    depends_on:
      - mysql
    volumes:
      - ./:/var/www/html
    ports:
      - "80:80"
    environment:
      - MYSQL_HOST=mysql
      - MYSQL_USER=root
      - MYSQL_PASSWORD=root
      - MYSQL_DATABASE=testlink
```

## Sample Data Included

### Test Projects

1. **Web Application** (Prefix: WEB)
   - Focus: Web application testing
   - Multiple test suites and cases
   - Test execution results

2. **Mobile Application** (Prefix: MOB)
   - Focus: Mobile app testing
   - Sample test scenarios

3. **API Testing** (Prefix: API)
   - Focus: REST API testing
   - Integration test examples

### Test Structure

#### Authentication Suite (Web App Project)

- **Valid Login with Correct Credentials** - 3 steps
- **Login with Invalid Password** - 3 steps
- **Session Timeout After Inactivity** - 3 steps

#### User Management Suite (Web App Project)

- **Update User Profile Information** - 3 steps
- **Change User Password** - 4 steps

#### Dashboard Suite (Web App Project)

- **Dashboard Loads Successfully** - 3 steps

### Test Execution Results

Sample executions showing:
- **Passed tests** - Successfully completed tests
- **Failed tests** - Tests with issues found
- **Blocked tests** - Tests unable to run
- **Retested cases** - Shows execution history
- **Progress tracking** - Build 1.0 vs Build 1.1 results

### Sample Users

#### Administrator
- **Login:** admin
- **Password:** admin
- **Role:** System Administrator
- **Access:** Full system access

#### Test Manager
- **Login:** manager
- **Password:** manager
- **Role:** Test Manager/Leader
- **Access:** Project management, plan creation

#### Testers
- **Login:** tester1 / tester2
- **Password:** tester1 / tester2
- **Role:** Tester
- **Access:** Test execution, result reporting

#### Senior Tester
- **Login:** senior_tester
- **Password:** senior
- **Role:** Senior Tester
- **Access:** Test execution, senior-level reporting

#### Test Designer
- **Login:** designer
- **Password:** designer
- **Role:** Test Designer
- **Access:** Test case creation and management

#### Guest User
- **Login:** guest
- **Password:** guest
- **Role:** Guest (Limited Access)
- **Access:** View-only access

### Keywords

Sample keywords for test organization:
- `smoke` - Smoke testing
- `regression` - Regression testing
- `critical` - Critical functionality
- `ui` - User interface testing
- `security` - Security testing

### Custom Fields

Defined custom fields for test cases:
- **Priority** - High, Medium, Low
- **Complexity** - Simple, Moderate, Complex

### Test Plans

1. **Web App - Authentication Testing**
   - Focused on authentication module
   - 3 linked test cases
   - 6 test executions across 2 builds

2. **Web App - Full Regression Suite**
   - Complete regression testing
   - 6 linked test cases
   - 10 test executions
   - Mixed results (pass/fail/block)

### Builds

- **Build 1.0** - Initial release candidate
- **Build 1.1** - Release candidate 2 with fixes

## Verification

### Check Database Creation

```sql
mysql -u root -p testlink -e "SHOW TABLES LIMIT 5;"
```

### Check Sample Data

```sql
mysql -u root -p testlink -e "SELECT COUNT(*) as 'Total Test Cases' FROM testcases;
SELECT COUNT(*) as 'Total Test Plans' FROM testplans;
SELECT COUNT(*) as 'Total Users' FROM users;
SELECT COUNT(*) as 'Total Executions' FROM executions;"
```

### Test Login

1. Open TestLink in browser: `http://localhost/testlink`
2. Login with: `admin / admin`
3. Verify you can see sample projects

## Configuration

Update `config.inc.php` with your database details:

```php
$tlCfg->db->dbType = 'mysql';
$tlCfg->db->host = 'localhost';
$tlCfg->db->user = 'testlink_user';
$tlCfg->db->password = 'testlink_password';
$tlCfg->db->name = 'testlink';
$tlCfg->db->port = 3306;
```

## Features Demonstrated

- ✅ **Project Management** - Multiple projects with different types
- ✅ **Test Organization** - Hierarchical test suites and cases
- ✅ **Test Planning** - Plans with linked test cases
- ✅ **Test Execution** - Complete execution workflows
- ✅ **Result Tracking** - Pass/fail/blocked status tracking
- ✅ **User Management** - Multiple roles and permissions
- ✅ **Keywords** - Test case organization and filtering
- ✅ **Custom Fields** - Extended test case metadata
- ✅ **Builds** - Version management and tracking
- ✅ **Multi-user Assignments** - Complex user/project/plan relationships

## Use Cases

### 1. Development & Testing

Perfect for:
- Testing new TestLink features
- API development and testing
- UI component verification
- Database schema changes

### 2. Training & Onboarding

Ideal for:
- New user training
- Demonstrating workflows
- Learning TestLink features
- Practice scenarios

### 3. Demo & Evaluation

Great for:
- Product demonstrations
- Customer evaluation
- Feature showcase
- Capability assessment

### 4. CI/CD Pipeline

Suitable for:
- Automated testing
- Integration testing
- Docker container setup
- Development environment provisioning

## Resetting the Database

### Option 1: Drop and Recreate

```bash
# Drop the database
mysql -u root -p -e "DROP DATABASE testlink;"

# Re-run restoration script
./restore_sample.sh
```

### Option 2: MySQL Commands

```bash
mysql -u root -p testlink -e "

-- Clear all execution data
DELETE FROM executions;

-- Clear all test plans
DELETE FROM testplans;

-- Reset auto-increment counters
ALTER TABLE executions AUTO_INCREMENT = 1;
ALTER TABLE testplans AUTO_INCREMENT = 1;
"
```

## Customization

### Add More Test Cases

Edit `testlink_sample_data.sql` and add INSERT statements:

```sql
INSERT INTO testcases (name, parent_id, order_on_testplan_create, testproject_id, summary)
VALUES ('New Test Case', @suite_id, 0, @project_id, 'Test description');
```

### Add More Users

```sql
INSERT INTO users (login, email, first, last, password, is_active)
VALUES ('newuser', 'user@example.com', 'First', 'Last', PASSWORD('password'), 1);
```

### Add More Executions

```sql
INSERT INTO executions (tcversion_id, tplan_id, build_id, status, execution_ts, tester_id)
VALUES (@tcv_id, @plan_id, @build_id, 'p', NOW(), @user_id);
```

## Troubleshooting

### Issue: "Access denied for user 'root'@'localhost'"

**Solution:** Verify MySQL password is correct

```bash
mysql -u root -p -e "SELECT 1;"
```

### Issue: "Database 'testlink' already exists"

**Solution:** Drop existing database or use different name

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS testlink;"
```

### Issue: "Table prefix issue"

**Solution:** The SQL files use `/*prefix*/` placeholder. If importing fails, ensure your database configuration handles this.

### Issue: "Character set errors"

**Solution:** Ensure database is created with UTF-8:

```bash
CREATE DATABASE testlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Performance Notes

### Database Size

- Uncompressed SQL: ~2.5 MB
- Loaded database: ~8-15 MB (depends on MySQL configuration)
- Import time: ~30-60 seconds (depending on MySQL performance)

### Optimization

For better performance:
1. Disable binary logging during import
2. Increase `max_allowed_packet` if needed
3. Disable foreign key checks during import
4. Add indexes after data load

## Support & Documentation

- **TestLink Wiki:** See project wiki for user documentation
- **Developer Guide:** See docs/development/DEVELOPER_GUIDE.md
- **API Documentation:** See wiki API Documentation page
- **GitHub Issues:** Report bugs or request features
- **GitHub Discussions:** Ask questions and share ideas

## Version Information

- **TestLink Version:** 1.9.20
- **Database Version:** DB 1.9.20
- **MySQL Version:** 5.7.24+ or 8.0+
- **PHP Version:** 7.4+

## License

This sample database is part of TestLink v1.9.20 and is distributed under the same license as TestLink.

---

**Last Updated:** August 2026  
**For:** TestLink v1.9.20
