<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderHashTest extends UnitTestCase {

  /** @covers ::stableHash */
  public function testCatalogHashIsStableAndContentSensitive(): void {
    $class = new \ReflectionClass(IcmsCatalogBuilder::class);
    $builder = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('stableHash');
    $method->setAccessible(TRUE);

    $first = ['paragraphTypes' => ['b' => ['label' => 'B'], 'a' => ['label' => 'A']]];
    $same = ['paragraphTypes' => ['a' => ['label' => 'A'], 'b' => ['label' => 'B']]];
    $changed = ['paragraphTypes' => ['a' => ['label' => 'Changed'], 'b' => ['label' => 'B']]];

    self::assertSame($method->invoke($builder, $first), $method->invoke($builder, $same));
    self::assertNotSame($method->invoke($builder, $first), $method->invoke($builder, $changed));
  }

}
