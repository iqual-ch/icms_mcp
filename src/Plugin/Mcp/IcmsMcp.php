<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Plugin\Mcp;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ICMS MCP plugin — catalog/validate/import/lookup tools for the iqual agent.
 *
 * Tools exposed:
 *   - get_icms_catalog: live node + paragraph + field introspection
 *   - validate_pivot: validate an icms-drupal-import-handoff-v1 contract
 *   - import_pivot: transactional create/update of node + paragraphs
 *   - lookup_existing_node: idempotency check by source URL
 *
 * Configurable site state (`\Drupal::state()`):
 *   - icms_mcp.source_key_field: defaults to `field_icms_source_key`. Plain
 *     string field on `icms_page` storing the agent's `idempotence_key`
 *     ({source_url}#{content_hash}). Must exist on the site — this module
 *     does NOT create it (fields belong in configuration management).
 *   - icms_mcp.layouts_field: defaults to `field_icms_paragraphs`. Entity
 *     reference revisions field on `icms_page` that holds the layout
 *     paragraphs in order.
 *
 * Contract: backend/agents/content-migrator/phase-5-import-contract-schema.json
 */
#[Mcp(
  id: 'icms-mcp',
  name: new TranslatableMarkup('ICMS MCP'),
  description: new TranslatableMarkup('ICMS catalog, pivot validation, transactional import, and idempotency lookup for the iqual ai-platform drupal-bridge agent.'),
)]
class IcmsMcp extends McpPluginBase implements ContainerFactoryPluginInterface {

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

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityTypeBundleInfoInterface $bundleInfo;
  protected EntityFieldManagerInterface $fieldManager;
  protected Connection $database;
  protected StateInterface $state;
  protected LoggerInterface $logger;
  protected TimeInterface $time;
  protected ClientInterface $httpClient;
  protected FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->fieldManager = $container->get('entity_field.manager');
    $instance->database = $container->get('database');
    $instance->state = $container->get('state');
    $instance->logger = $container->get('logger.channel.icms_mcp');
    $instance->time = $container->get('datetime.time');
    $instance->httpClient = $container->get('http_client');
    $instance->fileSystem = $container->get('file_system');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    return [
      new Tool(
        name: 'get_icms_catalog',
        description: 'Return the live ICMS catalog (nodeTypes, paragraphTypes, allowedParagraphBundles) for this site. The agent uses this in place of the bundled icms-catalog.json so a single deployment works across client sites with different bundle configurations.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [],
          'required' => [],
        ],
      ),
      new Tool(
        name: 'validate_pivot',
        description: 'Validate a pivot document against the LIVE field definitions on this site. Catches drift between the bundled contract and what is actually installed. Returns a list of {path, code, message} issues.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'pivot' => [
              'type' => 'object',
              'description' => 'The icms-drupal-import-handoff-v1 pivot document.',
            ],
          ],
          'required' => ['pivot'],
        ],
      ),
      new Tool(
        name: 'import_pivot',
        description: 'Transactionally import a pivot document. Honours strategy = skip | update | skip-or-update | fail-if-exists. Respects review_decision = review_required (HITL gate) unless approve=true. Returns {status, nid, revision_id, idempotence_key, journal}.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'pivot' => [
              'type' => 'object',
              'description' => 'The icms-drupal-import-handoff-v1 pivot document.',
            ],
            'dry_run' => [
              'type' => 'boolean',
              'description' => 'If true, validate + plan changes without writing.',
              'default' => FALSE,
            ],
            'approve' => [
              'type' => 'boolean',
              'description' => 'Required when metadata.review_decision = review_required. Acknowledges human approval.',
              'default' => FALSE,
            ],
          ],
          'required' => ['pivot'],
        ],
      ),
      new Tool(
        name: 'lookup_existing_node',
        description: 'Idempotency check: return {nid, content_hash, idempotence_key, changed} of the most recent node previously imported from this source URL, or null.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'source_url' => [
              'type' => 'string',
              'description' => 'Canonical URL of the source page.',
            ],
          ],
          'required' => ['source_url'],
        ],
      ),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * MCP ToolsCall passes the sanitized tool name (lowercase, underscores)
   * — see McpJsonRpcMethod\ToolsCall::execute(). Accept md5() too in case
   * a future caller follows the older docs at drupalmcp.io.
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    try {
      if ($toolId === 'get_icms_catalog' || $toolId === md5('get_icms_catalog')) {
        return $this->jsonResponse($this->doGetCatalog());
      }
      if ($toolId === 'validate_pivot' || $toolId === md5('validate_pivot')) {
        $pivot = $arguments['pivot'] ?? [];
        $log_uri = $this->logReceivedPivot('validate_pivot', $pivot);
        $result = $this->doValidatePivot($pivot);
        if ($log_uri !== NULL) {
          $result['pivot_log_uri'] = $log_uri;
        }
        return $this->jsonResponse($result);
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
        return $this->jsonResponse($result);
      }
      if ($toolId === 'lookup_existing_node' || $toolId === md5('lookup_existing_node')) {
        return $this->jsonResponse($this->doLookupExistingNode((string) ($arguments['source_url'] ?? '')));
      }
    }
    catch (\Throwable $e) {
      // Never let an uncaught throwable reach the MCP transport — it would
      // surface to the agent as a connection error rather than a structured
      // tool result the LLM can reason about.
      $this->logger->error('icms_mcp tool error: @msg', ['@msg' => $e->getMessage()]);
      return $this->jsonResponse([
        'status' => 'error',
        'error' => $e->getMessage(),
        'tool_id' => $toolId,
      ]);
    }
    throw new \InvalidArgumentException('icms_mcp: unknown tool id ' . $toolId);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(): AccessResult {
    return AccessResult::allowedIfHasPermission($this->currentUser, 'use icms_mcp tools');
  }

  // ---- Tool: get_icms_catalog ----------------------------------------------

  /**
   * Return live introspection of node + paragraph bundles on this site.
   *
   * Shape mirrors `backend/agents/content-migrator/icms-catalog.json` so the
   * orchestrator can swap the bundled catalog for live data without changing
   * downstream tools (`suggest_icms_layout_mapping`, `build_icms_page_pivot_v1`).
   */
  protected function doGetCatalog(): array {
    $node_types = [];
    $node_bundles = $this->bundleInfo->getBundleInfo('node');
    foreach ($node_bundles as $bundle => $info) {
      $fields = $this->describeFields('node', $bundle);
      $node_types[$bundle] = [
        'id' => $bundle,
        'label' => (string) ($info['label'] ?? $bundle),
        'fields' => $fields,
        'paragraphFields' => [],
      ];
    }

    $paragraph_types = [];
    if ($this->entityTypeManager->hasDefinition('paragraph')) {
      $paragraph_bundles = $this->bundleInfo->getBundleInfo('paragraph');
      foreach ($paragraph_bundles as $bundle => $info) {
        $paragraph_types[$bundle] = [
          'id' => $bundle,
          'label' => (string) ($info['label'] ?? $bundle),
          'fields' => $this->describeFields('paragraph', $bundle),
        ];
      }
    }

    // Discover which paragraph bundles are allowed on an icms_page via the
    // configured layouts field. This is what the agent calls
    // `allowedParagraphBundles` in the bundled catalog.
    $allowed = [];
    $layouts_field = $this->layoutsFieldName();
    if (isset($node_bundles['icms_page'])) {
      $defs = $this->fieldManager->getFieldDefinitions('node', 'icms_page');
      if (isset($defs[$layouts_field])) {
        $settings = $defs[$layouts_field]->getSetting('handler_settings') ?? [];
        $target = $settings['target_bundles'] ?? [];
        $allowed = array_keys($target);
        sort($allowed);
        $node_types['icms_page']['paragraphFields'][$layouts_field] = [
          'cardinality' => $defs[$layouts_field]->getFieldStorageDefinition()->getCardinality(),
          'targetBundles' => $allowed,
        ];
      }
    }

    return [
      'status' => 'ok',
      'format' => 'icms-target-catalog-v1',
      'site' => [
        'source_key_field' => $this->sourceKeyFieldName(),
        'layouts_field' => $layouts_field,
      ],
      'nodeTypes' => $node_types,
      'paragraphTypes' => $paragraph_types,
      'allowedParagraphBundles' => $allowed,
    ];
  }

  /**
   * Describe configured (non-base) fields on a bundle in the catalog shape.
   */
  protected function describeFields(string $entity_type, string $bundle): array {
    $out = [];
    $defs = $this->fieldManager->getFieldDefinitions($entity_type, $bundle);
    foreach ($defs as $name => $def) {
      // Skip base fields — agent only cares about content fields. FieldConfig
      // (the persisted kind) all expose getFieldStorageDefinition() with a
      // FieldStorageConfig instance; base fields use BaseFieldDefinition.
      if (!$def->getFieldStorageDefinition() instanceof \Drupal\field\FieldStorageConfigInterface) {
        continue;
      }
      $out[$name] = $this->describeField($def);
    }
    return $out;
  }

  /**
   * Describe a single field definition for the catalog.
   */
  protected function describeField(FieldDefinitionInterface $def): array {
    $type = $def->getType();
    $cardinality = $def->getFieldStorageDefinition()->getCardinality();
    $info = [
      'type' => $type,
      'fieldType' => $type,
      'fieldName' => $def->getName(),
      'label' => (string) $def->getLabel(),
      'required' => $def->isRequired(),
      'cardinality' => $cardinality,
    ];
    // For entity_reference-style fields, surface the target bundles too — the
    // agent needs this to know which paragraph types can sit in which slot.
    if (in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $info['target_type'] = $def->getSetting('target_type');
      $info['targetType'] = $info['target_type'];
      $handler_settings = $def->getSetting('handler_settings') ?? [];
      $target_bundles = $handler_settings['target_bundles'] ?? [];
      $info['target_bundles'] = array_values(array_keys($target_bundles));
      $info['targetBundles'] = $info['target_bundles'];
    }
    return $info;
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
    if (empty($node_attrs['title'])) {
      $issues[] = ['path' => '/drupal_import/node/attributes/title', 'code' => 'missing', 'message' => 'title is required.'];
    }

    if ($node_bundle) {
      $node_defs = $this->fieldManager->getFieldDefinitions('node', $node_bundle);
      foreach ($node_attrs as $name => $value) {
        if ($name === 'title' || $name === 'langcode' || $name === 'status' || $name === 'body') {
          continue;
        }
        if (!isset($node_defs[$name])) {
          $issues[] = ['path' => "/drupal_import/node/attributes/{$name}", 'code' => 'unknown_field', 'message' => "Field '{$name}' does not exist on node:{$node_bundle}."];
        }
      }
    }

    $paragraphs = $import['paragraphs'] ?? [];
    $para_bundles = $this->entityTypeManager->hasDefinition('paragraph')
      ? $this->bundleInfo->getBundleInfo('paragraph')
      : [];
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
          'message' => "Configured source-key field '{$source_field}' is not present on node:{$node_bundle}. Add a plain string field (max 512) and store its machine name in state('icms_mcp.source_key_field').",
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
    $import = $pivot['drupal_import'] ?? [];
    $node_bundle = $this->stripJsonApiPrefix($import['node']['type'] ?? '');
    $node_attrs = $import['node']['attributes'] ?? [];
    $paragraphs = $import['paragraphs'] ?? [];
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
    $existing_nid = $this->findNodeBySourceUrl($node_bundle, $source_field, $source_url);

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
      );
      $result['idempotence_key'] = $idempotence_key;
      $result['strategy'] = $strategy;
      $result['action'] = $existing_nid === NULL ? 'created' : 'updated';
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
  ): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $paragraph_storage = $this->entityTypeManager->hasDefinition('paragraph')
      ? $this->entityTypeManager->getStorage('paragraph')
      : NULL;
    if ($paragraphs && $paragraph_storage === NULL) {
      throw new \RuntimeException('Paragraphs requested but paragraphs module is not installed.');
    }

    if ($existing_nid !== NULL) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $node_storage->load($existing_nid);
      if ($node === NULL) {
        throw new \RuntimeException("Existing node {$existing_nid} disappeared between lookup and write.");
      }
      // Detach + delete the previous paragraph children so we don't leave
      // orphans behind on update. Paragraphs aren't deleted on detach alone.
      $layouts_field = $this->layoutsFieldName();
      $old_refs = [];
      if ($node->hasField($layouts_field)) {
        foreach ($node->get($layouts_field) as $item) {
          if ($item->entity !== NULL) {
            $old_refs[] = $item->entity;
          }
        }
        $node->set($layouts_field, []);
      }
    }
    else {
      $node = $node_storage->create(['type' => $node_bundle]);
      $old_refs = [];
    }

    $this->applyNodeAttributes($node, $node_attrs);
    $node->set($this->sourceKeyFieldName(), $idempotence_key);

    $created_paragraphs = [];
    $child_paragraph_count = 0;
    $media_count = 0;
    if ($paragraphs && $paragraph_storage !== NULL) {
      $sorted = $this->sortParagraphsBySequence($paragraphs);
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
    foreach ($old_refs as $old) {
      try { $old->delete(); }
      catch (\Throwable $e) { /* best effort */ }
    }

    return [
      'status' => 'ok',
      'nid' => (int) $node->id(),
      'vid' => (int) $node->getRevisionId(),
      'revision_id' => (int) $node->getRevisionId(),
      'paragraph_count' => count($created_paragraphs),
      'child_paragraph_count' => $child_paragraph_count,
      'media_count' => $media_count,
    ];
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
  ): \Drupal\paragraphs\ParagraphInterface {
    if ($depth > 8) {
      throw new \RuntimeException('Paragraph nesting exceeds the supported depth of 8.');
    }

    $bundle = $this->stripJsonApiPrefix((string) ($spec['type'] ?? ''));
    if ($bundle === '') {
      throw new \InvalidArgumentException('Nested paragraph is missing its type.');
    }

    /** @var \Drupal\paragraphs\ParagraphInterface $entity */
    $entity = $paragraph_storage->create(['type' => $bundle]);
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

      if ($field_type === 'entity_reference' && $target_type === 'media') {
        $references = [];
        foreach ($this->normalizeList($value) as $media_spec) {
          $target_id = $this->resolveMediaReference($media_spec, $definition);
          if ($target_id !== NULL) {
            $references[] = ['target_id' => $target_id];
            $media_count++;
          }
        }
        $entity->set($name, $references);
        continue;
      }

      $entity->set($name, $value);
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
      throw new \RuntimeException('No supported media bundle is allowed for remote media URL ' . $url);
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
      $field_value['alt'] = $this->firstNonEmptyString([
        $value['alt'] ?? NULL,
        $value['title'] ?? NULL,
        $this->mediaName($value, $url, $file),
      ]);
      $field_value['title'] = $this->firstNonEmptyString([$value['title'] ?? NULL]);
    }
    $media->set($source_field, $field_value);
    $this->validateAndSaveMedia($media);
    return (int) $media->id();
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
    return $this->firstNonEmptyString([
      $value['alt'] ?? NULL,
      $value['title'] ?? NULL,
      $value['filename'] ?? NULL,
      $file?->getFilename(),
      $url_filename,
      'Imported media',
    ]);
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
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
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
   * Pick an allowed media bundle from the source URL.
   */
  protected function chooseMediaBundle(string $url, array $allowed): ?string {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (in_array('remote_video', $allowed, TRUE) && preg_match('/(?:youtube\.com|youtu\.be|vimeo\.com)$/', $host)) {
      return 'remote_video';
    }
    $extension = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($extension === 'svg') {
      return in_array('icon', $allowed, TRUE) ? 'icon' : NULL;
    }
    if (in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], TRUE) && in_array('video', $allowed, TRUE)) {
      return 'video';
    }
    if (in_array('image', $allowed, TRUE)) {
      return 'image';
    }
    return $allowed[0] ?? NULL;
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
   * Apply pivot node.attributes to a Node entity. Body is unwrapped to its
   * canonical structured shape; everything else is set verbatim if the field
   * exists, ignored otherwise (validation already flagged unknowns).
   */
  protected function applyNodeAttributes(\Drupal\node\NodeInterface $node, array $attrs): void {
    if (isset($attrs['title'])) {
      $node->setTitle((string) $attrs['title']);
    }
    if (isset($attrs['langcode']) && $node->hasField('langcode')) {
      $node->set('langcode', $attrs['langcode']);
    }
    if (array_key_exists('status', $attrs)) {
      $node->setPublished((bool) $attrs['status']);
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
    foreach ($attrs as $name => $value) {
      if (in_array($name, ['title', 'body', 'langcode', 'status'], TRUE)) {
        continue;
      }
      if ($node->hasField($name)) {
        $node->set($name, $value);
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
  protected function findNodeBySourceUrl(string $bundle, string $field, string $source_url): ?int {
    if ($source_url === '') {
      return NULL;
    }
    try {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
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

  /**
   * Wrap a PHP array as an MCP `text` response carrying JSON.
   */
  protected function jsonResponse(array $data): array {
    return [
      [
        'type' => 'text',
        'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ],
    ];
  }

}
