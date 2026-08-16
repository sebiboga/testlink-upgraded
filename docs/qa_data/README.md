# TestLink v1.9.20 - QA Test Data & Sample Datasets

Comprehensive QA test datasets for development, testing, and demonstrations.

## Overview

This directory contains multiple pre-configured test datasets representing different scenarios:

- **Enterprise Crew** - Multi-team enterprise project with complex hierarchies
- **Formula One** - Comprehensive project with realistic test structure
- **Simple Projects** - Minimal configurations for quick setup
- **XML Examples** - Various complexity levels for import testing

## Available Datasets

### Enterprise Crew Project

**File:** `enterprise_crew.sql`

**Contents:**
- 3 test projects
- 8 users with different roles (admin, manager, testers, designers)
- Complex test hierarchies
- 20+ test cases with steps
- Multiple builds and platforms
- Test execution history
- Custom field configurations
- Realistic enterprise structure

**Use Cases:**
- Testing multi-project scenarios
- User role and permission testing
- Complex workflow validation
- Enterprise feature demonstrations
- Performance testing baseline

**Size:** ~500KB

### Formula One Project

**Files:** 
- `formula_one.sql` - Original format
- `formula_one_mysql.sql` - MySQL compatibility version

**Contents:**
- Complete Formula One testing project
- 5 test suites (Team, Driver, Car, Race, Results)
- 50+ test cases with detailed steps
- Multiple test plans
- Complete execution results
- Platform configurations (Windows, Linux, macOS, Browsers)
- Custom fields (Priority, Complexity, Category)
- Keywords (smoke, regression, critical, api, ui)
- Bug associations and traceability
- Version control across multiple builds

**Test Suites:**
1. **Team Management**
   - Add/Edit/Delete teams
   - Team member management
   - Team statistics

2. **Driver Management**
   - Driver registration
   - Contract management
   - Performance tracking

3. **Car Configuration**
   - Chassis setup
   - Engine configuration
   - Tire selection

4. **Race Execution**
   - Pre-race checks
   - Race simulation
   - Results recording

5. **Results & Analytics**
   - Standings calculation
   - Statistics analysis
   - Performance reports

**Use Cases:**
- Comprehensive feature demonstrations
- Full workflow testing
- Report generation testing
- Performance and load testing
- Training and onboarding
- UI/UX validation

**Size:** ~2MB

### Simple Projects

#### Simple with Platforms
**Directory:** `simple_with_platforms/`

**Contents:**
- 1 test project
- 2 test suites
- 10 test cases
- 3 platforms (Windows, Linux, macOS)
- 2 test plans
- Basic execution results
- Simple custom fields

**Use Cases:**
- Quick setup for basic testing
- Platform configuration examples
- Entry-level demonstrations

**Size:** ~100KB

#### Simple without Platforms
**Directory:** `simple_no_platforms/`

**Contents:**
- 1 test project
- 1 test suite
- 5 test cases
- No platform dependencies
- Basic test structure
- Minimal configuration

**Use Cases:**
- Simplest possible setup
- Minimal environment testing
- Docker quick-start
- Learning and training

**Size:** ~50KB

## XML Test Data Examples

### Empty Suites Example
**File:** `t1_empty_suites.xml`

Test project with empty suites (no test cases)

**Use:** Testing suite-only import functionality

### Suites with Test Cases (No Steps)
**File:** `t2_suites_tcase_no_steps.xml`

Test suites with test cases but no detailed steps

**Use:** Testing basic test case structure

### 2 Suites, 16 Test Cases
**File:** `testproject-2ts-16tc.xml`

Simple project with 2 test suites and 16 test cases

**Contents:**
- Suite 1: 8 test cases
- Suite 2: 8 test cases
- Basic steps for each case
- Keywords assigned

**Use:** Medium complexity import testing

### 35 Test Cases
**File:** `testproject-35-testcases.xml`

Comprehensive single suite with 35 test cases

**Contents:**
- Multiple test categories
- Varying complexity levels
- Custom fields
- Keywords
- Version information

**Use:** Large dataset import testing

## Import Methods

### Method 1: SQL Import (Fastest)

**For Enterprise/Formula One datasets:**

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE testlink;"

# Load schema
mysql -u root -p testlink < install/sql/mysql/testlink_create_tables.sql

# Load default data
mysql -u root -p testlink < install/sql/mysql/testlink_create_default_data.sql

# Load QA dataset
mysql -u root -p testlink < docs/qa_data/formula_one_mysql.sql
```

**Advantages:**
- Fastest import
- Complete with execution data
- All relationships preserved
- Immediate availability

### Method 2: XML Import

**For individual XML files:**

1. Navigate: Project → Test Specification → Import
2. Select: `testproject-35-testcases.xml`
3. Configure: Field mapping
4. Execute: Import

**Advantages:**
- Selective import
- Merge with existing data
- Test import functionality
- Validation testing

### Method 3: Docker Compose

**Quick setup with pre-loaded data:**

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
      - ./docs/qa_data/formula_one_mysql.sql:/docker-entrypoint-initdb.d/03-data.sql
    ports:
      - "3306:3306"
```

## Dataset Comparison

