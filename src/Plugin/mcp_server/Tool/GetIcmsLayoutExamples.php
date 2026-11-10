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
 * MCP tool: get_icms_layout_examples — thin adapter over IcmsMcpOperations.
 */
#[Tool(
  id: 'get_icms_layout_examples',
  label: new TranslatableMarkup('Get ICMS layout examples'),
  description: new TranslatableMarkup('The site\'s reference examples of each layout bundle and option combination, read from the showcase page declared in icms_mcp.catalog_examples and its menu children. Returns {status, format, indexHash, pages, examples: [{id, paragraphId, bundle, options, storedOptions, pageNid, pagePath, ordinal, siblingCount, title, children}], skipped}. paragraphId is the element id the ICMS frontend renders the section with; ordinal is its 1-based position among the page\'s published top-level layouts. Either locates it on the public page. Only pages an anonymous visitor can open are listed. status = not_configured when no root is declared.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'bundles' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these paragraph bundles (default: all).'],
      'langcode' => ['type' => 'string', 'description' => 'Language to read (default: the configured one, then the root page\'s own).'],
      'max_pages' => ['type' => 'integer', 'description' => 'Upper bound on indexed pages (default 60).'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetIcmsLayoutExamples extends ToolPluginBase {

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
    return $this->operations->execute('get_icms_layout_examples', $arguments);
  }

}
