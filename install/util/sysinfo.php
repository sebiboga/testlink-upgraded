<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	sysinfo.php
 * @author		Martin Havlat / Updated for v1.9.20
 *
 * System information and diagnostics utility
 * Provides comprehensive PHP, MySQL, server environment diagnostics
 *
 * @since 1.9.4
 * @version 1.9.20
 */

// Handle CLI mode
define('IS_CLI', php_sapi_name() === 'cli');

// Get basic paths
$scriptDir = dirname(__FILE__);
$installDir = dirname($scriptDir);
$rootDir = dirname($installDir);

// Try to load TestLink config
if (file_exists($rootDir . '/config.inc.php')) {
    require_once($rootDir . '/config.inc.php');
}

/**
 * Check PHP version
 */
function checkPHPVersion() {
    $version = phpversion();
    $minVersion = '7.4';
    $status = version_compare($version, $minVersion, '>=') ? 'OK' : 'ERROR';

    return [
        'version' => $version,
        'minimum' => $minVersion,
        'status' => $status,
        'message' => "PHP $version " . ($status === 'OK' ? "✓" : "✗ (minimum: $minVersion)")
    ];
}

/**
 * Check PHP extensions
 */
function checkPHPExtensions() {
    $required = [
        'mysqli' => 'Database support',
        'gd' => 'Image processing',
        'mbstring' => 'String handling',
        'json' => 'JSON support',
        'curl' => 'HTTP requests',
        'spl' => 'SPL support',
        'pdo' => 'PDO support'
    ];

    $results = [];
    foreach ($required as $ext => $description) {
        $loaded = extension_loaded($ext);
        $results[$ext] = [
            'name' => $ext,
            'description' => $description,
            'loaded' => $loaded,
            'status' => $loaded ? 'OK' : 'MISSING'
        ];
    }

    return $results;
}

/**
 * Check memory limits
 */
function checkMemoryLimits() {
    $limit = ini_get('memory_limit');
    $max_execution = ini_get('max_execution_time');
    $upload_max = ini_get('upload_max_filesize');
    $post_max = ini_get('post_max_size');

    // Parse memory limit
    $limitValue = intval($limit);
    $minMemory = 256; // 256 MB minimum
    $recommended = 512; // 512 MB recommended

    return [
        'memory_limit' => $limit,
        'max_execution_time' => $max_execution . ' seconds',
        'upload_max_filesize' => $upload_max,
        'post_max_size' => $post_max,
        'memory_status' => $limitValue >= $recommended ? 'OK' : ($limitValue >= $minMemory ? 'WARN' : 'ERROR'),
        'message' => "Memory: $limit (Recommended: {$recommended}M+), Upload: $upload_max, POST: $post_max"
    ];
}

/**
 * Check database connectivity
 */
function checkDatabase() {
    $result = [
        'available' => false,
        'version' => 'N/A',
        'status' => 'UNAVAILABLE',
        'message' => 'Database configuration not loaded'
    ];

    // Try to get DB info from config
    if (isset($GLOBALS['tlCfg']) && isset($GLOBALS['tlCfg']->db)) {
        $dbConfig = $GLOBALS['tlCfg']->db;

        try {
            // Try to create connection
            $conn = @mysqli_connect(
                $dbConfig->host,
                $dbConfig->user,
                $dbConfig->password,
                $dbConfig->name
            );

            if ($conn) {
                $version = mysqli_get_server_info($conn);
                $result = [
                    'available' => true,
                    'version' => $version,
                    'host' => $dbConfig->host,
                    'database' => $dbConfig->name,
                    'status' => 'OK',
                    'message' => "MySQL $version ✓"
                ];
                mysqli_close($conn);
            } else {
                $result['status'] = 'ERROR';
                $result['message'] = 'Failed to connect: ' . mysqli_connect_error();
            }
        } catch (Exception $e) {
            $result['status'] = 'ERROR';
            $result['message'] = 'Exception: ' . $e->getMessage();
        }
    }

    return $result;
}

/**
 * Check server environment
 */
function checkServerEnvironment() {
    return [
        'os' => php_uname(),
        'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
        'php_api' => php_sapi_name(),
        'zend_version' => zend_version(),
        'timezone' => date_default_timezone_get(),
        'file_uploads' => ini_get('file_uploads') ? 'Yes' : 'No',
        'display_errors' => ini_get('display_errors') ? 'Yes' : 'No'
    ];
}

/**
 * Check file permissions
 */
function checkFilePermissions($rootDir) {
    $paths = [
        'config.inc.php' => 'Config file',
        'gui/templates' => 'Templates directory',
        'lib' => 'Library directory',
        'install' => 'Install directory'
    ];

    $results = [];
    foreach ($paths as $path => $description) {
        $fullPath = $rootDir . '/' . $path;
        $exists = file_exists($fullPath);
        $readable = $exists && is_readable($fullPath);
        $writable = $exists && is_writable($fullPath);

        $results[$path] = [
            'path' => $path,
            'description' => $description,
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'status' => ($exists && $readable) ? 'OK' : 'ERROR'
        ];
    }

    return $results;
}

/**
 * Format output for CLI or web
 */
function formatOutput($data, $title) {
    if (IS_CLI) {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "$title\n";
        echo str_repeat("=", 70) . "\n";
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    echo "  $key:\n";
                    foreach ($value as $k => $v) {
                        echo "    $k: $v\n";
                    }
                } else {
                    echo "  $key: $value\n";
                }
            }
        }
    }
}

