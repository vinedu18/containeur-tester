/**
 * Containeur Tester - Frontend Dashboard JavaScript
 * 
 * Provides the interactive dashboard for testing container updates
 */

// Global state
const CT = {
    containers: [],
    pollingInterval: null,
    settingsDefaults: {
        TEST_TIMEOUT: 120,
        CHECK_INTERVAL: 10,
        MAX_PARALLEL_TESTS: 3,
        TEST_SCHEDULE: '0 2 * * *',
        LOG_RETENTION_DAYS: 30,
        DOCKER_HOST: '',
        NOTIFY_ON_SUCCESS: true,
        NOTIFY_ON_FAILURE: true
    }
};

/**
 * Initialize the dashboard
 */
function initDashboard() {
    console.log('Containeur Tester: Initializing dashboard...');
    refreshContainers();
    updateStatusBar();
    
    // Set up periodic refresh (every 30 seconds)
    setInterval(() => {
        refreshContainers();
    }, 30000);
}

/**
 * Refresh all container data from the server
 */
function refreshAll() {
    refreshContainers();
    refreshHistory();
    updateStatusBar();
}

/**
 * Fetch and display running containers
 */
function refreshContainers() {
    const tbody = document.getElementById('containers-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="5" class="loading-row"><i class="fa fa-spinner fa-spin"></i> Loading containers...</td></tr>';
    
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=list_containers')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                CT.containers = data.containers;
                renderContainers(data.containers);
            } else {
                showError('Failed to load containers: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            showError('Network error loading containers: ' + error.message);
        });
}

/**
 * Render container list in the table
 */
