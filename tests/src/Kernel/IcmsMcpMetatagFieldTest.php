<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\icms_mcp\Service\FieldValueReader;
use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * The import writes a node's `metatag` field, per language.
 *
 * Needs drupal/metatag, drupal/token and the paragraphs stack, which are no
 * Composer dependency of this module: the test skips itself when one is
 * missing, so run it from an ICMS project.
 *
 * @group icms_mcp
 */
class IcmsMcpMetatagFieldTest extends KernelTestBase {

  /**
   * One class per contributed module of $modules, to detect a missing one.
   */
  private const REQUIRED_CLASSES = [
    'entity_reference_revisions' => 'Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList',
    'paragraphs' => 'Drupal\paragraphs\Entity\Paragraph',
    'paragraphs_blokkli' => 'Drupal\paragraphs_blokkli\BlokkliOptionsSchemaHelper',
    'search_api' => 'Drupal\search_api\Entity\Index',
    'metatag' => 'Drupal\metatag\Plugin\Field\FieldType\MetatagFieldItem',
    'token' => 'Drupal\token\Token',
  ];

  private const LAYOUTS = 'field_icms_paragraphs';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'node',
    'language',
    'content_translation',
    'entity_reference_revisions',
    'paragraphs',
    'paragraphs_blokkli',
    'search_api',
    'token',
    'metatag',
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

