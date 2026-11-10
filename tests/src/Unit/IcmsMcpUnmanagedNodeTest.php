<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpUnmanagedNodeTest extends UnitTestCase {

  /**
   * A page somebody built by hand is never written or duplicated unasked.
   *
   * @covers ::unmanagedDecision
   * @dataProvider decisions
   */
  public function testDecision(?string $matchedBy, string $onUnmanaged, string $expected): void {
    self::assertSame($expected, IcmsMcpOperations::unmanagedDecision($matchedBy, $onUnmanaged));
  }

  public static function decisions(): array {
    return [
      'nothing matched' => [NULL, '', 'proceed'],
      'nothing matched, decision ignored' => [NULL, 'keep', 'proceed'],
      'imported before' => ['source_key', '', 'proceed'],
      'imported before, decision ignored' => ['source_key', 'keep', 'proceed'],
      'identity match is the migration\'s own' => ['identity', '', 'proceed'],
      'same path, no decision' => ['alias', '', 'conflict'],
      'same path, unknown decision' => ['alias', 'merge', 'conflict'],
      'same path, keep' => ['alias', 'keep', 'keep'],
      'same path, overwrite' => ['alias', ' Overwrite ', 'overwrite'],
      'front page, no decision' => ['front_page', '', 'conflict'],
      'front page, overwrite' => ['front_page', 'overwrite', 'overwrite'],
      'kept by an earlier run, no decision' => ['adopted', '', 'conflict'],
      'kept by an earlier run, keep again' => ['adopted', 'keep', 'keep'],
      'kept by an earlier run, now overwrite' => ['adopted', 'overwrite', 'overwrite'],
    ];
  }

  /**
   * @covers ::aliasLookupPath
   * @covers ::nodeIdFromPath
   */
  public function testOnlyRealAliasesAreLookedUp(): void {
    self::assertSame('/about-us', IcmsMcpOperations::aliasLookupPath('about-us/'));
    self::assertSame('/about/team', IcmsMcpOperations::aliasLookupPath(' /about/team '));
    self::assertSame('', IcmsMcpOperations::aliasLookupPath(''));
    self::assertSame('', IcmsMcpOperations::aliasLookupPath('/'));
    self::assertSame('', IcmsMcpOperations::aliasLookupPath('/node/12'));

    self::assertSame(12, IcmsMcpOperations::nodeIdFromPath('/node/12'));
    self::assertNull(IcmsMcpOperations::nodeIdFromPath('/about-us'));
    self::assertNull(IcmsMcpOperations::nodeIdFromPath('/node/12/edit'));
  }

}