| Feature | Enterprise | Formula One | Simple+ | Simple |
|---------|-----------|------------|---------|--------|
| Projects | 3 | 1 | 1 | 1 |
| Users | 8 | 5 | 3 | 2 |
| Suites | 12 | 5 | 2 | 1 |
| Test Cases | 20+ | 50+ | 10 | 5 |
| Builds | 3 | 5 | 2 | 1 |
| Executions | Yes | Yes | Yes | No |
| Platforms | 3 | 4 | 3 | 0 |
| Custom Fields | Yes | Yes | Yes | No |
| Complexity | High | Very High | Medium | Low |
| Setup Time | 5 min | 5 min | 2 min | 1 min |

## Use Cases

### Development & Testing

**Choose:** Formula One Dataset

**Rationale:**
- Comprehensive test structure
- All features covered
- Real-world complexity
- Complete execution data

### Training & Onboarding

**Choose:** Simple with Platforms

**Rationale:**
- Easy to understand
- Quick setup
- All main features present
- Not overwhelming

### Enterprise Demonstrations

**Choose:** Enterprise Crew Dataset

**Rationale:**
- Multiple projects
- Complex hierarchies
- User role variations
- Team collaboration examples

### Performance Testing

**Choose:** Formula One Dataset (scaled)

**Rationale:**
- Sufficient data volume
- Realistic structure
- Test execution data
- Good baseline

### Import/Export Testing

**Choose:** XML Examples

**Rationale:**
- Various complexity levels
- Format validation
- Import workflow testing
- Error scenario testing

## Database Credentials (Sample Data)

### Default Admin Account
- Username: `admin`
- Password: `admin`
- Role: System Administrator

### Sample Users
- **manager** / manager - Test Manager
- **tester1** / tester1 - Tester
- **tester2** / tester2 - Tester
- **senior** / senior - Senior Tester
- **designer** / designer - Test Designer

## Data Validation

### Before Import

**Check:**
1. Database schema is created
2. File is readable
3. SQL syntax is valid (for .sql files)
4. XML is well-formed (for .xml files)
5. Character encoding is UTF-8

**Validate SQL:**
```bash
mysql -u root -p --execute="SELECT COUNT(*) FROM testcases;" testlink
```

**Validate XML:**
```bash
xmllint --noout testproject-35-testcases.xml
```

### After Import

**Verify:**
1. All tables populated
2. User accounts exist
3. Projects visible
4. Test cases linked
5. Execution data present

**Count records:**
```sql
SELECT 'Projects' as entity, COUNT(*) FROM testprojects
UNION
SELECT 'Test Cases', COUNT(*) FROM testcases
UNION
SELECT 'Users', COUNT(*) FROM users
UNION
SELECT 'Executions', COUNT(*) FROM executions;
```

## Troubleshooting

### SQL Import Fails

**Issue:** "Access denied" error

**Solution:**
- Verify MySQL user has CREATE privilege
- Check database exists
- Ensure schema is loaded first

**Issue:** "Syntax error" in SQL

**Solution:**
- Check MySQL version compatibility
- Use correct .sql file for your MySQL version
- Validate file encoding

### XML Import Fails

**Issue:** "XML Parse Error"

**Solution:**
- Validate XML syntax with xmllint
- Check character encoding (must be UTF-8)
- Ensure file is not corrupted

**Issue:** "Reference Error" during import

**Solution:**
- Verify projects/suites exist
- Check custom field definitions
- Ensure keywords are defined

## Performance Notes

### Import Time

- **Simple Dataset:** 30 seconds
- **Enterprise Dataset:** 2-3 minutes
- **Formula One Dataset:** 3-5 minutes
- **XML Import:** Varies by file size

### Database Size

- **Simple:** ~20MB
- **Enterprise:** ~100MB
- **Formula One:** ~150MB

### Optimization Tips

1. **Disable indexing** during large import
2. **Increase timeout** for slow connections
3. **Split large files** into smaller batches
4. **Check server resources** (RAM, CPU)

## Related Resources

- [[Sample-Database]] - Basic sample database
- [[File-Import-Export]] - Import/export documentation
- [[Developer-Guide]] - Development guide
- [[API-Documentation]] - API reference

## Quick Start

**1. Choose Dataset**
```bash
# For Formula One (recommended for most uses)
docs/qa_data/formula_one_mysql.sql
```

**2. Create Database**
```bash
mysql -u root -p -e "CREATE DATABASE testlink;"
```

**3. Load Schema**
```bash
mysql -u root -p testlink < install/sql/mysql/testlink_create_tables.sql
mysql -u root -p testlink < install/sql/mysql/testlink_create_default_data.sql
```

**4. Load Data**
```bash
mysql -u root -p testlink < docs/qa_data/formula_one_mysql.sql
```

**5. Access TestLink**
```
http://localhost/testlink
Login: admin / admin
```

## Dataset License

All datasets are provided as-is for testing and evaluation purposes.

---

**Version:** TestLink 1.9.20  
**Last Updated:** August 2026

## File Listing

- `enterprise_crew.sql` - Enterprise project dataset
- `formula_one.sql` - Formula One dataset (original)
- `formula_one_mysql.sql` - Formula One dataset (MySQL optimized)
- `simple_with_platforms/` - Simple project with platforms
- `simple_no_platforms/` - Simple project without platforms
- `t1_empty_suites.xml` - Empty suites example
- `t2_suites_tcase_no_steps.xml` - Suites without steps example
- `testproject-2ts-16tc.xml` - 2 suites, 16 test cases example
- `testproject-35-testcases.xml` - 35 test cases example
- `README.md` - This file