    foreach (['de', 'it', 'fr'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ParagraphsType::create(['id' => 'button', 'label' => 'Button'])->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'page')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_metatags',
      'entity_type' => 'node',
      'type' => 'metatag',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_metatags',
      'entity_type' => 'node',
      'bundle' => 'page',
      'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => self::LAYOUTS,
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => self::LAYOUTS,
      'entity_type' => 'node',
      'bundle' => 'page',
      'translatable' => TRUE,
    ])->save();
    $this->container->get('state')->set('icms_mcp.log_pivots', FALSE);
    $this->operations = $this->container->get('icms_mcp.operations');
  }

  /**
   * A map is stored encoded and read back as the same map.
   */
  public function testMapIsStoredAndReadBack(): void {
    $tags = ['description' => 'Eine Beschreibung', 'robots' => 'noindex'];
    $nid = (int) $this->import($tags)['nid'];

    $node = $this->reload($nid);
    $this->assertIsString($node->get('field_metatags')->value);
    $this->assertSame($tags, metatag_data_decode($node->get('field_metatags')->value));
    $this->assertSame($tags, FieldValueReader::readFields($node, ['field_metatags'])['field_metatags']);
    $this->assertSame($tags, FieldValueReader::readFields($node, [])['field_metatags']);
  }

  /**
   * The one-item list and the encoded string store the same value.
   */
  public function testOtherShapesStoreTheSame(): void {
    $tags = ['description' => 'Same'];
    $shapes = [[['value' => $tags]], [['value' => json_encode($tags)]], json_encode($tags)];
    foreach ($shapes as $i => $shape) {
      $node = $this->reload((int) $this->import($shape, "key-shape-{$i}")['nid']);
      $this->assertSame($tags, FieldValueReader::readFields($node, ['field_metatags'])['field_metatags']);
    }
  }

  /**
   * Each translation keeps its own tags.
   */
  public function testTranslationsKeepTheirOwnValues(): void {
    $nid = (int) $this->import(['description' => 'Deutsch'])['nid'];

    $this->invoke('writeTranslations', [$nid, [$this->translation('it', 'IT', ['description' => 'Italiano'])]]);

    $node = $this->reload($nid);
    $this->assertSame(['description' => 'Deutsch'], FieldValueReader::readFields($node, ['field_metatags'])['field_metatags']);
    $italian = $node->getTranslation('it');
    $this->assertSame(['description' => 'Italiano'], FieldValueReader::readFields($italian, ['field_metatags'])['field_metatags']);
  }

  /**
   * An empty map clears the field on re-import.
   */
  public function testEmptyMapClears(): void {
    $nid = (int) $this->import(['description' => 'Weg damit'])['nid'];
    $this->assertFalse($this->reload($nid)->get('field_metatags')->isEmpty());

    $result = $this->import([], 'source-key-1', $nid);

    $this->assertSame($nid, (int) $result['nid']);
    $node = $this->reload($nid);
    $this->assertTrue($node->get('field_metatags')->isEmpty());
    $this->assertSame([], FieldValueReader::readFields($node, ['field_metatags'])['field_metatags']);
  }

  /**
   * Through import_pivot: a translation without the attribute has no tags.
   *
   * The engine relies on it: a language it sends no tags for must not show
   * the default language's (or stale) tags.
   */
  public function testTranslationWithoutAttributeIsEmpty(): void {
    $result = $this->operations->execute('import_pivot', [
      'pivot' => $this->pivot(
      ['description' => 'Deutsch'],
      [
        $this->translation('it', 'IT'),
        $this->translation('fr', 'FR', ['description' => 'Français']),
      ],
      ),
    ]);

    $this->assertSame('ok', $result['status'], print_r($result, TRUE));
    $node = $this->reload((int) $result['nid']);
    $this->assertSame(['description' => 'Deutsch'], $this->tags($node));
    $this->assertTrue($node->hasTranslation('it'));
    $this->assertTrue($node->getTranslation('it')->get('field_metatags')->isEmpty());
    $this->assertSame(['description' => 'Français'], $this->tags($node->getTranslation('fr')));

    // A re-import that sends no tags for Italian leaves it empty as well.
    $again = $this->operations->execute('import_pivot', [
      'pivot' => $this->pivot(
      ['description' => 'Deutsch 2'],
      [$this->translation('it', 'IT')],
      ),
    ]);
    $this->assertSame('ok', $again['status'], print_r($again, TRUE));
    $this->assertTrue($this->reload((int) $again['nid'])->getTranslation('it')->get('field_metatags')->isEmpty());
  }

  /**
   * A bad translation value is rejected before anything is written.
   */
  public function testBadTranslationValueIsRejectedBeforeWriting(): void {
    $result = $this->operations->execute('import_pivot', [
      'pivot' => $this->pivot(
      ['description' => 'Deutsch'],
      [
        $this->translation('it', 'IT', ['description' => 'ok']),
        $this->translation('fr', 'FR', [['value' => []], ['value' => []]]),
      ],
      ),
    ]);

    $this->assertSame('invalid', $result['status']);
    $issue = $result['issues'][0];
    $this->assertSame('invalid_metatag', $issue['code']);
    $this->assertSame('/drupal_import/translations/1/node/attributes/field_metatags', $issue['path']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame([], $storage->loadMultiple(), 'Nothing was written.');
  }

  /**
   * A bad value that reaches the writer names the field and the language.
   */
  public function testWriteTimeErrorNamesFieldAndLanguage(): void {
    $nid = (int) $this->import(['description' => 'Deutsch'])['nid'];
    try {
      $this->invoke('writeTranslations', [$nid, [$this->translation('it', 'IT', 5)]]);
      $this->fail('Expected an exception.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString("Field 'field_metatags' on node:page (it)", $e->getMessage());
    }
  }

  /**
   * The catalog advertises the capability.
   */
  public function testCatalogAdvertisesMetatagFields(): void {
    $catalog = $this->operations->execute('get_icms_catalog', []);
    $this->assertSame(['supported' => TRUE], $catalog['metatagFields']);
  }

  /**
   * An unwrapped field item is unwrapped, not stored as a tag named "value".
   */
  public function testBareValueItemIsUnwrapped(): void {
    $tags = ['description' => 'Unwrapped'];
    foreach ([['value' => $tags], ['value' => json_encode($tags)]] as $i => $shape) {
      $node = $this->reload((int) $this->import($shape, "key-bare-{$i}")['nid']);
      $this->assertSame($tags, $this->tags($node));
    }
  }

  /**
   * Metatag's preSave sorts the tags: the read-back is ordered by key.
   */
  public function testReadBackIsSortedByKey(): void {
    $nid = (int) $this->import(['title' => 'T', 'description' => 'D', 'abstract' => 'A'])['nid'];
    $this->assertSame(['abstract', 'description', 'title'], array_keys($this->tags($this->reload($nid))));
  }

  /**
   * An invalid shape is rejected by validate.
   *
   * @dataProvider invalidProvider
   */
  public function testInvalidShapeIsRejected(mixed $value): void {
    $result = $this->invoke('doValidatePivot', [$this->pivot($value)]);
    $codes = array_column($result['issues'], 'code', 'path');
    $this->assertSame('invalid_metatag', $codes['/drupal_import/node/attributes/field_metatags'] ?? NULL, print_r($result, TRUE));
    $messages = implode(' ', array_column($result['issues'], 'message'));
    $this->assertStringContainsString("Field 'field_metatags' takes meta tags", $messages);
  }

  /**
   * Provider.
   */
  public static function invalidProvider(): array {
    return [
      'scalar' => [5],
      'several items' => [[['value' => ['a' => 'b']], ['value' => ['c' => 'd']]]],
      'numeric tag name' => [['x' => 'y', 7 => 'z']],
      'not decodable string' => ['plain text'],
      'bare value with junk' => [['value' => 'plain text']],
      'encoded string with a number' => ['{"description":5}'],
      'invalid utf-8' => [['description' => "\xB1\x31"]],
    ];
  }

  /**
   * A valid value passes validate.
   */
  public function testValidShapePasses(): void {
    $result = $this->invoke('doValidatePivot', [$this->pivot(['description' => 'ok'])]);
    $this->assertSame([], $result['issues'], print_r($result, TRUE));
  }

  /**
   * Import (or update) a page carrying the given metatag value.
   */
  private function import(mixed $value, string $key = 'source-key-1', ?int $nid = NULL): array {
    return $this->invoke('writeNodeAndParagraphs', [
      'page',
      ['title' => 'DE', 'langcode' => 'de', 'field_metatags' => $value],
      [],
      $key,
      $nid,
    ]);
  }

  /**
   * A pivot with the given metatag value on the node.
   */
  private function pivot(mixed $value, array $translations = []): array {
    return [
      'format' => 'icms-drupal-import-handoff-v1',
      'metadata' => ['idempotence_key' => 'https://www.source.example/de/seite#1', 'strategy' => 'update'],
      'drupal_import' => [
        'node' => [
          'type' => 'node--page',
          'attributes' => ['title' => 'T', 'langcode' => 'de', 'field_metatags' => $value],
        ],
        'paragraphs' => [],
        'translations' => $translations,
      ],
    ];
  }

  /**
   * A translation spec; the metatag attribute is left out when NULL.
   */
  private function translation(string $langcode, string $title, mixed $tags = NULL): array {
    $attributes = ['title' => $title];
    if ($tags !== NULL) {
      $attributes['field_metatags'] = $tags;
    }
    return ['langcode' => $langcode, 'node' => ['attributes' => $attributes]];
  }

  /**
   * The metatag field of a node (translation) as the read-back returns it.
   */
  private function tags(NodeInterface $node): array {
    return FieldValueReader::readFields($node, ['field_metatags'])['field_metatags'];
  }

  /**
   * Call a protected method of the import service.
   */
  private function invoke(string $method, array $arguments): mixed {
    return (new \ReflectionMethod($this->operations, $method))->invokeArgs($this->operations, $arguments);
  }

  /**
   * Fresh copy of the node from storage.
   */
  private function reload(int $nid): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

}
