<?php
/*===============================================================================
 * Containeur Tester - Unraid Plugin Backend
 * 
 * PHP backend that handles API requests from the frontend dashboard
 * and executes the test-container.sh script for Docker operations.
 *==============================================================================*/

// Prevent direct access
if (!defined('EMHTTP')) {
    exit;
}

// Plugin configuration
$plugin_name = 'containeur-tester';
$plugin_path = "/usr/local/emhttp/plugins/{$plugin_name}";
$script_path = "{$plugin_path}/scripts/test-container.sh";
$config_path = "/boot/config/plugins/{$plugin_name}";
$state_file = "{$config_path}/state/containeur-tester.state";
$history_file = "{$config_path}/state/test-history.json";
$log_file = "{$config_path}/logs/containeur-tester.log";

// Ensure required directories
if (!is_dir("{$config_path}/logs")) {
    mkdir("{$config_path}/logs", 0755, true);
}
if (!is_dir("{$config_path}/state")) {
    mkdir("{$config_path}/state", 0755, true);
}

/*===============================================================================
 * Helper Functions
 *==============================================================================*/

/**
 * Execute a shell command and return the result
 */
function execute_command($command) {
    $output = [];
    $return_var = 0;
    exec($command . ' 2>&1', $output, $return_var);
    return [
        'output' => implode("\n", $output),
        'exit_code' => $return_var
    ];
}

/**
 * Execute the test-container.sh script with given arguments
 */
function run_script($action, $args = []) {
    global $script_path;
    
    $cmd = "bash {$script_path} {$action}";
    foreach ($args as $arg) {
        $escaped = escapeshellarg($arg);
        $cmd .= " {$escaped}";
    }
    
    return execute_command($cmd);
}

/**
 * Read and parse the state file
 */
function get_all_states() {
    global $state_file;
    
    if (!file_exists($state_file)) {
        return [];
    }
    
    $content = file_get_contents($state_file);
    if (empty($content)) {
        return [];
    }
    
    $states = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    return $states;
}

/**
 * Read test history from JSON file
 */
function get_test_history($container = '', $limit = 50) {
    global $history_file;
    
    if (!file_exists($history_file)) {
        return [];
    }
    
    $content = file_get_contents($history_file);
    if (empty($content)) {
        return [];
    }
    
    $history = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    // Filter by container if specified
    if (!empty($container)) {
        $history = array_filter($history, function($entry) use ($container) {
            return isset($entry['container']) && $entry['container'] === $container;
        });
        $history = array_values($history); // Reindex
    }
    
    // Apply limit (most recent first)
    $history = array_reverse($history);
    $history = array_slice($history, 0, $limit);
    
    return $history;
}

/**
 * Read the plugin configuration
 */
function get_plugin_config() {
    global $config_path;
    
    $config_file = "{$config_path}/default.cfg";
    $default_file = "/usr/local/emhttp/plugins/containeur-tester/default.cfg";
    
    // Start with defaults
    $config = [
        'TEST_TIMEOUT' => 120,
        'CHECK_INTERVAL' => 10,
        'MAX_PARALLEL_TESTS' => 3,
        'TEST_SCHEDULE' => '0 2 * * *',
        'LOG_RETENTION_DAYS' => 30,
        'DOCKER_HOST' => '',
        'NOTIFY_ON_SUCCESS' => 'true',
        'NOTIFY_ON_FAILURE' => 'true'
    ];
    
    // Load default config
    if (file_exists($default_file)) {
        $lines = file($default_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"");
            $config[$key] = $value;
        }
    }
    
    // Override with user config
    if (file_exists($config_file)) {
        $lines = file($config_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"");
            $config[$key] = $value;
        }
    }
    
    return $config;
}

/**
 * Save plugin configuration
 */
function save_plugin_config($config) {
    global $config_path;
    
    $config_file = "{$config_path}/default.cfg";
    $content = "# Containeur Tester Configuration\n";
    $content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($config as $key => $value) {
        $content .= "{$key}=\"{$value}\"\n";
    }
    
    return file_put_contents($config_file, $content) !== false;
}

