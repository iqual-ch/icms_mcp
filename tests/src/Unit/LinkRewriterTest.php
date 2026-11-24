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
    self::assertSame('entity:node/5#4711', LinkRewriter::rewriteUri('internal:/de/p', '/de/p', 'entity:node/5#4711'));
    self::assertSame('internal:/sites/default/files/a.pdf', LinkRewriter::rewriteUri('internal:/x.pdf', '/x.pdf', '/sites/default/files/a.pdf'));
  }

  /**
   * @covers ::linkFieldUri
   * @dataProvider linkFieldUris
   */
  public function testLinkFieldUrisAreOnlyThoseUrlFromUriAccepts(string $uri, ?string $expected): void {
    self::assertSame($expected, LinkRewriter::linkFieldUri($uri));
  }

  /**
   * A uri and what a link field stores for it (NULL: nothing).
   */
  public static function linkFieldUris(): array {
    return [
      'root-relative file URL' => [
        '/sites/default/files/2024-05/report.pdf',
        'internal:/sites/default/files/2024-05/report.pdf',
      ],
      'bare path with query and fragment' => ['/de/seite?x=1#a', 'internal:/de/seite?x=1#a'],
      'fragment only' => ['#kontakt', 'internal:#kontakt'],
      'query only' => ['?page=2', 'internal:?page=2'],
      'surrounding whitespace' => ['  /a ', 'internal:/a'],
      'empty' => ['', ''],
      'internal path' => ['internal:/a', 'internal:/a'],
      'internal fragment' => ['internal:#top', 'internal:#top'],
      'internal without slash' => ['internal:node/1', NULL],
      'entity behind internal' => ['internal:entity:node/5', NULL],
      'host behind internal without slash' => ['internal:www.example.com', NULL],
      'internal, empty' => ['internal:', 'internal:'],
      'entity' => ['entity:node/12#4711', 'entity:node/12#4711'],
      'route' => ['route:<nolink>', 'route:<nolink>'],
      'route with parameters' => ['route:entity.node.canonical;node=5', 'route:entity.node.canonical;node=5'],
      'route, query only' => ['route:?x=1', NULL],
      'route, fragment only' => ['route:#a', NULL],
      'route, parameters only' => ['route:;node=5', NULL],
      'front' => ['<front>', 'internal:/'],
      'front with fragment' => ['<front>#top', 'internal:/#top'],
      'nolink' => ['<nolink>', 'route:<nolink>'],
      'none' => ['<none>', 'route:<none>'],
      'button' => ['<button>', 'route:<button>'],
      'two slashes only' => ['//', NULL],
      'short tel number' => ['tel:117', NULL],
      'https' => ['https://example.com/a.pdf', 'https://example.com/a.pdf'],
      'protocol-relative' => ['//cdn.example/a.pdf', '//cdn.example/a.pdf'],
      'mailto' => ['mailto:info@example.com', 'mailto:info@example.com'],
      'tel' => ['tel:+41441234567', 'tel:+41441234567'],
      'base with a digit' => ['base:2024/a', 'base:2024/a'],
      'no scheme' => ['www.example.com', NULL],
      'relative path' => ['files/a.pdf', NULL],
      'external behind internal' => ['internal:https://example.com', NULL],
      'host behind internal' => ['internal://example.com/a', NULL],
      'entity without id' => ['entity:node/', NULL],
      'entity without type' => ['entity:12', NULL],
      'route without name' => ['route:', NULL],
    ];
  }

  /**
   * @covers ::invalidUriReason
   */
  public function testInvalidUriReasonExplainsShortTelNumbers(): void {
    self::assertSame('not a valid link-field URI', LinkRewriter::invalidUriReason('www.example.com'));
    self::assertStringContainsString('host and port', LinkRewriter::invalidUriReason('tel:117'));
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
