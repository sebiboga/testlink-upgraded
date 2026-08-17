# Code Tracker Management - Configuration Guide

## Location
**System → Code Tracker Management**

## Overview
Code Tracker Management allows TestLink to integrate with version control systems (Git, SVN, GitHub, Mercurial, etc.) to track which code version is being tested.

## Page Components

### Create Button
- Click to add a new code tracker system
- Opens configuration form for the selected VCS type

### Currently Configured Trackers
- Empty list (no code trackers configured yet on this instance)
- Will display all configured trackers once created

## Supported Code Tracker Systems

### 1. Git
- **Purpose**: Track Git repositories and commits
- **Used For**: Linking test plans to specific Git commits
- **Configuration**: Repository URL, branch selection

### 2. GitHub
- **Purpose**: GitHub-specific integration for repository tracking
- **Used For**: GitHub commits, pull requests, branch tracking
- **Configuration**: GitHub URL, authentication token (optional for public repos)

### 3. SVN (Subversion)
- **Purpose**: Subversion repository integration
- **Used For**: Older projects using SVN
- **Configuration**: SVN repository URL

### 4. Mercurial (Hg)
- **Purpose**: Mercurial DVCS integration
- **Used For**: Projects using Mercurial
- **Configuration**: Repository path and settings

### 5. Bazaar
- **Purpose**: Bazaar VCS integration
- **Used For**: Legacy Bazaar repositories
- **Configuration**: Repository location

## Why Configure Code Trackers?

| Benefit | Description |
|---------|-------------|
| **Version Tracking** | Know exactly which code version was tested |
| **Traceability** | Link test results to specific commits |
| **Regression Analysis** | Identify which commits introduced bugs |
| **Release Management** | Track which code is in each release |
| **CI/CD Integration** | Automate testing on code commits |

## Creating a Code Tracker

### Step 1: Navigate to Code Tracker Management
1. Click **System** in left menu
2. Click **Code Tracker Management**

### Step 2: Click Create Button
- Opens configuration form for new code tracker

### Step 3: Configure Tracker Details
- **Name**: Identifier for this tracker (e.g., "GitHub-TestLink-Upgraded")
- **Type**: Select version control system (Git, GitHub, SVN, etc.)
- **Repository URL**: Full URL to repository
- **Authentication**: Token/credentials if needed (private repos)
- **Description**: Optional notes

### Step 4: Test Connection
- Verify TestLink can connect to repository
- Check authentication works correctly

### Step 5: Save Configuration
- Code tracker is now available for project assignment

## Using Code Trackers in Projects

### Step 1: Assign Tracker to Project
1. Go to **Projects → Test Project Management**
2. Select project
3. Under "Code Tracker Integration":
   - Check **Active** to enable
   - Select the configured code tracker

### Step 2: Link Test Plan to Code Version
1. Create test plan
2. Link to specific:
   - Git commit hash
   - GitHub branch
   - SVN revision

### Step 3: Execute Tests
- Tests run against linked code version
- Results are associated with code version
- Reports show code traceability

## Example: Setting Up GitHub Code Tracker

### Configuration
```
Name: TestLink-Upgraded GitHub
Type: GitHub
Repository URL: https://github.com/sebiboga/testlink-upgraded.git
Branch: main
Authentication: GitHub Personal Access Token (optional)
```

### Usage in Project
```
Test Plan: Login Testing v1.0
Linked Code: GitHub commit abc123def (main branch)
Test Execution: 5 tests run
Results: 4 passed, 1 failed
Report Shows: "Tested on commit abc123def"
```

## API Integration

### REST API Configuration
Code trackers typically use:
- **Git/GitHub**: REST API or Git protocol
- **SVN**: SVN protocol over HTTP/HTTPS
- **Mercurial**: Hg protocol or REST API

### Authentication Types
- **SSH Keys**: For Git/GitHub (secure)
- **HTTP/HTTPS Tokens**: Personal Access Tokens
- **Username/Password**: Basic authentication (less secure)
- **Anonymous**: For public repositories

## Current TestLink-Upgraded Status

### Issue Tracker: ✅ Configured
- System: GitHub Issues
- Status: Active
- Configuration: GitHub REST API

### Code Tracker: ❌ Not Configured
- Status: Needs setup
- Recommended: GitHub (same repository)
- Next Step: Create Git/GitHub code tracker in System settings

## Security Considerations

### API Tokens
- Use Personal Access Tokens with minimal permissions
- Never commit tokens to version control
- Store in TestLink's secure credential storage
- Rotate tokens periodically

### SSH Keys
- Use SSH keys for better security than tokens
- Store keys securely on TestLink server
- Use SSH agents to manage keys
- Restrict key permissions

### Network Security
- Use HTTPS for repository access
- Verify SSL certificates
- Use SSH protocol when possible
- Validate webhook signatures (for CI/CD)

## Troubleshooting

### Connection Issues
```
Problem: Cannot connect to repository
Solution:
1. Verify repository URL is correct
2. Check authentication credentials
3. Verify network/firewall access
4. Check repository permissions
```

### Commit Not Found
```
Problem: Specified commit doesn't exist
Solution:
1. Verify commit hash/branch name
2. Check correct repository is configured
3. Ensure branch exists
4. Pull latest changes to repository
```

### Permission Denied
```
Problem: TestLink cannot read repository
Solution:
1. Check token/credential permissions
2. Verify user has repository access
3. Check SSH key permissions (644 or 600)
4. Validate API token scope
```

## Next Steps

### To Complete TestLink-Upgraded Setup:
1. ✅ Issue Tracker configured (GitHub Issues)
2. ⬜ Create Code Tracker (GitHub)
3. ⬜ Link TestLink-Upgraded project to code tracker
4. ⬜ Link test plans to specific commits
5. ⬜ Test execution with code tracking
6. ⬜ Configure webhook for automated runs

### For Advanced Usage:
- Set up CI/CD pipeline integration
- Configure automatic test runs on commits
- Enable automated issue creation for failed tests
- Set up dashboards showing code-to-test traceability

## Related Pages
- [[Tracker-Integrations-Guide]] - Overview of both tracker types
- [[Project-Setup-with-GitHub-Issues]] - GitHub Issues configuration
- Issue Tracker Management - Configure bug tracking systems

## Resources
- [TestLink Documentation](http://testlink.sourceforge.net/)
- [GitHub API](https://docs.github.com/en/rest)
- [Git Documentation](https://git-scm.com/doc)
- [SVN Documentation](https://svnbook.red-bean.com/)

---

## Successfully Configured: GitHub Code Tracker

### TestLink-Upgraded GitHub Code Tracker

**Configuration Details:**
- **Name**: GitHub-TestLink-Upgraded
- **Type**: slash (Interface: rest)
- **Repository URI**: https://github.com/sebiboga/testlink-upgraded.git
- **Branch**: main
- **Status**: ✅ Configured and Ready

### XML Configuration Format
The slash code tracker requires XML configuration:

```xml
<cvs>
  <uri>https://github.com/sebiboga/testlink-upgraded.git</uri>
  <branch>main</branch>
</cvs>
```

### Known Issues During Configuration
- See Issue #431: Duplicate menu sidebar appears on Code Tracker Management page
- See Issue #432: XML format requirement not documented in UI (error messages are confusing)

### Current Status
✅ **Issue Tracker**: GitHub Issues (configured)
✅ **Code Tracker**: GitHub Repository (configured)

Both trackers are now ready to use with the TestLink-Upgraded project!
