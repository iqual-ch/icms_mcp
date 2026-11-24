<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Translations of a node flagged as ICMS detached content.
 *
 * Runs the import writers against the real ICMS presave hook
 * (icms_core_logic's DetachedContentManager), which decides what a
 * translation's own paragraph list ends up holding. icms_core_logic and
 * search_api are not Composer dependencies of this module, so the test is
 * skipped where one of its modules is not in the codebase — run it from an
 * ICMS project.
 *
 * @group icms_mcp
 */
class IcmsMcpDetachedTranslationTest extends KernelTestBase {

  /**
   * One class per contributed module of $modules, to detect a missing one.
   */
  private const REQUIRED_CLASSES = [
    'entity_reference_revisions' => 'Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList',
    'paragraphs' => 'Drupal\paragraphs\Entity\Paragraph',
    'paragraphs_blokkli' => 'Drupal\paragraphs_blokkli\BlokkliOptionsSchemaHelper',
    'search_api' => 'Drupal\search_api\Entity\Index',
    'icms_core_logic' => 'Drupal\icms_core_logic\DetachedContent\DetachedContentManager',
  ];

  /**
   * The layouts field the import writes (the module's default).
   */
  private const LAYOUTS = 'field_icms_paragraphs';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'node',
    'language',
    'content_translation',
    'entity_reference_revisions',
    'paragraphs',
    'paragraphs_blokkli',
    // icms_core_logic's services need it.
    'search_api',
    'icms_core_logic',
    'icms_mcp',
  ];

  /**
   * The import service under test.
   */
  protected IcmsMcpOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $missing = array_keys(array_filter(self::REQUIRED_CLASSES, fn(string $class): bool => !class_exists($class)));
    if ($missing) {
      $this->markTestSkipped('Not in this codebase: ' . implode(', ', $missing) . '. Run the test from an ICMS project.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('paragraphs_blokkli_edit_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language']);

    foreach (['de', 'fr', 'it'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ParagraphsType::create(['id' => 'text', 'label' => 'Text'])->save();
    ParagraphsType::create(['id' => 'container', 'label' => 'Container'])->save();
    foreach ([['node', 'page'], ['paragraph', 'text'], ['paragraph', 'container']] as [$entity_type, $bundle]) {
      ContentLanguageSettings::loadByEntityTypeBundle($entity_type, $bundle)
        ->setLanguageAlterable(TRUE)
        ->setThirdPartySetting('content_translation', 'enabled', TRUE)
        ->save();
    }
    $this->createStringField('paragraph', 'text', 'field_text');
    // A section field the project fills from the page (fieldsFromPage).
    $this->createStringField('paragraph', 'text', 'field_topic');
    $this->createStringField('node', 'page', 'field_page_topic');
    $this->config('icms_mcp.catalog_descriptions')
      ->set('descriptions', ['paragraph' => ['text' => ['fieldsFromPage' => ['field_topic' => 'field_page_topic']]]])
      ->save();
    $this->createParagraphField('paragraph', 'container', 'field_children');
    // Translatable: the target opted into detached content.
    $this->createParagraphField('node', 'page', self::LAYOUTS);

    $this->operations = $this->container->get('icms_mcp.operations');
  }

  /**
   * A structure-matched translation of a detached node gets its own tree.
   *
   * Next to a detached language, as the migration ships them. A re-import
   * replaces each translation's tree and never touches the default list.
   */
  public function testStructureMatchedTranslationOwnsItsTree(): void {
    $nid = $this->importDefault();
    $node = $this->reload($nid);
    $de_ids = $this->ids($node);
    $this->assertCount(2, $de_ids);

    $summary = $this->keyed($this->writeTranslations($nid, [
      $this->detachedSpec('fr'),
      $this->matchedSpec('it', 'v1'),
    ]));

    $this->assertSame('ok', $summary['it']['status']);
    $this->assertSame('detached', $summary['it']['paragraphs_mode']);
    $this->assertTrue($summary['it']['paragraphs_translated']);
    $this->assertArrayHasKey('note', $summary['it']);
    $this->assertArrayNotHasKey('reason', $summary['it']);
    $this->assertSame('detached', $summary['fr']['paragraphs_mode']);
    $this->assertArrayNotHasKey('note', $summary['fr']);

    $node = $this->reload($nid);
    $this->assertSame($de_ids, $this->ids($node), 'The default language keeps its list.');
    $it = $node->getTranslation('it');
    $it_ids = $this->ids($it);
    $this->assertCount(2, $it_ids);
    $this->assertEmpty(array_intersect($de_ids, $it_ids), 'Italian owns different paragraph entities.');
    $this->assertSame(2, $this->rowCount($nid, 'it'), 'Italian has its own field rows.');
    $this->assertSame(['A (it v1)', 'B (it v1)'], $this->texts($it));
    $this->assertTreeLanguage($it, 'it');
    $this->assertSame('Tema (it)', $this->topic($it), "Filled from the Italian page's field.");
    $this->assertSame('Thema', $this->topic($node));
    $this->assertCount(3, $this->ids($node->getTranslation('fr')));
    foreach (Paragraph::loadMultiple($de_ids) as $paragraph) {
      $this->assertFalse($paragraph->hasTranslation('it'), 'Nothing was written into the shared paragraphs.');
    }
    $this->assertSame(['A', 'B'], $this->texts($node));

    // Second run of the same page: default language, then translations.
    $this->importDefault($nid);
    $node = $this->reload($nid);
    $de_ids = $this->ids($node);
    $this->writeTranslations($nid, [
      $this->detachedSpec('fr'),
      $this->matchedSpec('it', 'v2'),
    ]);

    $node = $this->reload($nid);
    $this->assertSame($de_ids, $this->ids($node), 'The translation write leaves the default list alone.');
    $this->assertCount(2, Paragraph::loadMultiple($de_ids));
    $this->assertSame(['A', 'B'], $this->texts($node));
    $it = $node->getTranslation('it');
    $new_it_ids = $this->ids($it);
    $this->assertCount(2, $new_it_ids);
    $this->assertSame(2, $this->rowCount($nid, 'it'));
    $this->assertEmpty(array_intersect($it_ids, $new_it_ids), 'The tree was replaced.');
    $this->assertEmpty(Paragraph::loadMultiple($it_ids), 'The previous Italian paragraphs were deleted.');
    $this->assertSame(['A (it v2)', 'B (it v2)'], $this->texts($it));
  }

  /**
   * A translation still holding the original's paragraphs keeps them alive.
   *
   * The node is flagged behind the presave hook's back, so the Italian
   * translation still lists the default language's paragraphs (shared IDs).
   */
  public function testSharedIdsAreNeverDeleted(): void {
    $nid = $this->importDefault(NULL, FALSE);
    $node = $this->reload($nid);
    $node->addTranslation('it', ['title' => 'IT'])->save();
    $node = $this->reload($nid);
    $de_ids = $this->ids($node);
    $this->assertSame($de_ids, $this->ids($node->getTranslation('it')), 'Attached: Italian shares the list.');

    foreach (['node_field_data', 'node_field_revision'] as $table) {
      $this->container->get('database')->update($table)
        ->fields([IcmsMcpOperations::DETACHED_CONTENT_FLAG => 1])
        ->condition('nid', $nid)
        ->execute();
    }

    $summary = $this->keyed($this->writeTranslations($nid, [$this->matchedSpec('it', 'v1')]));
    $this->assertSame('detached', $summary['it']['paragraphs_mode']);

    $node = $this->reload($nid);
    $this->assertSame($de_ids, $this->ids($node));
    $this->assertCount(2, Paragraph::loadMultiple($de_ids), 'The shared paragraphs survived.');
    $this->assertSame(['A', 'B'], $this->texts($node));
    $it = $node->getTranslation('it');
    $this->assertEmpty(array_intersect($de_ids, $this->ids($it)));
    $this->assertSame(['A (it v1)', 'B (it v1)'], $this->texts($it));
  }

  /**
   * An attached node still translates the shared paragraphs in place.
   */
  public function testStructureMatchOnAttachedNodeStaysSymmetric(): void {
    $nid = $this->importDefault(NULL, FALSE);
    $node = $this->reload($nid);
    $de_ids = $this->ids($node);
    $all_ids = $this->allParagraphIds();

    $summary = $this->keyed($this->writeTranslations($nid, [$this->matchedSpec('it', 'v1')]));

    $this->assertSame('ok', $summary['it']['status']);
    $this->assertSame('symmetric', $summary['it']['paragraphs_mode']);
    $this->assertTrue($summary['it']['paragraphs_translated']);
    $this->assertArrayNotHasKey('note', $summary['it']);
    $node = $this->reload($nid);
    $this->assertSame($de_ids, $this->ids($node));
    $this->assertSame($de_ids, $this->ids($node->getTranslation('it')), 'Italian lists the shared paragraphs.');
    $this->assertSame($all_ids, $this->allParagraphIds(), 'No paragraph was created or deleted.');
    $this->assertSame(['A (it v1)', 'B (it v1)'], $this->texts($node->getTranslation('it')));
    $this->assertSame(['A', 'B'], $this->texts($node));
  }

  /**
   * Import the default language (de) as the migration does.
   */
  private function importDefault(?int $nid = NULL, bool $detached = TRUE): int {
    $paragraphs = [
      [
        'sequence' => 0,
        'type' => 'paragraph--container',
        'attributes' => [
          'field_children' => [
            ['type' => 'paragraph--text', 'fields' => ['field_text' => 'A']],
          ],
        ],
      ],
      [
        'sequence' => 1,
        'type' => 'paragraph--text',
        'attributes' => ['field_text' => 'B'],
      ],
    ];
    $attributes = ['title' => 'DE', 'langcode' => 'de', 'field_page_topic' => 'Thema'];
    if ($detached) {
      $attributes[IcmsMcpOperations::DETACHED_CONTENT_FLAG] = TRUE;
    }
    $result = $this->invoke('writeNodeAndParagraphs', ['page', $attributes, $paragraphs, 'source-key-1', $nid]);
    return (int) $result['nid'];
  }

  /**
   * A translation with the default language's structure.
   */
  private function matchedSpec(string $langcode, string $version): array {
    $suffix = " ({$langcode} {$version})";
    return [
      'langcode' => $langcode,
      'structureMatch' => TRUE,
      'node' => [
        'attributes' => [
          'title' => strtoupper($langcode),
          'field_page_topic' => "Tema ({$langcode})",
        ],
      ],
      'paragraphs' => [
        [
          'sequence' => 0,
          'type' => 'paragraph--container',
          'attributes' => [
            'field_children' => [
              ['type' => 'paragraph--text', 'fields' => ['field_text' => 'A' . $suffix]],
            ],
          ],
          'options' => [],
        ],
        [
          'sequence' => 1,
          'type' => 'paragraph--text',
          'attributes' => ['field_text' => 'B' . $suffix],
          'options' => [],
        ],
      ],
    ];
  }

  /**
   * A translation with its own structure.
   */
  private function detachedSpec(string $langcode): array {
    $paragraphs = [];
    foreach (['X', 'Y', 'Z'] as $index => $text) {
      $paragraphs[] = [
        'sequence' => $index,
        'type' => 'paragraph--text',
        'attributes' => ['field_text' => "{$text} ({$langcode})"],
      ];
    }
    return [
      'langcode' => $langcode,
      'mode' => 'detached',
      'node' => ['attributes' => ['title' => strtoupper($langcode)]],
      'paragraphs' => $paragraphs,
    ];
  }

  /**
   * Run the translation writer.
   */
  private function writeTranslations(int $nid, array $translations): array {
    return $this->invoke('writeTranslations', [$nid, $translations]);
  }

  /**
   * Call a protected method of the (final) import service.
   */
  private function invoke(string $method, array $arguments): mixed {
    return (new \ReflectionMethod($this->operations, $method))->invokeArgs($this->operations, $arguments);
  }

  /**
   * The summary keyed by langcode.
   */
  private function keyed(array $summary): array {
    return array_column($summary, NULL, 'langcode');
  }

  /**
   * Adds a translatable string field to a bundle.
   */
  private function createStringField(string $entity_type, string $bundle, string $field_name): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Adds a paragraph reference field (translatable) to a bundle.
   */
  private function createParagraphField(string $entity_type, string $bundle, string $field_name): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Fresh copy of the node from storage.
   */
  private function reload(int $nid): NodeInterface {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('paragraph')->resetCache();
    $storage = $entity_type_manager->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * Paragraph IDs on a node translation's layouts field.
   */
  private function ids(NodeInterface $translation): array {
    return array_map('intval', array_column($translation->get(self::LAYOUTS)->getValue(), 'target_id'));
  }

  /**
   * Every stored paragraph ID.
   */
  private function allParagraphIds(): array {
    $ids = array_map('intval', array_values($this->container->get('entity_type.manager')
      ->getStorage('paragraph')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute()));
    sort($ids);
    return $ids;
  }

  /**
   * Stored layouts-field rows of one language.
   */
  private function rowCount(int $nid, string $langcode): int {
    return (int) $this->container->get('database')
      ->select('node__' . self::LAYOUTS, 'f')
      ->condition('f.entity_id', $nid)
      ->condition('f.langcode', $langcode)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * The texts a translation renders, nested text first, in its language.
   */
  private function texts(NodeInterface $translation): array {
    $texts = [];
    foreach ($this->rendered($translation) as $paragraph) {
      if ($paragraph->hasField('field_children')) {
        // Children by the untranslated item: in place, a translation of the
        // shared container does not repeat its child references.
        $child = $paragraph->getUntranslated()->get('field_children')->entity;
        $langcode = $translation->language()->getId();
        $child = $child->hasTranslation($langcode) ? $child->getTranslation($langcode) : $child;
        $texts[] = $child->get('field_text')->value;
        continue;
      }
      $texts[] = $paragraph->get('field_text')->value;
    }
    return $texts;
  }

  /**
   * The page-filled topic on the translation's top-level text section.
   */
  private function topic(NodeInterface $translation): ?string {
    foreach ($this->rendered($translation) as $paragraph) {
      if ($paragraph->hasField('field_topic')) {
        return $paragraph->get('field_topic')->value;
      }
    }
    return NULL;
  }

  /**
   * Top-level paragraphs of a translation, each in the translation's language.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   The paragraphs.
   */
  private function rendered(NodeInterface $translation): array {
    $langcode = $translation->language()->getId();
    $out = [];
    foreach ($translation->get(self::LAYOUTS)->referencedEntities() as $paragraph) {
      $out[] = $paragraph->hasTranslation($langcode) ? $paragraph->getTranslation($langcode) : $paragraph;
    }
    return $out;
  }

  /**
   * Every paragraph of the translation's tree is single-language, its own.
   */
  private function assertTreeLanguage(NodeInterface $translation, string $langcode): void {
    foreach ($translation->get(self::LAYOUTS)->referencedEntities() as $paragraph) {
      $this->assertSame([$langcode], array_keys($paragraph->getTranslationLanguages()));
      if ($paragraph->hasField('field_children')) {
        foreach ($paragraph->get('field_children')->referencedEntities() as $child) {
          $this->assertSame([$langcode], array_keys($child->getTranslationLanguages()));
        }
      }
    }
  }

}
