<?php
/**
 * Create Header test cases via XML-RPC API
 */
require_once '/home/sebi/testlink/third_party/xml-rpc/class-IXR.php';

$server_url = 'http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php';
$devKey = 'tl_dev_key_2026';
$tproject_id = 11;
$suite_id = 12; // Header

$testcases = [
    ['name' => 'Project Selector Displays All Accessible Projects',
     'summary' => '<p>Verify the project selector dropdown in the header displays all test projects the logged-in user has access to.</p>',
     'preconditions' => '<p>User is logged in as admin with access to multiple test projects (P2, P3, TLU, TP).</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the header area and locate the "Test Project" dropdown.</p>', 'expected_results' => '<p>The dropdown is visible and contains the label "Test Project".</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the project selector dropdown to expand it.</p>', 'expected_results' => '<p>Dropdown opens showing all accessible projects: P2:Project Two, P3:Project Three, TLU:TLU: TestLink Upgraded 2.0.1, TP:Test Project.</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify each option follows the format Prefix:Full Name.</p>', 'expected_results' => '<p>All options display correctly with the naming convention.</p>']
     ]],
    ['name' => 'Current Project Is Pre-selected in Header',
     'summary' => '<p>Verify the currently active project is pre-selected in the header dropdown.</p>',
     'preconditions' => '<p>User is logged in as admin. Currently active project is TLU.</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the project selector dropdown value in the header.</p>', 'expected_results' => '<p>The dropdown shows "TLU:TLU: TestLink Upgraded 2.0.1" as the visible/selected value.</p>'],
         ['step_number' => 2, 'actions' => '<p>Open the dropdown and check which option has the selected state.</p>', 'expected_results' => '<p>The TLU option has the selected attribute, matching the current test project context.</p>']
     ]],
    ['name' => 'Switch Test Project Changes Context',
     'summary' => '<p>Verify selecting a different project from the header dropdown reloads the application with the new project context.</p>',
     'preconditions' => '<p>User is logged in as admin in TLU project. Has access to P2:Project Two.</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Note the current project name displayed in the dropdown (TLU).</p>', 'expected_results' => '<p>TLU project is currently displayed.</p>'],
         ['step_number' => 2, 'actions' => '<p>Open the project selector dropdown and select "P2:Project Two".</p>', 'expected_results' => '<p>The application reloads with the new project context.</p>'],
         ['step_number' => 3, 'actions' => '<p>After reload, check the project selector dropdown value and main content.</p>', 'expected_results' => '<p>"P2:Project Two" is now the selected value. The entire application context (tree, content area) shows P2 data.</p>']
     ]],
    ['name' => 'Switch Project Preserves Feature Context',
     'summary' => '<p>Verify that when switching projects, the application attempts to return to the same feature/page type in the new project (e.g. remain on Test Specification).</p>',
     'preconditions' => '<p>User is on Test Specification in TLU project. Has access to TP:Test Project.</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Test Specification (editTc feature).</p>', 'expected_results' => '<p>Test Specification tree is displayed in the left panel.</p>'],
         ['step_number' => 2, 'actions' => '<p>Switch to TP:Test Project via the header dropdown.</p>', 'expected_results' => '<p>Application reloads.</p>'],
         ['step_number' => 3, 'actions' => '<p>Check if the main content area shows Test Specification for TP project.</p>', 'expected_results' => '<p>The page returns to Test Specification (or the closest equivalent) in the new project context, instead of the default dashboard.</p>']
     ]],
    ['name' => 'Logo Click Reloads Main View',
     'summary' => '<p>Verify clicking the TestLink logo in the header navigates back to the main dashboard for the current project.</p>',
     'preconditions' => '<p>User is on any sub-page (e.g. Test Specification).</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Test Specification or any non-dashboard page.</p>', 'expected_results' => '<p>Sub-page content is displayed in the main frame.</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the TestLink logo image in the top-left of the header.</p>', 'expected_results' => '<p>The page navigates to index.php showing the main dashboard for the current project.</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify the URL contains the correct tproject_id parameter.</p>', 'expected_results' => '<p>URL matches index.php?tproject_id=X where X is the current project ID.</p>']
     ]],
    ['name' => 'Logo Image Loads Without Error',
     'summary' => '<p>Verify the TestLink logo image in the header loads correctly without 404 or broken image icon.</p>',
     'preconditions' => '<p>User is logged in.</p>',
     'importance' => 0, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Inspect the header visually and check the browser network tab for the logo image request.</p>', 'expected_results' => '<p>Logo (tl-logo-transparent-25.png) loads with HTTP 200 status and is fully visible.</p>']
     ]],
    ['name' => 'Header Displays Logged-In User Info',
     'summary' => '<p>Verify the header displays the logged-in user name and role in the format "username [role]".</p>',
     'preconditions' => '<p>User "admin" with role "admin" is logged in.</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>After login, observe the top-right area of the header.</p>', 'expected_results' => '<p>Text "admin [admin]" is displayed as a clickable link.</p>'],
         ['step_number' => 2, 'actions' => '<p>Inspect the element title attribute via browser devtools.</p>', 'expected_results' => '<p>The title attribute shows "admin [admin]".</p>']
     ]],
    ['name' => 'User Info Link Opens Account Settings',
     'summary' => '<p>Verify clicking the user info link in the header opens the Account Settings page (userInfo.php) in the main content frame, showing personal data, password change, API key, and login history.</p>',
     'preconditions' => '<p>User is logged in as admin.</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click on "admin [admin]" link in the header.</p>', 'expected_results' => '<p>The main content frame loads userInfo.php (Account Settings).</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify the page contains sections: Personal data, Personal password, API interface, Login history.</p>', 'expected_results' => '<p>All four sections are visible with correct headings and content.</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify the API interface section shows the current API key.</p>', 'expected_results' => '<p>Personal API access key is displayed with a "Generate a new key" button.</p>'],
         ['step_number' => 4, 'actions' => '<p>Verify the link opens in the mainframe (not a new tab).</p>', 'expected_results' => '<p>The link has target="mainframe" and opens within the content area.</p>']
     ]],
    ['name' => 'Logout Terminates Session and Redirects to Login',
     'summary' => '<p>Verify clicking Logout terminates the user session and redirects to the login page.</p>',
     'preconditions' => '<p>User is logged in as admin.</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click the "Logout" link in the header.</p>', 'expected_results' => '<p>The session is terminated and the login page is displayed.</p>'],
         ['step_number' => 2, 'actions' => '<p>Try to navigate back to the application using the browser back button.</p>', 'expected_results' => '<p>User cannot access protected content; login page is shown instead.</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify the Logout link uses target="top" to break out of all iframes.</p>', 'expected_results' => '<p>The Logout link has href="logout.php?viewer=" and target="top".</p>']
     ]],
    ['name' => 'Sidebar Toggle Collapses and Expands Navigation',
     'summary' => '<p>Verify the hamburger icon (fa-bars) in the header toggles the left sidebar navigation between collapsed and expanded states.</p>',
     'preconditions' => '<p>User is logged in. Sidebar is initially visible/expanded.</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the left sidebar is visible with menu items (System, Projects, Requirements, Test Case Design, etc.).</p>', 'expected_results' => '<p>Sidebar is expanded showing all menu sections.</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the hamburger icon (fa-bars) in the header.</p>', 'expected_results' => '<p>The sidebar collapses/hides, giving more space to the main content area.</p>'],
         ['step_number' => 3, 'actions' => '<p>Click the hamburger icon again.</p>', 'expected_results' => '<p>The sidebar expands back to its original state showing all menu items.</p>']
     ]],
    ['name' => 'All Header Elements Present After Fresh Login',
     'summary' => '<p>Smoke test: verify all header elements are present and visible immediately after login.</p>',
     'preconditions' => '<p>User is on the login page with valid credentials (admin/admin).</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Login with valid credentials admin/admin.</p>', 'expected_results' => '<p>Login successful, main application loads.</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify the following header elements are present and visible: hamburger toggle icon, TestLink logo, "Test Project" label, project selector dropdown, user info link (admin [admin]), Logout link.</p>', 'expected_results' => '<p>All 6 header elements are visible and functional.</p>']
     ]],
    ['name' => 'CSRF Token Present in Project Switch Form',
     'summary' => '<p>Verify the project switch form in the header includes CSRF protection tokens to prevent cross-site request forgery.</p>',
     'preconditions' => '<p>User is logged in.</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Inspect the HTML source of the header project switch form via browser devtools.</p>', 'expected_results' => '<p>Form contains hidden input fields: CSRFName (with value starting with CSRFGuard_) and CSRFToken (with a long hex string).</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify the form action is index.php?action=projectChange and method is POST.</p>', 'expected_results' => '<p>Form correctly targets the project change endpoint with POST method.</p>']
     ]],
];

