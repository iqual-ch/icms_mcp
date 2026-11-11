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
 * MCP tool: delete_preview_pages — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'delete_preview_pages',
  label: new TranslatableMarkup('Delete preview pages'),
  description: new TranslatableMarkup('Delete the preview pages of one scope. A preview page is a page imported with import_pivot under the source URL https://icms-preview.invalid/<scope>/<name>, made to look at a layout with real content and thrown away afterwards. Only nodes whose source key starts with that prefix are deleted; no other content can be reached. Returns {status, scope, matched, deleted, nids}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'scope' => ['type' => 'string', 'description' => 'The scope the previews were imported under (6 to 128 letters, digits, "_" or "-"), typically a run id.'],
      'dry_run' => ['type' => 'boolean', 'description' => 'If true, report what would be deleted without deleting.', 'default' => FALSE],
    ],
    'required' => ['scope'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class DeletePreviewPages extends ToolPluginBase {

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
    return $this->operations->execute('delete_preview_pages', $arguments);
  }

}
