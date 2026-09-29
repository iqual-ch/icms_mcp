<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Plugin\mcp_server\Tool;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: update_node_references — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'update_node_references',
  label: new TranslatableMarkup('Update node references'),
  description: new TranslatableMarkup('Final reference pass: path-addressed write of entity_reference and link fields on an imported node or one of its (nested) paragraphs, translation-aware, saved only on change, one transaction, idempotent. Each update is {langcode?, path: [{field?, index}], expect_bundle?, field, value}; a path step without field walks the layouts field, an empty path addresses the node. Returns {status, nid, applied, entities_touched, unchanged, rejected, unmatched}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'nid' => ['type' => 'integer', 'description' => 'The imported node.'],
      'updates' => [
        'type' => 'array',
        'description' => 'Field writes, each addressed by a path from the node into its paragraphs.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'langcode' => ['type' => 'string', 'description' => 'Translation to write (default: the entity language).'],
            'path' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'field' => ['type' => 'string', 'description' => 'Paragraph reference field to step into (default: the layouts field).'],
                  'index' => ['type' => 'integer', 'description' => 'Position in that field.'],
                ],
                'required' => ['index'],
              ],
            ],
            'expect_bundle' => ['type' => 'string', 'description' => 'Reject the update when the addressed entity has another bundle.'],
            'field' => ['type' => 'string', 'description' => 'The entity_reference or link field to write.'],
            'value' => ['description' => 'The full field value, in the pivot shape ([{target_id}] or [{uri, title}]).'],
          ],
          'required' => ['field', 'value'],
        ],
      ],
    ],
    'required' => ['nid', 'updates'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class UpdateNodeReferences extends ToolPluginBase {

  protected IcmsMcpOperations $operations;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
    );
    $instance->operations = $container->get('icms_mcp.operations');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Enabled by default: the module exists solely to expose these tools.
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->operations->execute('update_node_references', $arguments);
  }

}