$created = 0;
$failed = 0;
$errors = [];

foreach ($testcases as $tc) {
    $args = [
        'devKey' => $devKey,
        'testprojectid' => $tproject_id,
        'testsuiteid' => $suite_id,
        'testcasename' => $tc['name'],
        'summary' => $tc['summary'],
        'preconditions' => $tc['preconditions'],
        'authorlogin' => 'admin',
        'checkduplicatedname' => 0,
        'status' => 1,
        'importance' => $tc['importance'],
        'steps' => $tc['steps'],
    ];

    $client = new IXR_Client($server_url);
    $client->debug = false;

    if (!$client->query('tl.createTestCase', $args)) {
        $failed++;
        $err = $client->getErrorCode() . ': ' . $client->getErrorMessage();
        $errors[] = "{$tc['name']}: {$err}";
        echo "FAIL[{$failed}] {$tc['name']} - {$err}\n";
    } else {
        $response = $client->getResponse();
        if ($response === true || is_array($response)) {
            $created++;
            echo "OK  [{$created}] {$tc['name']}\n";
        } else {
            $failed++;
            $errors[] = "{$tc['name']}: unexpected response";
            echo "FAIL[{$failed}] {$tc['name']} - unexpected response\n";
        }
    }
}

echo "\n=== HEADER SUITE SUMMARY ===\n";
echo "Created: {$created}\n";
echo "Failed: {$failed}\n";
if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
