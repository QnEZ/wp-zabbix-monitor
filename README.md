# WP Zabbix Monitor

**Full-stack WordPress monitoring plugin with native Zabbix integration.**

WP Zabbix Monitor bridges WordPress and [Zabbix](https://www.zabbix.com/) by exposing a secured REST API endpoint that Zabbix can poll (HTTP Agent / pull mode) and by pushing metrics directly to Zabbix via the Zabbix Sender protocol (trapper / push mode). A ready-to-import Zabbix XML template with items, dependent items, triggers, graphs, and a dashboard is included.

---

## Requirements

| Component | Minimum Version |
|---|---|
| WordPress | 5.9 |
| PHP | 7.4 |
| Zabbix Server / Proxy | 6.0 |
| PHP extension | `json`, `sockets` (for push mode) |

---

## Installation

**Step 1 — Install the plugin.**

Upload the `wp-zabbix-monitor` directory to `/wp-content/plugins/`, or install via the WordPress admin panel by uploading the ZIP file. Activate the plugin under **Plugins → Installed Plugins**.

**Step 2 — Note your API token.**

Navigate to **Settings → Zabbix Monitor → REST API** tab. Copy the auto-generated API token. This token authenticates all Zabbix HTTP Agent requests.

**Step 3 — Import the Zabbix template.**

In your Zabbix frontend, go to **Configuration → Templates → Import** and upload the file:

```
wp-zabbix-monitor/zabbix-template/wordpress-monitoring.xml
```

**Step 4 — Create a Zabbix host.**

In Zabbix, create a new host representing your WordPress site under **Data collection → Hosts → Create host**. Assign the imported template **"WordPress by WP Zabbix Monitor"** to the host.

**Step 5 — Configure host macros.**

On the host's **Macros** tab, set the following user macros:

| Macro | Value | Example |
|---|---|---|
| `{$WP_URL}` | WordPress site URL (no trailing slash) | `https://example.com` |
| `{$WP_API_TOKEN}` | API token from plugin settings | `abc123...` |

**Step 6 — (Optional) Enable push mode.**

For active push (Zabbix Trapper items), go to **Settings → Zabbix Monitor → Zabbix Connection** and fill in the Zabbix server hostname, port, and host name. Enable **Push Metrics** and choose a push interval.

---

## Metric Reference

### Performance Group (`performance`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.performance.load_time_ms` | Time elapsed since WordPress bootstrap | ms |
| `wordpress.performance.memory_usage_mb` | Current PHP memory usage | MB |
| `wordpress.performance.memory_peak_mb` | Peak PHP memory usage | MB |
| `wordpress.performance.memory_limit_mb` | PHP `memory_limit` setting | MB |
| `wordpress.performance.wp_memory_limit_mb` | WordPress `WP_MEMORY_LIMIT` | MB |

### Database Group (`database`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.database.query_count` | Total DB queries per request | count |
| `wordpress.database.query_time_ms` | Total DB query time per request | ms |
| `wordpress.database.slow_queries` | Queries exceeding 50 ms | count |
| `wordpress.database.db_size_mb` | Total database size | MB |
| `wordpress.database.autoload_size_kb` | Autoloaded options size | KB |

### Users Group (`users`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.users.total` | Total registered users | count |
| `wordpress.users.new_24h` | New registrations in last 24 hours | count |
| `wordpress.users.admin_count` | Users with administrator role | count |
| `wordpress.users.active_sessions` | Users with active session tokens | count |

### Content Group (`content`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.content.published_posts` | Published posts | count |
| `wordpress.content.published_pages` | Published pages | count |
| `wordpress.content.draft_posts` | Draft posts | count |
| `wordpress.content.custom_post_types` | Published custom post type items | count |
| `wordpress.content.media_files` | Media library items | count |
| `wordpress.content.comments_approved` | Approved comments | count |
| `wordpress.content.comments_pending` | Pending comments | count |
| `wordpress.content.comments_spam` | Spam comments | count |

### Plugins Group (`plugins`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.plugins.total` | Total installed plugins | count |
| `wordpress.plugins.active` | Active plugins | count |
| `wordpress.plugins.inactive` | Inactive plugins | count |
| `wordpress.plugins.needs_update` | Plugins with updates available | count |
| `wordpress.plugins.mu_plugins` | Must-use plugins | count |

### PHP Group (`php`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.php.version` | PHP version string | string |
| `wordpress.php.memory_limit_mb` | `memory_limit` ini value | MB |
| `wordpress.php.max_execution_time` | `max_execution_time` ini value | s |
| `wordpress.php.upload_max_mb` | `upload_max_filesize` | MB |
| `wordpress.php.opcache_enabled` | OPcache status (1=on, 0=off) | bool |
| `wordpress.php.opcache_hit_rate` | OPcache cache hit rate | % |

### Server Group (`server`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.server.disk_total_gb` | Total disk space | GB |
| `wordpress.server.disk_free_gb` | Free disk space | GB |
| `wordpress.server.disk_used_gb` | Used disk space | GB |
| `wordpress.server.disk_used_pct` | Disk usage percentage | % |
| `wordpress.server.wp_debug` | WP_DEBUG constant (1=on, 0=off) | bool |
| `wordpress.server.wp_debug_log` | WP_DEBUG_LOG constant | bool |

### Cron Group (`cron`)

| Zabbix Item Key | Description | Unit |
|---|---|---|
| `wordpress.cron.total_events` | Total scheduled cron events | count |
| `wordpress.cron.overdue_events` | Overdue cron events | count |
| `wordpress.cron.next_event_in` | Seconds until next cron event | s |

---

## REST API Reference

### Authentication

All metric endpoints require authentication. Pass the API token using one of these methods:

```http
Authorization: Bearer <your-api-token>
```

or as a query parameter:

```
GET /wp-json/wpzm/v1/metrics?token=<your-api-token>
```

### Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/wp-json/wpzm/v1/ping` | None | Health check; returns plugin version and timestamp |
| `GET` | `/wp-json/wpzm/v1/metrics` | Bearer token | Full metrics payload (all enabled groups) |
| `GET` | `/wp-json/wpzm/v1/metrics?groups=performance,database` | Bearer token | Selected metric groups only |
| `GET` | `/wp-json/wpzm/v1/metrics/{group}` | Bearer token | Single metric group |

### Example Response

```json
{
  "timestamp": 1704067200,
  "site_url": "https://example.com",
  "wp_version": "6.4.2",
  "performance": {
    "load_time_ms": 124.5,
    "memory_usage_mb": 32.4,
    "memory_peak_mb": 38.1,
    "memory_limit_mb": 256,
    "wp_memory_limit_mb": 256
  },
  "database": {
    "query_count": 23,
    "query_time_ms": 18.3,
    "slow_queries": 0,
    "db_size_mb": 45.2,
    "autoload_size_kb": 312.8
  }
}
```

---

## Zabbix Template Reference

### Template Name

`WordPress by WP Zabbix Monitor`

### Collection Modes

**HTTP Agent (pull mode):** The master item `wordpress.metrics.raw` polls the REST API every 60 seconds. All other metric items are **Dependent Items** that extract values from the master item's JSON payload using JSONPath preprocessing. This approach makes a single HTTP request per polling cycle regardless of how many metrics are configured.

**Zabbix Trapper (push mode):** When push mode is enabled in the plugin, WordPress pushes all metric values directly to Zabbix using the Zabbix Sender protocol on TCP port 10051. Create matching Trapper items in Zabbix with the same item keys listed in the Metric Reference above.

### Triggers

| Name | Severity | Condition |
|---|---|---|
| Site is unreachable | High | `wordpress.ping = 0` |
| Page load time critical | High | 5-min avg > `{$WP_LOAD_TIME_HIGH}` (3000ms) |
| Page load time warning | Warning | 5-min avg > `{$WP_LOAD_TIME_WARN}` (1000ms) |
| Slow database queries | Warning | `slow_queries > 0` |
| High query count | Warning | `query_count > {$WP_DB_QUERY_WARN}` (100) |
| Disk usage critical | High | `disk_used_pct > {$WP_DISK_HIGH}` (90%) |
| Disk usage warning | Warning | `disk_used_pct > {$WP_DISK_WARN}` (80%) |
| Plugin updates available | Info | `needs_update >= {$WP_PLUGIN_UPDATES_WARN}` (1) |
| Cron events overdue | Warning | `overdue_events >= {$WP_OVERDUE_CRON_WARN}` (3) |
| WP_DEBUG enabled | Warning | `wp_debug = 1` |
| OPcache disabled | Warning | `opcache_enabled = 0` |

### Graphs

Six pre-built graphs are included:

1. **Performance Overview** — load time (left axis) vs. memory usage (right axis)
2. **Database Activity** — query count, query time, and slow query count
3. **User Growth** — total users vs. new registrations per 24h
4. **Disk Usage** — disk used percentage vs. free space in GB
5. **OPcache Hit Rate** — OPcache efficiency over time
6. **Database Size Growth** — total DB size vs. autoload options size

---

## Security Considerations

The REST API endpoint is protected by a bearer token that is auto-generated on activation. The token is stored in the WordPress options table and never exposed in page source. For additional hardening, configure the **IP Allowlist** in the plugin settings to restrict access to your Zabbix server's IP address only. The token can be regenerated at any time from the **REST API** settings tab without affecting other plugin functionality.

---

## Frequently Asked Questions

**Does this plugin require a Zabbix agent installed on the server?**
No. In pull mode, Zabbix polls the WordPress REST API using the built-in HTTP Agent item type, which requires no agent on the WordPress host. In push mode, the plugin uses PHP's native socket functions to connect to the Zabbix server.

**Can I use this with Zabbix Proxy?**
Yes. In pull mode, configure the proxy to poll the WordPress URL. In push mode, set the Zabbix server address to your proxy's hostname/IP.

**Does it work with WordPress Multisite?**
The plugin activates per-site. Each site in a multisite network will have its own API token and settings. Add each site as a separate host in Zabbix.

**How do I enable SAVEQUERIES for database timing?**
Add `define('SAVEQUERIES', true);` to `wp-config.php`. Note that this has a small performance overhead and should be used carefully in production.

---

## Changelog

### 1.0.0
Initial release. Full metric collection across 8 groups, HTTP Agent pull mode, Zabbix Sender push mode, WP-Cron scheduling, admin settings page with live data tab, dashboard widget, and Zabbix XML template.

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
