<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Service;

use Drupal\Component\Utility\UrlHelper;

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
      // Only a bare path, query or fragment is internal; anything else
      // (`entity:node/5#4711`, an absolute URL) is a uri of its own.
      return $to !== '' && in_array($to[0], ['/', '?', '#'], TRUE) ? 'internal:' . $to : $to;
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
   * The value a link field can store for a uri, or NULL when there is none.
   *
   * A link field holds a URI that `Url::fromUri()` accepts: a scheme
   * (`https:`, `mailto:`, `entity:node/1`, `route:…`) or `internal:` followed
   * by a path, query or fragment (`internal:/a`, `internal:?q=1`,
   * `internal:#top`); protocol-relative `//host/x` passes as is. A bare path,
   * query or fragment — what a source, or a rewrite to a root-relative file
   * URL, hands over — gets the `internal:` prefix, and the special paths the
   * core link widget accepts get what it stores for them (`<front>` →
   * `internal:/`, `<nolink>` → `route:<nolink>`). What cannot be made valid
   * (no scheme, a path without a leading slash behind `internal:`, an
   * external URL behind `internal:`, `entity:` without an id, `route:`
   * without a name) is NULL: storing it breaks every `Url::fromUri()` on the
   * entity (entity usage tracking, GraphQL link resolvers). An empty uri
   * stays empty.
   *
   * @param string $uri
   *   The uri as the import or a link rewrite hands it over.
   *
   * @return string|null
   *   The uri to store, or NULL when the field must not hold it.
   */
  public static function linkFieldUri(string $uri): ?string {
    $uri = trim($uri);
    if ($uri === '') {
      return '';
    }
    if (str_starts_with($uri, '//')) {
      return strlen($uri) > 2 && parse_url($uri) !== FALSE ? $uri : NULL;
    }
    // What the core link widget stores for its special paths.
    if (in_array($uri, ['<nolink>', '<none>', '<button>'], TRUE)) {
      return 'route:' . $uri;
    }
    if (str_starts_with($uri, '<front>')) {
      $uri = '/' . substr($uri, strlen('<front>'));
    }
    if (in_array($uri[0], ['/', '?', '#'], TRUE)) {
      $uri = 'internal:' . $uri;
    }
    // Url::fromUri() reads `base:2024/x` as host and port; it prefixes a
    // slash first, and so does this check.
    $parts = parse_url((string) preg_replace('/^base:(\d)/', 'base:/$1', $uri));
    if ($parts === FALSE || empty($parts['scheme'])) {
      return NULL;
    }
    $rest = substr($uri, strlen($parts['scheme']) + 1);
    // The part before the query and fragment.
    $path = substr($rest, 0, strcspn($rest, '?#'));
    switch (strtolower($parts['scheme'])) {
      case 'internal':
        if ($path === '') {
          return 'internal:' . $rest;
        }
        // Without a leading slash it is no path (`internal:entity:node/5`,
        // `internal:www.example.com`), and guessing one makes a wrong link.
        // `internal://host/x` and `internal:/https://host/x` name another
        // site: Url::fromUri() rejects an external URL behind `internal:`.
        if ($path[0] !== '/' || str_starts_with($path, '//') || UrlHelper::isExternal(substr($path, 1))) {
          return NULL;
        }
        return 'internal:' . $rest;

      case 'entity':
        return preg_match('#^[a-z0-9_]+/[^/]+$#', $path) ? $uri : NULL;

      case 'route':
        return strcspn($path, ';') > 0 ? $uri : NULL;

      default:
        return $uri;
    }
  }

  /**
   * Why a uri linkFieldUri() refused is invalid, for a warning.
   *
   * @param string $uri
   *   The refused uri.
   *
   * @return string
   *   A short reason, ending without a full stop.
   */
  public static function invalidUriReason(string $uri): string {
    $reason = 'not a valid link-field URI';
    if (str_starts_with(strtolower(trim($uri)), 'tel:')) {
      $reason .= ' (a short number such as tel:117 reads as host and port; write it as tel:+41… or as text)';
    }
    return $reason;
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
