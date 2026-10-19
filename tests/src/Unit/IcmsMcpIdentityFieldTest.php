<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/**
 * How the per-bundle identity field is read from state.
 *
 * @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations
 */
final class IcmsMcpIdentityFieldTest extends UnitTestCase {

  /**
   * An array or the JSON a `drush state:set` stores; unknown bundles have none.
   *
   * @covers ::identityFieldFor
   */
  public function testIdentityFieldIsReadFromArrayOrJson(): void {
    $config = ['icms_product' => ' field_icms_product_id '];
    self::assertSame('field_icms_product_id', IcmsMcpOperations::identityFieldFor($config, 'icms_product'));
    self::assertSame('', IcmsMcpOperations::identityFieldFor($config, 'icms_page'));
    self::assertSame('field_icms_product_id', IcmsMcpOperations::identityFieldFor('{"icms_product":"field_icms_product_id"}', 'icms_product'));
    self::assertSame('', IcmsMcpOperations::identityFieldFor('not json', 'icms_product'));
    self::assertSame('', IcmsMcpOperations::identityFieldFor(NULL, 'icms_product'));
    self::assertSame('', IcmsMcpOperations::identityFieldFor(['icms_product' => 42], 'icms_product'));
  }

}