function renderContainers(containers) {
    const tbody = document.getElementById('containers-tbody');
    if (!tbody) return;
    
    if (!containers || containers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-row"><i class="fa fa-docker"></i> No running containers found</td></tr>';
        return;
    }
    
    let html = '';
    containers.forEach(container => {
        const testStatus = container.test_status || 'idle';
        const isTesting = testStatus === 'testing';
        const isUpdated = testStatus === 'updated';
        const isFailed = testStatus === 'failed' || testStatus === 'error';
        
        html += `
        <tr class="container-row" data-name="${escapeHtml(container.name)}">
            <td class="container-name-cell">
                <span class="container-name">${escapeHtml(container.name)}</span>
                <span class="container-id">${escapeHtml(container.id || '')}</span>
            </td>
            <td class="image-cell" title="${escapeHtml(container.image || '')}">
                <span class="image-name">${escapeHtml(shortenImage(container.image || '', 45))}</span>
            </td>
            <td>
                <span class="status-badge status-${getContainerStatusClass(container.status || '')}">
                    ${escapeHtml(container.status || 'unknown')}
                </span>
            </td>
            <td>
                <span class="status-badge status-${testStatus}" data-test-status="${testStatus}">
                    ${isTesting ? '<i class="fa fa-spinner fa-spin"></i> ' : ''}
                    ${escapeHtml(formatTestStatus(testStatus))}
                </span>
                ${isFailed && container.test_details ? `<br><small class="error-detail">${escapeHtml(container.test_details)}</small>` : ''}
                ${container.test_timestamp ? `<br><small class="timestamp">${formatTimestamp(container.test_timestamp)}</small>` : ''}
            </td>
            <td class="actions-cell">
                ${isTesting ? `
                    <span class="testing-badge"><i class="fa fa-spinner fa-spin"></i> Testing...</span>
                    <button class="btn btn-sm" onclick="viewTestDetails('${escapeHtml(container.name)}')">
                        <i class="fa fa-info-circle"></i>
                    </button>
                ` : `
                    <button class="btn btn-sm btn-primary" onclick="testContainer('${escapeHtml(container.name)}')" 
                            ${isTesting ? 'disabled' : ''}>
                        <i class="fa fa-play"></i> Test Update
                    </button>
                    <button class="btn btn-sm" onclick="viewTestHistory('${escapeHtml(container.name)}')" 
                            title="View test history">
                        <i class="fa fa-history"></i>
                    </button>
                `}
            </td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

/**
 * Test a single container
 */
function testContainer(containerName) {
    if (!confirm(`Test and update container "${containerName}"?`)) {
        return;
    }
    
    // Update UI immediately
    const rows = document.querySelectorAll('.container-row');
    rows.forEach(row => {
        if (row.dataset.name === containerName) {
            const testStatusCell = row.querySelector('[data-test-status]');
            if (testStatusCell) {
                testStatusCell.className = 'status-badge status-testing';
                testStatusCell.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testing...';
            }
            const actionsCell = row.querySelector('.actions-cell');
            if (actionsCell) {
                actionsCell.innerHTML = '<span class="testing-badge"><i class="fa fa-spinner fa-spin"></i> Testing...</span>';
            }
        }
    });
    
    // Send test request
    fetch('/plugins/containeur-tester/include/containeur-tester.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'test_container',
            container: containerName
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess(`Container "${containerName}" update: ${data.message || data.status}`);
        } else {
            showError(`Test failed for "${containerName}": ${data.error || 'Unknown error'}`);
        }
        // Refresh to show updated status
        setTimeout(refreshContainers, 2000);
    })
    .catch(error => {
        showError(`Network error testing "${containerName}": ${error.message}`);
        setTimeout(refreshContainers, 2000);
    });
}

/**
 * Test all containers that have available updates
 */
function testAllContainers() {
    const containersToTest = CT.containers.filter(c => 
        c.test_status !== 'testing' && c.test_status !== 'updated'
    );
    
    if (containersToTest.length === 0) {
        showInfo('All containers are up to date or already being tested.');
        return;
    }
    
    if (!confirm(`Test and update ${containersToTest.length} container(s)?`)) {
        return;
    }
    
    const btn = document.getElementById('test-all-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testing All...';
    }
    
    let completed = 0;
    let failed = 0;
    
    containersToTest.forEach((container, index) => {
        setTimeout(() => {
            fetch('/plugins/containeur-tester/include/containeur-tester.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'test_container',
                    container: container.name
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    completed++;
                } else {
                    failed++;
                }
                
                // Check if all done
                if (completed + failed === containersToTest.length) {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-play-circle"></i> Test All';
                    }
                    showSuccess(`Batch test complete: ${completed} succeeded, ${failed} failed`);
                    setTimeout(refreshContainers, 2000);
                }
            })
            .catch(error => {
                failed++;
                if (completed + failed === containersToTest.length) {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-play-circle"></i> Test All';
                    }
                    showSuccess(`Batch test complete: ${completed} succeeded, ${failed} failed`);
                    setTimeout(refreshContainers, 2000);
                }
            });
        }, index * 1000); // Stagger requests
    });
}

/**
 * Refresh the test history table
 */
function refreshHistory() {
    const tbody = document.getElementById('history-tbody');
    if (!tbody) return;
    
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=get_history&limit=20')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderHistory(data.history);
            }
        })
        .catch(error => {
            console.error('Failed to load history:', error);
        });
}

/**
 * Render test history in the table
 */
function renderHistory(history) {
    const tbody = document.getElementById('history-tbody');
    if (!tbody) return;
    
    if (!history || history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-row"><i class="fa fa-inbox"></i> No test history yet. Run a test to see results here.</td></tr>';
        return;
    }
    
    let html = '';
    history.forEach(entry => {
        html += `
        <tr>
            <td>${escapeHtml(entry.container || '')}</td>
            <td class="image-cell" title="${escapeHtml(entry.old_image || '')}">${escapeHtml(shortenImage(entry.old_image || '', 35))}</td>
            <td class="image-cell" title="${escapeHtml(entry.new_image || '')}">${escapeHtml(shortenImage(entry.new_image || '', 35))}</td>
            <td><span class="status-badge status-${entry.status || 'unknown'}">${escapeHtml(entry.status || 'unknown')}</span></td>
            <td>${escapeHtml(entry.details || '')}</td>
            <td class="timestamp-cell">${formatTimestamp(entry.timestamp || '')}</td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

/**
 * Update the status bar
 */
function updateStatusBar() {
    const dot = document.getElementById('connection-status-dot');
    const text = document.getElementById('connection-status-text');
    const schedule = document.getElementById('schedule-display');
    
    if (dot && text) {
        dot.className = 'status-dot status-dot-green';
        text.textContent = 'Connected';
    }
    
    // Load settings for schedule
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=get_settings')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.settings && schedule) {
                schedule.textContent = data.settings.TEST_SCHEDULE || 'Not set';
            }
        })
        .catch(() => {
            if (dot && text) {
                dot.className = 'status-dot status-dot-red';
                text.textContent = 'Disconnected';
            }
        });
}

/**
 * Show settings modal
 */
