<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderEnforcedTest extends UnitTestCase {

  /**
   * @covers ::enforcedFromConfig
   */
  public function testOptionsAndFieldsAreKeptSorted(): void {
    $enforced = IcmsCatalogBuilder::enforcedFromConfig([
      ['bundle' => 'project_layout_hero_slider', 'option' => 'colorMode', 'value' => 'dark'],
      ['bundle' => '*', 'field' => 'field_icms_title_style', 'value' => 'heading-1'],
      ['bundle' => ' icms_layout_text_media ', 'option' => ' imageFormat ', 'value' => 'auto'],
    ]);

    self::assertSame([
      ['bundle' => '*', 'field' => 'field_icms_title_style', 'value' => 'heading-1'],
      ['bundle' => 'icms_layout_text_media', 'option' => 'imageFormat', 'value' => 'auto'],
      ['bundle' => 'project_layout_hero_slider', 'option' => 'colorMode', 'value' => 'dark'],
    ], $enforced);
  }

  /**
   * @covers ::enforcedFromConfig
   */
  public function testIncompleteOrAmbiguousEntriesAreDropped(): void {
    self::assertSame([], IcmsCatalogBuilder::enforcedFromConfig([
      ['option' => 'colorMode', 'value' => 'dark'],
      ['bundle' => 'x', 'value' => 'dark'],
      ['bundle' => 'x', 'option' => 'a', 'field' => 'b', 'value' => 'dark'],
      ['bundle' => 'x', 'option' => 'a'],
      ['bundle' => 'x', 'option' => 'a', 'value' => ''],
      'not an entry',
    ]));
    self::assertSame([], IcmsCatalogBuilder::enforcedFromConfig(NULL));
  }

  /**
   * @covers ::enforcedFromConfig
   */
  public function testALaterEntryReplacesAnEarlierOne(): void {
    self::assertSame([
      ['bundle' => 'x', 'option' => 'width', 'value' => 'narrow'],
    ], IcmsCatalogBuilder::enforcedFromConfig([
      ['bundle' => 'x', 'option' => 'width', 'value' => 'wide'],
      ['bundle' => 'x', 'option' => 'width', 'value' => 'narrow'],
    ]));
  }

}
