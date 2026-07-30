#!/bin/bash
#===============================================================================
# Containeur Tester - Container Update Testing Script
# 
# This script tests a container update by:
# 1. Pulling the latest image
# 2. Creating a canary/test container with the new image
# 3. Monitoring the test container status
# 4. If healthy: updating the original container, removing test container
# 5. If failed: removing test container, reporting failure
#===============================================================================

# Source configuration
source /boot/config/plugins/containeur-tester/default.cfg 2>/dev/null || \
    source /usr/local/emhttp/plugins/containeur-tester/default.cfg

LOG_DIR="/boot/config/plugins/containeur-tester/logs"
STATE_FILE="/boot/config/plugins/containeur-tester/state/containeur-tester.state"
HISTORY_FILE="/boot/config/plugins/containeur-tester/state/test-history.json"

# Ensure directories exist
mkdir -p "$LOG_DIR"
mkdir -p "$(dirname "$STATE_FILE")"

# Timestamp for logging
log() {
    local level="$1"
    local message="$2"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" >> "$LOG_DIR/containeur-tester.log"
    echo "[$timestamp] [$level] $message"
}

# Function to get Docker command with optional host
get_docker_cmd() {
    if [ -n "$DOCKER_HOST" ]; then
        echo "docker -H $DOCKER_HOST"
    else
        echo "docker"
    fi
}

DOCKER=$(get_docker_cmd)

#===============================================================================
# Container State Management
#===============================================================================

# Save test state to file
save_state() {
    local container="$1"
    local status="$2"
    local details="$3"
    
    # Create a temporary file for safe writing
    local tmp_file="${STATE_FILE}.tmp"
    
    # If state file exists, update it; otherwise create new
    if [ -f "$STATE_FILE" ]; then
        # Remove existing entry for this container if exists
        grep -v "\"$container\":" "$STATE_FILE" > "$tmp_file" 2>/dev/null || true
        echo "{\"$container\": {\"status\": \"$status\", \"details\": \"$details\", \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}}" >> "$tmp_file"
    else
        echo "{\"$container\": {\"status\": \"$status\", \"details\": \"$details\", \"timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}}" > "$tmp_file"
    fi
    
    mv "$tmp_file" "$STATE_FILE"
}

# Get state for a container
get_state() {
    local container="$1"
    if [ -f "$STATE_FILE" ]; then
        local status=$(grep -o "\"$container\":{\"status\":\"[^\"]*\"" "$STATE_FILE" | grep -o "\"status\":\"[^\"]*\"" | cut -d'"' -f4)
        echo "$status"
    fi
}

#===============================================================================
# History Management
#===============================================================================

