<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderDescriptionsTest extends UnitTestCase {

  /**
   * @covers ::guidanceFromEditorial
   */
  public function testGuidanceListsAreTrimmedAndAlwaysPresent(): void {
    $guidance = IcmsCatalogBuilder::guidanceFromEditorial([
      'summary' => 'Hero slider.',
      'useWhen' => [' The page starts with a header slider. ', ''],
      'similarTo' => ['icms_layout_hero_image'],
    ]);

    self::assertSame(['The page starts with a header slider.'], $guidance['useWhen']);
    self::assertSame([], $guidance['avoidWhen']);
    self::assertSame(['icms_layout_hero_image'], $guidance['similarTo']);
  }

  /**
   * Declared source components reach the catalog as a normalised list.
   *
   * A project's description entry names the source components the bundle is
   * built for; the migrator applies them as a declared mapping.
   *
   * @covers ::sourcePatternsFromEditorial
   */
  public function testSourcePatternsAreNormalisedAndOptional(): void {
    self::assertSame(
      ['slider', 'animatedslider'],
      IcmsCatalogBuilder::sourcePatternsFromEditorial(['sourcePatterns' => ['Slider', ' animatedslider ', 'slider', '']]),
    );
    self::assertSame([], IcmsCatalogBuilder::sourcePatternsFromEditorial(['summary' => 'No recipe.']));
    // A scalar by mistake is one pattern, not a crash.
    self::assertSame(['testimonial'], IcmsCatalogBuilder::sourcePatternsFromEditorial(['sourcePatterns' => 'testimonial']));
  }

}
