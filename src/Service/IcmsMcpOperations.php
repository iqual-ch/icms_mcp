<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Service;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * ICMS MCP operations — catalog/validate/import/lookup business logic.
 *
 * Transport-agnostic: the mcp_server #[Tool] plugins (src/Plugin/Tool/) and
 * the legacy drupal/mcp plugin (src/Plugin/Mcp/, transition only) are thin
 * adapters over this service. execute() returns RAW result arrays — each
 * transport applies its own envelope.
 *
 * Configurable site state (\Drupal::state()):
 *   - icms_mcp.source_key_field: defaults to `field_icms_source_key`.
 *   - icms_mcp.layouts_field: defaults to `field_icms_paragraphs`.
 */
final class IcmsMcpOperations {

  /**
   * Default field name on icms_page that stores the agent idempotence_key.
   * Override via state('icms_mcp.source_key_field').
   */
  protected const DEFAULT_SOURCE_KEY_FIELD = 'field_icms_source_key';

  /**
   * Default entity_reference_revisions field that holds layout paragraphs.
   * Override via state('icms_mcp.layouts_field').
   */
  protected const DEFAULT_LAYOUTS_FIELD = 'field_icms_paragraphs';

  /**
   * Seconds a per-page import lock is held (a large page with many
   * paragraphs and translations takes a few seconds; this is the ceiling
   * before the lock is considered stale).
   */
  protected const IMPORT_LOCK_TIMEOUT = 120.0;

  /**
   * Assets skipped during the current import, as human-readable warnings.
   *
   * One unusable file must cost that file, not the page: before this, a
   * single mp3 pointed at an image field, or a site-relative URL, aborted
   * the whole node and every other field on it was lost with it.
   */
  protected array $mediaWarnings = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $fieldManager,
    protected Connection $database,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected IcmsCatalogBuilder $catalogBuilder,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   *
   * MCP ToolsCall passes the sanitized tool name (lowercase, underscores)
   * — see McpJsonRpcMethod\ToolsCall::execute(). Accept md5() too in case
   * a future caller follows the older docs at drupalmcp.io.
   */
  public function execute(string $toolId, mixed $arguments): array {
    try {
      if ($toolId === 'get_icms_catalog' || $toolId === md5('get_icms_catalog')) {
        return $this->doGetCatalog();
      }
      if ($toolId === 'get_icms_component_contract' || $toolId === md5('get_icms_component_contract')) {
        return $this->catalogBuilder->buildComponentContracts(
          (string) ($arguments['entity_type'] ?? ''),
          is_array($arguments['bundles'] ?? NULL) ? $arguments['bundles'] : [],
          (bool) ($arguments['include_children'] ?? TRUE),
        );
      }
      if ($toolId === 'validate_pivot' || $toolId === md5('validate_pivot')) {
        $pivot = $arguments['pivot'] ?? [];
        $log_uri = $this->logReceivedPivot('validate_pivot', $pivot);
        $result = $this->doValidatePivot($pivot);
        if ($log_uri !== NULL) {
          $result['pivot_log_uri'] = $log_uri;
        }
        return $result;
      }
      if ($toolId === 'import_taxonomy_terms' || $toolId === md5('import_taxonomy_terms')) {
        return $this->doImportTaxonomyTerms(
          (string) ($arguments['vocabulary'] ?? ''),
          is_array($arguments['terms'] ?? NULL) ? $arguments['terms'] : [],
        );
      }
      if ($toolId === 'import_users' || $toolId === md5('import_users')) {
        return $this->doImportUsers(
          is_array($arguments['users'] ?? NULL) ? $arguments['users'] : [],
          is_array($arguments['role_mapping'] ?? NULL) ? $arguments['role_mapping'] : [],
        );
      }
      if ($toolId === 'import_menu_links' || $toolId === md5('import_menu_links')) {
        return $this->doImportMenuLinks(
          (string) ($arguments['menu'] ?? ''),
          is_array($arguments['links'] ?? NULL) ? $arguments['links'] : [],
          (string) ($arguments['source_base_url'] ?? ''),
        );
      }
      if ($toolId === 'import_pivot' || $toolId === md5('import_pivot')) {
        $pivot = $arguments['pivot'] ?? [];
        $log_uri = $this->logReceivedPivot('import_pivot', $pivot);
        $result = $this->doImportPivot(
          $pivot,
          (bool) ($arguments['dry_run'] ?? FALSE),
          (bool) ($arguments['approve'] ?? FALSE),
        );
        if ($log_uri !== NULL) {
          $result['pivot_log_uri'] = $log_uri;
        }
        return $result;
      }
      if ($toolId === 'lookup_existing_node' || $toolId === md5('lookup_existing_node')) {
        return $this->doLookupExistingNode((string) ($arguments['source_url'] ?? ''));
      }
    }
    catch (\Throwable $e) {
      // Never let an uncaught throwable reach the MCP transport — it would
      // surface to the agent as a connection error rather than a structured
      // tool result the LLM can reason about.
      $this->logger->error('icms_mcp tool error: @msg', ['@msg' => $e->getMessage()]);
      return [
        'status' => 'error',
        'error' => $e->getMessage(),
        'tool_id' => $toolId,
      ];
    }
    throw new \InvalidArgumentException('icms_mcp: unknown tool id ' . $toolId);
  }

  // ---- Tool: get_icms_catalog ----------------------------------------------

  /** Return the compact normalized live catalog v2 manifest. */
  protected function doGetCatalog(): array {
    return $this->catalogBuilder->buildManifest(
      $this->sourceKeyFieldName(),
      $this->layoutsFieldName(),
    );
  }

  // ---- Tool: validate_pivot ------------------------------------------------

  /**
   * Validate an icms-drupal-import-handoff-v1 contract against live site state.
   */
  protected function doValidatePivot(array $pivot): array {
    $issues = [];

    if (($pivot['format'] ?? '') !== 'icms-drupal-import-handoff-v1') {
      $issues[] = ['path' => '/format', 'code' => 'unsupported_format', 'message' => 'Expected icms-drupal-import-handoff-v1.'];
    }

    $metadata = $pivot['metadata'] ?? [];
    if (empty($metadata['idempotence_key'])) {
      $issues[] = ['path' => '/metadata/idempotence_key', 'code' => 'missing', 'message' => 'idempotence_key is required.'];
    }
    $strategy = $metadata['strategy'] ?? 'skip-or-update';
    if (!in_array($strategy, ['skip', 'update', 'skip-or-update', 'fail-if-exists'], TRUE)) {
      $issues[] = ['path' => '/metadata/strategy', 'code' => 'invalid_strategy', 'message' => "Unknown strategy '{$strategy}'."];
    }

    $import = $pivot['drupal_import'] ?? [];
    $node = $import['node'] ?? [];
    $node_type_raw = $node['type'] ?? '';
    $node_bundle = $this->stripJsonApiPrefix($node_type_raw);
    if (!$node_bundle) {
      $issues[] = ['path' => '/drupal_import/node/type', 'code' => 'missing', 'message' => 'node.type is required.'];
    }
    elseif (!isset($this->bundleInfo->getBundleInfo('node')[$node_bundle])) {
      $issues[] = ['path' => '/drupal_import/node/type', 'code' => 'unknown_bundle', 'message' => "Node bundle '{$node_bundle}' does not exist on this site."];
    }

    $node_attrs = $node['attributes'] ?? [];
    $para_bundles = $this->entityTypeManager->hasDefinition('paragraph')
      ? $this->bundleInfo->getBundleInfo('paragraph')
      : [];
    if (empty($node_attrs['title'])) {
      $issues[] = ['path' => '/drupal_import/node/attributes/title', 'code' => 'missing', 'message' => 'title is required.'];
    }

    if ($node_bundle) {
      $node_defs = $this->fieldManager->getFieldDefinitions('node', $node_bundle);
      foreach ($node_attrs as $name => $value) {
        if ($name === 'title' || $name === 'langcode' || $name === 'status' || $name === 'body') {
          continue;
        }
        // `owner` names a PERSON, not a field: it resolves to the node's uid
        // by e-mail. Validating it against the bundle's field list rejected
        // every node that carried one ("Field 'owner' does not exist on
        // node:icms_event").
        if ($name === 'owner') {
          if (!is_array($value) || (trim((string) ($value['email'] ?? '')) === '' && trim((string) ($value['name'] ?? '')) === '')) {
            $issues[] = ['path' => '/drupal_import/node/attributes/owner', 'code' => 'invalid_owner', 'message' => 'owner must be an object with an email or a name.'];
          }
          continue;
        }
        if (!isset($node_defs[$name])) {
          $issues[] = ['path' => "/drupal_import/node/attributes/{$name}", 'code' => 'unknown_field', 'message' => "Field '{$name}' does not exist on node:{$node_bundle}."];
          continue;
        }
        $this->validateStructuredField($node_defs[$name], $value, "/drupal_import/node/attributes/{$name}", $para_bundles, $issues);
      }
    }

    $paragraphs = $import['paragraphs'] ?? [];
    foreach ($paragraphs as $i => $para) {
      $type_raw = $para['type'] ?? '';
      $bundle = $this->stripJsonApiPrefix($type_raw);
      if (!$bundle) {
        $issues[] = ['path' => "/drupal_import/paragraphs/{$i}/type", 'code' => 'missing', 'message' => 'paragraph type is required.'];
        continue;
      }
      if (!isset($para_bundles[$bundle])) {
        $issues[] = ['path' => "/drupal_import/paragraphs/{$i}/type", 'code' => 'unknown_bundle', 'message' => "Paragraph bundle '{$bundle}' does not exist."];
        continue;
      }
      $defs = $this->fieldManager->getFieldDefinitions('paragraph', $bundle);
      if (isset($para['options']) && !is_array($para['options'])) {
        $issues[] = ['path' => "/drupal_import/paragraphs/{$i}/options", 'code' => 'invalid_options', 'message' => 'Blökkli options must be an object.'];
      }
      foreach (($para['attributes'] ?? []) as $name => $value) {
        if (!isset($defs[$name])) {
          $issues[] = ['path' => "/drupal_import/paragraphs/{$i}/attributes/{$name}", 'code' => 'unknown_field', 'message' => "Field '{$name}' does not exist on paragraph:{$bundle}."];
          continue;
        }
        $this->validateStructuredField(
          $defs[$name],
          $value,
          "/drupal_import/paragraphs/{$i}/attributes/{$name}",
          $para_bundles,
          $issues,
        );
      }
    }

    // Verify the layouts field is present on the target node bundle when
    // paragraphs are being imported — otherwise we have nothing to attach to.
    if ($node_bundle && $paragraphs) {
      $layouts_field = $this->layoutsFieldName();
      $defs = $this->fieldManager->getFieldDefinitions('node', $node_bundle);
      if (!isset($defs[$layouts_field])) {
        $issues[] = [
          'path' => '/site',
          'code' => 'missing_layouts_field',
          'message' => "Configured layouts field '{$layouts_field}' is not present on node:{$node_bundle}. Set state('icms_mcp.layouts_field') to the correct field machine name.",
        ];
      }
    }

    // Verify the idempotency field is present on the target node bundle.
    if ($node_bundle) {
      $source_field = $this->sourceKeyFieldName();
      $defs = $this->fieldManager->getFieldDefinitions('node', $node_bundle);
      if (!isset($defs[$source_field])) {
        $issues[] = [
          'path' => '/site',
          'code' => 'missing_source_key_field',
          'message' => "Configured source-key field '{$source_field}' is not present on node:{$node_bundle}. Run the icms_mcp database updates (drush updb) to create it everywhere, or add a plain string field (max 512) and store its machine name in state('icms_mcp.source_key_field').",
        ];
      }
    }

    return [
      'status' => $issues ? 'invalid' : 'ok',
      'issues' => $issues,
    ];
  }

