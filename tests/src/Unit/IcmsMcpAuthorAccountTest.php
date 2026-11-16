<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpAuthorAccountTest extends UnitTestCase {

  /**
   * Only an account nobody ever used follows the source's status.
   *
   * @covers ::isImportMadeAccount
   */
  public function testOnlyAnUnusedAccountIsTheImportsOwn(): void {
    self::assertTrue(IcmsMcpOperations::isImportMadeAccount('', 0));
    self::assertFalse(IcmsMcpOperations::isImportMadeAccount('$2y$10$hash', 0));
    self::assertFalse(IcmsMcpOperations::isImportMadeAccount('', 1700000000));
  }

  /**
   * The name fields are found by how their names end, whatever the prefix.
   *
   * @covers ::nameFieldFor
   */
  public function testNameFieldsAreFoundByTheirEnding(): void {
    $fields = ['name', 'mail', 'field_user_first_name', 'field_user_last_name', 'field_nickname'];
    self::assertSame('field_user_first_name', IcmsMcpOperations::nameFieldFor($fields, 'first'));
    self::assertSame('field_user_last_name', IcmsMcpOperations::nameFieldFor($fields, 'last'));
    self::assertSame('field_firstname', IcmsMcpOperations::nameFieldFor(['field_firstname'], 'first'));
    self::assertNull(IcmsMcpOperations::nameFieldFor(['name', 'field_nickname'], 'first'));
  }

}
