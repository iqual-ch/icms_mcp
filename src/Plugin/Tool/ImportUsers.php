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
 * MCP tool: import_users — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_users',
  label: new TranslatableMarkup('Import users'),
  description: new TranslatableMarkup('Upsert the source site\'s accounts so imported nodes can be owned by a person (idempotent by e-mail). New accounts are created BLOCKED and without a password — this imports authorship, not access — and no notification mail is ever sent. An account that already exists is matched, never renamed or unblocked; only mapped roles are added. Users import BEFORE nodes. Returns per-user {targetUid, action} plus `pending_target_setup` for source roles with no target.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'users' => ['type' => 'array', 'description' => 'Source accounts: {uid, uuid?, name, mail, status?, roles?, created?}. A user without `mail` is reported as skipped, never guessed at.'],
      'role_mapping' => ['type' => 'object', 'description' => 'Source role id → target role id, as confirmed at the migration\'s role gate. An unmapped source role, or one mapped to a role this site lacks, is reported in `pending_target_setup`.'],
    ],
    'required' => ['users'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: TRUE,
)]
final class ImportUsers extends ToolPluginBase {

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
    return $this->operations->execute('import_users', $arguments);
  }

}
