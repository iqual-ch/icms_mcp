<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Plugin\Tool;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: lookup_existing_node — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'lookup_existing_node',
  label: new TranslatableMarkup('Lookup existing node'),
  description: new TranslatableMarkup('Idempotency check: return {nid, content_hash, idempotence_key, changed} of the most recent node previously imported from this source URL, or null.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'source_url' => ['type' => 'string', 'description' => 'Canonical URL of the source page.'],
    ],
    'required' => ['source_url'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class LookupExistingNode extends ToolPluginBase {

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
    return $this->operations->execute('lookup_existing_node', $arguments);
  }

}
