<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpSvgMediaBundleTest extends UnitTestCase {

  /**
   * @covers ::svgMediaBundle
   * @dataProvider cases
   */
  public function testAnSvgGoesWhereTheSiteTakesIt(array $allowed, array $imageExtensions, ?string $expected): void {
    self::assertSame($expected, IcmsMcpOperations::svgMediaBundle($allowed, $imageExtensions));
  }

  public static function cases(): array {
    return [
      'icon field' => [['icon'], ['png', 'jpg'], 'icon'],
      'icon wins over image' => [['image', 'icon'], ['png', 'svg'], 'icon'],
      'image that accepts svg' => [['image', 'video'], ['png', 'jpg', 'svg'], 'image'],
      'image that does not' => [['image', 'video'], ['png', 'jpg'], NULL],
      'no picture bundle' => [['document'], ['png', 'svg'], NULL],
    ];
  }

  /**
   * @covers \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder::fileExtensionsFromSetting
   */
  public function testTheExtensionSettingBecomesASortedList(): void {
    self::assertSame(['gif', 'jpg', 'png', 'svg'], IcmsCatalogBuilder::fileExtensionsFromSetting('png gif  JPG, svg png'));
    self::assertSame([], IcmsCatalogBuilder::fileExtensionsFromSetting(NULL));
    self::assertSame([], IcmsCatalogBuilder::fileExtensionsFromSetting(''));
  }

}
