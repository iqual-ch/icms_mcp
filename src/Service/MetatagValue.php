<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Service;

/**
 * The value of a `metatag` field (drupal/metatag) as the import speaks it.
 *
 * The field has one property, `value`, holding an encoded map of tag name to
 * tag value (`metatag_data_encode()`). The pivot sends the map itself; the
 * wire contract accepts three shapes, all meaning the same:
 * - a map `{tag: string|array}`,
 * - a list with one item `{"value": <map or encoded string>}`,
 * - an encoded string.
 * An empty map, an empty string, an empty list or NULL clears the field.
 */
final class MetatagValue {

  /**
   * The field type this class handles.
   */
  public const FIELD_TYPE = 'metatag';

  /**
   * Reduces any accepted shape to a map of tag name to value.
   *
   * @return array<string, string|array<string>>
   *   The tags; empty when the value clears the field.
   *
   * @throws \InvalidArgumentException
   *   When the value is not one of the accepted shapes.
   */
  public static function normalize(mixed $value): array {
    [$map, $error] = self::parse($value);
    if ($error !== NULL) {
      throw new \InvalidArgumentException($error);
    }
    return $map;
  }

  /**
   * Why a value is not an accepted shape, or NULL when it is.
   */
  public static function check(mixed $value): ?string {
    return self::parse($value)[1];
  }

  /**
   * The encoded string the field stores for a tag map.
   *
   * @throws \InvalidArgumentException
   *   When the map cannot be encoded (for example invalid UTF-8): a failed
   *   encode must never end up as an empty value that clears the field.
   */
  public static function encode(array $tags): string {
    $encoded = function_exists('metatag_data_encode')
      ? metatag_data_encode($tags)
      : json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || $encoded === 'null') {
      throw new \InvalidArgumentException('the tags cannot be encoded (' . json_last_error_msg() . ').');
    }
    return $encoded;
  }

  /**
   * The tag map behind a stored value (an encoded string or an array).
   *
   * @return array<string, mixed>
   *   Empty when nothing is stored or it cannot be decoded.
   */
  public static function decode(mixed $stored): array {
    if (is_array($stored)) {
      return $stored;
    }
    if (!is_string($stored) || trim($stored) === '') {
      return [];
    }
    return self::decodeString($stored) ?? [];
  }

  /**
   * The field item list value for a tag map: one item, or none to clear.
   */
  public static function items(array $tags): array {
    return $tags === [] ? [] : [['value' => self::encode($tags)]];
  }

  /**
   * Parses a value into [map, error].
   *
   * @return array{0: array, 1: ?string}
   *   The tag map (empty = clear) and the reason it was refused, if any.
   */
  private static function parse(mixed $value): array {
    $shapes = 'must be a map of tags, a list with one {"value": ...} item or an encoded string';
    if ($value === NULL || $value === []) {
      return [[], NULL];
    }
    if (is_string($value)) {
      return self::parseString($value);
    }
    if (!is_array($value)) {
      return [[], $shapes . ', not a ' . get_debug_type($value) . '.'];
    }
    if (array_is_list($value)) {
      if (count($value) !== 1) {
        return [[], 'a list must hold exactly one {"value": ...} item, not ' . count($value) . '.'];
      }
      if (!is_array($value[0]) || !array_key_exists('value', $value[0])) {
        return [[], 'a list item must be an object with a "value" key.'];
      }
      return self::parseInner($value[0]['value'], 'the "value" of an item');
    }
    if (array_keys($value) === ['value']) {
      // An unwrapped field item. A tag really named "value" is not a meta tag
      // (it would only ever be this mistake), so it is never kept as one.
      return self::parseInner($value['value'], 'a bare {"value": ...}');
    }
    return [$value, self::checkMap($value)];
  }

  /**
   * Parses what sits in the "value" of a field item.
   */
  private static function parseInner(mixed $inner, string $what): array {
    if ($inner === NULL || $inner === []) {
      return [[], NULL];
    }
    if (is_string($inner)) {
      return self::parseString($inner);
    }
    if (is_array($inner) && !array_is_list($inner)) {
      return [$inner, self::checkMap($inner)];
    }
    return [[], "{$what} must be a map of tags or an encoded string."];
  }

  /**
   * Parses an encoded string: empty clears, else it must decode to a valid map.
   */
  private static function parseString(string $encoded): array {
    if (trim($encoded) === '') {
      return [[], NULL];
    }
    $map = self::decodeString($encoded);
    if ($map === NULL) {
      return [[], 'an encoded string must decode to a map of tags.'];
    }
    return [$map, self::checkMap($map)];
  }

  /**
   * Checks the tags of a map.
   *
   * A tag has a string name (valid UTF-8) and a string value, or an array of
   * string values (Metatag's own shape for robots and similar tags). Nothing
   * deeper and no numbers or booleans: the field stores strings.
   */
  private static function checkMap(array $map): ?string {
    foreach ($map as $tag => $tag_value) {
      if (!is_string($tag) || $tag === '' || !mb_check_encoding($tag, 'UTF-8')) {
        return 'tag names must be non-empty UTF-8 strings.';
      }
      if (is_string($tag_value)) {
        $parts = [$tag_value];
      }
      elseif (is_array($tag_value)) {
        $parts = $tag_value;
      }
      else {
        return "the value of tag '{$tag}' must be a string or an array of strings, not a " . get_debug_type($tag_value) . '.';
      }
      foreach ($parts as $part) {
        if (!is_string($part)) {
          return "the value of tag '{$tag}' must be a string or an array of strings, not a " . get_debug_type($part) . ' inside it.';
        }
        if (!mb_check_encoding($part, 'UTF-8')) {
          return "the value of tag '{$tag}' is not valid UTF-8.";
        }
      }
    }
    return NULL;
  }

  /**
   * Decodes an encoded string to a map, NULL when it is not one.
   */
  private static function decodeString(string $encoded): ?array {
    // metatag_data_decode() returns [] for any string it does not know, which
    // would let garbage pass as "clear". JSON (Metatag v2) is decoded here;
    // the module's decoder is kept for the serialized v1 form only.
    $data = json_decode($encoded, TRUE);
    if (!is_array($data) && str_starts_with($encoded, 'a:') && function_exists('metatag_data_decode')) {
      $data = metatag_data_decode($encoded);
    }
    if (!is_array($data)) {
      return NULL;
    }
    return array_is_list($data) && $data !== [] ? NULL : $data;
  }

}