  // ---- Tool: import_pivot --------------------------------------------------

  /**
   * Transactionally import a pivot document.
   */
  protected function doImportPivot(array $pivot, bool $dry_run, bool $approve): array {
    // 0. Self-heal the source-key field before validating: it is this
    //    module's own idempotency infrastructure, so a real import recreates
    //    it (a config import may have dropped it, or the bundle may postdate
    //    the update hook) instead of failing. Dry runs stay read-only.
    $node_bundle = $this->stripJsonApiPrefix($pivot['drupal_import']['node']['type'] ?? '');
    if (!$dry_run && $node_bundle !== '') {
      $defs = $this->fieldManager->getFieldDefinitions('node', $node_bundle);
      if (!isset($defs[$this->sourceKeyFieldName()])) {
        icms_mcp_ensure_source_key_field($node_bundle);
      }
    }

    // 1. Always validate first — refuse to write a contract we already know
    //    is broken.
    $validation = $this->doValidatePivot($pivot);
    if ($validation['status'] !== 'ok') {
      return [
        'status' => 'invalid',
        'issues' => $validation['issues'],
        'dry_run' => $dry_run,
      ];
    }

    $metadata = $pivot['metadata'] ?? [];
    $idempotence_key = (string) ($metadata['idempotence_key'] ?? '');
    $strategy = (string) ($metadata['strategy'] ?? 'skip-or-update');
    $review_decision = (string) ($metadata['review_decision'] ?? 'auto_approve');
    $batch_id = (string) ($metadata['batch_id'] ?? '');
    $run_id = (string) ($metadata['run_id'] ?? '');

    // 2. HITL gate. If the agent assessed review_required, refuse to proceed
    //    until the caller passes approve=true (which the orchestrator only
    //    sends after a human says OK).
    if ($review_decision === 'review_required' && !$approve) {
      return [
        'status' => 'pending_review',
        'reason' => 'metadata.review_decision = review_required; re-call with approve=true after human approval.',
        'idempotence_key' => $idempotence_key,
        'dry_run' => $dry_run,
      ];
    }
    if ($review_decision === 'blocked') {
      return [
        'status' => 'blocked',
        'reason' => 'metadata.review_decision = blocked.',
        'idempotence_key' => $idempotence_key,
        'dry_run' => $dry_run,
      ];
    }

    // 3. Idempotency: find existing node by source URL prefix on the
    //    source-key field. We deliberately match by URL (not by exact
    //    idempotence_key) so a second import of the same page with updated
    //    content can `update` the existing node rather than creating a
    //    duplicate.
    $source_field = $this->sourceKeyFieldName();
    $source_url = $this->extractSourceUrl($idempotence_key);

    // Serialize per source page. The lookup below and the write that follows
    // are a check-then-act, and each request commits in its own transaction,
    // so two overlapping imports of the SAME page both see "nothing there"
    // and both create a node. That is not hypothetical: one migration run
    // whose import tool was invoked twice in parallel left 17 duplicate
    // nodes on the target. The lock makes the decision atomic no matter how
    // many clients (or retried tool calls) arrive at once.
    $lock_name = 'icms_mcp:import:' . hash('sha256', $source_url !== '' ? $source_url : $idempotence_key);
    $have_lock = $this->lock->acquire($lock_name, self::IMPORT_LOCK_TIMEOUT);
    if (!$have_lock) {
      // Someone else is importing this page: wait for them, then re-try once.
      // If it still is not free we continue anyway — by then the holder has
      // almost certainly committed, so our lookup sees their node and we
      // update it instead of creating a twin.
      $this->lock->wait($lock_name, (int) self::IMPORT_LOCK_TIMEOUT);
      $have_lock = $this->lock->acquire($lock_name, self::IMPORT_LOCK_TIMEOUT);
    }
    try {
      return $this->importPivotWithLock(
        $pivot,
        $dry_run,
        $node_bundle,
        $source_field,
        $source_url,
        $idempotence_key,
        $strategy,
        $batch_id,
        $run_id,
      );
    }
    finally {
      if ($have_lock) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * The import decision + write, executed while holding the per-page lock.
   *
   * Split out of doImportPivot() only so the lock has a single release point;
   * never call it directly.
   */
  protected function importPivotWithLock(
    array $pivot,
    bool $dry_run,
    string $node_bundle,
    string $source_field,
    string $source_url,
    string $idempotence_key,
    string $strategy,
    string $batch_id,
    string $run_id,
  ): array {
    $import = $pivot['drupal_import'] ?? [];
    $node_attrs = $import['node']['attributes'] ?? [];
    $path_alias = (string) ($import['node']['path_alias'] ?? '');
    $paragraphs = $import['paragraphs'] ?? [];
    $existing_nid = $this->findNodeBySourceUrl($node_bundle, $source_field, $source_url);

    // The idempotency lookup is bundle-scoped, so a page re-imported under a
    // corrected bundle (news instead of page) creates a NEW node and the old
    // one silently remains. Surface the cross-bundle twin so the caller can
    // delete or keep it deliberately.
    $bundle_conflict = NULL;
    if ($existing_nid === NULL) {
      $other_nid = $this->findNodeBySourceUrl(NULL, $source_field, $source_url);
      if ($other_nid !== NULL) {
        $other = $this->entityTypeManager->getStorage('node')->load($other_nid);
        $bundle_conflict = [
          'nid' => $other_nid,
          'bundle' => $other?->bundle(),
          'warning' => "A node with the same source key already exists as '" . ($other?->bundle() ?? '?') . "' (nid {$other_nid}). This import creates a separate '{$node_bundle}' node — delete the old one if the bundle mapping changed.",
        ];
      }
    }

    // 4. Apply strategy.
    if ($existing_nid !== NULL) {
      switch ($strategy) {
        case 'skip':
          return $this->journal('skipped', [
            'idempotence_key' => $idempotence_key,
            'nid' => $existing_nid,
            'strategy' => $strategy,
            'batch_id' => $batch_id,
            'run_id' => $run_id,
            'dry_run' => $dry_run,
          ]);

        case 'fail-if-exists':
          return $this->journal('conflict', [
            'idempotence_key' => $idempotence_key,
            'existing_nid' => $existing_nid,
            'strategy' => $strategy,
            'batch_id' => $batch_id,
            'run_id' => $run_id,
            'dry_run' => $dry_run,
            'reason' => 'Node already exists with this idempotence_key.',
          ]);

        case 'update':
        case 'skip-or-update':
        default:
          // Fall through to update path.
          break;
      }
    }

    if ($dry_run) {
      return [
        'status' => 'dry_run_ok',
        'plan' => $existing_nid === NULL ? 'create' : 'update',
        'existing_nid' => $existing_nid,
        'idempotence_key' => $idempotence_key,
        'node_bundle' => $node_bundle,
        'paragraph_count' => count($paragraphs),
      ];
    }

    // 5. Real write — wrap in a transaction so a paragraph failure rolls back
    //    the parent node create. We re-throw on error so the transaction's
    //    `__destruct` rolls back rather than committing.
    $transaction = $this->database->startTransaction('icms_mcp_import');
    try {
      $result = $this->writeNodeAndParagraphs(
        $node_bundle,
        $node_attrs,
        $paragraphs,
        $idempotence_key,
        $existing_nid,
        $path_alias,
      );
      $translations_spec = is_array($import['translations'] ?? NULL) ? $import['translations'] : [];
      if ($translations_spec) {
        $result['translations'] = $this->writeTranslations((int) $result['nid'], $translations_spec);
        // Translations run after the node result was assembled and can skip
        // assets of their own.
        if ($this->mediaWarnings) {
          $result['warnings'] = $this->mediaWarnings;
          $result['media_skipped_count'] = count($this->mediaWarnings);
        }
      }
      $result['idempotence_key'] = $idempotence_key;
      $result['strategy'] = $strategy;
      $result['action'] = $existing_nid === NULL ? 'created' : 'updated';
      if ($bundle_conflict !== NULL) {
        $result['bundle_conflict'] = $bundle_conflict;
        $result['warnings'] = array_merge($result['warnings'] ?? [], [$bundle_conflict['warning']]);
      }
      return $this->journal($result['action'], array_merge($result, [
        'batch_id' => $batch_id,
        'run_id' => $run_id,
        'dry_run' => FALSE,
      ]));
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('icms_mcp:import_pivot rolled back: @msg', ['@msg' => $e->getMessage()]);
      return $this->journal('error', [
        'idempotence_key' => $idempotence_key,
        'batch_id' => $batch_id,
        'run_id' => $run_id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Inner writer — runs inside a DB transaction. Re-throws on any error so
   * the caller's transaction handle rolls back.
   */
  protected function writeNodeAndParagraphs(
    string $node_bundle,
    array $node_attrs,
    array $paragraphs,
    string $idempotence_key,
    ?int $existing_nid,
    string $path_alias = '',
  ): array {
    $this->mediaWarnings = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $paragraph_storage = $this->entityTypeManager->hasDefinition('paragraph')
      ? $this->entityTypeManager->getStorage('paragraph')
      : NULL;
    if ($paragraphs && $paragraph_storage === NULL) {
      throw new \RuntimeException('Paragraphs requested but paragraphs module is not installed.');
    }

    if ($existing_nid !== NULL) {
      // Avoid a stale entity from Drupal's static cache when another request
      // has just created a new node revision.
      $node_storage->resetCache([$existing_nid]);
      /** @var \Drupal\node\NodeInterface $node */
      $node = $node_storage->load($existing_nid);
      if ($node === NULL) {
        throw new \RuntimeException("Existing node {$existing_nid} disappeared between lookup and write.");
      }
      // Detach + delete the previous paragraph children so we don't leave
      // orphans behind on update. Paragraphs aren't deleted on detach alone.
      $layouts_field = $this->layoutsFieldName();
      $old_ref_ids = [];
      if ($node->hasField($layouts_field)) {
        foreach ($node->get($layouts_field) as $item) {
          // Do not access `$item->entity`: entity_reference_revisions would
          // load target_revision_id and abort the whole update when a stale
          // paragraph revision is referenced. The stable paragraph entity ID
          // is sufficient for best-effort cleanup after the node is saved.
          $target_id = (int) ($item->target_id ?? 0);
          if ($target_id > 0) {
            $old_ref_ids[$target_id] = $target_id;
          }
        }
        $node->set($layouts_field, []);
      }
    }
    else {
      $node = $node_storage->create(['type' => $node_bundle]);
      $old_ref_ids = [];
    }

    $created_paragraphs = [];
    $child_paragraph_count = 0;
    $media_count = 0;
    $this->applyNodeAttributes(
      $node,
      $node_attrs,
      $media_count,
      $paragraph_storage,
      $old_ref_ids,
      $child_paragraph_count,
    );
    $this->applyPathAlias($node, $path_alias);
    $source_key_field = $this->sourceKeyFieldName();
    $node->set(
      $source_key_field,
      $this->normalizeFieldValue($node->getFieldDefinition($source_key_field), $idempotence_key),
    );
    if ($paragraphs && $paragraph_storage !== NULL) {
      $sorted = $this->sortParagraphsBySequence($paragraphs);
      $paragraph_langcode = $node->language()->getId();
      foreach ($sorted as $para) {
        $entity = $this->createParagraphFromSpec(
          [
            'type' => $para['type'] ?? '',
            'fields' => $para['attributes'] ?? [],
            'options' => $para['options'] ?? [],
          ],
          $paragraph_storage,
          $child_paragraph_count,
          $media_count,
          0,
          $paragraph_langcode,
        );
        $created_paragraphs[] = [
          'target_id' => $entity->id(),
          'target_revision_id' => $entity->getRevisionId(),
        ];
      }
      $node->set($this->layoutsFieldName(), $created_paragraphs);
    }

    $node->save();

    // Delete the old paragraphs we detached, AFTER the node save committed
    // the new layout list — so a partial failure leaves the old node intact.
    $old_refs = $paragraph_storage !== NULL && $old_ref_ids
      ? $paragraph_storage->loadMultiple(array_values($old_ref_ids))
      : [];
    foreach ($old_refs as $old) {
      try { $old->delete(); }
      catch (\Throwable $e) { /* best effort */ }
    }

    $result = [
      'status' => 'ok',
      'nid' => (int) $node->id(),
      'vid' => (int) $node->getRevisionId(),
      'revision_id' => (int) $node->getRevisionId(),
      'paragraph_count' => count($created_paragraphs),
      'child_paragraph_count' => $child_paragraph_count,
      'media_count' => $media_count,
    ];
    if ($this->mediaWarnings) {
      $result['warnings'] = $this->mediaWarnings;
      $result['media_skipped_count'] = count($this->mediaWarnings);
    }
    return $result;
  }

  /**
   * Upsert taxonomy terms for one vocabulary, preserving hierarchy.
   */
  protected function doImportTaxonomyTerms(string $vocabulary, array $terms): array {
    if ($vocabulary === '' || !$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return ['status' => 'error', 'reason' => 'vocabulary is required and taxonomy must be installed.'];
    }
    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if ($vocabulary_storage->load($vocabulary) === NULL) {
      return [
        'status' => 'error',
        'reason' => "Vocabulary '{$vocabulary}' does not exist on this site; create it first (target setup).",
      ];
    }
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $results = [];
    $source_to_target = [];
    foreach ($terms as $spec) {
      if (!is_array($spec) || trim((string) ($spec['name'] ?? '')) === '') {
        continue;
      }
      $name = trim((string) $spec['name']);
      $uuid = (string) ($spec['uuid'] ?? '');
      $existing = NULL;
      if ($uuid !== '') {
        $matches = $term_storage->loadByProperties(['uuid' => $uuid]);
        $existing = $matches ? reset($matches) : NULL;
      }
      if ($existing === NULL) {
        $matches = $term_storage->loadByProperties(['vid' => $vocabulary, 'name' => $name]);
        $existing = $matches ? reset($matches) : NULL;
      }

      $parent_source = (int) ($spec['parent'] ?? 0);
      $parent_target = $parent_source > 0 ? ($source_to_target[$parent_source] ?? 0) : 0;

      if ($existing === NULL) {
        $values = [
          'vid' => $vocabulary,
          'name' => $name,
          'weight' => (int) ($spec['weight'] ?? 0),
          'parent' => $parent_target,
        ];
        if ($uuid !== '') {
          $values['uuid'] = $uuid;
        }
        if (!empty($spec['description'])) {
          $values['description'] = ['value' => (string) $spec['description'], 'format' => 'basic_html'];
        }
        $term = $term_storage->create($values);
        $action = 'created';
      }
      else {
        $term = $existing;
        $term->set('name', $name);
        $term->set('weight', (int) ($spec['weight'] ?? $term->getWeight()));
        $term->set('parent', $parent_target);
        $action = 'updated';
      }

      $labels = is_array($spec['labels'] ?? NULL) ? $spec['labels'] : [];
      foreach ($labels as $langcode => $label) {
        if ($langcode === $term->language()->getId() || trim((string) $label) === '') {
          continue;
        }
        try {
          $translation = $term->hasTranslation($langcode)
            ? $term->getTranslation($langcode)
            : $term->addTranslation($langcode, []);
          $translation->set('name', trim((string) $label));
        }
        catch (\InvalidArgumentException $e) {
          // Language not enabled — skip silently, the term itself imports.
        }
      }
      $term->save();
      $source_to_target[(int) ($spec['tid'] ?? 0)] = (int) $term->id();
      $results[] = ['name' => $name, 'tid' => (int) $term->id(), 'action' => $action];
    }

    return [
      'status' => 'ok',
      'vocabulary' => $vocabulary,
      'term_count' => count($results),
      'terms' => $results,
    ];
  }

  /**
   * Upsert the source's accounts so imported nodes can be owned by a person.
   *
   * Matched by e-mail: it is the only identifier that survives a migration —
   * uids are per-site and usernames collide. An account with no e-mail cannot
   * be matched and is reported rather than guessed at; its pages stay with the
   * importing account.
   *
   * Accounts are created BLOCKED and without a password: this imports
   * authorship, not access. Anyone who should be able to log in is unblocked
   * deliberately, by a human. No notification mail is ever sent — a migration
   * must not e-mail a customer's editors.
   *
   * @param array $users
   *   Source accounts: {uid, uuid, name, mail, status, roles, created}.
   * @param array $role_mapping
   *   Source role id → target role id, as confirmed at the migration's role
   *   gate. A source role absent from the mapping, or mapped to a role this
   *   site does not have, is reported as `pending_target_setup`.
   */
  protected function doImportUsers(array $users, array $role_mapping): array {
    if (!$this->entityTypeManager->hasDefinition('user')) {
      return ['status' => 'error', 'reason' => 'the user entity type is not available on this site.'];
    }
    $user_storage = $this->entityTypeManager->getStorage('user');

    $existing_roles = [];
    if ($this->entityTypeManager->hasDefinition('user_role')) {
      $existing_roles = array_keys($this->entityTypeManager->getStorage('user_role')->loadMultiple());
    }

    $results = [];
    $pending_roles = [];
    $by_source_uid = [];
    foreach ($users as $spec) {
      if (!is_array($spec)) {
        continue;
      }
      $mail = trim((string) ($spec['mail'] ?? ''));
      $name = trim((string) ($spec['name'] ?? ''));
      $source_uid = (int) ($spec['uid'] ?? 0);
      if ($mail === '') {
        $results[] = [
          'uid' => $source_uid,
          'name' => $name,
          'action' => 'skipped',
          'reason' => 'owner_email_missing',
        ];
        continue;
      }

      // Roles first: an unmapped role is reported, never invented.
      $target_roles = [];
      foreach ((array) ($spec['roles'] ?? []) as $source_role) {
        $source_role = (string) $source_role;
        if ($source_role === '' || $source_role === 'authenticated') {
          continue;
        }
        $mapped = (string) ($role_mapping[$source_role] ?? '');
        if ($mapped === '' || !in_array($mapped, $existing_roles, TRUE)) {
          $pending_roles[$source_role] = TRUE;
          continue;
        }
        $target_roles[] = $mapped;
      }

      $matches = $user_storage->loadByProperties(['mail' => $mail]);
      $account = $matches ? reset($matches) : NULL;

      if ($account === NULL) {
        $values = [
          'name' => $this->availableUserName($name !== '' ? $name : $mail, $user_storage),
          'mail' => $mail,
          'status' => 0,
        ];
        if ((int) ($spec['created'] ?? 0) > 0) {
          $values['created'] = (int) $spec['created'];
        }
        if (!empty($spec['uuid'])) {
          $values['uuid'] = (string) $spec['uuid'];
        }
        $account = $user_storage->create($values);
        $action = 'created';
      }
      else {
        // An account that already exists on the target is the customer's, not
        // the migration's: never rename it and never change whether it can log
        // in. Only the roles this migration is responsible for are added.
        $action = 'matched';
      }
      foreach ($target_roles as $role_id) {
        if (!$account->hasRole($role_id)) {
          $account->addRole($role_id);
        }
      }
      $account->save();

      $by_source_uid[$source_uid] = (int) $account->id();
      $results[] = [
        'uid' => $source_uid,
        'targetUid' => (int) $account->id(),
        'name' => $account->getAccountName(),
        'mail' => $mail,
        'roles' => $target_roles,
        'action' => $action,
      ];
    }

    $created = 0;
    $skipped = 0;
    foreach ($results as $row) {
      if ($row['action'] === 'created') {
        $created++;
      }
      elseif ($row['action'] === 'skipped') {
        $skipped++;
      }
    }
    return [
      'status' => 'ok',
      'user_count' => count($results),
      'created_count' => $created,
      'matched_count' => count($results) - $created - $skipped,
      'skipped_count' => $skipped,
      'pending_target_setup' => array_keys($pending_roles),
      'source_uid_map' => $by_source_uid,
      'users' => $results,
    ];
  }

  /**
   * The target uid for a `{email, name}` owner, or NULL to leave it alone.
   *
   * By e-mail only. A name is not an identity — two people share one, and
   * matching on it would hand a customer's pages to the wrong account. When an
   * owner cannot be resolved the node keeps the importing account, which is
   * the honest outcome: authorship missing, not authorship wrong.
   */
  protected function resolveOwnerUid(array $owner): ?int {
    $mail = trim((string) ($owner['email'] ?? $owner['mail'] ?? ''));
    if ($mail === '' || !$this->entityTypeManager->hasDefinition('user')) {
      return NULL;
    }
    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail]);
    if (!$matches) {
      return NULL;
    }
    return (int) reset($matches)->id();
  }

  /**
   * A username nobody else holds — the source's, suffixed only when taken.
   *
   * Usernames are unique per site and two sites can disagree; the e-mail is
   * what identifies the person, so a collision must not stop the import.
   */
  protected function availableUserName(string $preferred, $user_storage): string {
    $preferred = trim($preferred) !== '' ? trim($preferred) : 'imported-user';
    $candidate = $preferred;
    $suffix = 1;
    while ($user_storage->loadByProperties(['name' => $candidate])) {
      $suffix++;
      $candidate = $preferred . '-' . $suffix;
    }
    return $candidate;
  }

  /**
   * Upsert menu links, resolving node targets through the source key.
   */
  protected function doImportMenuLinks(string $menu, array $links, string $source_base_url): array {
    if ($menu === '' || !$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return ['status' => 'error', 'reason' => 'menu is required and menu_link_content must be installed.'];
    }
    if ($this->entityTypeManager->getStorage('menu')->load($menu) === NULL) {
      return [
        'status' => 'error',
        'reason' => "Menu '{$menu}' does not exist on this site; create it first (target setup).",
      ];
    }
    $link_storage = $this->entityTypeManager->getStorage('menu_link_content');
    $source_field = $this->sourceKeyFieldName();

    $results = [];
    $uuid_to_plugin = [];
    foreach ($links as $spec) {
      if (!is_array($spec) || trim((string) ($spec['title'] ?? '')) === '') {
        continue;
      }
      $title = trim((string) $spec['title']);
      $uuid = (string) ($spec['uuid'] ?? '');
      $source_uri = (string) ($spec['uri'] ?? '');

      // Resolve the target: internal node links go through the migration
      // source key so they point at the IMPORTED node.
      $resolved_uri = '';
      $unresolved_reason = '';
      if (preg_match('#^(?:entity:node/|internal:/node/)(\\d+)$#', $source_uri, $m)) {
        $source_url = rtrim($source_base_url, '/') . '/node/' . $m[1];
        $nid = $this->findNodeBySourceUrl(NULL, $source_field, $source_url);
        if ($nid !== NULL) {
          $resolved_uri = 'entity:node/' . $nid;
        }
        else {
          $unresolved_reason = "No imported node found for source {$source_url}.";
        }
      }
      elseif (str_starts_with($source_uri, 'internal:') || str_starts_with($source_uri, 'route:')) {
        $resolved_uri = $source_uri;
      }
      elseif (str_starts_with($source_uri, 'http://') || str_starts_with($source_uri, 'https://')) {
        $resolved_uri = $source_uri;
      }
      elseif ($source_uri !== '') {
        $resolved_uri = 'internal:' . (str_starts_with($source_uri, '/') ? $source_uri : '/' . $source_uri);
      }

      if ($resolved_uri === '') {
        $results[] = [
          'title' => $title,
          'status' => 'unresolved',
          'reason' => $unresolved_reason ?: 'No usable link target.',
        ];
        continue;
      }

      $existing = NULL;
      if ($uuid !== '') {
        $matches = $link_storage->loadByProperties(['uuid' => $uuid]);
        $existing = $matches ? reset($matches) : NULL;
      }
      $parent_uuid = (string) ($spec['parent'] ?? '');
      $parent_plugin = '';
      if ($parent_uuid !== '') {
        // Source parents arrive as "menu_link_content:<uuid>" plugin ids.
        $parent_uuid_clean = str_replace('menu_link_content:', '', $parent_uuid);
        $parent_plugin = $uuid_to_plugin[$parent_uuid_clean] ?? '';
      }

      if ($existing === NULL) {
        $values = [
          'menu_name' => $menu,
          'title' => $title,
          'link' => ['uri' => $resolved_uri],
          'weight' => (int) ($spec['weight'] ?? 0),
          'enabled' => (bool) ($spec['enabled'] ?? TRUE),
          'expanded' => (bool) ($spec['expanded'] ?? FALSE),
        ];
        if ($uuid !== '') {
          $values['uuid'] = $uuid;
        }
        if ($parent_plugin !== '') {
          $values['parent'] = $parent_plugin;
        }
        $link = $link_storage->create($values);
        $action = 'created';
      }
      else {
        $link = $existing;
        $link->set('title', $title);
        $link->set('link', ['uri' => $resolved_uri]);
        $link->set('weight', (int) ($spec['weight'] ?? $link->getWeight()));
        if ($parent_plugin !== '') {
          $link->set('parent', $parent_plugin);
        }
        $action = 'updated';
      }

      $titles = is_array($spec['titles'] ?? NULL) ? $spec['titles'] : [];
      foreach ($titles as $langcode => $label) {
        if ($langcode === $link->language()->getId() || trim((string) $label) === '') {
          continue;
        }
        try {
          $translation = $link->hasTranslation($langcode)
            ? $link->getTranslation($langcode)
            : $link->addTranslation($langcode, []);
          $translation->set('title', trim((string) $label));
        }
        catch (\InvalidArgumentException $e) {
          // Language not enabled — skip.
        }
      }
      $link->save();
      $uuid_to_plugin[$link->uuid()] = 'menu_link_content:' . $link->uuid();
      $results[] = ['title' => $title, 'status' => 'ok', 'action' => $action, 'uri' => $resolved_uri];
    }

    return [
      'status' => 'ok',
      'menu' => $menu,
      'link_count' => count($results),
      'unresolved_count' => count(array_filter($results, static fn (array $row) => ($row['status'] ?? '') === 'unresolved')),
      'links' => $results,
    ];
  }

  /**
   * Create/update node translations under the symmetric paragraph model.
   *
   * ICMS keeps the layouts field non-translatable (one paragraph set shared
   * across languages). A translation whose paragraph structure matches the
   * default language's (`structureMatch: true`, stamped by the agent and
   * re-verified here) is written INTO translations of the shared paragraph
   * entities, matched by position. A mismatched language
   * (`structureMatch: false`, shipped without paragraphs) gets scalar node
   * fields only, with the reason in the summary — shared paragraphs are
   * never silently replaced per language. When a target's layouts field IS
   * translatable, the legacy per-language paragraph set path still applies.
   *
   * @param int $nid
   *   The saved default-language node ID.
   * @param array $translations
   *   Translation specs
   *   ({langcode, node.attributes, paragraphs, structureMatch?, structureReason?}).
   *
   * @return array
   *   One summary entry per requested translation.
   */
  protected function writeTranslations(int $nid, array $translations): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_storage->resetCache([$nid]);
    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->load($nid);
    if ($node === NULL) {
      throw new \RuntimeException("Node {$nid} disappeared before translation write.");
    }
    $paragraph_storage = $this->entityTypeManager->hasDefinition('paragraph')
      ? $this->entityTypeManager->getStorage('paragraph')
      : NULL;
    $layouts_field = $this->layoutsFieldName();

    $summary = [];
    foreach ($translations as $translation_spec) {
      if (!is_array($translation_spec)) {
        continue;
      }
      $langcode = (string) ($translation_spec['langcode'] ?? '');
      if ($langcode === '' || $langcode === $node->language()->getId()) {
        continue;
      }

      $exists = $node->hasTranslation($langcode);
      try {
        $translation = $exists
          ? $node->getTranslation($langcode)
          : $node->addTranslation($langcode, []);
      }
      catch (\InvalidArgumentException $e) {
        $summary[] = [
          'langcode' => $langcode,
          'status' => 'skipped',
          'reason' => 'Language is not enabled on the target site.',
        ];
        continue;
      }

      $attrs = is_array($translation_spec['node']['attributes'] ?? NULL)
        ? $translation_spec['node']['attributes']
        : [];
      unset($attrs['langcode']);
      $media_count = 0;
      $this->applyNodeAttributes($translation, $attrs, $media_count);
      // Aliases are per language: the French page keeps the French path.
      $this->applyPathAlias($translation, (string) ($translation_spec['node']['path_alias'] ?? ''));

      $paragraph_specs = is_array($translation_spec['paragraphs'] ?? NULL)
        ? $translation_spec['paragraphs']
        : [];
      $paragraphs_translated = FALSE;
      $paragraphs_mode = 'shared';
      $symmetric_reason = NULL;
      $untranslated_fields = [];
      $old_ref_ids = [];
      $field_definition = $translation->hasField($layouts_field)
        ? $translation->getFieldDefinition($layouts_field)
        : NULL;

      if ($paragraph_specs && $paragraph_storage !== NULL && $field_definition !== NULL && $field_definition->isTranslatable()) {
        foreach ($translation->get($layouts_field) as $item) {
          $target_id = (int) ($item->target_id ?? 0);
          if ($target_id > 0) {
            $old_ref_ids[$target_id] = $target_id;
          }
        }
        $created = [];
        $child_paragraph_count = 0;
        foreach ($this->sortParagraphsBySequence($paragraph_specs) as $para) {
          $entity = $this->createParagraphFromSpec(
            [
              'type' => $para['type'] ?? '',
              'fields' => $para['attributes'] ?? [],
              'options' => $para['options'] ?? [],
            ],
            $paragraph_storage,
            $child_paragraph_count,
            $media_count,
          );
          if ($entity->hasField('langcode')) {
            $entity->set('langcode', $langcode);
            $entity->save();
          }
          $created[] = [
            'target_id' => $entity->id(),
            'target_revision_id' => $entity->getRevisionId(),
          ];
        }
        $translation->set($layouts_field, $created);
        $paragraphs_translated = TRUE;
        $paragraphs_mode = 'per_language_set';
      }
      elseif (
        $paragraph_specs
        && $paragraph_storage !== NULL
        && $field_definition !== NULL
        && ($translation_spec['structureMatch'] ?? NULL) === TRUE
      ) {
        // Symmetric model: the layouts field is shared across languages, and
        // the agent verified this language's paragraph structure matches the
        // default language's. Write its content into translations of the
        // shared paragraph entities, matched by position — after our own
        // verification pass, so a drifted target never gets half-translated.
        try {
          $shared = $this->loadSharedParagraphs($node, $layouts_field);
          $specs = $this->sortParagraphsBySequence($paragraph_specs);
          $symmetric_reason = $this->verifySharedParagraphStructure($shared, $specs);
          if ($symmetric_reason === NULL) {
            foreach ($specs as $index => $para) {
              $para_fields = $para['attributes'] ?? $para['fields'] ?? [];
              $this->translateParagraphRecursive(
                $shared[$index],
                is_array($para_fields) ? $para_fields : [],
                $langcode,
                $media_count,
                $untranslated_fields,
              );
            }
            $paragraphs_translated = TRUE;
            $paragraphs_mode = 'symmetric';
          }
        }
        catch (\Throwable $e) {
          $symmetric_reason = 'symmetric translation failed: ' . $e->getMessage();
        }
      }

      $translation->save();

      // Best-effort cleanup of this language's previous paragraph set,
      // after the translation save committed the new references.
      if ($old_ref_ids && $paragraph_storage !== NULL) {
        foreach ($paragraph_storage->loadMultiple(array_values($old_ref_ids)) as $old) {
          try {
            $old->delete();
          }
          catch (\Throwable $e) {
            // Best effort.
          }
        }
      }

      $entry = [
        'langcode' => $langcode,
        'status' => 'ok',
        'action' => $exists ? 'updated' : 'created',
        'paragraph_count' => count($paragraph_specs),
        'paragraphs_translated' => $paragraphs_translated,
        'paragraphs_mode' => $paragraphs_mode,
        'media_count' => $media_count,
      ];
      if (($translation_spec['structureMatch'] ?? NULL) === FALSE) {
        $entry['reason'] = (string) ($translation_spec['structureReason']
          ?? 'paragraph structure differs from the default language; sections not imported.');
      }
      elseif ($paragraph_specs && !$paragraphs_translated) {
        $entry['reason'] = $symmetric_reason !== NULL
          ? "Symmetric translation aborted ({$symmetric_reason}); scalar fields were translated, paragraphs stay shared."
          : "Field {$layouts_field} is not translatable; scalar fields were translated, paragraphs stay shared.";
      }
      if ($untranslated_fields) {
        // Fields whose target config keeps them shared across languages —
        // step-2 setup work (mark them translatable), not a migration error.
        $entry['untranslated_fields'] = array_values(array_unique($untranslated_fields));
      }
      $summary[] = $entry;
    }

    return $summary;
  }

  /**
   * Ordered shared paragraph entities referenced by the default language.
   */
  protected function loadSharedParagraphs(\Drupal\node\NodeInterface $node, string $layouts_field): array {
    $entities = [];
    if (!$node->hasField($layouts_field)) {
      return $entities;
    }
    foreach ($node->get($layouts_field) as $item) {
      $entity = $item->entity ?? NULL;
      if ($entity instanceof \Drupal\paragraphs\ParagraphInterface) {
        $entities[] = $entity;
      }
    }
    return $entities;
  }

  /**
   * Verify translation specs align with the shared paragraphs positionally.
   *
   * Checks counts and bundles at every nesting level BEFORE any write, so a
   * target that drifted from the pivot (manual edits, partial earlier import)
   * never ends up with answers attached to the wrong questions. Returns NULL
   * on a full match, otherwise a human-readable mismatch description.
   */
  protected function verifySharedParagraphStructure(array $entities, array $specs, int $depth = 0): ?string {
    if ($depth > 8) {
      return 'paragraph nesting exceeds the supported depth of 8';
    }
    if (count($entities) !== count($specs)) {
      return sprintf('%d paragraphs on the target, %d in the translation', count($entities), count($specs));
    }
    foreach (array_values($specs) as $index => $spec) {
      if (!is_array($spec)) {
        return sprintf('position %d: translation paragraph is not an object', $index);
      }
      $entity = $entities[$index];
      $bundle = $this->stripJsonApiPrefix((string) ($spec['type'] ?? ''));
      if ($bundle !== '' && $bundle !== $entity->bundle()) {
        return sprintf("position %d: bundle '%s' on the target, '%s' in the translation", $index, $entity->bundle(), $bundle);
      }
      $fields = $spec['attributes'] ?? $spec['fields'] ?? [];
      if (!is_array($fields)) {
        continue;
      }
      foreach ($fields as $name => $value) {
        if (!$entity->hasField($name)) {
          continue;
        }
        $definition = $entity->getFieldDefinition($name);
        if ($definition->getType() !== 'entity_reference_revisions' || (string) ($definition->getSetting('target_type') ?? '') !== 'paragraph') {
          continue;
        }
        $child_entities = [];
        foreach ($entity->get($name) as $item) {
          if (($item->entity ?? NULL) instanceof \Drupal\paragraphs\ParagraphInterface) {
            $child_entities[] = $item->entity;
          }
        }
        $mismatch = $this->verifySharedParagraphStructure($child_entities, $this->normalizeList($value), $depth + 1);
        if ($mismatch !== NULL) {
          return sprintf('position %d, field %s: %s', $index, $name, $mismatch);
        }
      }
    }
    return NULL;
  }

  /**
   * Write one language's content into translations of a shared paragraph.
   *
   * Symmetric model: only translatable fields are written — a
   * non-translatable field keeps its shared value and is reported in
   * `untranslated_fields` (making it translatable is target setup, not a
   * migration error). Child paragraph references recurse positionally; the
   * structure was verified up front. Blökkli behavior settings are
   * entity-level, so they stay shared.
   */
  protected function translateParagraphRecursive(
    \Drupal\paragraphs\ParagraphInterface $paragraph,
    array $fields,
    string $langcode,
    int &$media_count,
    array &$untranslated_fields,
    int $depth = 0,
  ): void {
    if ($depth > 8) {
      throw new \RuntimeException('Paragraph nesting exceeds the supported depth of 8.');
    }
    $translation = $paragraph->hasTranslation($langcode)
      ? $paragraph->getTranslation($langcode)
      : $paragraph->addTranslation($langcode, []);
    foreach ($fields as $name => $value) {
      if (!$translation->hasField($name)) {
        continue;
      }
      $definition = $translation->getFieldDefinition($name);
      if ($definition->getType() === 'entity_reference_revisions' && (string) ($definition->getSetting('target_type') ?? '') === 'paragraph') {
        $child_entities = [];
        foreach ($paragraph->get($name) as $item) {
          if (($item->entity ?? NULL) instanceof \Drupal\paragraphs\ParagraphInterface) {
            $child_entities[] = $item->entity;
          }
        }
        foreach (array_values($this->normalizeList($value)) as $index => $child_spec) {
          if (!is_array($child_spec) || !isset($child_entities[$index])) {
            continue;
          }
          $child_fields = $child_spec['fields'] ?? $child_spec['attributes'] ?? [];
          $this->translateParagraphRecursive(
            $child_entities[$index],
            is_array($child_fields) ? $child_fields : [],
            $langcode,
            $media_count,
            $untranslated_fields,
            $depth + 1,
          );
        }
        continue;
      }
      if (!$definition->isTranslatable()) {
        $untranslated_fields[] = $name;
        continue;
      }
      $this->setEntityField($translation, $name, $value, $media_count);
    }
    $translation->save();
  }

  /**
   * Create one paragraph and recursively materialize paragraph/media fields.
   */
  protected function createParagraphFromSpec(
    array $spec,
    $paragraph_storage,
    int &$child_paragraph_count,
    int &$media_count,
    int $depth = 0,
    string $langcode = '',
  ): \Drupal\paragraphs\ParagraphInterface {
    if ($depth > 8) {
      throw new \RuntimeException('Paragraph nesting exceeds the supported depth of 8.');
    }

    $bundle = $this->stripJsonApiPrefix((string) ($spec['type'] ?? ''));
    if ($bundle === '') {
      throw new \InvalidArgumentException('Nested paragraph is missing its type.');
    }

    // Paragraphs must be born in the node's language, not the site default:
    // otherwise the default-language content is stored under the wrong
    // langcode and a later translation write for the site default language
    // OVERWRITES it (symmetric translations match by position on the shared
    // paragraph set, so the language identity of the base row is load-bearing).
    /** @var \Drupal\paragraphs\ParagraphInterface $entity */
    $entity = $paragraph_storage->create(
      ['type' => $bundle] + ($langcode !== '' ? ['langcode' => $langcode] : []),
    );
    $fields = $spec['fields'] ?? $spec['attributes'] ?? [];
    if (!is_array($fields)) {
      throw new \InvalidArgumentException("Fields for paragraph '{$bundle}' must be an object.");
    }

    foreach ($fields as $name => $value) {
      if (!$entity->hasField($name)) {
        throw new \InvalidArgumentException("Field '{$name}' does not exist on paragraph:{$bundle}.");
      }
      $definition = $entity->getFieldDefinition($name);
      $field_type = $definition->getType();
      $target_type = (string) ($definition->getSetting('target_type') ?? '');

      if ($field_type === 'entity_reference_revisions' && $target_type === 'paragraph') {
        $references = [];
        foreach ($this->normalizeList($value) as $child_spec) {
          if (!is_array($child_spec)) {
            throw new \InvalidArgumentException("Child value for '{$name}' must be an object.");
          }
          $child = $this->createParagraphFromSpec(
            $child_spec,
            $paragraph_storage,
            $child_paragraph_count,
            $media_count,
            $depth + 1,
            $langcode,
          );
          $references[] = [
            'target_id' => $child->id(),
            'target_revision_id' => $child->getRevisionId(),
          ];
          $child_paragraph_count++;
        }
        $entity->set($name, $references);
        continue;
      }

      $this->setEntityField($entity, $name, $value, $media_count);
    }

    $options = $spec['options'] ?? [];
    if ($options) {
      if (!is_array($options)) {
        throw new \InvalidArgumentException("Blökkli options for paragraph '{$bundle}' must be an object.");
      }
      $entity->setBehaviorSettings('paragraphs_blokkli_data', $options);
    }

    $entity->save();
    return $entity;
  }

  /**
   * Apply one non-paragraph field value, resolving reference field types.
   *
   * Shared by paragraph fields and node attributes so both resolve media and
   * taxonomy references and tablefield payloads identically; anything else is
   * set verbatim. Paragraph child references are NOT handled here — they need
   * recursive materialization and stay in createParagraphFromSpec.
   */
  protected function setEntityField(
    \Drupal\Core\Entity\FieldableEntityInterface $entity,
    string $name,
    mixed $value,
    int &$media_count,
  ): void {
    $definition = $entity->getFieldDefinition($name);
    $field_type = $definition->getType();
    $target_type = (string) ($definition->getSetting('target_type') ?? '');

    if ($field_type === 'entity_reference' && $target_type === 'media') {
      $references = [];
      foreach ($this->normalizeList($value) as $media_spec) {
        // An asset that cannot be resolved — unreachable, wrong type for the
        // field, rejected by validation — costs that asset only. Failing the
        // node here loses every other field on the page for one bad file.
        try {
          $target_id = $this->resolveMediaReference($media_spec, $definition);
        }
        catch (\Throwable $e) {
          $this->mediaWarnings[] = sprintf(
            "Field '%s' on %s:%s: skipped one media item — %s",
            $name,
            $entity->getEntityTypeId(),
            $entity->bundle(),
            $e->getMessage(),
          );
          $this->logger->warning('icms_mcp: skipped media on @field: @msg', [
            '@field' => $name,
            '@msg' => $e->getMessage(),
          ]);
          continue;
        }
        if ($target_id !== NULL) {
          $references[] = ['target_id' => $target_id];
          $media_count++;
        }
      }
      $entity->set($name, $references);
      return;
    }

    if ($field_type === 'entity_reference' && $target_type === 'taxonomy_term') {
      $references = [];
      foreach ($this->normalizeList($value) as $term_spec) {
        $target_id = $this->resolveTaxonomyReference($term_spec, $definition);
        if ($target_id !== NULL) {
          $references[] = ['target_id' => $target_id];
        }
      }
      $entity->set($name, $references);
      return;
    }

    if ($field_type === 'tablefield') {
      $entity->set($name, $this->buildTablefieldValue($value, $name, (string) $entity->bundle()));
      return;
    }

    $entity->set($name, $this->normalizeFieldValue($definition, $value));
  }

  /**
   * Convert the pivot's table shape into a tablefield storage value.
   *
   * The migration pivot emits `{rows, hasHeader?, caption?}`, where `rows` is
   * a list of rows, each a list of plain-text cells. The tablefield module
   * stores a 2D `value` map (row index → cell list) plus an optional caption;
   * its FieldType::setValue derives the rebuild rows/cols count from `value`.
   * tablefield has no header flag of its own — the default formatter renders
   * the first row as the header — so `hasHeader` is advisory and the header
   * row, when present, simply stays as row 0 of the data.
   */
  protected function buildTablefieldValue(mixed $value, string $name, string $bundle): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException("Field '{$name}' on paragraph:{$bundle} expects a table object.");
    }
    $rows_in = $value['rows'] ?? [];
    if (!is_array($rows_in)) {
      throw new \InvalidArgumentException("Field '{$name}' on paragraph:{$bundle} expects 'rows' to be a list of rows.");
    }

    $rows = [];
    foreach ($rows_in as $row) {
      if (!is_array($row)) {
        throw new \InvalidArgumentException("Field '{$name}' on paragraph:{$bundle} expects each table row to be a list of cells.");
      }
      $cells = [];
      foreach ($row as $cell) {
        $cells[] = is_scalar($cell) ? (string) $cell : '';
      }
      $rows[] = array_values($cells);
    }

    $table = ['value' => array_values($rows)];
    $caption = trim((string) ($value['caption'] ?? ''));
    if ($caption !== '') {
      $table['caption'] = $caption;
    }
    return $table;
  }

  /**
   * Resolve an existing media id or create media from a remote source URL.
   */
  protected function resolveMediaReference(mixed $value, FieldDefinitionInterface $definition): ?int {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      return (int) $value;
    }
    if (!is_array($value)) {
      return NULL;
    }
    if (!empty($value['target_id'])) {
      return (int) $value['target_id'];
    }

    $url = (string) ($value['preferred_url'] ?? $value['src'] ?? $value['url'] ?? '');
    if ($url === '') {
      return NULL;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException("Invalid media URL '{$url}'.");
    }

    $settings = $definition->getSetting('handler_settings') ?? [];
    $allowed = array_keys($settings['target_bundles'] ?? []);
    if (!$allowed) {
      $allowed = array_keys($this->bundleInfo->getBundleInfo('media'));
    }
    $bundle = $this->chooseMediaBundle($url, $allowed);
    if ($bundle === NULL) {
      throw new \RuntimeException(sprintf(
        "No allowed media bundle can hold '%s' (this field allows: %s). Add a bundle for that file type on the target, or drop the asset from the mapping.",
        basename((string) parse_url($url, PHP_URL_PATH)) ?: $url,
        $allowed ? implode(', ', $allowed) : 'none',
      ));
    }

    /** @var \Drupal\media\MediaTypeInterface|null $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($bundle);
    if ($media_type === NULL) {
      throw new \RuntimeException("Media type '{$bundle}' does not exist.");
    }
    $source_definition = $media_type->getSource()->getSourceFieldDefinition($media_type);
    if ($source_definition === NULL) {
      throw new \RuntimeException("Media type '{$bundle}' has no configured source field.");
    }
    $source_field = $source_definition->getName();
    $source_type = $source_definition->getType();

    if ($bundle === 'remote_video') {
      $existing_media = $this->entityTypeManager->getStorage('media')->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $bundle)
        ->condition($source_type === 'link' ? $source_field . '.uri' : $source_field, $url)
        ->range(0, 1)
        ->execute();
      if ($existing_media) {
        return (int) reset($existing_media);
      }
      $media = $this->entityTypeManager->getStorage('media')->create([
        'bundle' => $bundle,
        'name' => $this->mediaName($value, $url),
        'status' => 1,
      ]);
      $media->set($source_field, $source_type === 'link' ? ['uri' => $url] : $url);
      $this->validateAndSaveMedia($media);
      return (int) $media->id();
    }

    if (!in_array($source_type, ['image', 'file'], TRUE)) {
      throw new \RuntimeException(
        "Unsupported source field type '{$source_type}' on media:{$bundle}."
      );
    }

    $file = $this->downloadRemoteFile($url);
    $existing_media = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition($source_field . '.target_id', $file->id())
      ->range(0, 1)
      ->execute();
    if ($existing_media) {
      return (int) reset($existing_media);
    }

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'name' => $this->mediaName($value, $url, $file),
      'status' => 1,
    ]);
    $field_value = ['target_id' => $file->id()];
    if ($source_type === 'image') {
      $field_value['alt'] = $this->truncateString($this->firstNonEmptyString([
        $value['alt'] ?? NULL,
        $value['title'] ?? NULL,
        $this->mediaName($value, $url, $file),
      ]), 512);
      $field_value['title'] = $this->truncateString(
        $this->firstNonEmptyString([$value['title'] ?? NULL]),
        1024,
      );
    }
    $media->set($source_field, $field_value);
    $this->validateAndSaveMedia($media);
    return (int) $media->id();
  }

  /**
   * Resolve a taxonomy term reference by id or name, creating it if needed.
   *
   * Accepts an existing term id (int / numeric string / `{target_id}`) or a
   * term name (plain string or `{name|title}`). A name is matched within the
   * field's allowed vocabularies; an unmatched name is created in the first
   * allowed vocabulary, mirroring how remote media is created on import so a
   * migrated node keeps its topics even when the term is new.
   */
  protected function resolveTaxonomyReference(mixed $value, FieldDefinitionInterface $definition): ?int {
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      return (int) $value;
    }

    $name = '';
    if (is_array($value)) {
      if (!empty($value['target_id'])) {
        return (int) $value['target_id'];
      }
      $name = trim((string) ($value['name'] ?? $value['title'] ?? ''));
    }
    elseif (is_string($value)) {
      $name = trim($value);
    }
    if ($name === '') {
      return NULL;
    }

    $settings = $definition->getSetting('handler_settings') ?? [];
    $vocabularies = array_keys($settings['target_bundles'] ?? []);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name)->range(0, 1);
    if ($vocabularies) {
      $query->condition('vid', $vocabularies, 'IN');
    }
    $ids = $query->execute();
    if ($ids) {
      return (int) reset($ids);
    }

