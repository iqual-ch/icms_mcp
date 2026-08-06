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
 * MCP tool: import_pivot — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_pivot',
  label: new TranslatableMarkup('Import pivot'),
  description: new TranslatableMarkup('Transactionally import a pivot document. Honours strategy = skip | update | skip-or-update | fail-if-exists. Respects review_decision = review_required (HITL gate) unless approve=true. Returns {status, nid, revision_id, idempotence_key, journal}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'pivot' => ['type' => 'object', 'description' => 'The icms-drupal-import-handoff-v1 pivot document.'],
      'dry_run' => ['type' => 'boolean', 'description' => 'If true, validate + plan changes without writing.', 'default' => FALSE],
      'approve' => ['type' => 'boolean', 'description' => 'Required when metadata.review_decision = review_required. Acknowledges human approval.', 'default' => FALSE],
    ],
    'required' => ['pivot'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: TRUE,
)]
final class ImportPivot extends ToolPluginBase {

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
    return $this->operations->execute('import_pivot', $arguments);
  }

}
