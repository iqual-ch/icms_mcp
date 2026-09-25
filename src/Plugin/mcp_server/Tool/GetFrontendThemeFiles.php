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
 * MCP tool: get_frontend_theme_files — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'get_frontend_theme_files',
  label: new TranslatableMarkup('Get frontend theme files'),
  description: new TranslatableMarkup('Return the Nuxt frontend\'s theme layer as it is on this target (app.config.ts, theme/*.css, base/typography.css, utilities/utilities.css, project/*.css, tailwind.css), read-only, so a design handoff can be compared with what the project already has.'),
  inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetFrontendThemeFiles extends ToolPluginBase {

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
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->operations->execute('get_frontend_theme_files', $arguments);
  }

}