    if (!$vocabularies) {
      throw new \RuntimeException("Cannot create taxonomy term '{$name}': the field defines no target vocabulary.");
    }
    $term = $storage->create(['vid' => reset($vocabularies), 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Return a human-readable media name, ignoring present-but-empty values.
   */
  protected function mediaName(array $value, string $url, ?\Drupal\file\FileInterface $file = NULL): string {
    $url_filename = basename((string) parse_url($url, PHP_URL_PATH));
    // Some source URLs are encoded twice (for example `%2520`). Decode a
    // bounded number of times without turning this into an open-ended loop.
    for ($i = 0; $i < 2; $i++) {
      $decoded = rawurldecode($url_filename);
      if ($decoded === $url_filename) {
        break;
      }
      $url_filename = $decoded;
    }
    $name = $this->firstNonEmptyString([
      $value['alt'] ?? NULL,
      $value['title'] ?? NULL,
      $value['filename'] ?? NULL,
      $file?->getFilename(),
      $url_filename,
      'Imported media',
    ]);
    // Drupal media names are strings with a hard 255-character limit. Keep
    // the complete alt text on the image field, but bound the administrative
    // media entity label independently.
    return $this->truncateString($name, 255);
  }

  /**
   * Return the first non-empty scalar string from a list of candidates.
   */
  protected function firstNonEmptyString(array $values): string {
    foreach ($values as $value) {
      if (is_scalar($value) && trim((string) $value) !== '') {
        return trim((string) $value);
      }
    }
    return '';
  }

  /** Truncate a Unicode string to a hard storage limit. */
  protected function truncateString(string $value, int $max_length): string {
    if ($max_length <= 0 || mb_strlen($value) <= $max_length) {
      return $value;
    }
    return $max_length === 1 ? '…' : mb_substr($value, 0, $max_length - 1) . '…';
  }

  /** Normalize values according to live field storage constraints. */
  protected function normalizeFieldValue(FieldDefinitionInterface $definition, mixed $value): mixed {
    $field_type = $definition->getType();
    $max_length = (int) ($definition->getSetting('max_length') ?? 0);
    if ($max_length > 0 && in_array($field_type, ['string', 'string_long'], TRUE)) {
      if (is_string($value)) {
        return $this->truncateString($value, $max_length);
      }
      if (is_array($value)) {
        foreach ($value as &$item) {
          if (is_string($item)) {
            $item = $this->truncateString($item, $max_length);
          }
          elseif (is_array($item) && isset($item['value']) && is_string($item['value'])) {
            $item['value'] = $this->truncateString($item['value'], $max_length);
          }
        }
        unset($item);
      }
    }
    if ($field_type === 'link') {
      // A bare site-relative path is not a valid link-field URI (LinkItem
      // requires a scheme; entity_usage throws on every save otherwise).
      $normalize_uri = static fn (string $uri): string =>
        str_starts_with($uri, '/') ? 'internal:' . $uri : $uri;
      if (is_string($value)) {
        return $normalize_uri($value);
      }
      if (is_array($value)) {
        $items = array_is_list($value) ? $value : [$value];
        foreach ($items as &$item) {
          if (is_array($item)) {
            if (isset($item['title']) && is_string($item['title'])) {
              $item['title'] = $this->truncateString($item['title'], 255);
            }
            if (isset($item['uri']) && is_string($item['uri'])) {
              $item['uri'] = $normalize_uri($item['uri']);
            }
          }
          elseif (is_string($item)) {
            $item = $normalize_uri($item);
          }
        }
        unset($item);
        return array_is_list($value) ? $items : ($items[0] ?? $value);
      }
    }
    return $value;
  }

  /**
   * Validate a media entity before saving so a missing source is never silent.
   */
  protected function validateAndSaveMedia(\Drupal\media\MediaInterface $media): void {
    $violations = $media->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      throw new \RuntimeException('Media validation failed: ' . implode('; ', $messages));
    }
    $media->save();
  }

  /**
   * Download a URL once into public storage and return a managed file entity.
   */
  protected function downloadRemoteFile(string $url): \Drupal\file\FileInterface {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $extension = $this->mediaExtensionFromPath($path);
    $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) ? '.' . $extension : '';
    $directory = 'public://icms_mcp';
    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    $uri = $directory . '/' . hash('sha256', $url) . $extension;

    $existing = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = $existing ? reset($existing) : NULL;
    if ($file !== NULL && file_exists($uri) && filesize($uri) > 0) {
      /** @var \Drupal\file\FileInterface $file */
      // When previously imported media was deleted, its orphaned file entity
      // is demoted to temporary and "cannot be referenced" by new media.
      // Reuse must re-promote it or the import fails on a stale entity.
      if (!$file->isPermanent()) {
        $file->setPermanent();
        $file->save();
      }
      return $file;
    }

    $response = $this->httpClient->request('GET', $url, [
      'timeout' => 30,
      'connect_timeout' => 10,
      'allow_redirects' => ['max' => 5],
      'headers' => ['User-Agent' => 'ICMS content migrator/1.0'],
    ]);
    $data = (string) $response->getBody();
    if ($data === '') {
      throw new \RuntimeException("Remote media '{$url}' returned an empty body.");
    }
    $saved_uri = $this->fileSystem->saveData($data, $uri, FileExists::Replace);
    if ($saved_uri === FALSE) {
      throw new \RuntimeException("Could not write remote media to '{$uri}'.");
    }

    // Reuse an existing managed file entity whose physical file was missing.
    if ($file !== NULL) {
      if (!$file->isPermanent()) {
        $file->setPermanent();
        $file->save();
      }
      return $file;
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->create([
      'filename' => $this->mediaName([], $url),
      'uri' => $saved_uri,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Media bundles by the asset family they can legitimately hold.
   *
   * The names vary per install, so each family lists candidates in
   * preference order and only an allowed one is used.
   */
  protected const MEDIA_BUNDLES_BY_FAMILY = [
    'video' => ['video', 'file', 'document'],
    'audio' => ['audio', 'file', 'document'],
    'document' => ['document', 'file'],
  ];

  /**
   * Extensions that are definitely not images, by asset family.
   */
  protected const NON_IMAGE_EXTENSIONS = [
    'video' => ['mp4', 'webm', 'mov', 'm4v', 'ogv', 'avi', 'mpg', 'mpeg'],
    'audio' => ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'flac', 'aac', 'wma'],
    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'csv', 'txt', 'rtf', 'odt'],
  ];

  /**
   * Keep the source page's own path as the node's URL alias.
   *
   * Without this the target generates an alias from the title, so every URL
   * on a migrated site changes and any path hierarchy flattens. Pathauto,
   * when installed, would regenerate the alias on save and discard what we
   * set, so the item is stamped to skip it.
   *
   * A blank alias leaves the target's own behaviour untouched — that is what
   * a source front page (no path of its own) must do.
   */
  protected function applyPathAlias(\Drupal\Core\Entity\FieldableEntityInterface $entity, string $alias): void {
    $alias = trim($alias);
    if ($alias === '' || !$entity->hasField('path')) {
      return;
    }
    $alias = '/' . ltrim($alias, '/');
    if ($alias === '/') {
      return;
    }
    $value = ['alias' => $alias];
    $properties = $entity->getFieldDefinition('path')
      ->getFieldStorageDefinition()
      ->getPropertyNames();
    if (in_array('pathauto', $properties, TRUE)) {
      $value['pathauto'] = 0;
    }
    $entity->set('path', $value);
  }

  /**
   * Pick an allowed media bundle from the source URL.
   *
   * Falling back to `image` for an unrecognised asset is what turned one mp3
   * into a failed page: the file reaches an image field, validation rejects
   * the extension, and the import aborts. An asset whose family has no
   * allowed bundle returns NULL so the caller can skip it with a reason.
   */
  protected function chooseMediaBundle(string $url, array $allowed): ?string {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (in_array('remote_video', $allowed, TRUE) && preg_match('/(?:youtube\.com|youtu\.be|vimeo\.com)$/', $host)) {
      return 'remote_video';
    }
    $extension = $this->mediaExtensionFromPath((string) parse_url($url, PHP_URL_PATH));
    if ($extension === 'svg') {
      return in_array('icon', $allowed, TRUE) ? 'icon' : NULL;
    }
    foreach (self::NON_IMAGE_EXTENSIONS as $family => $extensions) {
      if (!in_array($extension, $extensions, TRUE)) {
        continue;
      }
      foreach (self::MEDIA_BUNDLES_BY_FAMILY[$family] as $candidate) {
        if (in_array($candidate, $allowed, TRUE)) {
          return $candidate;
        }
      }
      return NULL;
    }
    if (in_array('image', $allowed, TRUE)) {
      return 'image';
    }
    return $allowed[0] ?? NULL;
  }

  /**
   * Detect an asset extension even when a CDN appends transform path segments.
   *
   * Example: Storyblok uses `photo.jpg/m/100x69/filters:format(webp)`;
   * pathinfo() sees no extension because the final segment is the filter.
   */
  protected function mediaExtensionFromPath(string $path): string {
    if (preg_match('/filters:format\((png|gif|jpe?g|webp)\)/i', $path, $format_match)) {
      return strtolower($format_match[1]);
    }
    if (preg_match_all('/\.(svg|png|gif|jpe?g|webp|mp4|webm|mov|m4v)(?:\/|$)/i', $path, $matches) && !empty($matches[1])) {
      return strtolower((string) end($matches[1]));
    }
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $extension) ? $extension : '';
  }

  /**
   * Validate nested paragraph and media descriptors against live definitions.
   */
  protected function validateStructuredField(
    FieldDefinitionInterface $definition,
    mixed $value,
    string $path,
    array $paragraph_bundles,
    array &$issues,
  ): void {
    $field_type = $definition->getType();
    $target_type = (string) ($definition->getSetting('target_type') ?? '');
    if ($field_type === 'entity_reference_revisions' && $target_type === 'paragraph') {
      $settings = $definition->getSetting('handler_settings') ?? [];
      $allowed = array_keys($settings['target_bundles'] ?? []);
      foreach ($this->normalizeList($value) as $index => $child) {
        if (!is_array($child)) {
          $issues[] = ['path' => "{$path}/{$index}", 'code' => 'invalid_child', 'message' => 'Paragraph child must be an object.'];
          continue;
        }
        $bundle = $this->stripJsonApiPrefix((string) ($child['type'] ?? ''));
        if ($bundle === '' || !isset($paragraph_bundles[$bundle]) || ($allowed && !in_array($bundle, $allowed, TRUE))) {
          $issues[] = ['path' => "{$path}/{$index}/type", 'code' => 'invalid_child_bundle', 'message' => "Paragraph child bundle '{$bundle}' is not allowed."];
          continue;
        }
        $child_defs = $this->fieldManager->getFieldDefinitions('paragraph', $bundle);
        foreach (($child['fields'] ?? $child['attributes'] ?? []) as $child_name => $child_value) {
          if (!isset($child_defs[$child_name])) {
            $issues[] = ['path' => "{$path}/{$index}/fields/{$child_name}", 'code' => 'unknown_field', 'message' => "Field '{$child_name}' does not exist on paragraph:{$bundle}."];
            continue;
          }
          $this->validateStructuredField($child_defs[$child_name], $child_value, "{$path}/{$index}/fields/{$child_name}", $paragraph_bundles, $issues);
        }
      }
    }
    elseif ($field_type === 'entity_reference' && $target_type === 'media') {
      foreach ($this->normalizeList($value) as $index => $media) {
        $valid = is_int($media)
          || (is_string($media) && ctype_digit($media))
          || (is_array($media) && (!empty($media['target_id']) || !empty($media['preferred_url']) || !empty($media['src']) || !empty($media['url'])));
        if (!$valid) {
          $issues[] = ['path' => "{$path}/{$index}", 'code' => 'invalid_media', 'message' => 'Media must contain target_id or a source URL.'];
        }
      }
    }
  }

  /**
   * Normalize one descriptor or a descriptor list into a list.
   */
  protected function normalizeList(mixed $value): array {
    if (!is_array($value)) {
      return $value === NULL ? [] : [$value];
    }
    if ($value === [] || array_is_list($value)) {
      return $value;
    }
    return [$value];
  }

  /**
   * Apply pivot node.attributes to a Node entity.
   *
   * Body is unwrapped to its canonical structured shape. Node-level
   * paragraph fields (native bundles' own child elements) are materialized
   * recursively when a paragraph storage is provided — the default-language
   * import path — and left untouched otherwise: the translation path applies
   * scalar fields only, because node-level children are shared across
   * languages exactly like the layouts field. Every other field resolves
   * through setEntityField (media, taxonomy, tablefield, field-limit
   * normalization); unknown fields are ignored (validation flagged them).
   */
  protected function applyNodeAttributes(
    \Drupal\node\NodeInterface $node,
    array $attrs,
    int &$media_count,
    $paragraph_storage = NULL,
    array &$old_ref_ids = [],
    int &$child_paragraph_count = 0,
  ): void {
    if (isset($attrs['title'])) {
      $node->setTitle($this->truncateString((string) $attrs['title'], 255));
    }
    if (isset($attrs['langcode']) && $node->hasField('langcode')) {
      $node->set('langcode', $attrs['langcode']);
    }
    if (array_key_exists('status', $attrs)) {
      $node->setPublished((bool) $attrs['status']);
    }
    // Content moderation derives BOTH the published flag and whether the
    // saved revision becomes the live (default) revision from
    // moderation_state. Without setting it, imports of moderated bundles
    // save as 'draft': updates become forward revisions that never go live,
    // and the old-paragraph cleanup then deletes paragraphs the still-live
    // revision references (live incident: every updated page lost its
    // paragraphs). Publish state is resolved from the entity's workflow, not
    // hardcoded, so custom workflows keep working.
    if ($node->hasField('moderation_state') && \Drupal::hasService('content_moderation.moderation_information')) {
      /** @var \Drupal\content_moderation\ModerationInformationInterface $moderation_information */
      $moderation_information = \Drupal::service('content_moderation.moderation_information');
      $workflow = $moderation_information->getWorkflowForEntity($node);
      if ($workflow !== NULL) {
        $published = array_key_exists('status', $attrs) ? (bool) $attrs['status'] : $node->isPublished();
        foreach ($workflow->getTypePlugin()->getStates() as $state) {
          if ($state->isDefaultRevisionState() && $state->isPublishedState() === $published) {
            $node->set('moderation_state', $state->id());
            break;
          }
        }
      }
    }
    if (isset($attrs['body']) && $node->hasField('body')) {
      $body = $attrs['body'];
      if (is_string($body)) {
        $node->set('body', ['value' => $body, 'format' => 'basic_html']);
      }
      elseif (is_array($body)) {
        $node->set('body', $body + ['format' => 'basic_html']);
      }
    }
    if (isset($attrs['owner']) && is_array($attrs['owner'])) {
      $uid = $this->resolveOwnerUid($attrs['owner']);
      if ($uid !== NULL) {
        $node->setOwnerId($uid);
      }
    }
    foreach ($attrs as $name => $value) {
      if (in_array($name, ['title', 'body', 'langcode', 'status', 'owner'], TRUE)) {
        continue;
      }
      if ($node->hasField($name)) {
        $definition = $node->getFieldDefinition($name);
        if ($definition->getType() === 'entity_reference_revisions' && (string) ($definition->getSetting('target_type') ?? '') === 'paragraph') {
          if ($paragraph_storage === NULL) {
            // Translation path: node-level children are shared across
            // languages — leave them to the default-language import.
            continue;
          }
          foreach ($node->get($name) as $existing_item) {
            $target_id = (int) ($existing_item->target_id ?? 0);
            if ($target_id > 0) {
              $old_ref_ids[$target_id] = $target_id;
            }
          }
          $references = [];
          foreach ($this->normalizeList($value) as $child_spec) {
            if (!is_array($child_spec)) {
              throw new \InvalidArgumentException("Child value for node field '{$name}' must be an object.");
            }
            $child = $this->createParagraphFromSpec(
              $child_spec,
              $paragraph_storage,
              $child_paragraph_count,
              $media_count,
              0,
              (string) ($attrs['langcode'] ?? $node->language()->getId()),
            );
            $references[] = [
              'target_id' => $child->id(),
              'target_revision_id' => $child->getRevisionId(),
            ];
            $child_paragraph_count++;
          }
          $node->set($name, $references);
          continue;
        }
        $this->setEntityField($node, $name, $value, $media_count);
      }
    }
  }

  /**
   * Stable sort paragraphs by their 'sequence' key (missing → 0).
   */
  protected function sortParagraphsBySequence(array $paragraphs): array {
    $indexed = [];
    foreach ($paragraphs as $i => $p) {
      $indexed[] = [(int) ($p['sequence'] ?? $i), $i, $p];
    }
    usort($indexed, fn($a, $b) => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);
    return array_map(fn($t) => $t[2], $indexed);
  }

  // ---- Tool: lookup_existing_node ------------------------------------------

  protected function doLookupExistingNode(string $source_url): array {
    if ($source_url === '') {
      return ['status' => 'error', 'error' => 'source_url is required.'];
    }
    $source_field = $this->sourceKeyFieldName();
    // The idempotence_key shape is "{source_url}#{content_hash}". We match
    // by prefix on field_value, ordered by changed DESC to surface the most
    // recent import.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition($source_field, $source_url . '#', 'STARTS_WITH')
      ->sort('changed', 'DESC')
      ->range(0, 1);
    try {
      $nids = $query->execute();
    }
    catch (\Throwable $e) {
      return [
        'status' => 'error',
        'error' => 'Lookup failed (is field ' . $source_field . ' present?): ' . $e->getMessage(),
        'source_url' => $source_url,
      ];
    }
    if (!$nids) {
      return ['status' => 'ok', 'source_url' => $source_url, 'node' => NULL];
    }
    $nid = (int) reset($nids);
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if ($node === NULL) {
      return ['status' => 'ok', 'source_url' => $source_url, 'node' => NULL];
    }
    $key = (string) $node->get($source_field)->value;
    $hash = '';
    if (str_contains($key, '#')) {
      [, $hash] = explode('#', $key, 2);
    }
    return [
      'status' => 'ok',
      'source_url' => $source_url,
      'node' => [
        'nid' => $nid,
        'vid' => (int) $node->getRevisionId(),
        'bundle' => $node->bundle(),
        'idempotence_key' => $key,
        'content_hash' => $hash,
        'changed' => (int) $node->getChangedTime(),
        'title' => $node->label(),
      ],
    ];
  }

  // ---- helpers -------------------------------------------------------------

  /**
   * Find an existing node for this source URL on the configured source-key
   * field. Matches by `source_url + "#"` prefix so any prior import of the
   * same page (regardless of content_hash) is found.
   */
  protected function findNodeBySourceUrl(?string $bundle, string $field, string $source_url): ?int {
    if ($source_url === '') {
      return NULL;
    }
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE);
      if ($bundle !== NULL && $bundle !== '') {
        $query->condition('type', $bundle);
      }
      $nids = $query
        ->condition($field, $source_url . '#', 'STARTS_WITH')
        ->sort('changed', 'DESC')
        ->range(0, 1)
        ->execute();
      return $nids ? (int) reset($nids) : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('icms_mcp:findNodeBySourceUrl failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Extract the source URL component from an idempotence_key
   * ({source_url}#{content_hash}). Returns '' if the key has no '#'.
   */
  protected function extractSourceUrl(string $idempotence_key): string {
    if ($idempotence_key === '' || !str_contains($idempotence_key, '#')) {
      return '';
    }
    [$url] = explode('#', $idempotence_key, 2);
    return $url;
  }

  /**
   * Strip the JSON:API "type--bundle" prefix the contract uses (the agent
   * sends "node--icms_page", "paragraph--icms_layout_text", etc.).
   */
  protected function stripJsonApiPrefix(string $type_raw): string {
    if ($type_raw === '') {
      return '';
    }
    $parts = explode('--', $type_raw, 2);
    return count($parts) === 2 ? $parts[1] : $type_raw;
  }

  protected function sourceKeyFieldName(): string {
    return (string) $this->state->get('icms_mcp.source_key_field', self::DEFAULT_SOURCE_KEY_FIELD);
  }

  protected function layoutsFieldName(): string {
    return (string) $this->state->get('icms_mcp.layouts_field', self::DEFAULT_LAYOUTS_FIELD);
  }

  /**
   * Persist an incoming pivot as JSON in Drupal's private filesystem.
   *
   * Logging is deliberately best-effort: a missing/misconfigured private
   * filesystem must never prevent validation or import. Set the Drupal state
   * key `icms_mcp.log_pivots` to FALSE to disable this diagnostic log.
   */
  protected function logReceivedPivot(string $operation, mixed $pivot): ?string {
    if (!(bool) $this->state->get('icms_mcp.log_pivots', TRUE)) {
      return NULL;
    }

    try {
      $directory = 'private://icms_mcp/pivots';
      if (!$this->fileSystem->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      )) {
        throw new \RuntimeException("Could not prepare private pivot log directory '{$directory}'.");
      }

      $json = json_encode(
        [
          'logged_at' => gmdate(DATE_ATOM),
          'operation' => $operation,
          'pivot' => $pivot,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
      );
      $fingerprint = substr(hash('sha256', $json), 0, 12);
      $nonce = bin2hex(random_bytes(3));
      $filename = sprintf(
        '%s/%s-%s-%s-%s.json',
        $directory,
        gmdate('Ymd-His'),
        preg_replace('/[^a-z0-9_-]+/i', '-', $operation),
        $fingerprint,
        $nonce,
      );
      $saved_uri = $this->fileSystem->saveData(
        $json . PHP_EOL,
        $filename,
        FileExists::Error,
      );
      if ($saved_uri === FALSE) {
        throw new \RuntimeException("Could not write private pivot log '{$filename}'.");
      }

      $this->logger->notice('Saved received @operation pivot to @uri', [
        '@operation' => $operation,
        '@uri' => $saved_uri,
      ]);
      return $saved_uri;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not save received @operation pivot: @message', [
        '@operation' => $operation,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Log a structured journal entry and return it as the tool result.
   * Uses the icms_mcp logger channel → visible in admin/reports/dblog with
   * batch_id / run_id / idempotence_key in the context for correlation.
   */
  protected function journal(string $action, array $payload): array {
    $payload['action'] = $action;
    $payload['timestamp'] = $this->time->getRequestTime();
    $this->logger->info('icms_mcp:@action key=@key', [
      '@action' => $action,
      '@key' => $payload['idempotence_key'] ?? '(none)',
      'context' => $payload,
    ]);
    if (!isset($payload['status'])) {
      // Map action → status if the writer didn't set one explicitly.
      $payload['status'] = match ($action) {
        'created', 'updated', 'skipped' => 'ok',
        'conflict' => 'conflict',
        'error' => 'error',
        default => $action,
      };
    }
    return $payload;
  }


}
