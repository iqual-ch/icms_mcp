<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderWebformsTest extends UnitTestCase {

  /**
   * The catalog lists the target's forms by id, label and uuid, skipping the
   * module's own example and template forms, so the section gate can offer
   * them beside the imported source forms.
   *
   * @covers ::webformEntries
   */
  public function testWebformsAreListedWithoutTheShippedExamples(): void {
    $entries = IcmsCatalogBuilder::webformEntries([
      $this->webform('kontakt', 'Kontaktformular'),
      $this->webform('example_contact', 'Example'),
      $this->webform('template_x', 'Template'),
      $this->webform('anmeldung', 'Anmeldung'),
    ]);

    self::assertSame([
      ['id' => 'anmeldung', 'label' => 'Anmeldung', 'uuid' => 'uuid-anmeldung'],
      ['id' => 'kontakt', 'label' => 'Kontaktformular', 'uuid' => 'uuid-kontakt'],
    ], $entries);
  }

  private function webform(string $id, string $label): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($label);
    $entity->method('uuid')->willReturn('uuid-' . $id);
    return $entity;
  }

}
