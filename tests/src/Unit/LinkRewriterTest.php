<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\LinkRewriter;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\LinkRewriter */
final class LinkRewriterTest extends UnitTestCase {

  /** @covers ::rewriteText */
  public function testHrefsAreReplacedInEitherQuoteStyleAndEscapedForm(): void {
    $text = '<p><a href="#e-paper">E-Paper</a> <a href=\'#e-paper\'>again</a> '
      . '<a href="/de/global#e-paper">x</a> <a href="#e-paper-2">other</a></p>';
    [$out, $count] = LinkRewriter::rewriteText($text, '#e-paper', '#4711');

    self::assertSame(2, $count);
    self::assertStringContainsString('href="#4711"', $out);
    self::assertStringContainsString("href='#4711'", $out);
    self::assertStringContainsString('href="/de/global#e-paper"', $out);
    self::assertStringContainsString('href="#e-paper-2"', $out);

    [$out, $count] = LinkRewriter::rewriteText(
      '<a href="/files/a.pdf?x=1&amp;y=2">A</a>',
      '/files/a.pdf?x=1&y=2',
      'https://t.example/sites/default/files/a.pdf',
    );
    self::assertSame(1, $count);
    self::assertStringContainsString('href="https://t.example/sites/default/files/a.pdf"', $out);
  }

  /** @covers ::rewriteUri */
  public function testLinkUrisMatchExactlyOrByResolvedFragment(): void {
    self::assertSame('#4711', LinkRewriter::rewriteUri('#e-paper', '#e-paper', '#4711'));
    self::assertSame('entity:node/12#4711', LinkRewriter::rewriteUri('entity:node/12#e-paper', '/de/global#e-paper', '/de/global#4711'));
    self::assertSame('internal:/de/global#4711', LinkRewriter::rewriteUri('internal:/de/global#e-paper', '/de/global#e-paper', '/de/global#4711'));
    self::assertSame(
      'https://t.example/sites/default/files/a.pdf',
      LinkRewriter::rewriteUri('internal:/sites/default/files/a.pdf', '/sites/default/files/a.pdf', 'https://t.example/sites/default/files/a.pdf'),
    );
    self::assertNull(LinkRewriter::rewriteUri('https://other.example/x#e-paper', '/de/global#e-paper', '/de/global#4711'));
    self::assertNull(LinkRewriter::rewriteUri('entity:node/12#other', '/de/global#e-paper', '/de/global#4711'));
    self::assertNull(LinkRewriter::rewriteUri('entity:node/12', '/de/global', 'https://t.example/x'));
  }

  /** @covers ::mirroredFileUri */
  public function testSourceFilesKeepTheirPlaceUnderPublic(): void {
    self::assertSame('public://2023-07/Report.pdf', LinkRewriter::mirroredFileUri('https://www.source.example/sites/default/files/2023-07/Report.pdf'));
    self::assertSame('public://2026-01/Termine und Tarife 2026.pdf', LinkRewriter::mirroredFileUri('https://s.example/sites/default/files/2026-01/Termine%2520und%2520Tarife%25202026.pdf'));
    self::assertSame('public://2024-03/photo.jpg', LinkRewriter::mirroredFileUri('https://s.example/sites/default/files/styles/large/public/2024-03/photo.jpg?itok=abc'));
    self::assertNull(LinkRewriter::mirroredFileUri('https://s.example/de/media/123'));
    self::assertNull(LinkRewriter::mirroredFileUri('https://cdn.example/a/b.pdf'));
  }

}
