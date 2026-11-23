<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder
 * @group icms_mcp
 */
final class IcmsCatalogBuilderTextClassesTest extends UnitTestCase {

  /**
   * @covers ::textClassesFromConfig
   */
  public function testEntriesKeepOneSourceClassAndPlainSteps(): void {
    self::assertSame([
      ['from' => 'p.lead', 'to' => 'p.text-lead > span.text-primary'],
      ['from' => 'span.small', 'to' => 'span.text-small'],
    ], IcmsCatalogBuilder::textClassesFromConfig([
      ['from' => 'span.small', 'to' => 'span.text-small'],
      ['from' => ' p.lead ', 'to' => 'p.text-lead>span.text-primary'],
      ['from' => 'p', 'to' => 'p.x'],
      ['from' => 'p.a.b', 'to' => 'p'],
      ['from' => 'p.ok', 'to' => 'p > <b>'],
      ['to' => 'p.y'],
      'nonsense',
    ]));
  }

  /**
   * @covers ::textClassesFromConfig
   */
  public function testALaterEntryReplacesAnEarlierOne(): void {
    self::assertSame([
      ['from' => 'p.lead', 'to' => 'p.intro'],
    ], IcmsCatalogBuilder::textClassesFromConfig([
      ['from' => 'p.lead', 'to' => 'p.text-lead'],
      ['from' => 'p.lead', 'to' => 'p.intro'],
    ]));
    self::assertSame([], IcmsCatalogBuilder::textClassesFromConfig(NULL));
  }

}
