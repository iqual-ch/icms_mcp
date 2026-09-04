<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ICMS MCP OAuth client lifecycle.
 *
 * Self-contained on purpose: Drush discovers this class through the
 * autoloader, and nothing guarantees icms_mcp.module has been included
 * when the command runs — a helper living there was an undefined function
 * in practice. Everything the command needs is injected here.
 */
final class IcmsMcpCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The consumer's client_id, fixed by icms_mcp_ensure_oauth_client().
   */
  private const CLIENT_ID = 'icms_mcp';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PasswordGeneratorInterface $passwordGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Rotate the ICMS MCP OAuth client secret.
   */
  #[CLI\Command(name: 'icms-mcp:rotate-secret', aliases: ['icms-mcp-rotate'])]
  #[CLI\Usage(name: 'drush icms-mcp:rotate-secret', description: 'Generate a new client_secret for the icms_mcp consumer; the old secret stops working immediately.')]
  public function rotateSecret(): void {
    $consumers = $this->entityTypeManager->getStorage('consumer')
      ->loadByProperties(['client_id' => self::CLIENT_ID]);
    $consumer = reset($consumers);
    if (!$consumer) {
      throw new \RuntimeException('No ' . self::CLIENT_ID . ' consumer exists — run the module updates (drush updb) first.');
    }
    $secret = $this->passwordGenerator->generate(32);
    $consumer->set('secret', $secret);
    $consumer->save();

    $this->output()->writeln('New client_secret (shown only this once — update the cockpit connection):');
    $this->output()->writeln($secret);
  }

}
