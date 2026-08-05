# icms_mcp — Drupal module

Custom MCP plugin that exposes ICMS-specific tools to MCP clients (in our
case, the iqual `drupal-bridge` ADK agent on Cloud Run).

## What it ships

Seven tools. On the wire the names become `icms-mcp_<sanitized-tool-name>`
(drupal/mcp prepends the plugin id and `_`; note the hyphen — see
"Plugin ID gotcha" below):

| Tool (wire name)                  | Purpose                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------- |
| `icms-mcp_get_icms_catalog`       | Compact normalized v2 manifest with hash, indexes, capabilities, descriptions, fields and options. |
| `icms-mcp_get_icms_component_contract` | Full contracts for selected node, paragraph or media bundles, optionally including paragraph children. |
| `icms-mcp_validate_pivot`         | Drupal-side validation of an `icms-drupal-import-handoff-v1` pivot.                       |
| `icms-mcp_import_pivot`           | Transactional create/update of node + paragraphs + translations. Honours `strategy` and HITL gate. |
| `icms-mcp_import_taxonomy_terms`  | Upsert one vocabulary's terms (uuid/name identity, hierarchy, translations). Step 3, before nodes. |
| `icms-mcp_import_menu_links`      | Upsert one menu's links; node links resolve via the source-key field. Step 3, after nodes. |
| `icms-mcp_lookup_existing_node`   | Idempotency lookup by canonical source URL (matches against the configured source-key field). |

`get_icms_catalog` intentionally replaces the old expanded v1 response. Use
`get_icms_component_contract` for details; clients should cache by
`catalogHash`. Component guidance and option definitions ship under `resources/`,
can be overridden through `icms_mcp.catalog_descriptions` and
`icms_mcp.catalog_options`, and expose alter hooks documented in
`icms_mcp.api.php`.

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
    commits, so a failure leaves the previous content intact). Cleanup uses
    paragraph entity IDs rather than historical revision IDs, allowing an
    update to repair a node that contains a stale paragraph revision reference.
  - `fail-if-exists` → returns `status: conflict` if a node with the same
    `idempotence_key` already exists.
- **Transactional.** All writes run inside a `database->startTransaction()`.
  Any throw inside the write path triggers a `rollBack()` and surfaces as
  `status: error`.
- **Audit journal.** Every outcome (`created` / `updated` / `skipped` /
  `conflict` / `error`) is logged to the `icms_mcp` logger channel with
  `idempotence_key`, `batch_id`, `run_id` in the context so you can correlate
  in `/admin/reports/dblog`.
- **Private pivot log.** Every payload received by `validate_pivot` or
  `import_pivot` is saved as formatted JSON under
  `private://icms_mcp/pivots`. The resulting URI is returned as
  `pivot_log_uri`. Disable this diagnostic log with
  `drush state:set icms_mcp.log_pivots 0`.

## Prerequisites on the target site

Two field machine names are configurable via `state` (defaults shown):

| State key                     | Default                  | Purpose                                                                   |
| ----------------------------- | ------------------------ | ------------------------------------------------------------------------- |
| `icms_mcp.source_key_field`   | `field_icms_source_key`  | Plain string field (max 512) on every migrated node bundle storing the agent's `idempotence_key`. |
| `icms_mcp.layouts_field`      | `field_icms_paragraphs`  | `entity_reference_revisions` field on `icms_page` that holds layout paragraphs. |

The **source-key field is owned by this module** — it is pure migration
infrastructure, so install/update hooks create it on every node bundle,
new bundles get it automatically, and `import_pivot` recreates it when a
config import dropped it. After the module (or an update) first creates it
on an environment, export config (`drush cex`) so the next config import
keeps it. The **layouts field** stays your site's responsibility: it is part
of the ICMS content model. If the defaults don't match your install:

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
2. The service account is created for you: install adds an `icms_mcp` role
   (only `Use MCP server` + `Use ICMS MCP tools`, nothing else) and an
   active `icms_mcp` user with a generated password **printed exactly once**
   in the install/updb output. Rotate it any time with
   `ddev drush upwd icms_mcp '<new password>'`. Uninstall deletes both —
   no standing credential outside the migration window.
3. The role is a config entity: run `ddev drush cex` after install, or the
   next config import deletes it (the user would survive but lose its
   grants).

## Why a plugin and not a custom REST/JSON:API endpoint?

Reuse + provenance. Every MCP-enabled client (Claude Desktop / Cursor / the
ADK `McpToolset`) discovers the tools automatically, the auth + RBAC layer
comes from `drupal/mcp`, and we get the streamable-HTTP transport, schema
validation, and permission gating for free.
