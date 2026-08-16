# TestLink v1.9.20 - File Import/Export Format Examples

Complete documentation and examples for TestLink file import/export functionality.

## Overview

This directory contains real-world examples of all file formats used by TestLink for importing and exporting data:

- **Custom Fields** - Field definitions and configurations
- **Keywords** - Test case organization tags
- **Platforms** - Testing environments and configurations
- **Requirements** - Requirement specifications and traceability
- **Test Plans** - Test plan definitions and structure
- **Test Specifications** - Test case and test suite definitions
- **Test Results** - Execution results and status reports
- **Security Examples** - XXE vulnerability demonstrations

## Directory Structure

```
file_examples/
├── README.md                    # This file
├── custom_fields/               # Custom field XML definitions
│   ├── simple_field.xml        # Simple text field
│   ├── dropdown_field.xml      # Dropdown/select field
│   ├── checkbox_field.xml      # Boolean checkbox field
│   └── multiselect_field.xml   # Multi-select field
├── keywords/                    # Keyword definition examples
│   ├── single_keyword.xml      # Single keyword
│   └── keyword_list.xml        # Multiple keywords
├── platforms/                   # Platform configuration examples
│   ├── single_platform.xml     # Single platform
│   ├── platform_list.xml       # Multiple platforms
│   └── platform_hierarchy.xml  # Hierarchical platforms
├── requirements/                # Requirement format examples
│   ├── simple_requirement.xml   # Single requirement
│   ├── requirement_spec.xml     # Complete requirement spec
│   └── requirement_traceability.xml  # With test coverage
├── testplan/                    # Test plan examples
│   ├── basic_plan.xml          # Basic test plan
│   └── plan_with_builds.xml    # Plan with builds
├── testspec/                    # Test specification examples
│   ├── simple_testcase.xml     # Single test case
│   ├── testcase_with_steps.xml # Test case with steps
│   ├── testsuite.xml           # Test suite structure
│   └── full_hierarchy.xml      # Complete project structure
├── results/                     # Test execution results
│   ├── single_result.xml       # Single execution result
│   ├── batch_results.xml       # Multiple results
│   └── result_with_bugs.xml    # Results with bug linking
└── xxe-attack/                  # Security examples (XXE)
    ├── xxe-attack.xml          # XXE External Entity attack
    └── xxe-attack2.xml         # XXE variant attack
```

## File Format Specifications

### Custom Fields

**Purpose:** Define additional metadata fields for test cases

**Format:** XML

**Key Elements:**
- `name` - Field identifier
- `label` - Display label
- `type` - Field type (text, dropdown, checkbox, multiselect)
- `possible_values` - Options for dropdown/select types
- `default_value` - Initial value
- `enable_on_testcase` - Apply to test cases
- `enable_on_requirement` - Apply to requirements

**Example:**
```xml
<field>
  <name>priority</name>
  <label>Test Priority</label>
  <type>dropdown</type>
  <possible_values>High,Medium,Low</possible_values>
  <default_value>Medium</default_value>
  <enable_on_testcase>1</enable_on_testcase>
</field>
```

### Keywords

**Purpose:** Tag and organize test cases

**Format:** XML

**Key Elements:**
- `keyword` - Keyword text
- `testcase_id` - Link to test case
- `keyword_id` - Keyword identifier

**Example:**
```xml
<keywords>
  <keyword>
    <text>smoke</text>
    <description>Smoke testing</description>
  </keyword>
  <keyword>
    <text>regression</text>
    <description>Regression testing</description>
  </keyword>
</keywords>
```

### Platforms

**Purpose:** Define testing environments and configurations

**Format:** XML

**Key Elements:**
- `name` - Platform name (Windows, Linux, macOS, iOS, Android)
- `notes` - Description
- `is_active` - Enable/disable flag

**Example:**
```xml
<platforms>
  <platform>
    <name>Windows 10</name>
    <notes>Windows 10 Enterprise Edition</notes>
    <is_active>1</is_active>
  </platform>
  <platform>
    <name>Chrome Browser</name>
    <notes>Latest Chrome version</notes>
    <is_active>1</is_active>
  </platform>
</platforms>
```

### Requirements

**Purpose:** Define requirements with traceability

**Format:** XML

**Key Elements:**
- `doc_id` - Document identifier
- `title` - Requirement title
- `description` - Detailed description
- `status` - Requirement status (draft, accepted, review, deprecated)
- `type` - Requirement type
- `version` - Version number
- `author_id` - Creator user ID
- `custom_fields` - Additional metadata

**Example:**
```xml
<requirement>
  <doc_id>REQ-001</doc_id>
  <title>User Authentication</title>
  <description>System shall support user login with username and password</description>
  <status>accepted</status>
  <type>functional</type>
  <version>1.0</version>
  <author_id>1</author_id>
</requirement>
```

### Test Specifications (Test Cases)

**Purpose:** Define test cases and test suites

**Format:** XML