// Start output
if (!IS_CLI) {
    ?>
<!DOCTYPE HTML>
<html>
<head>
    <title>TestLink - System Information & Diagnostics</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #007bff; }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .status-warn { color: #ffc107; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        .footer { margin-top: 30px; text-align: center; color: #666; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 TestLink v1.9.20 - System Information & Diagnostics</h1>
    <?php
}

// Collect all information
$phpVersion = checkPHPVersion();
$phpExtensions = checkPHPExtensions();
$memory = checkMemoryLimits();
$database = checkDatabase();
$server = checkServerEnvironment();
$permissions = checkFilePermissions($rootDir);

// Output PHP Version
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($phpVersion, 'PHP Version'); } else {
    echo '<h2>✓ PHP Information</h2>';
    echo '<table>';
    echo '<tr><th>Item</th><th>Value</th><th>Status</th></tr>';
    echo '<tr><td>Version</td><td>' . $phpVersion['version'] . '</td>';
    echo '<td class="' . ($phpVersion['status'] === 'OK' ? 'status-ok' : 'status-error') . '">' . $phpVersion['status'] . '</td></tr>';
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Output PHP Extensions
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($phpExtensions, 'PHP Extensions'); } else {
    echo '<h2>✓ PHP Extensions</h2>';
    echo '<table>';
    echo '<tr><th>Extension</th><th>Description</th><th>Status</th></tr>';
    foreach ($phpExtensions as $ext => $info) {
        $statusClass = $info['loaded'] ? 'status-ok' : 'status-error';
        $status = $info['loaded'] ? '✓ Loaded' : '✗ Missing';
        echo '<tr><td>' . $ext . '</td><td>' . $info['description'] . '</td>';
        echo '<td class="' . $statusClass . '">' . $status . '</td></tr>';
    }
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Output Memory Limits
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($memory, 'Memory & Execution Limits'); } else {
    echo '<h2>✓ Memory & Execution Limits</h2>';
    echo '<table>';
    echo '<tr><th>Setting</th><th>Value</th></tr>';
    echo '<tr><td>Memory Limit</td><td>' . $memory['memory_limit'] . '</td></tr>';
    echo '<tr><td>Max Execution Time</td><td>' . $memory['max_execution_time'] . '</td></tr>';
    echo '<tr><td>Upload Max Size</td><td>' . $memory['upload_max_filesize'] . '</td></tr>';
    echo '<tr><td>POST Max Size</td><td>' . $memory['post_max_size'] . '</td></tr>';
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Output Database
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($database, 'Database'); } else {
    echo '<h2>✓ Database Connection</h2>';
    echo '<table>';
    echo '<tr><th>Item</th><th>Value</th><th>Status</th></tr>';
    echo '<tr><td>Status</td><td>' . ($database['available'] ? 'Connected' : 'Not Available') . '</td>';
    echo '<td class="' . ($database['status'] === 'OK' ? 'status-ok' : 'status-error') . '">' . $database['status'] . '</td></tr>';
    if ($database['available']) {
        echo '<tr><td>MySQL Version</td><td>' . $database['version'] . '</td><td class="status-ok">OK</td></tr>';
        echo '<tr><td>Host</td><td>' . $database['host'] . '</td><td>Info</td></tr>';
        echo '<tr><td>Database</td><td>' . $database['database'] . '</td><td>Info</td></tr>';
    }
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Output Server Environment
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($server, 'Server Environment'); } else {
    echo '<h2>✓ Server Environment</h2>';
    echo '<table>';
    echo '<tr><th>Setting</th><th>Value</th></tr>';
    echo '<tr><td>OS</td><td>' . $server['os'] . '</td></tr>';
    echo '<tr><td>Server Software</td><td>' . $server['server_software'] . '</td></tr>';
    echo '<tr><td>PHP API</td><td>' . $server['php_api'] . '</td></tr>';
    echo '<tr><td>Zend Version</td><td>' . $server['zend_version'] . '</td></tr>';
    echo '<tr><td>Timezone</td><td>' . $server['timezone'] . '</td></tr>';
    echo '<tr><td>File Uploads</td><td>' . $server['file_uploads'] . '</td></tr>';
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Output File Permissions
if (!IS_CLI) { echo '<div class="section">'; }
if (IS_CLI) { formatOutput($permissions, 'File Permissions'); } else {
    echo '<h2>✓ File Permissions</h2>';
    echo '<table>';
    echo '<tr><th>Path</th><th>Exists</th><th>Readable</th><th>Writable</th><th>Status</th></tr>';
    foreach ($permissions as $path => $info) {
        $statusClass = $info['status'] === 'OK' ? 'status-ok' : 'status-error';
        echo '<tr>';
        echo '<td>' . $path . '</td>';
        echo '<td>' . ($info['exists'] ? '✓' : '✗') . '</td>';
        echo '<td>' . ($info['readable'] ? '✓' : '✗') . '</td>';
        echo '<td>' . ($info['writable'] ? '✓' : '✗') . '</td>';
        echo '<td class="' . $statusClass . '">' . $info['status'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
if (!IS_CLI) { echo '</div>'; }

// Final summary
if (!IS_CLI) {
    echo '<div class="footer">';
    echo '<p>TestLink System Information Diagnostic Complete</p>';
    echo '<p><small>For support, visit: <a href="https://github.com/sebiboga/testlink-upgraded" target="_blank">GitHub</a></small></p>';
    echo '</div>';
    echo '</div></body></html>';
} else {
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "System Information Diagnostic Complete\n";
    echo str_repeat("=", 70) . "\n\n";
}
?>
