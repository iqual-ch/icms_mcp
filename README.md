# icms_mcp — Drupal module

Custom MCP plugin that exposes ICMS-specific tools to MCP clients (in our
case, the iqual `drupal-bridge` ADK agent on Cloud Run).

## What it ships

Four tools. On the wire the names become `icms-mcp_<sanitized-tool-name>`
(drupal/mcp prepends the plugin id and `_`; note the hyphen — see
"Plugin ID gotcha" below):

| Tool (wire name)                  | Purpose                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------- |
| `icms-mcp_get_icms_catalog`       | Live `nodeTypes` / `paragraphTypes` / `allowedParagraphBundles` introspected from this site. |
| `icms-mcp_validate_pivot`         | Drupal-side validation of an `icms-drupal-import-handoff-v1` pivot.                       |
| `icms-mcp_import_pivot`           | Transactional create/update of node + paragraphs. Honours `strategy` and HITL gate.       |
| `icms-mcp_lookup_existing_node`   | Idempotency lookup by canonical source URL (matches against the configured source-key field). |

### Plugin ID gotcha

drupal/mcp's `McpPluginManager::getAvailablePlugins()` enforces
`/^[a-zA-Z0-9-]+$/` on plugin IDs — **underscores are forbidden**. That's
why the `#[Mcp]` attribute uses `id: 'icms-mcp'` (hyphen) even though the
module name is `icms_mcp` (underscore, as Drupal requires). If you ever see
`tools/list` silently drop your plugin, check
`/admin/reports/dblog` for `InvalidArgumentException: "Plugin ID must be
made of letters, numbers, and hyphens"`.

### Wire perf gotcha

drupal/mcp's bundled `drush` MCP plugin exposes ~230 tools and each one
runs an access check that costs ~300–700ms in DDEV, which makes
`tools/list` time out (~4 min wall-clock). Disable plugins you don't need:

```bash
ddev drush php:eval '$c=\Drupal::configFactory()->getEditable("mcp.settings"); $p=$c->get("plugins"); foreach (["drush","content","aif","jsonapi","tools","aia"] as $k) { $p[$k]["enabled"]=false; } $c->set("plugins",$p)->save();'
ddev drush cr
```

## Behaviour summary

- **Validation runs on every import.** `import_pivot` calls `validate_pivot`
  first and refuses to write a contract with any issues.
- **HITL gate.** If `metadata.review_decision == "review_required"`, the
  import returns `status: pending_review` unless the caller passes
  `approve: true`. `review_decision == "blocked"` always refuses.
- **Strategies** (`metadata.strategy`):
  - `skip` → if a node with the same `idempotence_key` exists, return
    `status: skipped` without writing.
  - `update` / `skip-or-update` → load and rewrite the existing node, replacing
    its paragraph children (old paragraphs are deleted after the node save
    commits, so a failure leaves the previous content intact).
  - `fail-if-exists` → returns `status: conflict` if a node with the same
    `idempotence_key` already exists.
- **Transactional.** All writes run inside a `database->startTransaction()`.
  Any throw inside the write path triggers a `rollBack()` and surfaces as
  `status: error`.
- **Audit journal.** Every outcome (`created` / `updated` / `skipped` /
  `conflict` / `error`) is logged to the `icms_mcp` logger channel with
  `idempotence_key`, `batch_id`, `run_id` in the context so you can correlate
  in `/admin/reports/dblog`.

## Prerequisites on the target site

Two field machine names are configurable via `state` (defaults shown):

| State key                     | Default                  | Purpose                                                                   |
| ----------------------------- | ------------------------ | ------------------------------------------------------------------------- |
| `icms_mcp.source_key_field`   | `field_icms_source_key`  | Plain string field (max 512) on `icms_page` storing the agent's `idempotence_key`. |
| `icms_mcp.layouts_field`      | `field_icms_paragraphs`  | `entity_reference_revisions` field on `icms_page` that holds layout paragraphs. |

This module **does not auto-create those fields** — fields are part of your
site configuration. If the defaults don't match your install:

```bash
ddev drush state:set icms_mcp.source_key_field field_my_source_key
ddev drush state:set icms_mcp.layouts_field field_my_paragraphs
```

`validate_pivot` and `get_icms_catalog` both surface a clear error when
either field is missing, so misconfiguration is caught before the first
import attempt.

## Install

Requires `drupal/mcp ^1.0`.

```bash
ddev composer require 'iqual/icms_mcp'
ddev drush en icms_mcp -y
```

## Configure

1. `/admin/config/mcp` — enable token auth and the `icms-mcp` plugin
   (hyphen, not underscore — see "Plugin ID gotcha" above).
2. Create a dedicated Drupal user for the agent. Grant **only** the
   `Use MCP server` permission (from drupal/mcp) and the
   `Use ICMS MCP tools` permission (from this module). No other roles.

## Why a plugin and not a custom REST/JSON:API endpoint?

Reuse + provenance. Every MCP-enabled client (Claude Desktop / Cursor / the
ADK `McpToolset`) discovers the tools automatically, the auth + RBAC layer
comes from `drupal/mcp`, and we get the streamable-HTTP transport, schema
validation, and permission gating for free.
