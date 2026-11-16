<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpFieldsFromPageTest extends UnitTestCase {

  /**
   * @covers ::fieldsFromPageMap
   */
  public function testOnlyNamedPairsAreKept(): void {
    self::assertSame(
      ['field_example_topic' => 'field_page_topic'],
      IcmsMcpOperations::fieldsFromPageMap([
        ' field_example_topic ' => ' field_page_topic ',
        'field_without_source' => '',
        'field_wrong_type' => ['field_page_topic'],
        0 => 'field_page_topic',
      ]),
    );
    self::assertSame([], IcmsMcpOperations::fieldsFromPageMap(NULL));
    self::assertSame([], IcmsMcpOperations::fieldsFromPageMap('field_page_topic'));
  }

}
