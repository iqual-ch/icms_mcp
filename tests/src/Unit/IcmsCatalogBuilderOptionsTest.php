<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderOptionsTest extends UnitTestCase {

  /**
   * @covers ::definitionsFromSchema
   */
  public function testSchemaBlocksBecomeCatalogOptionDefinitions(): void {
    $schema = [
      'textColumns' => [
        'type' => 'radios',
        'default' => 'one',
        'label' => 'Columns',
        'options' => ['one' => ['label' => 'One'], 'two' => ['label' => 'Two'], 'three' => ['label' => 'Three']],
      ],
      'slider' => ['type' => 'checkbox', 'default' => FALSE, 'label' => 'Slider'],
      'sliderColumns' => ['type' => 'number', 'default' => 1, 'min' => 1, 'max' => 4, 'label' => 'Columns'],
      'broken' => 'not-a-spec',
    ];

    $definitions = IcmsCatalogBuilder::definitionsFromSchema($schema);

    self::assertSame(['slider', 'sliderColumns', 'textColumns'], array_keys($definitions));
    self::assertSame(['one', 'two', 'three'], $definitions['textColumns']['values']);
    self::assertSame('one', $definitions['textColumns']['default']);
    self::assertSame([TRUE, FALSE], $definitions['slider']['values']);
    self::assertSame([1, 2, 3, 4], $definitions['sliderColumns']['values']);
    self::assertSame('number', $definitions['sliderColumns']['type']);
  }

  /**
   * A hero and a text layout declare different options — the whole point of
   * reading the schema instead of advertising every option on every layout.
   *
   * @covers ::definitionsFromSchema
   */
  public function testBundlesKeepTheirOwnOptionSets(): void {
    $hero = IcmsCatalogBuilder::definitionsFromSchema([
      'colorMode' => ['type' => 'radios', 'options' => ['none' => [], 'light' => [], 'dark' => []]],
      'textShadow' => ['type' => 'radios', 'options' => ['none' => [], 'light' => []]],
    ]);
    $text = IcmsCatalogBuilder::definitionsFromSchema([
      'spacingTop' => ['type' => 'radios', 'options' => ['none' => [], 'small' => [], 'large' => []]],
      'textColumns' => ['type' => 'radios', 'options' => ['one' => [], 'two' => [], 'three' => []]],
    ]);

    self::assertSame(['colorMode', 'textShadow'], array_keys($hero));
    self::assertSame(['spacingTop', 'textColumns'], array_keys($text));
  }

}