/**
 * Read log file (last N lines)
 */
function get_logs($lines = 100) {
    global $log_file;
    
    if (!file_exists($log_file)) {
        return [];
    }
    
    $logs = file($log_file, FILE_IGNORE_NEW_LINES);
    if (!$logs) {
        return [];
    }
    
    return array_slice($logs, -$lines);
}

/**
 * Send Unraid notification
 */
function send_notification($subject, $message, $type = 'normal') {
    $notify_cmd = "/usr/local/emhttp/webGui/scripts/notify";
    if (file_exists($notify_cmd)) {
        $cmd = "{$notify_cmd} -e \"Containeur Tester\" -s " . escapeshellarg($subject) 
             . " -d " . escapeshellarg($message) . " -i {$type}";
        execute_command($cmd);
    }
}

/*===============================================================================
 * API Endpoint Handlers
 *==============================================================================*/

/**
 * Handle 'list_containers' API call
 */
function api_list_containers() {
    $result = run_script('list');
    
    if ($result['exit_code'] !== 0) {
        return [
            'success' => false,
            'error' => $result['output']
        ];
    }
    
    // Parse output: Name|Image|Status|ID
    $containers = [];
    $lines = explode("\n", trim($result['output']));
    
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 3) {
            $containers[] = [
                'name' => $parts[0],
                'image' => $parts[1],
                'status' => $parts[2],
                'id' => $parts[3] ?? ''
            ];
        }
    }
    
    // Enrich with test state
    $states = get_all_states();
    foreach ($containers as &$container) {
        $name = $container['name'];
        if (isset($states[$name])) {
            $container['test_status'] = $states[$name]['status'];
            $container['test_details'] = $states[$name]['details'] ?? '';
            $container['test_timestamp'] = $states[$name]['timestamp'] ?? '';
        } else {
            $container['test_status'] = 'idle';
            $container['test_details'] = '';
            $container['test_timestamp'] = '';
        }
    }
    
    return [
        'success' => true,
        'containers' => $containers
    ];
}

/**
 * Handle 'test_container' API call
 */
function api_test_container($container_name) {
    if (empty($container_name)) {
        return [
            'success' => false,
            'error' => 'Container name is required'
        ];
    }
    
    // Run test in background
    $result = run_script('test', [$container_name]);
    
    // Parse result
    $output = trim($result['output']);
    $lines = explode("\n", $output);
    $last_line = end($lines);
    
    if ($result['exit_code'] !== 0) {
        $error_msg = !empty($last_line) ? $last_line : $output;
        return [
            'success' => false,
            'error' => $error_msg
        ];
    }
    
    // Determine status from output
    if (strpos($last_line, 'SUCCESS:') === 0) {
        return [
            'success' => true,
            'message' => substr($last_line, 8),
            'status' => 'success'
        ];
    } elseif (strpos($last_line, 'UP_TO_DATE:') === 0) {
        return [
            'success' => true,
            'message' => 'Container is already up to date',
            'status' => 'up_to_date'
        ];
    } elseif (strpos($last_line, 'FAILED:') === 0) {
        return [
            'success' => false,
            'error' => substr($last_line, 7),
            'status' => 'failed'
        ];
    } else {
        return [
            'success' => true,
            'message' => $last_line,
            'status' => 'completed'
        ];
    }
}

/**
 * Handle 'get_status' API call
 */
function api_get_status($container_name) {
    $states = get_all_states();
    
    if (empty($container_name)) {
        return [
            'success' => true,
            'states' => $states
        ];
    }
    
    if (isset($states[$container_name])) {
        return [
            'success' => true,
            'status' => $states[$container_name]
        ];
    }
    
    return [
        'success' => true,
        'status' => [
            'status' => 'idle',
            'details' => '',
            'timestamp' => ''
        ]
    ];
}

/**
 * Handle 'get_history' API call
 */
