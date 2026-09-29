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
 * MCP tool: read_node — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'read_node',
  label: new TranslatableMarkup('Read node'),
  description: new TranslatableMarkup('Read back an imported node per language: {status, title, path_alias, fields, paragraphs: [{id, sequence, bundle, translated, status, fields, children}]}. Reference fields carry the referenced entity label, link fields {uri, title}, formatted text {value, format}. For checking what landed against what was sent.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'nid' => ['type' => 'integer', 'description' => 'The node to read.'],
      'langcodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Languages to read (default: all translations).'],
      'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Node fields to read (default: every content field).'],
      'include_paragraphs' => ['type' => 'boolean', 'description' => 'Include the paragraph tree (default true).'],
    ],
    'required' => ['nid'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ReadNode extends ToolPluginBase {

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
    return $this->operations->execute('read_node', $arguments);
  }

}
