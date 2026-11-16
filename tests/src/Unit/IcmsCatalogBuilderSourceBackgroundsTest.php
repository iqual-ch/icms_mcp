<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderSourceBackgroundsTest extends UnitTestCase {

  /**
   * @covers ::sourceBackgroundsFromConfig
   */
  public function testFilesAreNormalisedAndKeepTheirOption(): void {
    $backgrounds = IcmsCatalogBuilder::sourceBackgroundsFromConfig([
      ['file' => ' /sites/default/files/2024-01/Circles Blue.PNG ', 'option' => 'bgCircles', 'value' => TRUE],
      ['file' => 'World Map.png'],
    ]);

    self::assertSame([
      ['file' => 'circles blue.png', 'option' => 'bgCircles', 'value' => TRUE],
      ['file' => 'world map.png'],
    ], $backgrounds);
  }

  /**
   * @covers ::sourceBackgroundsFromConfig
   */
  public function testAnOptionWithoutAValueIsADroppedPicture(): void {
    $backgrounds = IcmsCatalogBuilder::sourceBackgroundsFromConfig([
      ['file' => 'a.png', 'option' => 'bgCircles'],
      ['file' => 'b.png', 'option' => '', 'value' => TRUE],
      ['file' => 'c.png', 'option' => 'bgCircles', 'value' => FALSE],
    ]);

    self::assertSame([
      ['file' => 'a.png'],
      ['file' => 'b.png'],
      ['file' => 'c.png', 'option' => 'bgCircles', 'value' => FALSE],
    ], $backgrounds);
  }

  /**
   * @covers ::sourceBackgroundsFromConfig
   */
  public function testBrokenEntriesAreLeftOutAndTheFirstEntryWins(): void {
    $backgrounds = IcmsCatalogBuilder::sourceBackgroundsFromConfig([
      'not-an-entry',
      ['file' => ''],
      ['option' => 'bgCircles', 'value' => TRUE],
      ['file' => 'Dup.png', 'option' => 'bgCircles', 'value' => TRUE],
      ['file' => 'dup.png'],
    ]);

    self::assertSame([['file' => 'dup.png', 'option' => 'bgCircles', 'value' => TRUE]], $backgrounds);
    self::assertSame([], IcmsCatalogBuilder::sourceBackgroundsFromConfig(NULL));
  }

}