function api_get_history($container_name = '', $limit = 50) {
    $history = get_test_history($container_name, $limit);
    
    return [
        'success' => true,
        'history' => $history
    ];
}

/**
 * Handle 'save_settings' API call
 */
function api_save_settings($settings) {
    $current_config = get_plugin_config();
    
    // Update only provided settings
    foreach ($settings as $key => $value) {
        if (array_key_exists($key, $current_config)) {
            $current_config[$key] = $value;
        }
    }
    
    $result = save_plugin_config($current_config);
    
    if ($result) {
        // Update cron schedule if changed
        if (isset($settings['TEST_SCHEDULE'])) {
            update_cron_schedule($settings['TEST_SCHEDULE']);
        }
        
        return [
            'success' => true,
            'message' => 'Settings saved successfully'
        ];
    }
    
    return [
        'success' => false,
        'error' => 'Failed to save settings'
    ];
}

/**
 * Update cron schedule
 */
function update_cron_schedule($schedule) {
    $cron_file = "/etc/cron.d/containeur-tester";
    $parts = explode(' ', $schedule);
    
    $content = "# Containeur Tester - Automatic container update testing\n";
    $content .= "{$schedule} root bash /usr/local/emhttp/plugins/containeur-tester/scripts/test-container.sh auto-test\n";
    
    file_put_contents($cron_file, $content);
    execute_command("/etc/rc.d/rc.crond reload");
}

/**
 * Handle 'get_settings' API call
 */
function api_get_settings() {
    return [
        'success' => true,
        'settings' => get_plugin_config()
    ];
}

/**
 * Handle 'get_logs' API call
 */
function api_get_logs($lines = 100) {
    $logs = get_logs($lines);
    
    return [
        'success' => true,
        'logs' => $logs
    ];
}

/*===============================================================================
 * Request Router
 *==============================================================================*/

/**
 * Main entry point - routes API requests
 */
function handle_request($action, $params = []) {
    switch ($action) {
        case 'list_containers':
            return api_list_containers();
            
        case 'test_container':
            return api_test_container($params['container'] ?? '');
            
        case 'get_status':
            return api_get_status($params['container'] ?? '');
            
        case 'get_history':
            return api_get_history(
                $params['container'] ?? '',
                intval($params['limit'] ?? 50)
            );
            
        case 'get_settings':
            return api_get_settings();
            
        case 'save_settings':
            return api_save_settings($params['settings'] ?? []);
            
        case 'get_logs':
            return api_get_logs(intval($params['lines'] ?? 100));
            
        default:
            return [
                'success' => false,
                'error' => "Unknown action: {$action}"
            ];
    }
}

/*===============================================================================
 * Dashboard Page Rendering
 *==============================================================================*/

/**
 * Render the main dashboard page
 */
