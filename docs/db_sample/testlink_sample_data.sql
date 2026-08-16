-- TestLink v1.9.20 Sample Database Data
-- This file contains sample data for development and testing
-- It creates sample projects, test cases, plans, executions, and users
--
-- Usage:
--   mysql -u user -p testlink < testlink_sample_data.sql
--
-- Prerequisites:
--   - Database schema must be created first (testlink_create_tables.sql)
--   - Default data must be loaded (testlink_create_default_data.sql)
--
-- Data Included:
--   - 3 Sample Projects
--   - Test Suites and Test Cases
--   - Test Plans and Builds
--   - Test Executions with Results
--   - Multiple Users with Different Roles
--   - Custom Fields with Data
--   - Requirements with Traceability
--   - Keywords and Test Case Linking
--

-- ============================================================================
-- SAMPLE USERS
-- ============================================================================

-- Admin user (admin / admin)
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('admin', 'admin@testlink.local', 'Admin', 'User',
        PASSWORD('admin'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'adminkey');

-- Test Manager
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('manager', 'manager@testlink.local', 'Test', 'Manager',
        PASSWORD('manager'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'managerkey');

-- Senior Tester
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('senior_tester', 'senior@testlink.local', 'Senior', 'Tester',
        PASSWORD('senior'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'seniorkey');

-- Regular Tester 1
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('tester1', 'tester1@testlink.local', 'John', 'Tester',
        PASSWORD('tester1'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'tester1key');

-- Regular Tester 2
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('tester2', 'tester2@testlink.local', 'Jane', 'Tester',
        PASSWORD('tester2'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'tester2key');

-- Test Designer
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('designer', 'designer@testlink.local', 'Alice', 'Designer',
        PASSWORD('designer'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'designerkey');

-- Guest
INSERT INTO /*prefix*/users (login, email, first, last, password, date_format,
           expiration_date, is_active, script_key)
VALUES ('guest', 'guest@testlink.local', 'Guest', 'User',
        PASSWORD('guest'), 'Y-m-d', DATE_ADD(NOW(), INTERVAL 365 DAY), 1, 'guestkey');

-- ============================================================================
-- SAMPLE TEST PROJECTS
-- ============================================================================

-- Project 1: Web Application Testing
INSERT INTO /*prefix*/testprojects (name, notes, color, active, tc_counter, is_public, prefix)
VALUES ('Web Application', 'Sample project for web application testing', '#3498db', 1, 1, 1, 'WEB');

-- Project 2: Mobile Application Testing
INSERT INTO /*prefix*/testprojects (name, notes, color, active, tc_counter, is_public, prefix)
VALUES ('Mobile Application', 'Sample project for mobile application testing', '#e74c3c', 1, 1, 1, 'MOB');

-- Project 3: API Testing
INSERT INTO /*prefix*/testprojects (name, notes, color, active, tc_counter, is_public, prefix)
VALUES ('API Testing', 'Sample project for REST API testing', '#2ecc71', 1, 1, 1, 'API');

-- ============================================================================
-- SAMPLE TEST SUITES - Web Application Project
-- ============================================================================

SET @web_project_id = (SELECT id FROM /*prefix*/testprojects WHERE prefix = 'WEB' LIMIT 1);

-- Test Suite: Authentication
INSERT INTO /*prefix*/testsuites (name, description, parent_id, testproject_id, order_on_testplan_create)
VALUES ('Authentication', 'Tests for user authentication and login', 0, @web_project_id, 0);
SET @auth_suite = LAST_INSERT_ID();

-- Test Suite: User Management
INSERT INTO /*prefix*/testsuites (name, description, parent_id, testproject_id, order_on_testplan_create)
VALUES ('User Management', 'Tests for user profile and settings management', 0, @web_project_id, 1);
SET @user_suite = LAST_INSERT_ID();

-- Test Suite: Dashboard
INSERT INTO /*prefix*/testsuites (name, description, parent_id, testproject_id, order_on_testplan_create)
VALUES ('Dashboard', 'Tests for dashboard functionality', 0, @web_project_id, 2);
SET @dashboard_suite = LAST_INSERT_ID();

-- ============================================================================
-- SAMPLE TEST CASES - Authentication Suite
-- ============================================================================

-- Test Case 1: Successful Login
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Valid Login with Correct Credentials', @auth_suite, 0, @web_project_id,
        'User is on login page', 'User should be able to login with valid username and password');
SET @tc1 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc1, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv1 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv1, 1, 'Enter username: admin', 'Username field accepts input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv1, 2, 'Enter password: admin', 'Password field accepts input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv1, 3, 'Click Login button', 'User is redirected to dashboard', 0);

-- Test Case 2: Invalid Password
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Login with Invalid Password', @auth_suite, 1, @web_project_id,
        'User is on login page', 'System should reject invalid password');
SET @tc2 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc2, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv2 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv2, 1, 'Enter username: admin', 'Username field accepts input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv2, 2, 'Enter password: wrongpass', 'Password field accepts input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv2, 3, 'Click Login button', 'Error message appears: Invalid credentials', 0);

-- Test Case 3: Session Timeout
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Session Timeout After Inactivity', @auth_suite, 2, @web_project_id,
        'User is logged in', 'System should timeout session after inactivity period');
SET @tc3 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc3, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv3 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv3, 1, 'Login to application', 'User is on dashboard', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv3, 2, 'Wait for 30 minutes without activity', 'Session should be tracked', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv3, 3, 'Try to access dashboard', 'User is redirected to login page', 0);

