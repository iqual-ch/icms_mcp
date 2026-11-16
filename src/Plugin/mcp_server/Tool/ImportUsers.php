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
 * MCP tool: import_users — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'import_users',
  label: new TranslatableMarkup('Import users'),
  description: new TranslatableMarkup('Upsert the source site\'s accounts so imported nodes can be owned by a person (idempotent by e-mail). A new account is created without a password and with the source\'s status: active when the source account is active (a blocked account, and with it the author of its pages, is hidden from visitors), blocked otherwise. No notification mail is ever sent. An account that already exists is matched and never renamed; its status is left alone unless an earlier import made it (no password, never logged in). `first_name` / `last_name` and `picture` fill the account\'s empty name fields and `user_picture`. Users import BEFORE nodes. Returns per-user {targetUid, action} plus `pending_target_setup` for source roles with no target.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'users' => ['type' => 'array', 'description' => 'Source accounts: {uid, uuid?, name, mail, status?, roles?, created?, first_name?, last_name?, picture?: {url, alt?}}. A user without `mail` is reported as skipped, never guessed at.'],
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