# Add test result to history
add_to_history() {
    local container="$1"
    local old_image="$2"
    local new_image="$3"
    local status="$4"
    local details="$5"
    
    local entry=$(cat <<EOF
{
    "container": "$container",
    "old_image": "$old_image",
    "new_image": "$new_image",
    "status": "$status",
    "details": "$details",
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
)
    
    # Append to history with JSON array management
    if [ -f "$HISTORY_FILE" ]; then
        # Remove trailing bracket and add comma + new entry
        local content=$(head -c -1 "$HISTORY_FILE" 2>/dev/null || echo "[]")
        if [ "$(echo "$content" | tail -c 1)" = "]" ]; then
            content="${content%?}"
            echo "$content,$entry]" > "$HISTORY_FILE"
        else
            echo "[$entry]" > "$HISTORY_FILE"
        fi
    else
        echo "[$entry]" > "$HISTORY_FILE"
    fi
}

#===============================================================================
# Docker Operations
#===============================================================================

# Get current image of a container
get_container_image() {
    local container="$1"
    $DOCKER inspect --format '{{.Config.Image}}' "$container" 2>/dev/null || echo ""
}

# Get container status
get_container_status() {
    local container="$1"
    $DOCKER inspect --format '{{.State.Status}}' "$container" 2>/dev/null || echo "not_found"
}

# Get container health (if healthcheck defined)
get_container_health() {
    local container="$1"
    $DOCKER inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no_healthcheck{{end}}' "$container" 2>/dev/null || echo "unknown"
}

# Check if container is running (and healthy if healthcheck exists)
is_container_healthy() {
    local container="$1"
    local status=$(get_container_status "$container")
    
    if [ "$status" != "running" ]; then
        return 1
    fi
    
    local health=$(get_container_health "$container")
    if [ "$health" = "unhealthy" ]; then
        return 1
    fi
    
    return 0
}

# Check if a newer image tag is available for a container
check_newer_image() {
    local container="$1"
    local current_image=$(get_container_image "$container")
    
    if [ -z "$current_image" ]; then
        echo "ERROR:Could not determine current image for $container"
        return 1
    fi
    
    log "INFO" "Current image for $container: $current_image"
    
    # Pull the latest version of the image
    log "INFO" "Pulling latest image: $current_image"
    local pull_output=$($DOCKER pull "$current_image" 2>&1)
    local pull_exit=$?
    
    if [ $pull_exit -ne 0 ]; then
        echo "ERROR:Pull failed: $pull_output"
        return 1
    fi
    
    # Check if the image was actually updated
    if echo "$pull_output" | grep -q "Status: Downloaded newer image"; then
        echo "NEW_IMAGE_AVAILABLE:$current_image"
        return 0
    elif echo "$pull_output" | grep -q "Status: Image is up to date"; then
        echo "UP_TO_DATE:$current_image"
        return 0
    else
        echo "UNKNOWN:$current_image"
        return 0
    fi
}

# Create a test container from the original container's configuration
create_test_container() {
    local container="$1"
    local image="$2"
    local test_name="${container}-test"
    
    log "INFO" "Creating test container: $test_name from image: $image"
    
    # Get the container's configuration
    local config=$($DOCKER inspect "$container" 2>/dev/null)
    if [ -z "$config" ]; then
        echo "ERROR:Could not inspect container $container"
        return 1
    fi
    
    # Extract configuration
    local cmd=$(echo "$config" | python3 -c "
import json, sys
data = json.load(sys.stdin)
c = data[0]
config = c.get('Config', {})
# Build docker run args
args = []
# Entrypoint
entrypoint = config.get('Entrypoint')
if entrypoint:
    args.extend(['--entrypoint', ' '.join(entrypoint)])
# Environment
env = config.get('Env', [])
for e in env:
    args.extend(['-e', e])
# Volumes
mounts = c.get('Mounts', [])
for m in mounts:
    if m.get('Type') == 'bind':
        args.extend(['-v', f\"{m['Source']}:{m['Destination']}\"])
    elif m.get('Type') == 'volume':
        args.extend(['-v', f\"{m['Name']}:{m['Destination']}\"])
# Ports
ports = c.get('NetworkSettings', {}).get('Ports', {}) or {}
for port, bindings in ports.items():
    if bindings:
        for b in bindings:
            args.extend(['-p', f\"{b['HostPort']}:{port.split('/')[0]}\"])
    else:
        args.extend(['-p', port.split('/')[0]])
# Network
networks = c.get('NetworkSettings', {}).get('Networks', {})
for net in networks:
    args.extend(['--network', net])
# Labels
labels = config.get('Labels', {})
for k, v in labels.items():
    args.extend(['-l', f'{k}={v}')
# Add test label
args.extend(['-l', 'containeur-tester=true'])
args.extend(['-l', f'containeur-tester.original={container}')
# Name
args.extend(['--name', f'{container}-test'])
print(' '.join(args))
" 2>/dev/null)
    
    if [ $? -ne 0 ]; then
        # Fallback: simpler create
        log "WARN" "Python parsing failed, using simple container creation"
        config=""
    fi
    
    # Stop and remove existing test container if any
    $DOCKER stop "$test_name" 2>/dev/null || true
    $DOCKER rm "$test_name" 2>/dev/null || true
    
    # Create the test container
    if [ -n "$config" ] && [ "$config" != " " ]; then
        local run_cmd="$DOCKER run -d $config $image"
        eval "$run_cmd 2>&1"
    else
        # Simple copy without port conflicts (use random ports)
        $DOCKER run -d --name "$test_name" \
            -l "containeur-tester=true" \
            -l "containeur-tester.original=$container" \
            "$image" 2>&1
    fi
    
    local run_exit=$?
    if [ $run_exit -ne 0 ]; then
        echo "ERROR:Failed to create test container"
        return 1
    fi
    
    echo "SUCCESS:$test_name"
    return 0
}

# Monitor a test container until timeout
monitor_test_container() {
    local container="$1"
    local timeout="${2:-$TEST_TIMEOUT}"
    local interval="${3:-$CHECK_INTERVAL}"
    
    log "INFO" "Monitoring test container $container (timeout: ${timeout}s, interval: ${interval}s)"
    
    local elapsed=0
    while [ $elapsed -lt $timeout ]; do
        local status=$(get_container_status "$container")
        
        if [ "$status" = "running" ]; then
            local health=$(get_container_health "$container")
            if [ "$health" = "healthy" ] || [ "$health" = "no_healthcheck" ]; then
                log "INFO" "Test container $container is healthy (status: $status, health: $health)"
                echo "HEALTHY"
                return 0
            elif [ "$health" = "unhealthy" ]; then
                log "WARN" "Test container $container is unhealthy"
                echo "UNHEALTHY"
                return 1
            fi
            # Still starting up, wait
        elif [ "$status" = "exited" ] || [ "$status" = "dead" ]; then
            local exit_code=$($DOCKER inspect --format '{{.State.ExitCode}}' "$container" 2>/dev/null)
            log "WARN" "Test container $container exited with code $exit_code"
            echo "DIED:$exit_code"
            return 1
        fi
        
        sleep "$interval"
        elapsed=$((elapsed + interval))
    done
    
    log "WARN" "Test container $container timed out after ${timeout}s"
    echo "TIMEOUT"
    return 1
}

# Update original container to new image
update_original_container() {
    local original="$1"
    local new_image="$2"
    
    log "INFO" "Updating container $original to image $new_image"
    
    # Get container config for recreation
    local config=$($DOCKER inspect "$original" 2>/dev/null)
    if [ -z "$config" ]; then
        echo "ERROR:Could not inspect original container $original"
        return 1
    fi
    
    # Stop the original container
    log "INFO" "Stopping original container $original"
    $DOCKER stop "$original" 2>&1 || {
        log "WARN" "Failed to stop $original (may already be stopped)"
    }
    
    # Remove the original container
    log "INFO" "Removing original container $original"
    $DOCKER rm "$original" 2>&1 || {
        echo "ERROR:Failed to remove original container $original"
        return 1
    }
    
    # Recreate with new image
    log "INFO" "Recreating $original with image $new_image"
    
    # Use the original container's config but with new image
    local recreate_cmd=$($DOCKER run -d \
        --name "$original" \
        $(echo "$config" | python3 -c "
import json, sys
data = json.load(sys.stdin)
c = data[0]
args = []
# Restart policy
restart = c.get('HostConfig', {}).get('RestartPolicy', {}).get('Name', 'no')
if restart == 'always':
    args.append('--restart=always')
elif restart == 'unless-stopped':
    args.append('--restart=unless-stopped')
elif restart == 'on-failure':
    max_retries = c.get('HostConfig', {}).get('RestartPolicy', {}).get('MaximumRetryCount', 0)
    args.append(f'--restart=on-failure:{max_retries}' if max_retries > 0 else '--restart=on-failure')
# Volumes
mounts = c.get('Mounts', [])
for m in mounts:
    if m.get('Type') == 'bind':
        args.extend(['-v', f\"{m['Source']}:{m['Destination']}\"])
    elif m.get('Type') == 'volume':
        args.extend(['-v', f\"{m['Name']}:{m['Destination']}\"])
# Ports
ports = c.get('NetworkSettings', {}).get('Ports', {}) or {}
for port, bindings in ports.items():
    if bindings:
        for b in bindings:
            args.extend(['-p', f\"{b['HostPort']}:{port.split('/')[0]}\"])
    else:
        args.extend(['-p', port.split('/')[0]])
# Env
env = c.get('Config', {}).get('Env', [])
for e in env:
    args.extend(['-e', e])
# Network
networks = c.get('NetworkSettings', {}).get('Networks', {})
for net in networks:
    args.extend(['--network', net])
# Labels
labels = c.get('Config', {}).get('Labels', {})
for k, v in labels.items():
    args.extend(['-l', f'{k}={v}')
print(' '.join(args))
" 2>/dev/null || echo "") \
        "$new_image" 2>&1)
    
    local recreate_exit=$?
    if [ $recreate_exit -ne 0 ]; then
        log "ERROR" "Failed to recreate $original: $recreate_cmd"
        echo "ERROR:$recreate_cmd"
        return 1
    fi
    
    log "INFO" "Successfully updated $original to $new_image"
    echo "SUCCESS:$original"
    return 0
}

# Clean up test container
cleanup_test_container() {
    local container="$1"
    local test_name="${container}-test"
    
    log "INFO" "Cleaning up test container $test_name"
    
    $DOCKER stop "$test_name" 2>/dev/null || true
    $DOCKER rm "$test_name" 2>/dev/null || true
    
    # Remove test label from original container if exists
    $DOCKER label "$container" "containeur-tester.testing-" 2>/dev/null || true
    
    # Clean state
    if [ -f "$STATE_FILE" ]; then
        local tmp_file="${STATE_FILE}.tmp"
        grep -v "\"$container\":" "$STATE_FILE" > "$tmp_file" 2>/dev/null || true
        mv "$tmp_file" "$STATE_FILE" 2>/dev/null || true
    fi
    
    log "INFO" "Cleanup complete for $container"
}

#===============================================================================
# Main Test Pipeline
#===============================================================================

test_container_update() {
    local container="$1"
    local force="${2:-false}"
    
    log "INFO" "Starting test pipeline for container: $container"
    
    # Check if already being tested
    local current_state=$(get_state "$container")
    if [ "$current_state" = "testing" ] && [ "$force" != "true" ]; then
        echo "ERROR:Container $container is already being tested"
        return 1
    fi
    
    save_state "$container" "testing" "Starting test pipeline"
    
    # Get current image
    local current_image=$(get_container_image "$container")
    if [ -z "$current_image" ]; then
        save_state "$container" "failed" "Could not determine current image"
        add_to_history "$container" "unknown" "unknown" "failed" "Could not determine current image"
        echo "ERROR:Could not determine current image for $container"
        return 1
    fi
    
    # Check for newer image
    local check_result=$(check_newer_image "$container")
    local check_status=$?
    
    if [ $check_status -ne 0 ]; then
        save_state "$container" "failed" "Image check failed: $check_result"
        add_to_history "$container" "$current_image" "$current_image" "failed" "Image check failed: $check_result"
        echo "ERROR:$check_result"
        return 1
    fi
    
    local check_type=$(echo "$check_result" | cut -d: -f1)
    local image_info=$(echo "$check_result" | cut -d: -f2-)
    
    if [ "$check_type" = "UP_TO_DATE" ]; then
        save_state "$container" "up_to_date" "Image is already up to date"
        add_to_history "$container" "$current_image" "$current_image" "up_to_date" "Image is already up to date"
        echo "UP_TO_DATE:$container"
        return 0
    fi
    
    # Create test container
    local test_result=$(create_test_container "$container" "$current_image")
    local test_status=$?
    
    if [ $test_status -ne 0 ]; then
        save_state "$container" "failed" "Failed to create test container: $test_result"
        add_to_history "$container" "$current_image" "$current_image" "failed" "Failed to create test container"
        echo "ERROR:$test_result"
        return 1
    fi
    
    local test_container=$(echo "$test_result" | cut -d: -f2-)
    log "INFO" "Test container created: $test_container"
    
    # Monitor the test container
    local monitor_result=$(monitor_test_container "${container}-test")
    local monitor_status=$?
    
    if [ $monitor_status -ne 0 ]; then
        log "WARN" "Test container failed health check"
        cleanup_test_container "$container"
        save_state "$container" "failed" "Health check failed: $monitor_result"
        add_to_history "$container" "$current_image" "$current_image" "failed" "Health check failed: $monitor_result"
        echo "FAILED:$container - $monitor_result"
        return 1
    fi
    
    # Test passed - update the original container
    log "INFO" "Test passed! Updating original container $container"
    
    local update_result=$(update_original_container "$container" "$current_image")
    local update_status=$?
    
    # Clean up test container
    cleanup_test_container "$container"
    
    if [ $update_status -ne 0 ]; then
        save_state "$container" "failed" "Update failed: $update_result"
        add_to_history "$container" "$current_image" "$current_image" "failed" "Update failed"
        echo "ERROR:Update failed: $update_result"
        return 1
    fi
    
    save_state "$container" "updated" "Successfully updated"
    add_to_history "$container" "$current_image" "$current_image" "success" "Container updated successfully"
    
    # Trigger Unraid notification if configured
    if [ "$NOTIFY_ON_SUCCESS" = "true" ]; then
        /usr/local/emhttp/webGui/scripts/notify \
            -e "Containeur Tester" \
            -s "Container Updated: $container" \
            -d "Container $container was successfully updated to the latest image." \
            -i "normal" 2>/dev/null || true
    fi
    
    echo "SUCCESS:$container updated successfully"
    return 0
}

#===============================================================================
# List all containers with their images
#===============================================================================

list_containers() {
    $DOCKER ps --format '{{.Names}}|{{.Image}}|{{.Status}}|{{.ID}}' 2>/dev/null
}

#===============================================================================
# Get test history for a container
#===============================================================================

get_history() {
    local container="${1:-}"
    local lines="${2:-50}"
    
    if [ ! -f "$HISTORY_FILE" ]; then
        echo "[]"
        return 0
    fi
    
    if [ -n "$container" ]; then
        python3 -c "
import json, sys
with open('$HISTORY_FILE') as f:
    data = json.load(f)
filtered = [e for e in data if e.get('container') == '$container']
print(json.dumps(filtered[-$lines:]))
" 2>/dev/null || echo "[]"
    else
        python3 -c "
import json, sys
with open('$HISTORY_FILE') as f:
    data = json.load(f)
print(json.dumps(data[-$lines:]))
" 2>/dev/null || echo "[]"
    fi
}

#===============================================================================
# Main entry point - dispatches commands
#===============================================================================

case "${1:-}" in
    test)
        test_container_update "$2" "$3"
        ;;
    list)
        list_containers
        ;;
    status)
        get_state "$2"
        ;;
    history)
        get_history "$2" "$3"
        ;;
    cleanup)
        cleanup_test_container "$2"
        ;;
    image)
        get_container_image "$2"
        ;;
    *)
        echo "Usage: $0 {test|list|status|history|cleanup|image} [args]"
        echo ""
        echo "Commands:"
        echo "  test <container> [force]  - Test and update a container"
        echo "  list                       - List all containers"
        echo "  status <container>         - Get test status for a container"
        echo "  history [container]        - Show test history"
        echo "  cleanup <container>        - Clean up test container"
        echo "  image <container>          - Show current image"
        exit 1
        ;;
esac