**Key Elements:**
- `id` - Test case identifier
- `name` - Test case name
- `description` - Test purpose
- `preconditions` - Pre-test setup
- `steps` - Individual test steps
- `custom_fields` - Additional metadata
- `keywords` - Associated keywords
- `testcase_version` - Version information

**Test Case Structure:**
```xml
<testcase>
  <id>1</id>
  <name>Login Test</name>
  <description>Test user login functionality</description>
  <preconditions>User is on login page</preconditions>
  <steps>
    <step>
      <step_number>1</step_number>
      <actions>Enter username</actions>
      <expected_results>Field accepts input</expected_results>
    </step>
    <step>
      <step_number>2</step_number>
      <actions>Enter password</actions>
      <expected_results>Field accepts input</expected_results>
    </step>
  </steps>
  <keywords>
    <keyword>smoke</keyword>
    <keyword>critical</keyword>
  </keywords>
</testcase>
```

**Test Suite Structure:**
```xml
<testsuite>
  <name>Authentication Suite</name>
  <description>Tests for user authentication</description>
  <testcases>
    <testcase>...</testcase>
    <testcase>...</testcase>
  </testcases>
</testsuite>
```

### Test Plans

**Purpose:** Define test plans with test cases and builds

**Format:** XML

**Key Elements:**
- `name` - Plan name
- `project_id` - Associated project
- `notes` - Plan description
- `testcases` - Linked test cases
- `builds` - Associated builds
- `platforms` - Testing platforms

**Example:**
```xml
<testplan>
  <name>Web App - Regression</name>
  <project_id>1</project_id>
  <notes>Full regression test suite for web application</notes>
  <builds>
    <build>
      <name>Build 1.0</name>
      <notes>Initial release</notes>
    </build>
  </builds>
  <testcases>
    <testcase>
      <id>1</id>
      <version>1</version>
    </testcase>
  </testcases>
</testplan>
```

### Test Results (Execution)

**Purpose:** Import execution results and test outcomes

**Format:** XML

**Key Elements:**
- `testcase_id` - Test case identifier
- `plan_id` - Test plan identifier
- `build_id` - Build identifier
- `status` - Result status (p=pass, f=fail, b=blocked, w=wait)
- `execution_ts` - Timestamp
- `notes` - Execution notes
- `tester_id` - Tester user ID
- `bugs` - Associated bug IDs

**Status Codes:**
- `p` - PASSED
- `f` - FAILED
- `b` - BLOCKED
- `w` - WAIT

**Example:**
```xml
<execution>
  <testcase_id>1</testcase_id>
  <plan_id>1</plan_id>
  <build_id>1</build_id>
  <status>p</status>
  <execution_ts>2024-08-16 10:30:00</execution_ts>
  <notes>Test passed successfully</notes>
  <tester_id>1</tester_id>
  <bugs>
    <bug>BUG-123</bug>
  </bugs>
</execution>
```

## Import/Export Workflows

### Exporting Test Cases

1. **Navigate:** Project → Test Specification → Export
2. **Select:** Test cases or entire suite
3. **Format:** XML format selected
4. **File:** Saved as `testspec.xml`

### Importing Test Cases

1. **Navigate:** Project → Test Specification → Import
2. **Select File:** Choose `testspec.xml`
3. **Options:** 
   - Create new test cases or update existing
   - Platform assignment
   - Custom field mapping
4. **Validate:** Check for XML errors
5. **Import:** Confirm and execute

### Exporting Test Results

1. **Navigate:** Test Plan → Results → Export
2. **Select:** Date range or specific executions
3. **Format:** XML format
4. **Include:** Notes, bugs, custom fields

### Importing Test Results

1. **Navigate:** Test Plan → Results → Import
2. **Select File:** Choose `results.xml`
3. **Mapping:** Map columns to fields
4. **Validation:** Check data integrity
5. **Import:** Execute import

## Data Validation Rules

### Custom Fields
- Field name: Alphanumeric, unique
- Label: Max 255 characters
- Type: Must be valid type (text, dropdown, checkbox, multiselect)
- Default value: Must be in possible_values (if dropdown)

### Keywords
- Keyword text: Max 100 characters, unique
- Special characters: Allowed but may need escaping
- Description: Optional, max 500 characters

### Platforms
- Platform name: Required, unique, max 255 characters
- Notes: Optional, max 500 characters
- is_active: Boolean (0 or 1)

### Test Cases
- Name: Required, max 255 characters
- Description: Optional, max 2000 characters
- Steps: Each step must have actions and expected_results
- Keywords: Must exist in system before linking

### Requirements
- doc_id: Required, unique identifier
- Title: Required, max 500 characters
- Status: Must be valid status (draft, accepted, review, deprecated)
- Version: Must be numeric

### Results
- testcase_id: Must reference valid test case
- plan_id: Must reference valid test plan
- build_id: Must reference valid build
- status: Must be p, f, b, or w
- execution_ts: Valid timestamp format

