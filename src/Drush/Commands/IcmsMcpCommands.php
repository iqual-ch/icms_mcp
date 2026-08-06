<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ICMS MCP OAuth client lifecycle.
 */
final class IcmsMcpCommands extends DrushCommands {

  /**
   * Rotate the ICMS MCP OAuth client secret.
   */
  #[CLI\Command(name: 'icms-mcp:rotate-secret', aliases: ['icms-mcp-rotate'])]
  #[CLI\Usage(name: 'drush icms-mcp:rotate-secret', description: 'Generate a new client_secret for the icms_mcp consumer; the old secret stops working immediately.')]
  public function rotateSecret(): void {
    $secret = icms_mcp_rotate_oauth_secret();
    $this->output()->writeln('New client_secret (shown only this once — update the cockpit connection):');
    $this->output()->writeln($secret);
  }

}