-- ============================================================================
-- SAMPLE TEST CASES - User Management Suite
-- ============================================================================

-- Test Case 4: Update User Profile
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Update User Profile Information', @user_suite, 0, @web_project_id,
        'User is logged in and on profile page', 'User should be able to update profile');
SET @tc4 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc4, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv4 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv4, 1, 'Navigate to User Profile', 'Profile page loads with current data', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv4, 2, 'Edit name field to: John Doe', 'Field accepts new value', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv4, 3, 'Click Save button', 'Profile is updated and saved successfully', 0);

-- Test Case 5: Change Password
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Change User Password', @user_suite, 1, @web_project_id,
        'User is logged in', 'User should be able to change password');
SET @tc5 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc5, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv5 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv5, 1, 'Navigate to Settings > Change Password', 'Change password form loads', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv5, 2, 'Enter current password', 'Password field accepts input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv5, 3, 'Enter new password twice', 'New password fields accept input', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv5, 4, 'Click Save', 'Password is changed successfully', 0);

-- ============================================================================
-- SAMPLE TEST CASES - Dashboard Suite
-- ============================================================================

-- Test Case 6: Dashboard Loading
INSERT INTO /*prefix*/testcases (name, parent_id, order_on_testplan_create, testproject_id,
                                 preconditions, summary)
VALUES ('Dashboard Loads Successfully', @dashboard_suite, 0, @web_project_id,
        'User is logged in', 'Dashboard should display all widgets');
SET @tc6 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcversions (tcid, version, layout, status, is_open, active, author_id,
                                  creation_ts, modification_ts)
VALUES (@tc6, 1, 10, 1, 1, 1, 1, NOW(), NOW());
SET @tcv6 = LAST_INSERT_ID();

INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv6, 1, 'Login to application', 'Login successful', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv6, 2, 'Wait for dashboard to load', 'All widgets load within 3 seconds', 0);
INSERT INTO /*prefix*/tcsteps (tcversion_id, step_number, actions, expected_results, execution_type)
VALUES (@tcv6, 3, 'Verify all elements are visible', 'No layout errors or missing elements', 0);

-- ============================================================================
-- SAMPLE KEYWORDS
-- ============================================================================

INSERT INTO /*prefix*/keywords (keyword) VALUES ('smoke');
INSERT INTO /*prefix*/keywords (keyword) VALUES ('regression');
INSERT INTO /*prefix*/keywords (keyword) VALUES ('critical');
INSERT INTO /*prefix*/keywords (keyword) VALUES ('ui');
INSERT INTO /*prefix*/keywords (keyword) VALUES ('security');

-- Link keywords to test cases
SET @smoke_kw = (SELECT id FROM /*prefix*/keywords WHERE keyword = 'smoke' LIMIT 1);
SET @regression_kw = (SELECT id FROM /*prefix*/keywords WHERE keyword = 'regression' LIMIT 1);
SET @critical_kw = (SELECT id FROM /*prefix*/keywords WHERE keyword = 'critical' LIMIT 1);
SET @security_kw = (SELECT id FROM /*prefix*/keywords WHERE keyword = 'security' LIMIT 1);

