<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Plugin\Mcp\IcmsMcp;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Plugin\Mcp\IcmsMcp */
final class IcmsMcpMediaNameTest extends UnitTestCase {

  /** @covers ::mediaName */
  public function testMediaNameIsLimitedWithoutChangingTheAltDescriptor(): void {
    $class = new \ReflectionClass(IcmsMcp::class);
    $plugin = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('mediaName');
    $method->setAccessible(TRUE);
    $descriptor = ['alt' => str_repeat('é', 300)];

    $name = $method->invoke($plugin, $descriptor, 'https://example.com/photo.jpg', NULL);

    self::assertSame(255, mb_strlen($name));
    self::assertStringEndsWith('…', $name);
    self::assertSame(300, mb_strlen($descriptor['alt']));
  }

  /** @covers ::truncateString */
  public function testImagePropertyLimitsCanBeAppliedSafely(): void {
    $class = new \ReflectionClass(IcmsMcp::class);
    $plugin = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('truncateString');
    $method->setAccessible(TRUE);

    self::assertSame(512, mb_strlen($method->invoke($plugin, str_repeat('a', 700), 512)));
    self::assertSame(1024, mb_strlen($method->invoke($plugin, str_repeat('b', 1200), 1024)));
  }

}
