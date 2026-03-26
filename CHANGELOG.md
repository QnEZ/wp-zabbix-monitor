# Changelog

All notable changes to WP Zabbix Monitor will be documented in this file.

## [1.3.1] - 2026-03-26

### Fixed
- **Admin UI**: Added missing Matomo settings tab to WordPress admin page
  - Matomo URL, API Token, and Site ID configuration fields now visible
  - Matomo metrics documentation and collection info displayed
  - Settings properly saved and validated

### Technical Details
- Updated admin-page.php template with Matomo tab and form fields
- Matomo group added to enabled_metrics checkboxes
- All Matomo settings properly integrated into settings form

---

## [1.3.0] - 2026-03-23

### Added
- **Matomo Integration**: New matomo metric group collects analytics data from self-hosted Matomo instances
  - Site-level metrics: pageviews, unique visitors, bounce rate, avg session duration
  - Traffic sources: direct, organic, referral, social, campaigns
  - Top 5 most-visited pages with hit counts
  - Matomo settings fields: URL, API token, site ID (configurable in WordPress admin)
  - 12 new Zabbix dependent items with JSONPath parsing
  - 3 new triggers: low traffic, high bounce rate, low engagement
  - 2 new graphs: Traffic & Engagement and Traffic Sources
  - Metrics collected on same WP-Cron schedule as other groups
  - Exposed via REST API when matomo group is enabled

### Changed
- Updated WPZM_Settings to support Matomo configuration
- Updated WPZM_Metrics to include Matomo collector in the metrics collection pipeline
- Zabbix template now includes 52 total items (40 WordPress + 12 Matomo)

### Technical Details
- New class: WPZM_Matomo handles Matomo API communication
- Uses WordPress wp_remote_get() for HTTP requests with 10-second timeout
- Graceful error handling if Matomo is not configured or unreachable
- Matomo metrics are optional; plugin works without Matomo configuration

---

## [1.2.13] - 2026-03-23

### Fixed
- **Push mode failure**: Zabbix Sender now sends a single JSON blob to `wordpress.metrics.push` (TRAPPER master item) instead of individual flat keys. This resolves the "Processed: 0, Failed: 47" error where Zabbix was rejecting all metrics because DEPENDENT items cannot receive data from Sender directly—only from their master item.
- **Template architecture**: Updated Zabbix template to include a new TRAPPER master item (`wordpress.metrics.push`) for push mode, with all 40 dependent items now pointing to this master for proper JSON parsing via JSONPath.

### Changed
- Zabbix Sender class (`class-wpzm-sender.php`): `push()` method now encodes the full metrics structure as JSON and sends it to `wordpress.metrics.push` key.
- Zabbix template (`wordpress-monitoring.xml`): All dependent items now reference `wordpress.metrics.push` as master instead of `wordpress.metrics.raw`.

### Technical Details
- **Push mode**: Plugin sends JSON blob → `wordpress.metrics.push` (TRAPPER) → dependent items parse via JSONPath
- **Pull mode**: HTTP Agent polls REST API → `wordpress.metrics.raw` (HTTP_AGENT) → stored as reference
- Both modes can coexist; dependent items populate from whichever master receives data

---

## [1.2.12] - 2026-03-22

### Fixed
- Settings sanitization: `sanitize_callback` now returns the sanitized array instead of `true` or `WP_Error`, fixing critical save errors in admin settings page.

---

## [1.2.11] - 2026-03-21

### Fixed
- Missing `require_once` for `class-wpzm-provisioner.php` causing fatal error when auto-provisioning feature was accessed.

---

## [1.2.10] - 2026-03-20

### Fixed
- Zabbix Server Host field now auto-strips URL schemes (https://, http://) to extract hostname only, allowing users to paste full URLs.

---

## [1.2.9] - 2026-03-19

### Changed
- Author name updated to "QnEZ Servers".

---

## [1.2.8] - 2026-03-18

### Fixed
- Zabbix 7.x API authentication: Moved from deprecated `auth` field to `Authorization: Bearer` header format for JSON-RPC 2.0 API calls.

---

## [1.2.7] - 2026-03-17

### Fixed
- Zabbix template compatibility with 7.4 schema: Fixed item types, preprocessing steps, and value mappings.

---

## [1.2.6] - 2026-03-16

### Fixed
- Zabbix template: Corrected graph definitions and dashboard widget configurations for Zabbix 7.4.

---

## [1.2.5] - 2026-03-15

### Fixed
- Zabbix template: Updated trigger expressions and item dependencies for Zabbix 7.4 compatibility.

---

## [1.2.4] - 2026-03-14

### Fixed
- Zabbix template: Corrected JSONPath preprocessing parameters and error handlers.

---

## [1.2.3] - 2026-03-13

### Fixed
- Zabbix template: Initial 7.4 schema compatibility fixes.

---

## [1.2.2] - 2026-03-12

### Fixed
- ZIP packaging: Ensured consistent `wp-zabbix-monitor/` directory name for proper WordPress plugin installation.

---

## [1.2.1] - 2026-03-11

### Fixed
- Admin page blank tab bug: Fixed JavaScript syntax error preventing proper tab switching in settings page.

---

## [1.2.0] - 2026-03-10

### Added
- **WooCommerce metrics group**: 22 store metrics including orders, revenue, products, customers, and reviews.
- Zabbix template items for WooCommerce metrics with 16 items, 5 triggers, and 2 graphs.
- HPOS (High-Performance Order Storage) support for WooCommerce compatibility.

---

## [1.1.0] - 2026-03-09

### Added
- **Auto-provisioning feature**: Automatically create Zabbix hosts and assign templates via JSON-RPC API.
- Provisioner class with host creation, template assignment, and macro configuration.
- Admin UI section for auto-provisioning with test button.

---

## [1.0.0] - 2026-03-08

### Added
- **Core plugin**: Full-stack WordPress monitoring with 40+ metrics across 8 groups (performance, database, users, content, plugins, PHP, server, cron).
- **REST API**: Secured endpoint `/wp-json/wpzm/v1/metrics` with token-based authentication.
- **Zabbix Sender**: Native Zabbix Sender protocol support for push mode (TCP port 10051).
- **WP-Cron scheduler**: Periodic metric collection and push to Zabbix server.
- **Zabbix template**: XML template with 40+ items, 11 triggers, 6 graphs, and 1 dashboard.
- **Admin settings page**: 5-tab interface for Connection, REST API, Metrics, Live Data, and Auto-Provision configuration.
- **Dashboard widget**: Quick status display in WordPress admin dashboard.
