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

  /**
   * A reviewer picks a value by the words blökkli shows an editor.
   *
   * @covers ::definitionsFromSchema
   */
  public function testValueLabelsAndPresentationSurviveTheConversion(): void {
    $definitions = IcmsCatalogBuilder::definitionsFromSchema([
      'teaserVariant' => [
        'type' => 'radios',
        'label' => 'Variant',
        'displayAs' => 'grid',
        'group' => 'Layout',
        'options' => ['spotlight' => ['label' => 'Spotlight', 'icon' => 'x'], 'text' => 'Text only', 'bare' => []],
      ],
      'slider' => ['type' => 'checkbox', 'label' => 'Slider'],
    ]);

    self::assertSame(['spotlight' => 'Spotlight', 'text' => 'Text only'], $definitions['teaserVariant']['valueLabels']);
    self::assertSame('grid', $definitions['teaserVariant']['displayAs']);
    self::assertSame('Layout', $definitions['teaserVariant']['group']);
    self::assertArrayNotHasKey('valueLabels', $definitions['slider']);
    self::assertArrayNotHasKey('displayAs', $definitions['slider']);
  }

  /**
   * @covers ::withOptionEffects
   * @covers ::optionValueKey
   */
  public function testDeclaredEffectsAreAddedPerBundle(): void {
    $definitions = [
      'teaserVariant' => ['type' => 'radios', 'values' => ['teaser', 'spotlight']],
      'slider' => ['type' => 'checkbox', 'values' => [TRUE, FALSE]],
      'width' => ['type' => 'radios', 'values' => ['narrow', 'wide']],
    ];
    $declared = [
      'teaserVariant' => [
        'description' => 'How each teaser is laid out.',
        'effects' => ['teaser' => 'Image above the title.', 'spotlight' => 'Generic.', 'gone' => 'Not offered here.'],
        'bundles' => [
          'example_layout_teasers' => ['effects' => ['spotlight' => 'Image fills the card.']],
          'example_layout_other' => ['description' => 'Other.'],
        ],
      ],
      'slider' => ['effects' => ['true' => 'One sliding row.', 'false' => '  ']],
    ];

    $teasers = IcmsCatalogBuilder::withOptionEffects($definitions, $declared, 'example_layout_teasers');
    $other = IcmsCatalogBuilder::withOptionEffects($definitions, $declared, 'example_layout_other');

    self::assertSame('How each teaser is laid out.', $teasers['teaserVariant']['description']);
    self::assertSame(
      ['spotlight' => 'Image fills the card.', 'teaser' => 'Image above the title.'],
      $teasers['teaserVariant']['effects'],
    );
    self::assertSame(['true' => 'One sliding row.'], $teasers['slider']['effects']);
    self::assertArrayNotHasKey('effects', $teasers['width']);
    self::assertArrayNotHasKey('description', $teasers['width']);
    self::assertSame('Other.', $other['teaserVariant']['description']);
    self::assertSame('Generic.', $other['teaserVariant']['effects']['spotlight']);
  }

  /**
   * A relative `schema_file` must resolve whatever the working directory is,
   * and an unreadable one must read as "no schema", never as "no options".
   *
   * @covers ::resolveSchemaPath
   */
  public function testSchemaPathResolvesAgainstEachBase(): void {
    $root = sys_get_temp_dir() . '/icms_mcp_schema_' . bin2hex(random_bytes(4));
    mkdir($root . '/docroot/libraries', 0777, TRUE);
    $file = $root . '/docroot/libraries/schema.json';
    file_put_contents($file, '{}');
    try {
      self::assertSame($file, IcmsCatalogBuilder::resolveSchemaPath($file, []));
      self::assertSame(
        $root . '/docroot/../docroot/libraries/schema.json',
        IcmsCatalogBuilder::resolveSchemaPath('../docroot/libraries/schema.json', ['/nowhere', $root . '/docroot']),
      );
      self::assertNull(IcmsCatalogBuilder::resolveSchemaPath('../docroot/libraries/schema.json', ['/nowhere', '']));
      self::assertNull(IcmsCatalogBuilder::resolveSchemaPath('', [$root]));
      self::assertNull(IcmsCatalogBuilder::resolveSchemaPath($root . '/missing.json', [$root]));
    }
    finally {
      unlink($file);
      rmdir($root . '/docroot/libraries');
      rmdir($root . '/docroot');
      rmdir($root);
    }
  }

}