INSERT INTO /*prefix*/testcase_keywords (tcid, keywordid) VALUES (@tc1, @smoke_kw);
INSERT INTO /*prefix*/testcase_keywords (tcid, keywordid) VALUES (@tc1, @critical_kw);
INSERT INTO /*prefix*/testcase_keywords (tcid, keywordid) VALUES (@tc2, @security_kw);
INSERT INTO /*prefix*/testcase_keywords (tcid, keywordid) VALUES (@tc3, @regression_kw);
INSERT INTO /*prefix*/testcase_keywords (tcid, keywordid) VALUES (@tc4, @smoke_kw);

-- ============================================================================
-- SAMPLE BUILDS
-- ============================================================================

INSERT INTO /*prefix*/builds (testproject_id, name, notes, active, is_open, release_date, closed_on_date)
VALUES (@web_project_id, 'Build 1.0', 'Initial release candidate 1', 1, 0, NOW(), NULL);
SET @build1 = LAST_INSERT_ID();

INSERT INTO /*prefix*/builds (testproject_id, name, notes, active, is_open, release_date, closed_on_date)
VALUES (@web_project_id, 'Build 1.1', 'Release candidate 2 with bug fixes', 1, 1, NOW(), NULL);
SET @build2 = LAST_INSERT_ID();

-- ============================================================================
-- SAMPLE TEST PLANS
-- ============================================================================

-- Test Plan 1: Web App Authentication Testing
INSERT INTO /*prefix*/testplans (name, testproject_id, notes, active, is_public, api_key,
                                 testplan_manager_id, creation_ts)
VALUES ('Web App - Authentication Testing', @web_project_id,
        'Test plan for authentication module', 1, 1, 'webauth_plan_key',
        (SELECT id FROM /*prefix*/users WHERE login = 'manager' LIMIT 1), NOW());
SET @plan1 = LAST_INSERT_ID();

-- Test Plan 2: Web App Full Regression
INSERT INTO /*prefix*/testplans (name, testproject_id, notes, active, is_public, api_key,
                                 testplan_manager_id, creation_ts)
VALUES ('Web App - Full Regression Suite', @web_project_id,
        'Complete regression test suite', 1, 1, 'webfull_plan_key',
        (SELECT id FROM /*prefix*/users WHERE login = 'manager' LIMIT 1), NOW());
SET @plan2 = LAST_INSERT_ID();

-- ============================================================================
-- LINK TEST CASES TO TEST PLANS
-- ============================================================================

-- Link test cases to Plan 1
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan1, @tc1, @tcv1);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan1, @tc2, @tcv2);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan1, @tc3, @tcv3);

-- Link test cases to Plan 2
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc1, @tcv1);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc2, @tcv2);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc3, @tcv3);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc4, @tcv4);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc5, @tcv5);
INSERT INTO /*prefix*/test_plan_test_cases (testplan_id, testcaseid, tcversionid)
VALUES (@plan2, @tc6, @tcv6);

-- ============================================================================
-- SAMPLE TEST EXECUTIONS (Results)
-- ============================================================================

SET @tester1_id = (SELECT id FROM /*prefix*/users WHERE login = 'tester1' LIMIT 1);
SET @tester2_id = (SELECT id FROM /*prefix*/users WHERE login = 'tester2' LIMIT 1);

-- Executions for Plan 1, Build 1
-- TC1: Passed
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv1, @plan1, @build1, 'p', DATE_SUB(NOW(), INTERVAL 2 DAY), 'Test passed successfully', @tester1_id);

-- TC2: Failed
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv2, @plan1, @build1, 'f', DATE_SUB(NOW(), INTERVAL 2 DAY),
        'Failed: Error message not displayed correctly', @tester2_id);

-- TC3: Blocked
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv3, @plan1, @build1, 'b', DATE_SUB(NOW(), INTERVAL 1 DAY),
        'Blocked: Unable to test due to timeout not configured', @tester1_id);

-- Executions for Plan 1, Build 2 (retest)
-- TC1: Passed
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv1, @plan1, @build2, 'p', NOW(), 'Test passed successfully', @tester1_id);

-- TC2: Passed (bug fixed)
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv2, @plan1, @build2, 'p', NOW(), 'Bug fixed, test now passes', @tester2_id);

-- TC3: Passed
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv3, @plan1, @build2, 'p', NOW(), 'Test passed after timeout configuration', @tester1_id);

