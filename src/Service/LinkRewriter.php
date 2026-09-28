<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Service;

/**
 * Pure string rules for rewriting links inside imported content.
 *
 * The migrator's post-import pass turns source links into what only the
 * target knows: an in-page anchor into `#<paragraph id>`, a document link into
 * the file URL the target stored. Both forms live in two places — `href`
 * attributes inside formatted text, and the `uri` of link fields, where a
 * resolved internal link is `entity:node/<nid>#<fragment>`. These helpers
 * apply one `{from, to}` replacement to either form and say whether anything
 * changed, so the service can count and report unmatched replacements.
 */
final class LinkRewriter {

  /**
   * Replace `href="from"` (either quote style, HTML-escaped or not) in text.
   *
   * @return array{0: string, 1: int}
   *   The rewritten text and the number of hrefs replaced.
   */
  public static function rewriteText(string $text, string $from, string $to): array {
    if ($from === '' || $text === '' || $from === $to) {
      return [$text, 0];
    }
    $count = 0;
    foreach ([$from, htmlspecialchars($from, ENT_QUOTES)] as $needle) {
      $replacement = $needle === $from ? $to : htmlspecialchars($to, ENT_QUOTES);
      $pattern = '/(href\s*=\s*)(["\'])' . preg_quote($needle, '/') . '\2/i';
      $text = (string) preg_replace_callback($pattern, function (array $m) use ($replacement, &$count): string {
        $count++;
        return $m[1] . $m[2] . $replacement . $m[2];
      }, $text);
    }
    return [$text, $count];
  }

  /**
   * The link-field uri after applying the replacement, or NULL when it does
   * not match.
   *
   * A uri matches when it equals `from` (with or without an `internal:`
   * prefix), or — for an anchor replacement, where both sides carry a
   * fragment — when it ends in `from`'s fragment: the deferred link
   * resolution already turned the path into `entity:node/<nid>`, so only the
   * fragment is still the source's.
   */
  public static function rewriteUri(string $uri, string $from, string $to): ?string {
    if ($uri === '' || $from === '' || $from === $to) {
      return NULL;
    }
    if ($uri === $from) {
      return $to;
    }
    if ($uri === 'internal:' . $from) {
      return preg_match('#^https?://#i', $to) ? $to : 'internal:' . $to;
    }
    $from_fragment = self::fragment($from);
    $to_fragment = self::fragment($to);
    if ($from_fragment === NULL || $to_fragment === NULL) {
      return NULL;
    }
    $suffix = '#' . $from_fragment;
    if (strlen($uri) > strlen($suffix) && str_ends_with($uri, $suffix)) {
      $base = substr($uri, 0, -strlen($suffix));
      // Only an internal link (entity:, internal:, or a bare path) can be the
      // resolved form of this anchor; an external URL ending in the same
      // fragment is a coincidence.
      if (preg_match('#^(entity:node/\d+|internal:.*|/.*)$#', $base)) {
        return $base . '#' . $to_fragment;
      }
    }
    return NULL;
  }

  /**
   * The fragment of a link, or NULL when it carries none.
   */
  public static function fragment(string $link): ?string {
    $position = strpos($link, '#');
    if ($position === FALSE) {
      return NULL;
    }
    $fragment = substr($link, $position + 1);
    return $fragment === '' ? NULL : $fragment;
  }

  /**
   * The `public://` path a source file mirrors, or NULL when the source path
   * is not under a Drupal files directory.
   *
   * `https://source/sites/default/files/2023-07/Report.pdf` keeps its place
   * (`public://2023-07/Report.pdf`); anything else (a media route, a CDN
   * transform) has no path worth mirroring.
   */
  public static function mirroredFileUri(string $url): ?string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!preg_match('#/sites/[^/]+/files/(.+)$#', $path, $m)) {
      return NULL;
    }
    $segments = [];
    foreach (explode('/', $m[1]) as $segment) {
      $decoded = rawurldecode(rawurldecode($segment));
      $decoded = trim($decoded);
      if ($decoded === '' || $decoded === '.' || $decoded === '..') {
        continue;
      }
      $segments[] = $decoded;
    }
    if (!$segments) {
      return NULL;
    }
    // Image-style derivatives mirror the original's place.
    if (count($segments) > 2 && $segments[0] === 'styles') {
      $public = array_search('public', $segments, TRUE);
      if ($public !== FALSE && $public + 1 < count($segments)) {
        $segments = array_slice($segments, $public + 1);
      }
    }
    return 'public://' . implode('/', $segments);
  }

}
