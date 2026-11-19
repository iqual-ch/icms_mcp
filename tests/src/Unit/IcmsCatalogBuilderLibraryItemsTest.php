<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderLibraryItemsTest extends UnitTestCase {

  /**
   * The catalog lists the library's reusable components with the component
   * each holds and its title per language, so a migration can link a source
   * section with the same heading to the item instead of creating the block
   * on every page.
   *
   * @covers ::libraryItemEntries
   */
  public function testLibraryItemsCarryTheirComponentAndTitlesPerLanguage(): void {
    $entries = IcmsCatalogBuilder::libraryItemEntries([
      ['id' => '7', 'uuid' => 'uuid-trust', 'label' => 'Trust logos', 'bundle' => 'icms_layout_partnerships',
        'titles' => ['fr' => ' Faites confiance ', 'de' => "Vertrauen Sie auf\n <em>geprüfte</em> Qualit&auml;t", 'it' => '']],
      ['id' => 3, 'uuid' => 'uuid-cta', 'label' => 'Contact band', 'bundle' => 'icms_layout_call_to_action', 'titles' => []],
      ['id' => 9, 'uuid' => '', 'label' => 'No uuid', 'bundle' => 'icms_layout_text', 'titles' => ['de' => 'x']],
    ]);

    self::assertSame([
      ['id' => 3, 'uuid' => 'uuid-cta', 'label' => 'Contact band', 'bundle' => 'icms_layout_call_to_action', 'titles' => []],
      ['id' => 7, 'uuid' => 'uuid-trust', 'label' => 'Trust logos', 'bundle' => 'icms_layout_partnerships',
        'titles' => ['de' => 'Vertrauen Sie auf geprüfte Qualität', 'fr' => 'Faites confiance']],
    ], $entries);
    self::assertSame([], IcmsCatalogBuilder::libraryItemEntries([]));
  }

}
