<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Plugin\mcp_server\Tool;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: import_webforms — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_webforms',
  label: new TranslatableMarkup('Import webforms'),
  description: new TranslatableMarkup('Import the source site\'s webforms: config verbatim (elements, settings, handlers, translations) and submissions. A form this site already has under the same id is matched and left untouched — only submissions are added. Submissions are idempotent by uuid and their submitter is re-linked by e-mail (anonymous when unknown), so users import BEFORE webforms; webforms import BEFORE nodes so a contact component or a lifted registration form can reference them. Returns per-webform {action, submissionsCreated, submissionsSkipped}.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'webforms' => ['type' => 'array', 'description' => 'Source webforms: {id, uuid?, label, status?, config, configTranslations?, submissions?}. `config` is the webform.webform.<id> config object as exported; `submissions` may be a slice — call again with the next slice, the form is matched.'],
      'include_submissions' => ['type' => 'boolean', 'description' => 'Write the submissions lists (default true).', 'default' => TRUE],
    ],
    'required' => ['webforms'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: TRUE,
)]
final class ImportWebforms extends ToolPluginBase {

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
    return $this->operations->execute('import_webforms', $arguments);
  }

}