function render_dashboard() {
    global $plugin_path, $plugin_name;
    
    $config = get_plugin_config();
    $states = get_all_states();
    $history = get_test_history('', 20);
    
    ?>
    <div id="<?= $plugin_name ?>-app">
        <!-- Header -->
        <div class="containeur-tester-header">
            <h1>
                <i class="fa fa-docker"></i> Containeur Tester
                <small>Test and verify container updates safely</small>
            </h1>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="refreshAll()" id="refresh-btn">
                    <i class="fa fa-refresh"></i> Refresh
                </button>
                <button class="btn" onclick="showSettings()">
                    <i class="fa fa-cog"></i> Settings
                </button>
                <button class="btn" onclick="showLogs()">
                    <i class="fa fa-file-text-o"></i> Logs
                </button>
            </div>
        </div>

        <!-- Status Bar -->
        <div class="containeur-tester-status-bar" id="status-bar">
            <span class="status-item">
                <span class="status-dot" id="connection-status-dot"></span>
                <span id="connection-status-text">Initializing...</span>
            </span>
            <span class="status-item">
                <strong>Schedule:</strong> 
                <span id="schedule-display"><?= htmlspecialchars($config['TEST_SCHEDULE']) ?></span>
            </span>
        </div>

        <!-- Main Content -->
        <div class="containeur-tester-content">
            <!-- Container List -->
            <div class="containeur-tester-section">
                <div class="section-header">
                    <h2>Running Containers</h2>
                    <div class="section-actions">
                        <button class="btn btn-success" onclick="testAllContainers()" id="test-all-btn">
                            <i class="fa fa-play-circle"></i> Test All
                        </button>
                    </div>
                </div>
                
                <div class="containeur-tester-table-wrapper">
                    <table class="containeur-tester-table" id="containers-table">
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Current Image</th>
                                <th>Status</th>
                                <th>Test Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="containers-tbody">
                            <tr>
                                <td colspan="5" class="loading-row">
                                    <i class="fa fa-spinner fa-spin"></i> Loading containers...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Test History -->
            <div class="containeur-tester-section">
                <div class="section-header">
                    <h2>Recent Test History</h2>
                    <div class="section-actions">
                        <button class="btn" onclick="showFullHistory()">
                            <i class="fa fa-history"></i> View All
                        </button>
                    </div>
                </div>
                
                <div class="containeur-tester-table-wrapper">
                    <table class="containeur-tester-table" id="history-table">
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Old Image</th>
                                <th>New Image</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="history-tbody">
                            <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="6" class="empty-row">
                                    <i class="fa fa-inbox"></i> No test history yet. Run a test to see results here.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars($entry['container'] ?? '') ?></td>
                                <td class="image-cell" title="<?= htmlspecialchars($entry['old_image'] ?? '') ?>">
                                    <?= htmlspecialchars(shorten_image($entry['old_image'] ?? '')) ?>
                                </td>
                                <td class="image-cell" title="<?= htmlspecialchars($entry['new_image'] ?? '') ?>">
                                    <?= htmlspecialchars(shorten_image($entry['new_image'] ?? '')) ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= htmlspecialchars($entry['status'] ?? 'unknown') ?>">
                                        <?= htmlspecialchars($entry['status'] ?? 'unknown') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($entry['details'] ?? '') ?></td>
                                <td class="timestamp-cell"><?= htmlspecialchars(format_timestamp($entry['timestamp'] ?? '')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Logs Modal -->
    <div id="logs-modal" class="modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Containeur Tester Logs</h2>
                <span class="modal-close" onclick="closeLogs()">&times;</span>
            </div>
            <div class="modal-body">
                <pre id="logs-content" class="logs-container"><i class="fa fa-spinner fa-spin"></i> Loading logs...</pre>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="refreshLogs()"><i class="fa fa-refresh"></i> Refresh</button>
                <button class="btn btn-primary" onclick="closeLogs()">Close</button>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal" class="modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Containeur Tester Settings</h2>
                <span class="modal-close" onclick="closeSettings()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="settings-form">
                    <div class="settings-group">
                        <h3>Test Configuration</h3>
                        
                        <label for="TEST_TIMEOUT">Health Check Timeout (seconds):</label>
                        <input type="number" id="TEST_TIMEOUT" name="TEST_TIMEOUT" 
                               value="<?= htmlspecialchars($config['TEST_TIMEOUT']) ?>" min="30" max="600">
                        <span class="help-text">How long to wait for a test container to become healthy</span>
                        
                        <label for="CHECK_INTERVAL">Check Interval (seconds):</label>
                        <input type="number" id="CHECK_INTERVAL" name="CHECK_INTERVAL" 
                               value="<?= htmlspecialchars($config['CHECK_INTERVAL']) ?>" min="1" max="60">
                        <span class="help-text">How often to poll test container status</span>
                        
                        <label for="MAX_PARALLEL_TESTS">Max Parallel Tests:</label>
                        <input type="number" id="MAX_PARALLEL_TESTS" name="MAX_PARALLEL_TESTS" 
                               value="<?= htmlspecialchars($config['MAX_PARALLEL_TESTS']) ?>" min="1" max="10">
                        <span class="help-text">Maximum number of containers to test simultaneously</span>
                    </div>
                    
                    <div class="settings-group">
                        <h3>Schedule</h3>
                        
                        <label for="TEST_SCHEDULE">Auto-Test Schedule (cron):</label>
                        <input type="text" id="TEST_SCHEDULE" name="TEST_SCHEDULE" 
                               value="<?= htmlspecialchars($config['TEST_SCHEDULE']) ?>">
                        <span class="help-text">
                            Cron expression for automatic testing. Examples:<br>
                            <code_edit>0 2 * * *</code_edit> - Daily at 2 AM<br>
                            <code_edit>0 */6 * * *</code_edit> - Every 6 hours<br>
                            <code_edit>0 0 * * 0</code_edit> - Weekly on Sunday
                        </span>
                        
                        <label for="LOG_RETENTION_DAYS">Log Retention (days):</label>
                        <input type="number" id="LOG_RETENTION_DAYS" name="LOG_RETENTION_DAYS" 
                               value="<?= htmlspecialchars($config['LOG_RETENTION_DAYS']) ?>" min="1" max="365">
                    </div>
                    
                    <div class="settings-group">
                        <h3>Notifications</h3>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" id="NOTIFY_ON_SUCCESS" name="NOTIFY_ON_SUCCESS" 
                                   <?= $config['NOTIFY_ON_SUCCESS'] === 'true' ? 'checked' : '' ?>>
                            Notify on successful update
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" id="NOTIFY_ON_FAILURE" name="NOTIFY_ON_FAILURE" 
                                   <?= $config['NOTIFY_ON_FAILURE'] === 'true' ? 'checked' : '' ?>>
                            Notify on test failure
                        </label>
                    </div>
                    
                    <div class="settings-group">
                        <h3>Docker</h3>
                        
                        <label for="DOCKER_HOST">Docker Host (optional):</label>
                        <input type="text" id="DOCKER_HOST" name="DOCKER_HOST" 
                               value="<?= htmlspecialchars($config['DOCKER_HOST']) ?>"
                               placeholder="tcp://192.168.1.100:2375">
                        <span class="help-text">Leave empty for local Docker socket. For remote hosts, use <code_edit>tcp://host:port</code_edit></span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="resetSettings()"><i class="fa fa-undo"></i> Reset to Defaults</button>
                <button class="btn btn-primary" onclick="saveSettings()"><i class="fa fa-save"></i> Save Settings</button>
                <button class="btn" onclick="closeSettings()">Cancel</button>
            </div>
        </div>
    </div>
    <?php
    // Ensure the page always renders something even if JS/assets are missing.
}

/**
 * Shorten image name for display
 */
function shorten_image($image, $max_length = 40) {
    if (strlen($image) <= $max_length) {
        return $image;
    }
    return substr($image, 0, $max_length - 3) . '...';
}

/**
 * Format timestamp for display
 */
function format_timestamp($timestamp) {
    if (empty($timestamp)) {
        return '';
    }
    
    $time = strtotime($timestamp);
    if ($time === false) {
        return $timestamp;
    }
    
    $diff = time() - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return "{$minutes}m ago";
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return "{$hours}h ago";
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return "{$days}d ago";
    } else {
        return date('M j, Y H:i', $time);
    }
}

/**
 * Save test results to PHP session for AJAX
 */
function save_test_result($container, $result) {
    global $config_path;
    $file = "{$config_path}/state/test-results.json";
    
    $results = [];
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $results = json_decode($content, true) ?? [];
    }
    
    $results[$container] = $result;
    file_put_contents($file, json_encode($results));
}

/*===============================================================================
 * Bootstrap - called when page loads
 *==============================================================================*/

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    $params = $_GET;
    
    // Merge POST data if present
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_data = json_decode(file_get_contents('php://input'), true);
        if (is_array($post_data)) {
            $params = array_merge($params, $post_data);
        }
    }
    
    $response = handle_request($action, $params);
    echo json_encode($response);
    exit;
}

?>
