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
 * MCP tool: rewrite_node_links — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'rewrite_node_links',
  label: new TranslatableMarkup('Rewrite node links'),
  description: new TranslatableMarkup('Post-import pass: replace links inside an imported node (every translation, layout paragraphs recursively). Each replacement {from, to} rewrites href="<from>" in text fields and link-field uris equal to <from> or ending in its #fragment (entity:node/12#anchor → entity:node/12#4711). Idempotent. Returns {status, nid, replaced, paragraphs_touched, unmatched}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'nid' => ['type' => 'integer', 'description' => 'The imported node.'],
      'replacements' => [
        'type' => 'array',
        'description' => 'Links to rewrite.',
        'items' => [
          'type' => 'object',
          'properties' => [
            'from' => ['type' => 'string'],
            'to' => ['type' => 'string'],
          ],
          'required' => ['from', 'to'],
        ],
      ],
    ],
    'required' => ['nid', 'replacements'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class RewriteNodeLinks extends ToolPluginBase {

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
    return $this->operations->execute('rewrite_node_links', $arguments);
  }

}
