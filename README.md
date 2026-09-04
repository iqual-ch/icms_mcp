# icms_mcp — Drupal module

Custom MCP plugin that exposes ICMS-specific tools to MCP clients (in our
case, the iqual `drupal-bridge` ADK agent on Cloud Run).

## What it ships

Seven tools. On the wire the names become `icms-mcp_<sanitized-tool-name>`
(drupal/mcp prepends the plugin id and `_`; note the hyphen — see
"Plugin ID gotcha" below):

| Tool (wire name)                  | Purpose                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------- |
| `icms-mcp_get_icms_catalog`       | Compact normalized v2 manifest with hash, indexes, capabilities, descriptions, fields, options and existing taxonomy vocabularies. |
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

The full stack (drupal/mcp_server, simple_oauth, consumers) is pulled in
transitively — nothing else to require on the target project.

```bash
ddev composer require 'iqual/icms_mcp'
ddev drush en icms_mcp -y     # prints the OAuth client credentials ONCE
ddev drush cex -y             # consumer, scope, and role are config
```

## Authentication — OAuth 2.1 client_credentials

Everything is provisioned on install (and on `drush updb`, update 10104):

1. **Signing keys** generated under `private://simple_oauth/` and wired into
   `simple_oauth.settings` as a stream URI — the private file system is never
   served and never in git, and the exported setting is valid in every
   environment (DDEV, Upsun mount, production). Skipped when the site already
   has keys. Install is refused when no `file_private_path` is configured.
2. An **`icms_mcp` OAuth scope** with ROLE granularity: access tokens carry
   exactly the `icms_mcp` role's permissions (`access mcp server`,
   `use icms_mcp tools`), nothing more.
3. A **confidential consumer** (`client_id: icms_mcp`, client_credentials
   grant, 1h tokens) bound to a **passwordless** `icms_mcp` service user —
   nothing can log in as it; only tokens act through it.
4. The `client_id` + `client_secret` are printed **exactly once** — paste
   them into the cockpit connection form. Rotate any time:
   `ddev drush icms-mcp:rotate-secret`. Uninstall deletes consumer, scope,
   user, and role — no standing credential outside the migration window.

Token flow (what the agent does for you):

```bash
curl -X POST https://<site>/oauth/token \
  -d 'grant_type=client_credentials&client_id=icms_mcp&client_secret=<secret>&scope=icms_mcp'
# → {access_token, expires_in: 3600}
curl -X POST https://<site>/mcp -H 'Authorization: Bearer <access_token>' ...
```

**Router note (ICMS/Varnish):** the path whitelist that passes requests to
Drupal must include `/mcp` AND `/oauth` — otherwise token requests land in
the Nuxt frontend as 404s.

The module ships a route subscriber that allows the `oauth2` authentication
provider on `/mcp` (mcp_server's route declares `_auth: ['cookie']`, and an
explicit `_auth` list excludes even global providers).

## Legacy drupal/mcp endpoint (transition only)

`src/Plugin/Mcp/IcmsMcp.php` keeps the old `/mcp/post` + basic-auth
endpoint working on sites that still have `drupal/mcp` enabled. It shares
the same operations service. It is removed together with the
`drupal/mcp` composer requirement once the fleet has moved.

## Why a plugin and not a custom REST/JSON:API endpoint?

Reuse + provenance. Every MCP-enabled client (Claude Desktop / Cursor / the
ADK `McpToolset`) discovers the tools automatically, the auth + RBAC layer
comes from `drupal/mcp`, and we get the streamable-HTTP transport, schema
validation, and permission gating for free.

## Moving keys that were generated inside the project

Earlier versions generated the pair at `<project>/keys/`, inside the
repository. If a site still has them there (`ddev drush cget simple_oauth.settings`),
regenerate into the private file system — anything signed with the old pair
stops validating, which is the point if they were ever committed:

```bash
ddev drush simple-oauth:generate-keys private://simple_oauth
ddev drush cset simple_oauth.settings public_key private://simple_oauth/public.key -y
ddev drush cset simple_oauth.settings private_key private://simple_oauth/private.key -y
ddev drush cex -y
rm -rf drupal/keys   # and purge them from git history if they were pushed
```
