<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpMenuLinkMatchTest extends UnitTestCase {

  private const CANDIDATES = [
    7 => ['titles' => ['About us', 'Über uns'], 'target' => '/node/5'],
    3 => ['titles' => ['Contact'], 'target' => '/node/9'],
    9 => ['titles' => ['More'], 'target' => 'route:<nolink>'],
  ];

  /** @covers ::pickEquivalentMenuLink */
  public function testTheSamePageIsTheSameItemWhateverItIsCalled(): void {
    self::assertSame(7, IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, 'Company', '/node/5'));
  }

  /** @covers ::pickEquivalentMenuLink */
  public function testTheSameTitleIsTheSameItemPointingElsewhere(): void {
    self::assertSame(3, IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, ' contact ', '/node/44'));
    self::assertSame(7, IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, 'über uns', ''));
  }

  /** @covers ::pickEquivalentMenuLink */
  public function testAPlaceholderNeverMatchesByTargetAndAStrangerMatchesNothing(): void {
    self::assertNull(IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, 'Other', 'route:<nolink>'));
    self::assertSame(9, IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, 'More', 'route:<nolink>'));
    self::assertNull(IcmsMcpOperations::pickEquivalentMenuLink(self::CANDIDATES, 'Jobs', '/node/77'));
    self::assertNull(IcmsMcpOperations::pickEquivalentMenuLink([], 'Jobs', '/node/77'));
  }

  /** @covers ::normalizeMenuLinkTarget */
  public function testSpellingsOfOneTargetShareAKey(): void {
    $alias = static fn (string $path): string => $path === '/about-us' ? '/node/5' : $path;

    self::assertSame('/node/5', IcmsMcpOperations::normalizeMenuLinkTarget('entity:node/5', $alias));
    self::assertSame('/node/5', IcmsMcpOperations::normalizeMenuLinkTarget('internal:/node/5', $alias));
    self::assertSame('/node/5', IcmsMcpOperations::normalizeMenuLinkTarget('internal:/about-us/', $alias));
    self::assertSame('/node/5#team', IcmsMcpOperations::normalizeMenuLinkTarget('internal:/about-us#team', $alias));
    self::assertSame('/unknown', IcmsMcpOperations::normalizeMenuLinkTarget('internal:/unknown', $alias));
    self::assertSame('/', IcmsMcpOperations::normalizeMenuLinkTarget('internal:/', $alias));
    self::assertSame('/taxonomy/term/3', IcmsMcpOperations::normalizeMenuLinkTarget('entity:taxonomy_term/3', $alias));
    self::assertSame('https://example.org/a', IcmsMcpOperations::normalizeMenuLinkTarget('https://example.org/a/', $alias));
    self::assertSame('route:<nolink>', IcmsMcpOperations::normalizeMenuLinkTarget('route:<nolink>', $alias));
  }

}
