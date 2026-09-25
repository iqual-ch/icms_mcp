<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderEditorStylesTest extends UnitTestCase {

  /**
   * The catalog lists a format's editor styles with the tag and classes each
   * style applies, and the classes the format allows on text tags, so the
   * section gate can bind a source text style and a design handoff can say
   * which styles the editor still lacks.
   *
   * @covers ::editorTextStyleEntry
   */
  public function testEditorStylesAreReducedToTagsAndClasses(): void {
    $entry = IcmsCatalogBuilder::editorTextStyleEntry(
      'icms_basic',
      'ICMS Basic',
      [
        ['label' => 'Lead', 'element' => '<p class="text-lead">'],
        ['label' => 'Bold', 'element' => '<span class="font-bold">'],
        ['label' => 'Broken'],
      ],
      '<a href> <br> <p class="text-lead text-small"> <span class="font-bold"> <h2> <h3> <ul> <li>',
    );

    self::assertSame('icms_basic', $entry['format']);
    self::assertSame('ICMS Basic', $entry['label']);
    self::assertSame([
      ['label' => 'Lead', 'element' => '<p class="text-lead">', 'tag' => 'p', 'classes' => ['text-lead']],
      ['label' => 'Bold', 'element' => '<span class="font-bold">', 'tag' => 'span', 'classes' => ['font-bold']],
    ], $entry['styles']);
    self::assertSame([
      'p' => ['text-lead', 'text-small'],
      'span' => ['font-bold'],
      'h2' => [],
      'h3' => [],
    ], $entry['allowedClasses']);
  }

}
