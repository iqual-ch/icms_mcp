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
 * MCP tool: validate_pivot — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'validate_pivot',
  label: new TranslatableMarkup('Validate pivot'),
  description: new TranslatableMarkup('Validate a pivot document against the LIVE field definitions on this site. Catches drift between the bundled contract and what is actually installed. Returns a list of {path, code, message} issues.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'pivot' => ['type' => 'object', 'description' => 'The icms-drupal-import-handoff-v1 pivot document.'],
    ],
    'required' => ['pivot'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ValidatePivot extends ToolPluginBase {

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
    return $this->operations->execute('validate_pivot', $arguments);
  }

}
