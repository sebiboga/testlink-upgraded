# TestLink Tracker Integrations Guide

## Overview
TestLink supports two types of external system integrations:
1. **Issue Tracker Integration** - for managing bugs and issues
2. **Code Tracker Integration** - for managing source code and commits

## Issue Tracker vs Code Tracker

| Feature | Issue Tracker | Code Tracker |
|---------|---------------|--------------|
| **Purpose** | Track bugs and test failures | Track code commits and versions |
| **Used For** | Linking test failures to issues | Linking test plans to code versions |
| **Examples** | GitHub Issues, JIRA, Bugzilla | Git, GitHub, SVN, Mercurial |
| **Current Status** | GitHub Issues configured | None configured |

## Issue Tracker Integration

### Supported Systems
- GitHub Issues (GitHub)
- JIRA
- Bugzilla
- Redmine
- Mantis
- Azure DevOps

### Configuration
1. Project must be marked as **Active**
2. Select issue tracker from available systems
3. Configure authentication (API keys, credentials)
4. Map repository/project details

### Usage Scenario
```
Test Execution Flow:
1. Test case fails during execution
2. Developer links failure to GitHub Issue #123
3. Issue tracker records the association
4. Dashboard shows test-to-issue traceability
5. Report includes issue status
```

## Code Tracker Integration

### Supported Systems
- Git
- GitHub (as code repository)
- SVN (Subversion)
- Mercurial
- Bazaar

### Why Code Tracking?
- **Version Control**: Know which code version was tested
- **Commit History**: Trace test failures to specific commits
- **Regression Detection**: Identify when bugs were introduced
- **Release Management**: Track which commits are in each release

### Configuration Steps (Once Installed)
1. Go to **Projects > Code Tracker Integration**
2. Install and configure code tracker system
3. Link test plans to specific code branches/commits
4. Execution reports show code version info

### Example Usage
```
Test Plan Execution:
1. Test plan is linked to Git commit abc123def
2. 5 test cases execute against that version
3. Report shows: "Tested on commit abc123def"
4. Developers can pull exact code version that was tested
```

## Setting Up GitHub for Both Integrations

### As Issue Tracker
- Use GitHub Issues for bug/issue tracking
- API Endpoint: GitHub Issues REST API
- Authentication: GitHub Personal Access Token

### As Code Tracker
- Use GitHub Repositories for source code
- Track branches and commit history
- Authentication: GitHub SSH key or token

### GitHub Configuration Example
```
Issue Tracker:
- Repository: https://github.com/sebiboga/testlink-upgraded
- Tracker Type: GitHub Issues
- API Token: [personal access token]

Code Tracker:
- Repository URL: https://github.com/sebiboga/testlink-upgraded.git
- Branch: main/develop
- Credentials: SSH key or HTTPS token
```

## Current TestLink-Upgraded Setup

### Configured
✅ **Issue Tracker**: GitHub Issues
- Project: TestLink-Upgraded (TLU)
- Status: Active
- Integration: GitHub REST API

### Not Yet Configured
❌ **Code Tracker**: None
- Pending: GitHub repository linking
- Pending: Commit tracking setup

## Next Steps for Full Integration

1. **Configure GitHub Repository**
   - Add GitHub repository URL to TestLink settings
   - Configure authentication tokens
   - Set up automatic webhook (optional)

2. **Enable Code Tracking**
   - Install Code Tracker plugin (if needed)
   - Configure Git integration
   - Link test plans to code branches

3. **Set Up Automation**
   - Configure GitHub webhooks to notify TestLink
   - Set up automated test runs on commits
   - Enable CI/CD integration (Jenkins, GitHub Actions, etc.)

4. **Configure Reporting**
   - Enable traceability reports
   - Set up dashboards showing issue/code status
   - Configure notifications

## Troubleshooting

### Issue Tracker Connection Issues
- Verify GitHub token has correct permissions
- Check network connectivity to GitHub API
- Validate repository URL format
- Ensure GitHub account has access to repository

### Code Tracker Sync Problems
- Verify Git credentials/SSH keys are valid
- Check branch names match
- Ensure commit history is accessible
- Verify disk space for repository clone

## Security Considerations

- **API Tokens**: Use personal access tokens with minimal required permissions
- **SSH Keys**: Store securely, use SSH agents
- **Webhooks**: Validate webhook signatures
- **Credentials**: Never commit credentials to version control
- **Network**: Use HTTPS/SSH for all connections

## Resources

- [GitHub API Documentation](https://docs.github.com/en/rest)
- [TestLink Documentation](http://testlink.sourceforge.net/)
- [GitHub Issues REST API](https://docs.github.com/en/rest/issues)
