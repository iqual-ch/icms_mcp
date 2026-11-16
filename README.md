# icms_mcp — Drupal module

mcp_server tools that expose ICMS-specific operations to MCP clients (in our
case, the iqual `drupal-bridge` ADK agent on Cloud Run).

## What it ships

Tools (`src/Plugin/mcp_server/Tool/`), advertised under their tool id:

| Tool (wire name)                  | Purpose                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------- |
| `get_icms_catalog`       | Compact normalized v2 manifest with hash, indexes, capabilities, descriptions, fields, options and existing taxonomy vocabularies. |
| `get_icms_component_contract` | Full contracts for selected node, paragraph or media bundles, optionally including paragraph children. |
| `get_frontend_theme_files` | Read-only: the Nuxt frontend's theme layer as it is on this target (`app.config.ts`, `theme/*.css`, `base/typography.css`, `utilities/utilities.css`, `project/*.css`, `tailwind.css`, `nuxt.config.ts`, `package.json`), from `<repo>/frontend` (`ICMS_MCP_FRONTEND_DIR` overrides), so a design handoff can be diffed against what the project already themes. |
| `validate_pivot`         | Drupal-side validation of an `icms-drupal-import-handoff-v1` pivot.                       |
| `import_pivot`           | Transactional create/update of node + paragraphs + translations. Honours `strategy` and HITL gate. |
| `import_taxonomy_terms`  | Upsert one vocabulary's terms (uuid/name identity, hierarchy, translations). Step 3, before nodes. |
| `import_menu_links`      | Upsert one menu's links, parents before children; node links resolve via the source-key field, and a parent whose page is missing is kept disabled so its children keep their place. Step 3, after nodes. |
| `lookup_existing_node`   | The node an import of a source page would land on: by canonical source URL (the source-key field), and with `path_alias` / `is_front_page` also a page built by hand at the same path (`matched_by: alias \| front_page \| adopted`). |
| `get_icms_layout_examples` | Read-only: the site's reference examples of each layout bundle and option combination, from the showcase page declared in `icms_mcp.catalog_examples` and its menu children. `{indexHash, pages, examples: [{id, paragraphId, bundle, options, storedOptions, pagePath, ordinal, siblingCount, title, children}], skipped}`. |
| `rewrite_node_links`     | Post-import pass: `{from, to}` replacements applied to `href`s in text fields and link-field uris (exact, or by resolved `#fragment`) on the node, its translations and its paragraphs. Anchors → `#<paragraph id>`, document links → the stored file's URL. |
| `set_front_page`         | Make an imported, published node the site front page (`system.site page.front`). |
| `delete_preview_pages`   | Delete the preview pages of one scope (see *Preview pages*). |
| `update_node_references` | Final reference pass: path-addressed write of `entity_reference` / `link` fields on the node or a (nested) paragraph (`{langcode?, path: [{field?, index}], expect_bundle?, field, value}`), translation-aware, saved only on change, one transaction, idempotent. Lets a page imported before the pages it references get its teasers and buttons without a re-import (which would recreate paragraph ids). |
| `read_node`              | Read back a node per language: `{status, title, path_alias, fields, paragraphs: [{id, sequence, bundle, translated, status, fields, children}]}`, references with their labels, booleans as `true`/`false`. For checking what landed against what was sent (job title, publication type, per-language paragraph status). |

`import_pivot` also reports what it created: `paragraphs [{sequence, id, revision_id, children: {field: count}}]`
(top-level layout paragraphs) and `media [{sourceUrl, mediaId, bundle, fileUrl, fileUrlAbsolute}]`
(every media resolved, translations included), which the migrator's post-import link pass
consumes. `fileUrl` is root-relative (`/sites/default/files/…`) so rewritten links keep
working when the site changes host. A media descriptor's `caption` is written into the
media's `field_caption` when the media type has one and the media has no caption yet. Remote files are stored under the source's own path when it is under a
Drupal files directory (`public://2023-07/Report.pdf`), else under
`public://icms_mcp/<sha256>.<ext>` (a media route's extension comes from the
response), and reused by source URL on re-runs. `pivot.target.linkedDocuments
[{src, title}]` — files only linked from text — are created as unplaced document
media. A translation's paragraph `status` is applied per language.

