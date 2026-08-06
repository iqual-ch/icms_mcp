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
 * MCP tool: import_taxonomy_terms — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_taxonomy_terms',
  label: new TranslatableMarkup('Import taxonomy terms'),
  description: new TranslatableMarkup('Upsert one vocabulary of taxonomy terms (idempotent by source uuid, then by name). Preserves hierarchy, weights, and per-language labels. Returns per-term {tid, action}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'vocabulary' => ['type' => 'string', 'description' => 'Target vocabulary machine name (must exist).'],
      'terms' => ['type' => 'array', 'description' => 'Terms: {uuid?, name, labels?, description?, parent (source tid or 0), tid (source id), weight?}.'],
    ],
    'required' => ['vocabulary', 'terms'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: TRUE,
)]
final class ImportTaxonomyTerms extends ToolPluginBase {

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
    return $this->operations->execute('import_taxonomy_terms', $arguments);
  }

}
