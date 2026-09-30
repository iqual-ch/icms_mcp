<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * One normalising reader for field values, for the read-back tool.
 *
 * The migrator compares what it sent with what landed. Storage shapes differ
 * per field type (a taxonomy reference is a target id, a link a uri + title,
 * a text a value + format), so each is reduced to the keys the pivot speaks
 * in, and references carry the referenced entity's label so a term written
 * by name can be checked by name.
 */
final class FieldValueReader {

  /**
   * Base fields that say nothing about content and are left out.
   */
  private const SKIPPED = [
    'uuid', 'vid', 'revision_timestamp', 'revision_uid', 'revision_log',
    'revision_translation_affected', 'revision_default', 'default_langcode',
    'content_translation_source', 'content_translation_outdated',
    'content_translation_uid', 'content_translation_created', 'behavior_settings',
    'parent_id', 'parent_type', 'parent_field_name', 'metatag', 'path',
  ];

  /**
   * The entity's field values, keyed by field name.
   *
   * @param string[] $only
   *   Field names to read; empty reads every field except bookkeeping and
   *   paragraph references (the paragraph tree is read separately).
   */
  public static function readFields(FieldableEntityInterface $entity, array $only): array {
    $out = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($only && !in_array($name, $only, TRUE)) {
        continue;
      }
      if (!$only && (in_array($name, self::SKIPPED, TRUE) || $definition->getType() === 'entity_reference_revisions')) {
        continue;
      }
      $out[$name] = self::read($entity->get($name));
    }
    return $out;
  }

  /**
   * One field's items, normalised.
   */
  public static function read(FieldItemListInterface $items): array {
    $definition = $items->getFieldDefinition();
    $type = $definition->getType();
    $values = [];
    foreach ($items as $item) {
      $raw = $item->getValue();
      if (!is_array($raw)) {
        $values[] = $raw;
        continue;
      }
      if ($type === 'entity_reference') {
        $entry = ['target_id' => isset($raw['target_id']) ? (int) $raw['target_id'] : NULL];
        $referenced = $item->entity ?? NULL;
        if ($referenced !== NULL && method_exists($referenced, 'label')) {
          $entry['label'] = (string) $referenced->label();
          $entry['target_type'] = $referenced->getEntityTypeId();
          if (method_exists($referenced, 'bundle')) {
            $entry['bundle'] = $referenced->bundle();
          }
        }
        $values[] = $entry;
        continue;
      }
      if ($type === 'link') {
        $values[] = ['uri' => (string) ($raw['uri'] ?? ''), 'title' => (string) ($raw['title'] ?? '')];
        continue;
      }
      if (in_array($type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
        $entry = ['value' => (string) ($raw['value'] ?? ''), 'format' => (string) ($raw['format'] ?? '')];
        if (isset($raw['summary']) && $raw['summary'] !== '') {
          $entry['summary'] = (string) $raw['summary'];
        }
        $values[] = $entry;
        continue;
      }
      if ($type === 'boolean') {
        // A loaded boolean item holds the database string ("0" / "1"); a
        // consumer comparing it as a value would read "0" as set.
        $values[] = (bool) ($raw['value'] ?? FALSE);
        continue;
      }
      if (array_key_exists('value', $raw) && count($raw) === 1) {
        $values[] = $raw['value'];
        continue;
      }
      $values[] = $raw;
    }
    return $values;
  }

}
