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
 * MCP tool: lookup_existing_node — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'lookup_existing_node',
  label: new TranslatableMarkup('Lookup existing node'),
  description: new TranslatableMarkup('Idempotency check: return {nid, content_hash, idempotence_key, changed, matched_by, url} of the node an import of this source page would land on, or null. matched_by = source_key for a page imported before. With path_alias (or is_front_page) it also finds a page built by hand at the same path: matched_by = alias | front_page, or adopted for one an earlier run was told to keep; importing such a page needs metadata.on_unmanaged = keep | overwrite. bundle_conflict names a hand-built page of another bundle at that path.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'source_url' => ['type' => 'string', 'description' => 'Canonical URL of the source page.'],
      'path_alias' => ['type' => 'string', 'description' => 'The path the page is imported under; enables the match on a hand-built page.'],
      'langcode' => ['type' => 'string', 'description' => 'Language of that path (default: any).'],
      'bundle' => ['type' => 'string', 'description' => 'Node bundle the page is mapped to; a hand-built page of another bundle is reported as bundle_conflict.'],
      'is_front_page' => ['type' => 'boolean', 'description' => 'The page is the source front page: match the target front page.'],
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
