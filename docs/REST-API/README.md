# TestLink v1.9.20 REST API Documentation & Testing

Complete REST API documentation with Postman collection and CLI testing tools for TestLink v1.9.20.

## Overview

TestLink provides a comprehensive REST API v3 for programmatic access to all major functionality:

- **Projects** - Create, read, update, delete test projects
- **Test Plans** - Manage test planning and coordination
- **Test Cases** - CRUD operations for test cases and versions
- **Executions** - Record and retrieve test results
- **Users** - User management and role assignment
- **Custom Fields** - Extended metadata management
- **Requirements** - Requirements tracking and traceability
- **Issue Tracker** - Bug integration and linking
- **Builds** - Version/build management
- **Keywords** - Test case organization

## API Versions

- **REST API v3** ✅ **RECOMMENDED** - Latest and most stable
- **REST API v2** - Legacy (compatible but not recommended)
- **REST API v1** - Legacy (compatible but not recommended)
- **XMLRPC API v1** - Legacy (for backward compatibility)

## Quick Start

### Base URL

```
https://your-testlink-instance/api/rest/v3
```

### Authentication

Two methods supported:

#### 1. Bearer Token (Recommended)

```bash
curl -H "Authorization: Bearer YOUR_API_TOKEN" \
  https://your-testlink-instance/api/rest/v3/whoami
```

#### 2. Basic Authentication

```bash
curl -u username:password \
  https://your-testlink-instance/api/rest/v3/whoami
```

## Core Endpoints

### Authentication & User

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/whoami` | Get current user information |
| POST | `/login` | Authenticate and get session |
| POST | `/logout` | Revoke session |

### Projects

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/projects` | List all projects |
| GET | `/projects/{id}` | Get project details |
| POST | `/projects` | Create new project |
| PUT | `/projects/{id}` | Update project |
| DELETE | `/projects/{id}` | Delete project |

### Test Plans

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/projects/{id}/plans` | List project test plans |
| GET | `/plans/{id}` | Get plan details |
| POST | `/plans` | Create test plan |
| PUT | `/plans/{id}` | Update plan |
| DELETE | `/plans/{id}` | Delete plan |

### Test Cases

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/projects/{id}/testcases` | List project test cases |
| GET | `/testcases/{id}` | Get test case details |
| POST | `/testcases` | Create test case |
| PUT | `/testcases/{id}` | Update test case |
| DELETE | `/testcases/{id}` | Delete test case |

### Test Execution

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/execution/create` | Record execution result |
| GET | `/execution/{id}` | Get execution details |
| GET | `/execution/history` | Get execution history |

### Builds

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/projects/{id}/builds` | List builds |
| POST | `/builds` | Create build |
| PUT | `/builds/{id}` | Update build |
| DELETE | `/builds/{id}` | Delete build |

### Requirements

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/requirements` | List requirements |
| GET | `/requirements/{id}` | Get requirement details |
| POST | `/requirements` | Create requirement |
| PUT | `/requirements/{id}` | Update requirement |

### Keywords

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/keywords` | List keywords |
| POST | `/keywords` | Create keyword |
| DELETE | `/keywords/{id}` | Delete keyword |

### Custom Fields

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/customfields` | List custom fields |
| GET | `/customfields/{id}` | Get field details |
| POST | `/customfields` | Create custom field |
| PUT | `/customfields/{id}` | Update custom field |

## Request/Response Format

### Request Headers

```
Content-Type: application/json
Authorization: Bearer YOUR_TOKEN
```

### Response Format

All responses follow a consistent format:

```json
{
  "status": "success|error",
  "data": { ... },
  "message": "Status message",
  "code": 200
}
```

### Success Response Example

```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "Test Project",
    "prefix": "TP",
    "notes": "Project description"
  },
  "message": "Project created successfully",
  "code": 201
}
```

### Error Response Example

```json
{
  "status": "error",
  "message": "Invalid project ID",
  "code": 400
}
```

## HTTP Status Codes

| Code | Meaning | Use Case |
|------|---------|----------|
| 200 | OK | Successful GET, PUT, DELETE |
| 201 | Created | Successful POST |
| 400 | Bad Request | Invalid parameters |
| 401 | Unauthorized | Missing/invalid authentication |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Resource not found |
| 500 | Server Error | Internal server error |

## Authentication Token Management

### Generate API Token

1. **Login to TestLink**
2. **Navigate:** Admin → User Management
3. **Select User:** Choose user account
4. **API Token Section:** Generate new token
5. **Copy Token:** Store securely

### Token Security

- Treat tokens like passwords
- Rotate tokens regularly
- Use HTTPS only
- Implement expiration policy
- Revoke unused tokens
- Never commit tokens to version control

### Store Securely

**Environment Variables:**

```bash
export TESTLINK_API_TOKEN="your_token_here"
```

**Configuration File:**

```bash
# ~/.testlink/config
API_TOKEN=your_token_here
API_BASE_URL=https://testlink.example.com/api/rest/v3
```

## Common API Patterns

### 1. Create a Test Project

**Request:**

```bash
curl -X POST https://testlink/api/rest/v3/projects \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Test Project",
    "prefix": "MTP",
    "notes": "Project description"
  }'
