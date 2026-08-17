# Creating a TestLink Project with GitHub Issues Integration

## Overview
TestLink supports integration with external issue tracking systems. This guide documents how to create a project and configure GitHub Issues as the incident manager.

## Project Types

### Issue Tracker Integration
- **Purpose**: Links test execution results to bugs/issues (JIRA, GitHub Issues, Bugzilla, Redmine, etc.)
- **Use Case**: When a test fails, automatically create or link to an issue in your issue tracking system
- **Example**: Linking failed test case execution to GitHub Issues

### Code Tracker Integration
- **Purpose**: Links commits and source code versions to test plans (Git, SVN, GitHub, etc.)
- **Use Case**: Tracking which version of code was tested
- **Note**: Currently no Code Tracker Systems are configured in this TestLink instance

## Creating a Project with GitHub Issues

### Step 1: Navigate to Test Project Management
1. Click **Projects** in the left sidebar
2. Click **Test Project Management**

### Step 2: Create New Project
1. Click the **Create** button
2. Fill in the following required fields:
   - **Name**: TestLink-Upgraded
   - **Prefix**: TLU (used for test case IDs like TLU-1, TLU-2)

### Step 3: Configure GitHub Issues Integration
1. Under **Issue Tracker Integration** section:
   - Check the **Active** checkbox to enable issue tracking
   - Select **GitHub Issues (github (Interface: rest))** from the dropdown

2. Under **Availability** section:
   - Check **Active** to make the project available
   - Check **Public** to allow all users to access it (optional)

### Step 4: Enhanced Features (Optional)
- **Testing Priority**: Priority levels for test cases
- **Test Automation**: API keys for test automation integration
- **Requirements**: Requirements traceability
- **Inventory**: Inventory management

### Step 5: Create the Project
Click the **Create** button to save the project.

## Result
The project is now available in the Test Project Management list with:
- Name: TestLink-Upgraded
- Prefix: TLU
- Issue Tracker: GitHub Issues
- Status: Active and Public

## Using GitHub Issues Integration
Once the project is created with GitHub Issues configured:
1. During test execution, failed tests can be linked to GitHub issues
2. Test case failures create or reference issues in the associated GitHub repository
3. Traceability between test results and code issues is automatically maintained

## Configuration Requirements
- GitHub repository URL must be configured in the issue tracker settings
- API credentials (if using private repositories) need to be set up
- The TestLink instance must have internet access to reach GitHub API

## Next Steps
- Create test plans within the project
- Design test cases
- Link test cases to GitHub issues
- Execute tests and track results against GitHub issues