## Import Error Handling

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| XML Parse Error | Malformed XML | Validate XML syntax |
| Invalid Field Type | Unknown field type | Check allowed types |
| Duplicate ID | Record already exists | Use update mode |
| Missing Required Field | Missing mandatory data | Add required field |
| Invalid Status | Unknown status value | Use valid status |
| Reference Error | ID doesn't exist | Verify referenced IDs |

## Export Best Practices

1. **Backup First** - Always backup before importing large datasets
2. **Validate XML** - Ensure XML is well-formed before import
3. **Map Fields** - Understand field mapping before import
4. **Test First** - Test import on development before production
5. **Version Control** - Keep XML files in version control
6. **Document Changes** - Note what was imported and when
7. **Archive Exports** - Keep history of exports for audit trail

## Security Considerations

### XXE (XML External Entity) Attacks

**What is XXE?**
- Vulnerability in XML parsers
- Can lead to data exposure or denial of service
- Allow attackers to read system files
- Can cause server overload

**Examples:**
See `xxe-attack.xml` and `xxe-attack2.xml` for demonstration purposes only.

**Prevention:**
- Disable external entities in XML parser
- Use whitelist for allowed entities
- Validate XML before processing
- Limit file size for imports
- Update XML parser libraries regularly

**Safe XML Parsing:**

```php
// Disable external entities
libxml_disable_entity_loader(true);
$xml = simplexml_load_file('file.xml');

// Or use strict parsing
$dom = new DOMDocument();
$dom->resolveExternals = false;
$dom->substituteEntities = false;
$dom->load('file.xml');
```

### Data Protection

- **Sanitize Input** - Clean all imported data
- **Validate IDs** - Ensure referenced records exist
- **Encrypt Exports** - For sensitive data
- **Access Control** - Restrict import/export to authorized users
- **Audit Logging** - Track all import/export operations

## File Format Compatibility

| Format | Version | Support | Notes |
|--------|---------|---------|-------|
| XML | 1.0 | Full | Standard format, recommended |
| CSV | 1.0 | Partial | Limited to specific operations |
| JSON | 1.0 | Limited | REST API primarily |

## Integration Examples

### Bash Script for Import

```bash
#!/bin/bash

API_TOKEN="your_token"
BASE_URL="https://testlink/api/rest/v3"

# Import test specification
curl -X POST "$BASE_URL/import/testspec" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/xml" \
  -d @testspec.xml

# Import test results
curl -X POST "$BASE_URL/import/results" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/xml" \
  -d @results.xml
```

### Python Script for Export

```python
import requests
import xml.etree.ElementTree as ET

API_TOKEN = "your_token"
BASE_URL = "https://testlink/api/rest/v3"

headers = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Accept": "application/xml"
}

# Export test cases
response = requests.get(f"{BASE_URL}/export/testspec/1", 
                       headers=headers)

# Save to file
with open('testspec.xml', 'w') as f:
    f.write(response.text)

# Parse and process
root = ET.fromstring(response.text)
for testcase in root.findall('testcase'):
    print(f"Test Case: {testcase.find('name').text}")
```

## File Size Limits

- **Single File:** Max 50 MB
- **Batch Import:** Max 500 MB total
- **Test Cases:** Max 10,000 per file
- **Execution Results:** Max 50,000 per file

## Troubleshooting

### File Won't Import

1. **Check XML Validity:**
   ```bash
   xmllint --noout file.xml
   ```

2. **Verify Character Encoding:** UTF-8 required
3. **Check File Permissions:** Readable by web server
4. **Validate References:** All IDs must exist
5. **Review Error Log:** Check for detailed error messages

### Missing Data After Import

1. **Verify Mapping:** Check field mapping configuration
2. **Check Validation:** Ensure data meets requirements
3. **Review Permissions:** User must have import rights
4. **Validate XML:** Ensure no data truncation

### Slow Import Performance

1. **Split Files:** Import in smaller batches
2. **Disable Indexing:** Temporarily during large import
3. **Increase Timeout:** For large files
4. **Check Server Resources:** Memory and CPU usage

## Related Documentation

- [[Developer-Guide]] - Development and coding examples
- [[API-Documentation]] - REST API reference
- [[Sample-Database]] - Sample data structure

## Reference Files

All example files are included in this directory:
- `custom_fields/*.xml` - Custom field examples
- `keywords/*.xml` - Keyword examples
- `platforms/*.xml` - Platform examples
- `requirements/*.xml` - Requirement examples
- `testplan/*.xml` - Test plan examples
- `testspec/*.xml` - Test specification examples
- `results/*.xml` - Test result examples
- `xxe-attack/*.xml` - Security examples (XXE)

---

**Version:** TestLink 1.9.20  
**Last Updated:** August 2026

## Quick Links

- [Custom Field Format](#custom-fields)
- [Test Case Format](#test-specifications-test-cases)
- [Test Plan Format](#test-plans)
- [Test Results Format](#test-results-execution)
- [Import Workflows](#importexport-workflows)
- [Error Handling](#import-error-handling)
- [Security](#security-considerations)
