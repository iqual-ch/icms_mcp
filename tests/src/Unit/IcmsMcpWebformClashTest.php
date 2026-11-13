<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpWebformClashTest extends UnitTestCase {

  /**
   * A form with the source's uuid is the source form, whatever its id; a
   * form that only shares the id is another form, and the caller decides.
   *
   * @covers ::resolveWebformTarget
   * @dataProvider cases
   */
  public function testASourceFormIsFoundByUuidAndAnIdClashIsDecided(array $expected, string $source_uuid, ?string $id_holder_uuid, ?string $uuid_holder_id, string $on_clash, array $taken = []): void {
    $id_taken = static fn (string $id): bool => in_array($id, $taken, TRUE);
    self::assertSame($expected, IcmsMcpOperations::resolveWebformTarget('contact', $source_uuid, $id_holder_uuid, $uuid_holder_id, $on_clash, $id_taken));
  }

  public static function cases(): array {
    return [
      'no form with this id: created' => [['created', 'contact', FALSE], 'src', NULL, NULL, ''],
      'same id and uuid: an earlier import made it' => [['matched', 'contact', FALSE], 'src', 'src', 'contact', ''],
      'imported under another id earlier' => [['matched', 'contact_2', FALSE], 'src', 'other', 'contact_2', 'new_id'],
      'no source uuid: the id is all there is' => [['matched', 'contact', FALSE], '', 'other', NULL, 'replace'],
      'clash, undecided: the site keeps its form' => [['matched', 'contact', TRUE], 'src', 'other', NULL, ''],
      'clash, keep' => [['matched', 'contact', TRUE], 'src', 'other', NULL, 'keep'],
      'clash, replace' => [['replaced', 'contact', TRUE], 'src', 'other', NULL, 'replace'],
      'clash, new id' => [['created', 'contact_2', TRUE], 'src', 'other', NULL, 'new_id'],
      'clash, new id, first free one' => [['created', 'contact_3', TRUE], 'src', 'other', NULL, 'new_id', ['contact_2']],
    ];
  }

  /**
   * A new id stays a machine name of at most 32 characters.
   *
   * @covers ::resolveWebformTarget
   */
  public function testANewIdFitsTheMachineNameLength(): void {
    $long = str_repeat('a', 32);
    [, $target] = IcmsMcpOperations::resolveWebformTarget($long, 'src', 'other', NULL, 'new_id', static fn (): bool => FALSE);
    self::assertSame(str_repeat('a', 30) . '_2', $target);
  }

}