function showSettings() {
    document.getElementById('settings-modal').style.display = 'block';
    
    // Load current settings
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=get_settings')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.settings) {
                const settings = data.settings;
                document.getElementById('TEST_TIMEOUT').value = settings.TEST_TIMEOUT || 120;
                document.getElementById('CHECK_INTERVAL').value = settings.CHECK_INTERVAL || 10;
                document.getElementById('MAX_PARALLEL_TESTS').value = settings.MAX_PARALLEL_TESTS || 3;
                document.getElementById('TEST_SCHEDULE').value = settings.TEST_SCHEDULE || '0 2 * * *';
                document.getElementById('LOG_RETENTION_DAYS').value = settings.LOG_RETENTION_DAYS || 30;
                document.getElementById('DOCKER_HOST').value = settings.DOCKER_HOST || '';
                document.getElementById('NOTIFY_ON_SUCCESS').checked = settings.NOTIFY_ON_SUCCESS === 'true';
                document.getElementById('NOTIFY_ON_FAILURE').checked = settings.NOTIFY_ON_FAILURE === 'true';
            }
        });
}

/**
 * Close settings modal
 */
function closeSettings() {
    document.getElementById('settings-modal').style.display = 'none';
}

/**
 * Save settings from the form
 */
function saveSettings() {
    const settings = {
        TEST_TIMEOUT: parseInt(document.getElementById('TEST_TIMEOUT').value) || 120,
        CHECK_INTERVAL: parseInt(document.getElementById('CHECK_INTERVAL').value) || 10,
        MAX_PARALLEL_TESTS: parseInt(document.getElementById('MAX_PARALLEL_TESTS').value) || 3,
        TEST_SCHEDULE: document.getElementById('TEST_SCHEDULE').value || '0 2 * * *',
        LOG_RETENTION_DAYS: parseInt(document.getElementById('LOG_RETENTION_DAYS').value) || 30,
        DOCKER_HOST: document.getElementById('DOCKER_HOST').value || '',
        NOTIFY_ON_SUCCESS: document.getElementById('NOTIFY_ON_SUCCESS').checked ? 'true' : 'false',
        NOTIFY_ON_FAILURE: document.getElementById('NOTIFY_ON_FAILURE').checked ? 'true' : 'false'
    };
    
    fetch('/plugins/containeur-tester/include/containeur-tester.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'save_settings',
            settings: settings
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Settings saved successfully');
            closeSettings();
            updateStatusBar();
        } else {
            showError('Failed to save settings: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        showError('Network error saving settings: ' + error.message);
    });
}

/**
 * Reset settings to defaults
 */
function resetSettings() {
    if (!confirm('Reset all settings to defaults?')) return;
    
    const settings = { ...CT.settingsDefaults };
    
    fetch('/plugins/containeur-tester/include/containeur-tester.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_settings',
            settings: settings
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Settings reset to defaults');
            closeSettings();
            updateStatusBar();
        } else {
            showError('Failed to reset settings: ' + (data.error || 'Unknown error'));
        }
    });
}

/**
 * Show logs modal
 */
function showLogs() {
    document.getElementById('logs-modal').style.display = 'block';
    refreshLogs();
}

/**
 * Close logs modal
 */
function closeLogs() {
    document.getElementById('logs-modal').style.display = 'none';
}

/**
 * Refresh logs content
 */
function refreshLogs() {
    const content = document.getElementById('logs-content');
    if (!content) return;
    
    content.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading logs...';
    
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=get_logs&lines=200')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.logs) {
                content.textContent = data.logs.join('\n');
                content.scrollTop = content.scrollHeight;
            } else {
                content.textContent = 'No logs available';
            }
        })
        .catch(error => {
            content.textContent = 'Error loading logs: ' + error.message;
        });
}

/**
 * View test details for a container
 */