```

**Response:**

```json
{
  "status": "success",
  "data": {
    "id": 123,
    "name": "My Test Project",
    "prefix": "MTP"
  }
}
```

### 2. Create a Test Case

**Request:**

```bash
curl -X POST https://testlink/api/rest/v3/testcases \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Login Test",
    "project_id": 123,
    "suite_id": 456,
    "description": "Test user login functionality",
    "steps": [
      {
        "actions": "Enter username",
        "expected_results": "Field accepts input"
      },
      {
        "actions": "Enter password",
        "expected_results": "Field accepts input"
      }
    ]
  }'
```

**Response:**

```json
{
  "status": "success",
  "data": {
    "id": 789,
    "name": "Login Test",
    "version": 1,
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

### 3. Record Test Execution

**Request:**

```bash
curl -X POST https://testlink/api/rest/v3/execution/create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "testcase_id": 789,
    "plan_id": 234,
    "build_id": 567,
    "status": "passed",
    "notes": "Test passed successfully",
    "duration": 120
  }'
```

**Response:**

```json
{
  "status": "success",
  "data": {
    "execution_id": 999,
    "testcase_id": 789,
    "status": "passed",
    "executed_at": "2024-01-15T11:00:00Z"
  }
}
```

### 4. Get Execution History

**Request:**

```bash
curl -X GET "https://testlink/api/rest/v3/execution/history?testcase_id=789&plan_id=234" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "status": "success",
  "data": [
    {
      "id": 999,
      "testcase_id": 789,
      "status": "passed",
      "executed_at": "2024-01-15T11:00:00Z"
    },
    {
      "id": 998,
      "testcase_id": 789,
      "status": "failed",
      "executed_at": "2024-01-14T15:30:00Z"
    }
  ]
}
```

## Testing the API

### Using Postman

1. **Import Collection:**
   - Open Postman
   - Click "Import"
   - Select `TestLink.postman_collection.json`

2. **Configure Environment:**
   - Set Base URL: `https://your-testlink/api/rest/v3`
   - Set API Token: Your authentication token
   - Save Environment

3. **Execute Requests:**
   - Select request from collection
   - Click "Send"
   - View response

### Using cURL

```bash
# Get current user
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://testlink/api/rest/v3/whoami

# List projects
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://testlink/api/rest/v3/projects

# Create project
curl -X POST https://testlink/api/rest/v3/projects \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","prefix":"T"}'
```

### Using Newman (CLI Testing)

```bash
# Run all tests
newman run TestLink.postman_collection.json \
  --environment environment.json

# Generate HTML report
newman run TestLink.postman_collection.json \
  --environment environment.json \
  --reporters cli,html \
  --reporter-html-template newman-tpl.hbs

# Run specific folder
newman run TestLink.postman_collection.json \
  --folder "Projects"
```

## API Pagination

### Query Parameters

| Parameter | Type | Default | Max |
|-----------|------|---------|-----|
| limit | integer | 20 | 100 |
| offset | integer | 0 | unlimited |

### Example

```bash
curl "https://testlink/api/rest/v3/projects?limit=50&offset=0" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Error Handling

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| 401 Unauthorized | Invalid/missing token | Verify token is correct |
| 403 Forbidden | Insufficient permissions | Check user role |
| 404 Not Found | Invalid resource ID | Verify ID exists |
| 400 Bad Request | Invalid parameters | Check request format |
| 500 Server Error | Server issue | Check logs, retry |

### Error Response Example

```json
{
  "status": "error",
  "message": "Invalid project ID: 999",
  "code": 404,
  "details": {
    "field": "project_id",
    "error": "Project not found"
  }
}
```

## Rate Limiting

- **Default Limit:** 1000 requests per hour
- **Per-User:** Applied per API token
- **Headers:** Include rate limit info in response headers

```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1234567890
```

## Data Validation

### Test Case Example

```json
{
  "name": "Login Test",           // Required: string, 1-255 chars
  "project_id": 123,              // Required: integer
  "suite_id": 456,                // Required: integer
  "description": "Test login",    // Optional: string
  "execution_type": "manual",     // Optional: "manual|automated"
  "status": "active",             // Optional: "active|frozen"
  "steps": [                      // Optional: array
    {
      "actions": "Enter username",
      "expected_results": "Field accepts input"
    }
  ]
}
```

## Security Best Practices

1. **Use HTTPS Only**
   - Never send tokens over HTTP
   - Verify SSL certificates

2. **Token Management**
   - Rotate tokens regularly
   - Revoke unused tokens
   - Never commit to version control

3. **Input Validation**
   - Validate all user input
   - Sanitize before use
   - Use parameterized queries

4. **Logging**
   - Log API calls for audit
   - Don't log sensitive data (tokens, passwords)
   - Monitor for suspicious activity

5. **Access Control**
   - Use role-based permissions
   - Grant minimum required access
   - Audit user activities

## Performance Tips

1. **Batch Operations**
   - Use limit/offset for pagination
   - Batch create operations when possible
   - Avoid N+1 queries

2. **Caching**
   - Cache static data (keywords, fields)
   - Implement client-side caching
   - Use ETags when available

3. **Optimization**
   - Minimize number of API calls
   - Use bulk operations
   - Filter results server-side

## Postman Collection

### Features

- **Pre-configured endpoints** for all major API operations
- **Authentication setup** with token management
- **Request examples** with sample data
- **Response assertions** for validation
- **Data extraction** and chaining between requests
- **Environment variables** for dynamic configuration
- **Pre-request scripts** for setup and teardown

### Included Requests

#### Projects
- List all projects
- Get project by ID
- Create new project
- Update project
- Delete project

#### Test Plans
- List project plans
- Get plan details
- Create test plan
- Update plan
- Delete plan

#### Test Cases
- List project test cases
- Get test case details
- Create test case
- Update test case
- Delete test case

#### Execution
- Record execution result
- Get execution details
- Get execution history

#### Users
- List users
- Get user details
- Create user
- Update user

#### Custom Fields
- List custom fields
- Get field details
- Create field
- Update field

#### Other Resources
- Keywords management
- Requirements management
- Builds management
- Issue tracker operations

## Newman CLI Testing

### Features

- **Automated testing** of API endpoints
- **CI/CD integration** for automated pipelines
- **HTML report generation** with detailed results
- **Request validation** with assertions
- **Error reporting** with detailed failures
- **Performance metrics** and timing

### Configuration

**newman.json** - Test execution configuration:

```json
{
  "collection": "TestLink.postman_collection.json",
  "environment": "environment.json",
  "reporters": ["cli", "html"],
  "reporterOptions": {
    "html": {
      "export": "test-results.html",
      "template": "newman-tpl.hbs"
    }
  }
}
```

### Report Template

**newman-tpl.hbs** - Custom HTML report template with:
- Test summary statistics
- Detailed request/response logging
- Assertion results
- Performance metrics
- Error details
- Formatted output

## Usage Examples

### Bash Script for API Automation

```bash
#!/bin/bash

API_TOKEN="your_token"
API_URL="https://testlink/api/rest/v3"

# Get current user
curl -s -H "Authorization: Bearer $API_TOKEN" \
  "$API_URL/whoami" | jq .

# Create project
PROJECT=$(curl -s -X POST "$API_URL/projects" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"My Project","prefix":"MP"}')

PROJECT_ID=$(echo $PROJECT | jq -r '.data.id')
echo "Created project: $PROJECT_ID"
```

### Python Script for API Usage

```python
import requests
import json

API_TOKEN = "your_token"
API_URL = "https://testlink/api/rest/v3"

headers = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Content-Type": "application/json"
}

# Get projects
response = requests.get(f"{API_URL}/projects", headers=headers)
projects = response.json()["data"]

# Create test case
data = {
    "name": "Login Test",
    "project_id": projects[0]["id"],
    "suite_id": 123,
    "description": "Test login functionality"
}
response = requests.post(f"{API_URL}/testcases", 
                         headers=headers, json=data)
testcase = response.json()["data"]
print(f"Created test case: {testcase['id']}")
```

## Related Documentation

- [[API-Documentation]] - User-friendly API reference
- [[Developer-Guide]] - Development guide with code examples
- [[Test-Execution]] - Execution workflow details
- See Wiki for complete API reference

## Troubleshooting

### 401 Unauthorized

**Issue:** Invalid or missing authentication

**Solutions:**
- Verify token is correct
- Check token hasn't expired
- Ensure Authorization header format is correct: `Bearer TOKEN`

### 404 Not Found

**Issue:** Resource doesn't exist

**Solutions:**
- Verify resource ID is correct
- Check resource hasn't been deleted
- Ensure correct endpoint URL

### 400 Bad Request

**Issue:** Invalid request parameters

**Solutions:**
- Validate JSON format
- Check required fields are present
- Verify field data types

### Rate Limit Exceeded

**Issue:** Too many requests

**Solutions:**
- Implement request throttling
- Use batch operations
- Cache results when possible

## Support & Feedback

- **GitHub Issues:** Report bugs or request features
- **GitHub Discussions:** Ask questions
- **Wiki:** Check documentation
- **Code Examples:** See scripts and samples

---

**Version:** TestLink 1.9.20  
**API Version:** REST v3  
**Last Updated:** August 2026

## Files in This Directory

- **README.md** - This documentation
- **TestLink.postman_collection.json** - Postman collection with all endpoints
- **environment.json** - Postman environment configuration
- **newman.json** - Newman CLI configuration
- **newman-tpl.hbs** - Custom Newman HTML report template
