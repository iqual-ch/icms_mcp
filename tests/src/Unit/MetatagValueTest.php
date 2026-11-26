<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\MetatagValue;
use Drupal\Tests\UnitTestCase;

/**
 * The accepted shapes of a metatag field value.
 *
 * @coversDefaultClass \Drupal\icms_mcp\Service\MetatagValue
 * @group icms_mcp
 */
final class MetatagValueTest extends UnitTestCase {

  /**
   * Every accepted shape reduces to the same map.
   *
   * @covers ::normalize
   * @covers ::check
   * @dataProvider acceptedProvider
   */
  public function testAcceptedShapes(mixed $value, array $expected): void {
    $this->assertNull(MetatagValue::check($value));
    $this->assertSame($expected, MetatagValue::normalize($value));
  }

  /**
   * Provider.
   */
  public static function acceptedProvider(): array {
    $map = ['description' => 'Hello', 'robots' => ['noindex', 'nofollow']];
    $encoded = json_encode($map);
    return [
      'map' => [$map, $map],
      'item with map' => [[['value' => $map]], $map],
      'item with encoded string' => [[['value' => $encoded]], $map],
      'encoded string' => [$encoded, $map],
      'bare value with map' => [['value' => $map], $map],
      'bare value with encoded string' => [['value' => $encoded], $map],
      'bare value with empty map' => [['value' => []], []],
      'null clears' => [NULL, []],
      'empty map clears' => [[], []],
      'empty string clears' => ['', []],
      'item with empty value clears' => [[['value' => []]], []],
      'item with null value clears' => [[['value' => NULL]], []],
    ];
  }

  /**
   * Anything else is refused with a message.
   *
   * @covers ::check
   * @covers ::normalize
   * @dataProvider rejectedProvider
   */
  public function testRejectedShapes(mixed $value): void {
    $this->assertIsString(MetatagValue::check($value));
    $this->expectException(\InvalidArgumentException::class);
    MetatagValue::normalize($value);
  }

  /**
   * Provider.
   */
  public static function rejectedProvider(): array {
    return [
      'integer' => [5],
      'boolean' => [TRUE],
      'several items' => [[['value' => ['a' => 'b']], ['value' => ['c' => 'd']]]],
      'list of strings' => [['a', 'b']],
      'item without value' => [[['tags' => ['a' => 'b']]]],
      'numeric tag name' => [['a' => 'b', 5 => 'c']],
      'integer tag value' => [['description' => 5]],
      'string that is not a map' => ['just text'],
      'item value scalar' => [[['value' => 3]]],
      'bare value with junk string' => [['value' => 'plain text']],
      'bare value with scalar' => [['value' => 3]],
      'encoded string with a number' => ['{"description":5}'],
      'encoded string with deep nesting' => ['{"robots":{"a":["b"]}}'],
      'deep nesting' => [['robots' => ['a' => ['b']]]],
      'number inside an array' => [['robots' => ['noindex', 3]]],
      'invalid utf-8 value' => [['description' => "\xB1\x31"]],
      'invalid utf-8 name' => [["\xB1\x31" => 'x']],
    ];
  }

  /**
   * Encode and decode round-trip; an unset value decodes to an empty map.
   *
   * @covers ::encode
   * @covers ::decode
   * @covers ::items
   */
  public function testRoundTrip(): void {
    $map = ['title' => 'Grüsse / Hello', 'robots' => ['noindex']];
    $items = MetatagValue::items($map);
    $this->assertCount(1, $items);
    $this->assertSame($map, MetatagValue::decode($items[0]['value']));
    $this->assertSame([], MetatagValue::items([]));
    $this->assertSame([], MetatagValue::decode(NULL));
    $this->assertSame([], MetatagValue::decode(''));
  }

  /**
   * A map that does not encode throws; it never becomes an empty value.
   *
   * @covers ::encode
   */
  public function testEncodeFailureThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    MetatagValue::encode(['description' => "\xB1\x31"]);
  }

}