The catalog also carries `editorTextStyles`: per text format, the CKEditor 5 style
plugin's styles (label, tag, classes) and the classes the format allows on text tags —
what a source's `lead` / `small` / blockquote text can bind to, and what a design
handoff's type scale still has to add.

### Translations: symmetric by default, detached on demand

A translation in `import_pivot`'s `translations[]` is written one of three ways,
chosen by the spec, never by the field's translatability alone:

| Spec | Written as | Summary `paragraphs_mode` |
| --- | --- | --- |
| `structureMatch: true` | into translations of the **shared** paragraph entities, by position, after a structure re-check | `symmetric` |
| `mode: "detached"` | its **own** single-language paragraph tree on the translation's field rows (ICMS detached content, ICMS-768 — unreleased) | `detached` |
| neither | scalar node fields only, with the `reason` | `shared` |

Detached needs two things on the target, both reported by the catalog: the bundle's
layouts field translatable (`nodeTypes.<bundle>.paragraphsFieldTranslatable`) and the
`icms_detached_content` node base field (`detachedContent.supported`). The
default-language import sets the flag like any other node attribute
(`node.attributes.icms_detached_content: true`); without it the ICMS presave hook
would mirror the original's paragraphs back over the translation on the next
editorial save, so `writeTranslations` refuses a detached translation on an
unflagged node and says why in `reason`. A new translation of a detached node is
saved once empty before its paragraphs are set, so the ICMS hook has nothing to
clone and the imported list is the one that stays. On a target that ships detached content the
field is translatable for every page: an attached page still takes the
symmetric path, which is why `structureMatch` decides and not the field.

