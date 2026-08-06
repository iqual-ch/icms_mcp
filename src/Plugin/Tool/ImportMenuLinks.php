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
 * MCP tool: import_menu_links — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_menu_links',
  label: new TranslatableMarkup('Import menu links'),
  description: new TranslatableMarkup('Upsert menu links into an existing menu (idempotent by source uuid). Node links are resolved through the migration source-key (source URL -> imported node); unresolvable links are reported, not guessed. Returns per-link {action, target}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'menu' => ['type' => 'string', 'description' => 'Target menu machine name (must exist).'],
      'links' => ['type' => 'array', 'description' => 'Links: {uuid, title, titles?, uri (source uri), parent (source uuid or empty), weight?, enabled?}.'],
      'source_base_url' => ['type' => 'string', 'description' => 'Source site base URL, used to resolve internal link targets against imported nodes.'],
    ],
    'required' => ['menu', 'links'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: TRUE,
)]
final class ImportMenuLinks extends ToolPluginBase {

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
    return $this->operations->execute('import_menu_links', $arguments);
  }

}
