<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\LayoutExampleIndex;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\LayoutExampleIndex */
final class LayoutExampleIndexTest extends UnitTestCase {

  /**
   * blökkli stores only what an editor changed, and stores strings.
   *
   * @covers ::effectiveOptions
   */
  public function testStoredOptionsAreLaidOverTypedDefaults(): void {
    $definitions = [
      'teaserVariant' => ['type' => 'radios', 'default' => 'teaser'],
      'slider' => ['type' => 'checkbox', 'default' => FALSE],
      'sliderColumns' => ['type' => 'number', 'default' => 3],
      'spacingTop' => ['type' => 'radios', 'default' => 'small'],
      'noDefault' => ['type' => 'radios'],
    ];

    $options = LayoutExampleIndex::effectiveOptions(
      ['teaserVariant' => 'spotlight', 'slider' => '1', 'sliderColumns' => '4', 'retired' => 'x'],
      $definitions,
    );

    self::assertSame(
      ['slider' => TRUE, 'sliderColumns' => 4, 'spacingTop' => 'small', 'teaserVariant' => 'spotlight'],
      $options,
    );
    self::assertSame(
      ['slider' => FALSE, 'sliderColumns' => 3, 'spacingTop' => 'small', 'teaserVariant' => 'teaser'],
      LayoutExampleIndex::effectiveOptions([], $definitions),
    );
  }

  /**
   * @covers ::effectiveOptions
   */
  public function testWithoutASchemaTheStoredValuesPass(): void {
    self::assertSame(['a' => '1', 'b' => 'x'], LayoutExampleIndex::effectiveOptions(['b' => 'x', 'a' => '1'], []));
  }

  /**
   * @covers ::frontendPath
   * @covers ::languagePrefix
   */
  public function testPublicPathCarriesTheLanguagePrefix(): void {
    self::assertSame('de', LayoutExampleIndex::languagePrefix(['de' => 'de', 'en' => ''], 'de'));
    self::assertSame('', LayoutExampleIndex::languagePrefix(['de' => 'de', 'en' => ''], 'en'));
    self::assertSame('', LayoutExampleIndex::languagePrefix(NULL, 'de'));
    self::assertSame('/de/showcase/teasers', LayoutExampleIndex::frontendPath('de', '/showcase/teasers'));
    self::assertSame('/showcase/teasers', LayoutExampleIndex::frontendPath('', 'showcase/teasers'));
    self::assertSame('/de', LayoutExampleIndex::frontendPath('de', '/'));
    self::assertSame('/', LayoutExampleIndex::frontendPath('', '/'));
  }

  /**
   * A cached screenshot is only as good as the page it was taken from.
   *
   * @covers ::indexHash
   */
  public function testHashFollowsPositionAndOptionsNotKeyOrder(): void {
    $example = [
      'id' => 'a', 'bundle' => 'example_layout_teasers', 'pagePath' => '/showcase/teasers',
      'ordinal' => 2, 'siblingCount' => 5, 'options' => ['slider' => FALSE, 'teaserVariant' => 'teaser'],
    ];
    $reordered = $example;
    $reordered['options'] = ['teaserVariant' => 'teaser', 'slider' => FALSE];
    $moved = ['ordinal' => 3] + $example;
    $changed = $example;
    $changed['options']['teaserVariant'] = 'spotlight';
    $grown = ['siblingCount' => 6] + $example;

    $hash = LayoutExampleIndex::indexHash([$example]);
    self::assertSame($hash, LayoutExampleIndex::indexHash([$reordered]));
    self::assertNotSame($hash, LayoutExampleIndex::indexHash([$moved]));
    self::assertNotSame($hash, LayoutExampleIndex::indexHash([$changed]));
    self::assertNotSame($hash, LayoutExampleIndex::indexHash([$grown]));
  }

}