`get_icms_catalog` intentionally replaces the old expanded v1 response. Use
`get_icms_component_contract` for details; clients should cache by
`catalogHash`. Component guidance and option definitions ship under `resources/`,
can be overridden through `icms_mcp.catalog_descriptions` and
`icms_mcp.catalog_options`, and expose alter hooks documented in
`icms_mcp.api.php`. A description entry carries `summary` (the component's
`description`), the prose lists `useWhen` / `avoidWhen` / `similarTo`
(`guidance`), `minItems`, and optionally `sourcePatterns`: the source system's
component names this bundle is the home for (`[slider, animatedslider]` on a
project's hero slider). The content migrator applies `sourcePatterns` as a
declared mapping before its own rules, so list only components verified against
real pages; everything inferred stays in the prose lists. `sourceRegions: [side]`
on a bundle a node's paragraph slot targets (a sidebar bundle) declares that
content the source placed in a narrow side column belongs in that slot rather
than in the page content; without it the migrator never moves content between
slots. `pageFields: [body]` on a NODE type's entry
(`descriptions.node.<bundle>`) declares the node fields this project's page
template shows on the page itself, outside the paragraphs (a lead above the
content). The catalog passes it on as `nodeTypes.<bundle>.pageFields`; the
migrator then leaves that content in the field instead of repeating it as a
paragraph. Without it a page is assumed to show its paragraphs only.

### Options: what each value does

On a site with a blökkli schema file (`paragraphs_blokkli.settings: schema_file`)
every paragraph bundle lists its own options under `optionDefinitions`:
`{type, default, values, label, valueLabels, displayAs, group}`. `valueLabels`
are the words an editor sees in blökkli for each value. A relative
`schema_file` is resolved against the working directory and then the Drupal
root; a site whose schema cannot be read falls back to the packaged option list
on every `icms_layout_*` bundle instead of advertising bundles without options.

A key or a label does not say what a value does to the layout. That is
declared: `resources/blokkli-options.yml` for what holds on every ICMS site,
the project's `icms_mcp.catalog_options` for its own components.

```yaml
# icms_mcp.catalog_options
options:
  teaserVariant:
    description: "How each teaser is laid out."
    effects:
      teaser: "Image above, then title and text."
      spotlight: "Image fills the card, title on top of it."
    bundles:
      example_layout_teasers:        # only where this bundle differs
        effects:
          spotlight: "Large image left, text right."
```

`description` and `effects` are merged into each bundle's
`optionDefinitions[<option>]`, the bundle entry over the option-wide one, and
only for values that bundle offers.

### Examples: where each combination can be seen

A project that builds a showcase (one page per component, one section per
option combination) declares its root page:

```yaml
# icms_mcp.catalog_examples
root: '/showcase'      # path alias, node id or node uuid
menu: ''               # menu the pages are linked in; empty = any
max_depth: 3           # menu levels below the root
langcode: ''           # empty = the root page's language
```

`get_icms_layout_examples` then lists every published top-level layout
paragraph on the root and its menu children: its bundle, the options it renders
with (schema defaults, then what is stored, typed), the page's public path and
its `paragraphId` (the ICMS frontend renders a layout as
`<section id="<paragraph id>">`) and its 1-based `ordinal` among the page's
published layouts, so the section can be found on the public page. Only pages an anonymous visitor can open are listed;
the rest is reported under `skipped`. `indexHash` changes when an example
moves, appears, disappears or changes options. The catalog reports
`examples.configured`.


## Preview pages

A reviewer deciding which layout a kind of source section becomes wants to see
that section in the layout, not a showcase example. A client does that with
the tools it already has:

1. `import_pivot` a page that holds the one section, with the source URL
   `https://icms-preview.invalid/<scope>/<name>` and a path alias out of the
   way (`/migration-preview/<scope>/<name>`), published so a browser can load
   it.
2. Load the page and look at it.
3. `delete_preview_pages` with the same `scope`.

`<scope>` is a token of letters, digits, `_` and `-`, six or more long,
typically a run id. `delete_preview_pages` deletes the nodes whose source key
starts with that scope's prefix and nothing else: `.invalid` is a reserved
host, so no page imported from a real source and no page built by hand can
carry such a key. Media a preview imported stays; a later import of the real
page reuses it.

While they exist, preview pages are published content: listings, the sitemap
and the search index may show them. Delete them as soon as they have been
looked at, and use previews on a preview environment, not on production.

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
- **Pages that exist before the migration.** A node the migration did not
  write but that sits at the page's path (or is the front page, for the source
  front page) is matched after the source key and the identity field:
  `matched_by: alias | front_page`. Only a node without a source key and of
  the same bundle qualifies. It is never written or duplicated silently:
  - no `metadata.on_unmanaged` → `status: conflict`,
    `code: existing_unmanaged_node`, with `existing {nid, bundle, title, url}`;
  - `on_unmanaged: keep` → the node only receives the source key
    (`action: adopted`, `kept: true`), so menu links, references and text links
    to the source page resolve to it. It stays `matched_by: adopted` on later
    runs, which again need a decision;
  - `on_unmanaged: overwrite` → the normal update path, whatever the run's
    strategy; from then on it is an imported page.
  A hand-built page of another bundle at that path returns `status: conflict`,
  `code: existing_unmanaged_node_other_bundle`.
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
| `icms_mcp.identity_fields`    | `{}`                     | Per bundle, the field that identifies a node that exists **before** the migration — e.g. `{"icms_product": "field_icms_product_id"}` for products an ERP feed created. A page whose attributes carry that field updates the matching node instead of creating a twin, and stamps its source key on it. Several matches, or a node another page already claimed, are refused with a warning and the page creates a new node. Reported in the catalog as `site.identityFields`. |

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
ddev drush state:set icms_mcp.identity_fields '{"icms_product": "field_icms_product_id"}'
```

`validate_pivot` and `get_icms_catalog` both surface a clear error when
either field is missing, so misconfiguration is caught before the first
import attempt.

## Install

The full stack (drupal/mcp_server, simple_oauth, consumers) is pulled in
transitively — nothing else to require on the target project.

mcp_server **2.0.0-beta4 or newer** is required: beta4 moved tool discovery
from `src/Plugin/Tool/` to `src/Plugin/mcp_server/Tool/`, where this module's
tools now live. On an older beta the site answers `/mcp` normally and
advertises no tools at all, which the migration agent reports as "Drupal MCP
tool not advertised by target site".

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

## Why MCP tools and not a custom REST/JSON:API endpoint?

Reuse + provenance. Every MCP-enabled client (Claude Desktop / Cursor / the
ADK `McpToolset`) discovers the tools automatically, the OAuth + RBAC layer
comes from `drupal/mcp_server` and `simple_oauth`, and we get the
streamable-HTTP transport, schema validation, and permission gating for free.

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
