<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpMenuLinkOrderTest extends UnitTestCase {

  /**
   * Invoke the protected orderer on an uninstantiated service.
   */
  private function order(array $links): array {
    $class = new \ReflectionClass(IcmsMcpOperations::class);
    $service = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('orderLinksParentsFirst');
    $method->setAccessible(TRUE);
    return array_map(
      static fn (array $link): string => (string) $link['uuid'],
      $method->invoke($service, $links),
    );
  }

  /** @covers ::orderLinksParentsFirst */
  public function testAChildNeverPrecedesItsParent(): void {
    // The source exports links sorted by weight, so a child with a lower
    // weight than its parent arrives first — that is what flattened menus.
    $order = $this->order([
      ['uuid' => 'child', 'parent' => 'menu_link_content:parent'],
      ['uuid' => 'grandchild', 'parent' => 'menu_link_content:child'],
      ['uuid' => 'parent', 'parent' => ''],
    ]);

    self::assertSame(['parent', 'child', 'grandchild'], $order);
  }

  /** @covers ::orderLinksParentsFirst */
  public function testLinksWithoutAParentInTheSetKeepTheirOrder(): void {
    $order = $this->order([
      ['uuid' => 'a', 'parent' => ''],
      ['uuid' => 'b', 'parent' => 'standard.front_page'],
      ['uuid' => 'c', 'parent' => 'menu_link_content:imported-earlier'],
    ]);

    self::assertSame(['a', 'b', 'c'], $order);
  }

  /** @covers ::parentUuids */
  public function testAParentIsRecognizedWhateverFormItsChildNamesItIn(): void {
    $class = new \ReflectionClass(IcmsMcpOperations::class);
    $service = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('parentUuids');
    $method->setAccessible(TRUE);

    $parents = $method->invoke($service, [
      ['uuid' => 'parent', 'parent' => ''],
      ['uuid' => 'child', 'parent' => 'menu_link_content:parent'],
      ['uuid' => 'other', 'parent' => 'standard.front_page'],
    ]);

    // The set is matched against a link's own uuid, so the module-provided
    // 'standard.front_page' in it is inert; what matters is that 'parent' is
    // in it, which is what earns it a placeholder when its page is missing.
    self::assertSame(['parent' => TRUE, 'standard.front_page' => TRUE], $parents);
  }

  /** @covers ::orderLinksParentsFirst */
  public function testACycleIsEmittedRatherThanHangingTheImport(): void {
    $order = $this->order([
      ['uuid' => 'a', 'parent' => 'menu_link_content:b'],
      ['uuid' => 'b', 'parent' => 'menu_link_content:a'],
      ['uuid' => 'self', 'parent' => 'menu_link_content:self'],
    ]);

    self::assertSame(['b', 'a', 'self'], $order);
    self::assertCount(3, $order);
  }

}