function viewTestDetails(containerName) {
    fetch(`/plugins/containeur-tester/include/containeur-tester.php?action=get_status&container=${encodeURIComponent(containerName)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.status) {
                const msg = `Container: ${containerName}\nStatus: ${data.status.status}\nDetails: ${data.status.details || 'N/A'}\nTimestamp: ${data.status.timestamp || 'N/A'}`;
                alert(msg);
            } else {
                showError('Could not load test details');
            }
        });
}

/**
 * View test history for a specific container
 */
function viewTestHistory(containerName) {
    fetch(`/plugins/containeur-tester/include/containeur-tester.php?action=get_history&container=${encodeURIComponent(containerName)}&limit=50`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.history) {
                let msg = `Test History for "${containerName}":\n`;
                msg += '='.repeat(50) + '\n';
                
                if (data.history.length === 0) {
                    msg += 'No tests recorded yet.';
                } else {
                    data.history.forEach(entry => {
                        msg += `\nStatus: ${entry.status}\n`;
                        msg += `Old Image: ${entry.old_image || 'N/A'}\n`;
                        msg += `New Image: ${entry.new_image || 'N/A'}\n`;
                        msg += `Details: ${entry.details || 'N/A'}\n`;
                        msg += `Time: ${formatTimestamp(entry.timestamp || '')}\n`;
                        msg += '-'.repeat(30) + '\n';
                    });
                }
                
                // Show in a larger dialog
                const modal = document.getElementById('logs-modal');
                const content = document.getElementById('logs-content');
                modal.style.display = 'block';
                content.textContent = msg;
            } else {
                showError('Could not load history');
            }
        });
}

/**
 * Show full history in logs modal
 */
function showFullHistory() {
    fetch('/plugins/containeur-tester/include/containeur-tester.php?action=get_history&limit=200')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.history) {
                const modal = document.getElementById('logs-modal');
                const content = document.getElementById('logs-content');
                modal.style.display = 'block';
                
                let msg = '=== Complete Test History ===\n\n';
                if (data.history.length === 0) {
                    msg += 'No test history available.';
                } else {
                    data.history.forEach((entry, i) => {
                        msg += `#${i + 1}\n`;
                        msg += `Container: ${entry.container || 'N/A'}\n`;
                        msg += `Status: ${entry.status}\n`;
                        msg += `Old Image: ${entry.old_image || 'N/A'}\n`;
                        msg += `New Image: ${entry.new_image || 'N/A'}\n`;
                        msg += `Details: ${entry.details || 'N/A'}\n`;
                        msg += `Time: ${formatTimestamp(entry.timestamp || '')}\n`;
                        msg += '='.repeat(40) + '\n\n';
                    });
                }
                
                content.textContent = msg;
            }
        });
}

/**
 * Show a notification message
 */
function showNotification(message, type = 'info') {
    // Remove existing notification
    const existing = document.querySelector('.containeur-tester-notification');
    if (existing) existing.remove();
    
    const notification = document.createElement('div');
    notification.className = `containeur-tester-notification notification-${type}`;
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-circle' : 
                 'fa-info-circle';
    
    notification.innerHTML = `
        <i class="fa ${icon}"></i>
        <span>${escapeHtml(message)}</span>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Helper: Show success notification
 */
function showSuccess(message) {
    showNotification(message, 'success');
}

/**
 * Helper: Show error notification
 */
function showError(message) {
    showNotification(message, 'error');
}

/**
 * Helper: Show info notification
 */
function showInfo(message) {
    showNotification(message, 'info');
}

/**
 * Escape HTML entities
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Shorten a long image name for display
 */
function shortenImage(image, maxLength = 40) {
    if (!image || image.length <= maxLength) return image;
    return image.substring(0, maxLength - 3) + '...';
}

/**
 * Format test status for display
 */
function formatTestStatus(status) {
    const statusMap = {
        'idle': 'Idle',
        'testing': 'Testing...',
        'updated': '✓ Updated',
        'success': '✓ Success',
        'failed': '✗ Failed',
        'error': '✗ Error',
        'up_to_date': 'Up to Date',
        'skipped': 'Skipped'
    };
    return statusMap[status] || status || 'Unknown';
}

/**
 * Get CSS class for container status
 */
function getContainerStatusClass(status) {
    if (!status) return 'unknown';
    if (status.includes('running')) return 'running';
    if (status.includes('Up')) return 'running';
    if (status.includes('exited')) return 'exited';
    if (status.includes('paused')) return 'paused';
    return 'unknown';
}

/**
 * Format timestamp for display
 */
function formatTimestamp(timestamp) {
    if (!timestamp) return '';
    
    const date = new Date(timestamp);
    if (isNaN(date.getTime())) return timestamp;
    
    const now = new Date();
    const diffMs = now - date;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);
    
    if (diffSec < 60) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHour < 24) return `${diffHour}h ago`;
    if (diffDay < 7) return `${diffDay}d ago`;
    
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Initialize when DOM is ready
 */
document.addEventListener('DOMContentLoaded', initDashboard);