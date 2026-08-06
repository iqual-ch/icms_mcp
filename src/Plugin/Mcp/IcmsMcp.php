<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Plugin\Mcp;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Legacy drupal/mcp adapter over IcmsMcpOperations — transition only.
 *
 * The module's primary transport is drupal/mcp_server (src/Plugin/Tool/,
 * OAuth 2.1 Bearer). This plugin keeps the old /mcp/post endpoint working
 * while fleet sites migrate; it is removed together with the drupal/mcp
 * dependency once the transition window closes.
 */
#[Mcp(
  id: 'icms-mcp',
  name: new TranslatableMarkup('ICMS MCP'),
  description: new TranslatableMarkup('ICMS catalog, pivot validation, transactional import, and idempotency lookup for the iqual ai-platform drupal-bridge agent.'),
)]
class IcmsMcp extends McpPluginBase implements ContainerFactoryPluginInterface {

  protected IcmsMcpOperations $operations;

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
    $instance->operations = $container->get('icms_mcp.operations');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    return [
      new Tool(
        name: 'get_icms_catalog',
        description: 'Return the compact normalized ICMS catalog v2 manifest: bundle indexes, capabilities, reusable field/option definitions, descriptions, and catalogHash.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [],
          'required' => [],
        ],
      ),
      new Tool(
        name: 'get_icms_component_contract',
        description: 'Resolve full live contracts for selected node, paragraph, or media bundles. Optionally includes nested paragraph child bundles.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'entity_type' => [
              'type' => 'string',
              'enum' => ['node', 'paragraph', 'media'],
            ],
            'bundles' => [
              'type' => 'array',
              'items' => ['type' => 'string'],
              'maxItems' => 25,
            ],
            'include_children' => [
              'type' => 'boolean',
              'default' => TRUE,
            ],
          ],
          'required' => ['entity_type', 'bundles'],
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
        name: 'import_taxonomy_terms',
        description: 'Upsert one vocabulary of taxonomy terms (idempotent by source uuid, then by name). Preserves hierarchy, weights, and per-language labels. Returns per-term {tid, action}.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'vocabulary' => [
              'type' => 'string',
              'description' => 'Target vocabulary machine name (must exist).',
            ],
            'terms' => [
              'type' => 'array',
              'description' => 'Terms: {uuid?, name, labels?, description?, parent (source tid or 0), tid (source id), weight?}.',
            ],
          ],
          'required' => ['vocabulary', 'terms'],
        ],
      ),
      new Tool(
        name: 'import_menu_links',
        description: 'Upsert menu links into an existing menu (idempotent by source uuid). Node links are resolved through the migration source-key (source URL -> imported node); unresolvable links are reported, not guessed. Returns per-link {action, target}.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'menu' => [
              'type' => 'string',
              'description' => 'Target menu machine name (must exist).',
            ],
            'links' => [
              'type' => 'array',
              'description' => 'Links: {uuid, title, titles?, uri (source uri), parent (source uuid or empty), weight?, enabled?}.',
            ],
            'source_base_url' => [
              'type' => 'string',
              'description' => 'Source site base URL, used to resolve internal link targets against imported nodes.',
            ],
          ],
          'required' => ['menu', 'links'],
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
   * MCP ToolsCall passes the sanitized tool name (lowercase, underscores).
   * Accept md5() too in case a caller follows the older docs.
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    foreach ([
      'get_icms_catalog', 'get_icms_component_contract', 'validate_pivot',
      'import_pivot', 'import_taxonomy_terms', 'import_menu_links',
      'lookup_existing_node',
    ] as $known) {
      if ($toolId === $known || $toolId === md5($known)) {
        return $this->jsonResponse($this->operations->execute($known, $arguments));
      }
    }
    throw new \InvalidArgumentException('icms_mcp: unknown tool id ' . $toolId);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(): AccessResult {
    return AccessResult::allowedIfHasPermission($this->currentUser, 'use icms_mcp tools');
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
