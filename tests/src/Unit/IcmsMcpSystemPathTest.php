<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpSystemPathTest extends UnitTestCase {

  /** @covers ::isSystemPath */
  public function testEntitySystemPathsAreRecognised(): void {
    self::assertTrue(IcmsMcpOperations::isSystemPath('/node/985'));
    self::assertTrue(IcmsMcpOperations::isSystemPath('/node/985/edit'));
    self::assertTrue(IcmsMcpOperations::isSystemPath('/taxonomy/term/12'));
    self::assertTrue(IcmsMcpOperations::isSystemPath('/media/7'));
    self::assertTrue(IcmsMcpOperations::isSystemPath('/user/1'));
  }

  /** @covers ::isSystemPath */
  public function testAliasesAreNotSystemPaths(): void {
    self::assertFalse(IcmsMcpOperations::isSystemPath('/veranstaltungen/node-5'));
    self::assertFalse(IcmsMcpOperations::isSystemPath('/node-archiv/985'));
    self::assertFalse(IcmsMcpOperations::isSystemPath('/media/archiv'));
    self::assertFalse(IcmsMcpOperations::isSystemPath('/user/profil'));
    self::assertFalse(IcmsMcpOperations::isSystemPath('/test'));
  }

}