-- Executions for Plan 2
-- Full test suite executions in progress
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv1, @plan2, @build1, 'p', DATE_SUB(NOW(), INTERVAL 3 DAY), 'Passed', @tester1_id);
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv2, @plan2, @build1, 'p', DATE_SUB(NOW(), INTERVAL 3 DAY), 'Passed', @tester2_id);
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv3, @plan2, @build1, 'b', DATE_SUB(NOW(), INTERVAL 3 DAY), 'Blocked', @tester1_id);
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv4, @plan2, @build1, 'p', DATE_SUB(NOW(), INTERVAL 2 DAY), 'Passed', @tester2_id);
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv5, @plan2, @build1, 'p', DATE_SUB(NOW(), INTERVAL 2 DAY), 'Passed', @tester1_id);
INSERT INTO /*prefix*/executions (tcversion_id, tplan_id, build_id, status, execution_ts, notes, tester_id)
VALUES (@tcv6, @plan2, @build1, 'f', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Failed: Slow dashboard loading', @tester2_id);

-- ============================================================================
-- SAMPLE CUSTOM FIELDS
-- ============================================================================

-- Create custom field for priority
INSERT INTO /*prefix*/cfields (name, label, type, possible_values, default_value, active, enable_on_testcase)
VALUES ('priority', 'Test Priority', 1, 'High,Medium,Low', 'Medium', 1, 1);

-- Create custom field for test complexity
INSERT INTO /*prefix*/cfields (name, label, type, possible_values, default_value, active, enable_on_testcase)
VALUES ('complexity', 'Test Complexity', 1, 'Simple,Moderate,Complex', 'Moderate', 1, 1);

-- ============================================================================
-- ASSIGN USERS TO PROJECTS
-- ============================================================================

-- Assign users to Web Project
INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'admin' LIMIT 1), @web_project_id, 8); -- admin role

INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'manager' LIMIT 1), @web_project_id, 9); -- leader role

INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'senior_tester' LIMIT 1), @web_project_id, 6); -- senior tester role

INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester1' LIMIT 1), @web_project_id, 7); -- tester role

INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester2' LIMIT 1), @web_project_id, 7); -- tester role

INSERT INTO /*prefix*/user_testproject_roles
  (user_id, testproject_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'designer' LIMIT 1), @web_project_id, 4); -- test designer role

-- ============================================================================
-- ASSIGN USERS TO TEST PLANS
-- ============================================================================

INSERT INTO /*prefix*/user_testplan_roles
  (user_id, testplan_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester1' LIMIT 1), @plan1, 7); -- tester role

INSERT INTO /*prefix*/user_testplan_roles
  (user_id, testplan_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester2' LIMIT 1), @plan1, 7); -- tester role

INSERT INTO /*prefix*/user_testplan_roles
  (user_id, testplan_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester1' LIMIT 1), @plan2, 7); -- tester role

INSERT INTO /*prefix*/user_testplan_roles
  (user_id, testplan_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'tester2' LIMIT 1), @plan2, 7); -- tester role

INSERT INTO /*prefix*/user_testplan_roles
  (user_id, testplan_id, role_id)
VALUES ((SELECT id FROM /*prefix*/users WHERE login = 'senior_tester' LIMIT 1), @plan2, 6); -- senior tester role

-- ============================================================================
-- Sample Data Load Complete
-- ============================================================================
--
-- Summary of Sample Data Created:
-- - 7 Users (admin, manager, senior_tester, tester1, tester2, designer, guest)
-- - 3 Test Projects (Web Application, Mobile Application, API Testing)
-- - 3 Test Suites (Authentication, User Management, Dashboard)
-- - 6 Test Cases with complete steps
-- - 5 Keywords (smoke, regression, critical, ui, security)
-- - 2 Builds (Build 1.0, Build 1.1)
-- - 2 Test Plans
-- - 10 Test Executions with mixed results (Passed, Failed, Blocked)
-- - 2 Custom Fields
-- - Complete user assignments to projects and plans
--
-- Default Login Credentials:
-- - Username: admin / Password: admin (System Administrator)
-- - Username: manager / Password: manager (Test Manager)
-- - Username: tester1 / Password: tester1 (Tester)
-- - Username: tester2 / Password: tester2 (Tester)
-- - Username: senior_tester / Password: senior (Senior Tester)
-- - Username: designer / Password: designer (Test Designer)
-- - Username: guest / Password: guest (Guest User)
--
