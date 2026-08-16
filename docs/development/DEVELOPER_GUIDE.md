# TestLink v1.9.20 Developer Guide

## Overview

This guide provides developers with the knowledge needed to understand, extend, and maintain TestLink codebase.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Development Setup](#development-setup)
3. [Code Organization](#code-organization)
4. [Key Components](#key-components)
5. [Common Tasks](#common-tasks)
6. [Best Practices](#best-practices)
7. [Debugging Tips](#debugging-tips)

---

## Architecture Overview

### High-Level Structure

```
testlink/
├── lib/                    # Core library code
│   ├── functions/         # Helper functions and classes
│   ├── api/              # REST and XMLRPC APIs
│   ├── execute/          # Test execution logic
│   ├── testcases/        # Test case management
│   ├── plan/             # Test plan management
│   └── ...
├── gui/                   # User interface
│   ├── templates/        # Smarty templates (tl-classic, dashio themes)
│   └── javascript/       # JavaScript code
├── install/              # Installation utilities
│   └── util/            # sysinfo.php, config tools
├── docs/                 # Documentation
├── config.inc.php        # Main configuration
└── common.php            # Entry point
```

### Key Architectural Patterns

1. **Class-Based Design**: Most functionality is encapsulated in classes (e.g., `testcase.class.php`, `testproject.class.php`)
2. **Database Abstraction**: Database operations go through a `database` class
3. **Template Engine**: Smarty templates for UI rendering
4. **API Layer**: REST and XMLRPC APIs for programmatic access
5. **Configuration-Driven**: Features controlled by config.inc.php settings

---

## Development Setup

### Requirements

- PHP 7.4+
- MySQL 5.7.24+ or MariaDB
- Apache or nginx with mod_rewrite
- Git for version control

### Initial Setup

```bash
# 1. Clone the repository
git clone https://github.com/sebiboga/testlink-upgraded.git
cd testlink-upgraded

# 2. Copy sample config
cp config.inc.php.sample config.inc.php

# 3. Edit config for your environment
# - Set database connection details
# - Set base href
# - Configure features you need

# 4. Set up database
# - Run install/sql/mysql/testlink_create_tables.sql
# - Or use web installer at /install

# 5. Create a development branch
git checkout -b feature/your-feature-name

# 6. Start development!
```

---

## Code Organization

### Directory Structure Explained

#### `lib/` - Core Library

- **`functions/`**: Helper classes and functions
  - `testcase.class.php` - Test case operations
  - `testproject.class.php` - Project operations
  - `database.class.php` - Database abstraction
  - `common.php` - Common functions and initialization
  - `tlSmarty.inc.php` - Smarty template configuration

- **`api/`**: API endpoints
  - `rest/v3/` - REST API v3 (recommended)
  - `xmlrpc/v1/` - XMLRPC API v1 (legacy)

- **`execute/`**: Test execution
  - `execSetResults.php` - Record execution results
  - `execHistory.php` - View execution history
  - `bugAdd.php` - Link bugs to execution

- **`testcases/`**: Test case management
  - `tcEdit.php` - Edit test cases
  - `tcImport.php` - Import test cases
  - `tcSearch.php` - Search test cases

- **`plan/`**: Test plan management
  - `planEdit.php` - Edit test plans
  - `planAddTC.php` - Add test cases to plan
  - `planImport.php` - Import test plans

#### `gui/` - User Interface

- **`templates/`**: Smarty templates
  - `tl-classic/` - Classic theme
  - `dashio/` - Modern dashboard theme

- **`javascript/`**: Client-side code
  - `testlink_library.js` - Common JavaScript functions
  - `test_automation.js` - Automation scripts

---

## Key Components

### Database Layer

All database operations go through the `database` class:

```php
// Example: Selecting data
$sql = "SELECT * FROM users WHERE id = " . intval($user_id);
$result = $db->get_recordset($sql);

// Example: Inserting data
$sql = "INSERT INTO users (name, email) VALUES ('" . 
       $db->prepare_string($name) . "', '" . 
       $db->prepare_string($email) . "')";
$result = $db->exec_query($sql);

// Never use raw MySQL functions!
```

**Key Methods:**
- `get_recordset($sql)` - SELECT returning array
- `exec_query($sql)` - INSERT/UPDATE/DELETE
- `prepare_string($var)` - Escape string values
- `prepare_int($var)` - Ensure integer values

### Class-Based Operations

#### Test Cases

```php
// Create a test case class instance
$tcase_mgr = new testcase($db);

// Create a test case
$result = $tcase_mgr->create([
    'name' => 'Login Test',
    'suite_id' => 5,
    'project_id' => 1,
    'description' => 'Test user login'
]);

// Update a test case
$tcase_mgr->update($testcase_id, [
    'name' => 'Updated Name'
]);

// Get test case
$tc = $tcase_mgr->get_by_id($testcase_id);

// Delete test case
$tcase_mgr->delete($testcase_id);
```

#### Test Projects

```php
// Create a test project class instance
$tproject_mgr = new testproject($db);

// Get all projects
$projects = $tproject_mgr->get_all();

// Get specific project
$project = $tproject_mgr->get_by_id($project_id);

// Create project
$project_id = $tproject_mgr->create([
    'name' => 'New Project',
    'description' => 'Project description'
]);
```

### API Usage

#### REST API v3

```php
// Create a test case via API
$curl = curl_init('https://testlink/api/rest/v3/testcases');
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer YOUR_TOKEN',
    'Content-Type: application/json'
]);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
    'name' => 'Login Test',
    'project_id' => 1,
    'suite_id' => 5
]));
$response = curl_exec($curl);
$result = json_decode($response, true);
```

#### XMLRPC API v1

```php
// Create a test case via XMLRPC
$client = new xmlrpc_client('https://testlink/lib/api/xmlrpc/v1/xmlrpc.php');

$result = $client->send(new xmlrpcmsg('tl.createTestCase', [
    xmlrpc_string('YOUR_TOKEN'),
    xmlrpc_string('TestCaseName'),
    xmlrpc_string('SuiteID')
]));
```

### Template Engine (Smarty)

```smarty
{* Get labels for current language *}
{lang_get var="labels" s="btn_save,btn_cancel"}

{* Conditional display *}
{if $user->is_admin}
  <p>You are an admin</p>
{/if}

{* Loop through array *}
{foreach item=project from=$projects}
  <p>{$project.name}</p>
{/foreach}

{* Include another template *}
{include file="inc_header.tpl"}

{* Escape output *}
<p>{$variable|escape}</p>
```

---

## Common Tasks

### Task: Add a New Feature

1. **Create a branch**:
   ```bash
   git checkout -b feature/my-feature
   ```

2. **Identify affected components**:
   - Will it affect test cases? → Edit `lib/functions/testcase.class.php`
   - Will it affect UI? → Edit templates in `gui/templates/`
   - Will it affect API? → Edit `lib/api/`
   - Will it affect database? → Create migration in `install/sql/`

3. **Implement the feature**:
   - Write code following existing patterns
   - Add locale strings if needed (`locale/en_US/strings.txt`)
   - Update database schema if needed

4. **Test thoroughly**:
   - Test through UI
   - Test via API if applicable
   - Check for errors in browser console and PHP logs

5. **Create a pull request**:
   ```bash
   git push origin feature/my-feature
   gh pr create --title "Feature: My Feature" --body "Description"
   ```

### Task: Add a New Database Column

1. **Create migration file**:
   ```sql
   -- install/sql/migrations/20240816_add_new_column.sql
   ALTER TABLE tcsteps ADD COLUMN my_new_column INT DEFAULT 0;
   ```

2. **Update related class**:
   - If for test cases: Edit `lib/functions/testcase.class.php`
   - Update `get_*()` methods to include new column
   - Update `create()` method to handle new column
   - Update `update()` method to handle new column

3. **Update UI if needed**:
   - Add form field to relevant template
   - Handle in corresponding PHP controller

4. **Update API**:
   - Add field to REST API response
   - Update API documentation

### Task: Fix a Bug

1. **Locate the bug**:
   - Check error logs: `tail -f /var/log/php-error.log`
   - Use sysinfo.php diagnostic tool
   - Check browser console for JavaScript errors

2. **Write a test**:
   - If test exists: update it
   - If test missing: create new test

3. **Fix the bug**:
   - Identify root cause
   - Apply minimal fix (don't refactor)
   - Verify fix doesn't break other tests

4. **Verify fix**:
   - Test the specific scenario
   - Run related tests
   - Check for regressions

---

## Best Practices

### Code Style

1. **Use consistent naming**:
   - Classes: PascalCase (`TestProject`, `TestCase`)
   - Functions: snake_case (`get_test_cases()`)
   - Variables: snake_case (`$test_case_id`)

2. **Database queries**:
   - Always use `prepare_string()` and `prepare_int()`
   - Never concatenate user input directly
   - Use comments to explain complex queries

3. **Error handling**:
   - Check for null/false returns
   - Log errors for debugging
   - Provide user-friendly error messages

### Security

1. **Input validation**:
   - Always validate user input
   - Use `prepare_string()` for database strings
   - Use `prepare_int()` for integer values

2. **Database access**:
   - Use parameterized queries (via prepare methods)
   - Never trust user input
   - Limit query results when possible

3. **Authentication**:
   - Check user permissions before operations
   - Use role-based access control (RBAC)
   - Log security-relevant actions

### Performance

1. **Database queries**:
   - Limit result sets with WHERE clauses
   - Use indexes for frequently queried fields
   - Cache static data when possible

2. **Code**:
   - Avoid N+1 queries
   - Cache computed values
   - Use appropriate data structures

3. **Caching**:
   - Cache template compilation
   - Cache database query results
   - Use browser cache for static assets

---

## Debugging Tips

### Enable Debug Mode

```php
// In config.inc.php
define('DEBUG', 1);
define('TLCFG_DEBUG_LVL', DEBUG);
```

### Use Error Logging

```php
// Log messages
tLog('Message here', 'WARNING');
tLog('Debug info', 'DEBUG');

// Check logs at: ?
// Defined by TLCFG_LOG_PATH in config.inc.php
```

### Browser Developer Tools

1. **Console tab**: Check for JavaScript errors
2. **Network tab**: Check API requests/responses
3. **Storage tab**: Check localStorage/sessionStorage

### Common Issues

| Issue | Solution |
|-------|----------|
| Blank page | Check PHP error log, enable DEBUG mode |
| Database connection error | Verify config.inc.php settings |
| API not working | Check authentication token, review logs |
| UI rendering broken | Check template syntax, verify Smarty cache |
| Test execution failing | Check database, review error logs |

---

## Useful Resources

- **GitHub**: https://github.com/sebiboga/testlink-upgraded
- **Wiki Documentation**: See Wiki for user and admin docs
- **API Documentation**: See Wiki API Documentation page
- **Database Schema**: `install/sql/mysql/testlink_create_tables.sql`
- **Configuration Reference**: `config.inc.php` comments

---

## Getting Help

1. **Check existing issues**: Search GitHub issues
2. **Review similar code**: Find similar features and learn from them
3. **Read tests**: Understanding test files helps understand code intent
4. **Ask in discussions**: Use GitHub Discussions for questions

---

**Last Updated**: August 2026  
**Version**: 1.9.20
