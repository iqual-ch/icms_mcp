<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/**
 * Imported webform config keeps only the third-party settings this site can hold.
 *
 * @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations
 */
final class IcmsMcpWebformThirdPartySettingsTest extends UnitTestCase {

  /**
   * Settings of absent modules go, settings of present ones stay, deps reset.
   *
   * @covers ::withoutForeignThirdPartySettings
   */
  public function testSettingsOfAbsentModulesAreDropped(): void {
    $config = [
      'id' => 'contact',
      'third_party_settings' => [
        'antibot' => ['antibot' => TRUE],
        'honeypot' => ['honeypot' => TRUE],
        'webform_ui' => ['x' => 1],
      ],
      'dependencies' => ['module' => ['antibot', 'honeypot', 'webform_ui'], 'enforced' => ['module' => ['webform']]],
    ];
    $installed = static fn (string $module): bool => $module === 'webform_ui';
    [$result, $dropped] = IcmsMcpOperations::withoutForeignThirdPartySettings($config, $installed);
    self::assertSame(['antibot', 'honeypot'], $dropped);
    self::assertSame(['webform_ui' => ['x' => 1]], $result['third_party_settings']);
    self::assertSame(['enforced' => ['module' => ['webform']]], $result['dependencies']);
    self::assertSame('contact', $result['id']);
  }

  /**
   * Nothing foreign: the config comes back unchanged except the derived deps.
   *
   * @covers ::withoutForeignThirdPartySettings
   */
  public function testNothingToDropLeavesSettingsAlone(): void {
    $config = ['id' => 'contact', 'third_party_settings' => ['webform_ui' => ['x' => 1]], 'dependencies' => ['module' => ['webform_ui']]];
    [$result, $dropped] = IcmsMcpOperations::withoutForeignThirdPartySettings($config, static fn (): bool => TRUE);
    self::assertSame([], $dropped);
    self::assertSame(['webform_ui' => ['x' => 1]], $result['third_party_settings']);
    self::assertArrayNotHasKey('dependencies', $result);
  }

  /**
   * An empty remainder removes the key; configs without the keys pass through.
   *
   * @covers ::withoutForeignThirdPartySettings
   */
  public function testEmptyRemainderAndMissingKeys(): void {
    [$result, $dropped] = IcmsMcpOperations::withoutForeignThirdPartySettings(['third_party_settings' => ['antibot' => []]], static fn (): bool => FALSE);
    self::assertSame(['antibot'], $dropped);
    self::assertArrayNotHasKey('third_party_settings', $result);
    [$result, $dropped] = IcmsMcpOperations::withoutForeignThirdPartySettings(['id' => 'x'], static fn (): bool => FALSE);
    self::assertSame([], $dropped);
    self::assertSame(['id' => 'x'], $result);
  }

}
