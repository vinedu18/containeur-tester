# Containeur Tester - Unraid Plugin

## Overview

Containeur Tester is an Unraid plugin that safely tests Docker container updates before applying them to your production containers. It creates temporary test (canary) containers from the latest images, verifies they run correctly, and only then updates the original containers.

## Features

- **Safe Testing**: Creates isolated test containers to verify updates work
- **Web Dashboard**: Full Unraid dashboard integration under "Tools" menu
- **Health Checks**: Monitors container status (running, healthy, unhealthy)
- **Automatic Updates**: If test passes, updates the original container seamlessly
- **Rollback Protection**: If test fails, removes test container without affecting production
- **Scheduled Checks**: Configurable cron-based automatic testing
- **Test History**: Complete log of all tests performed
- **Notifications**: Unraid notifications on success/failure
- **Dark Mode**: Full compatibility with Unraid dark theme

## Installation

### Via Community Applications (Recommended)
1. Open Unraid WebUI
2. Go to Apps tab
3. Search for "Containeur Tester"
4. Click Install

### Manual Installation
1. Download the plugin file
2. In Unraid WebUI, go to Plugins tab
3. Click "Install Plugin"
4. Paste the plugin URL or browse to the `.plg` file

## Usage

### Dashboard
Navigate to **Tools → Containeur Tester** in your Unraid WebUI.

### Testing a Container
1. The dashboard shows all running containers
2. Click "Test Update" next to any container
3. The plugin will:
   - Pull the latest image
   - Create a test container with `-test` suffix
   - Monitor container health for configurable timeout
   - If healthy: update the original container
   - If failed: remove test container, report failure

### Batch Testing
Click "Test All" to test all containers with available updates.

### Automatic Testing
Configure a cron schedule in Settings to automatically test and update containers.

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| Test Timeout | 120s | How long to wait for test container to become healthy |
| Check Interval | 10s | How often to poll container status |
| Max Parallel Tests | 3 | Maximum simultaneous test containers |
| Test Schedule | 0 2 * * * | Cron expression for automatic testing |
| Log Retention | 30 days | How long to keep logs |
| Notify on Success | true | Send notification on successful update |
| Notify on Failure | true | Send notification on test failure |

## How It Works

1. **Pull Latest Image**: The plugin pulls the latest version of the container's image
2. **Create Canary**: A test container is created with the new image, duplicating the original's configuration (volumes, ports, networks, environment)
3. **Monitor Health**: The plugin checks if the test container starts successfully and passes any defined healthchecks
4. **Promote**: If the test passes, the original container is stopped, removed, and recreated with the new image
5. **Cleanup**: The test container is removed regardless of outcome
6. **Report**: Results are logged and notifications are sent

## File Locations

- **Plugin Files**: `/usr/local/emhttp/plugins/containeur-tester/`
- **Configuration**: `/boot/config/plugins/containeur-tester/default.cfg`
- **Logs**: `/boot/config/plugins/containeur-tester/logs/`
- **State**: `/boot/config/plugins/containeur-tester/state/`
- **Test History**: `/boot/config/plugins/containeur-tester/state/test-history.json`

## Troubleshooting

### Test container fails to start
- Check Docker logs: `docker logs <container>-test`
- Verify the container's image is compatible
- Check resource limits (memory, CPU)

### Plugin not appearing in Tools menu
- Refresh the Unraid WebUI page
- Clear browser cache
- Check plugin installation status in Plugins tab

### Permission errors
- Ensure the plugin has execute permissions
- Run: `chmod +x /usr/local/emhttp/plugins/containeur-tester/scripts/*.sh`

## Support

- GitHub Issues: [Repository URL]
- Unraid Forums: [Forum Thread URL]

## License

MIT License - See LICENSE file for details.