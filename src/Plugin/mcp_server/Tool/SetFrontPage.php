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
 * MCP tool: set_front_page — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'set_front_page',
  label: new TranslatableMarkup('Set front page'),
  description: new TranslatableMarkup('Make an imported node the site front page (system.site page.front = /node/<nid>). The node must exist and be published. Returns {status, nid, previous, current}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'nid' => ['type' => 'integer', 'description' => 'The node to serve at /.'],
    ],
    'required' => ['nid'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SetFrontPage extends ToolPluginBase {

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
    return $this->operations->execute('set_front_page', $arguments);
  }

}
