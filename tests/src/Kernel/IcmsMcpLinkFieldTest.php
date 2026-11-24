<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Kernel;

use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\system\Entity\Menu;

/**
 * Link-field values the import writes are URIs Url::fromUri() accepts.
 *
 * A link to a source file reached the target as a bare path
 * (`/sites/default/files/…`): the link pass replaced the source URL with the
 * root-relative file URL as is. Url::fromUri() then threw on every load of
 * the paragraph (entity usage, GraphQL link resolvers). Covers the import
 * (default language, a detached translation's own tree, nested paragraphs),
 * the link pass and the reference pass.
 *
 * Detached translations need icms_core_logic's presave hook, which is not a
 * Composer dependency of this module: run the test from an ICMS project.
 *
 * @group icms_mcp
 */
class IcmsMcpLinkFieldTest extends KernelTestBase {

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
   * The source URL of a document and the file URL the target reported.
   */
  private const SOURCE_PDF = 'https://www.source.example/sites/default/files/2024-05/report.pdf';
  private const TARGET_PDF = '/sites/default/files/2024-05/report.pdf';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'menu_link_content',
    'node',
    'language',
    'content_translation',
    'entity_reference_revisions',
    'paragraphs',
    'paragraphs_blokkli',
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
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language']);

    foreach (['de', 'fr', 'it'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ParagraphsType::create(['id' => 'button', 'label' => 'Button'])->save();
    ParagraphsType::create(['id' => 'container', 'label' => 'Container'])->save();
    foreach ([['node', 'page'], ['paragraph', 'button'], ['paragraph', 'container']] as [$entity_type, $bundle]) {
      ContentLanguageSettings::loadByEntityTypeBundle($entity_type, $bundle)
        ->setLanguageAlterable(TRUE)
        ->setThirdPartySetting('content_translation', 'enabled', TRUE)
        ->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_icms_link',
      'entity_type' => 'paragraph',
      'type' => 'link',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_icms_link',
      'entity_type' => 'paragraph',
      'bundle' => 'button',
      'translatable' => TRUE,
    ])->save();
    // A link field on the node itself.
    FieldStorageConfig::create([
      'field_name' => 'field_page_link',
      'entity_type' => 'node',
      'type' => 'link',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_page_link',
      'entity_type' => 'node',
      'bundle' => 'page',
      'translatable' => TRUE,
    ])->save();
    $this->createParagraphField('paragraph', 'container', 'field_children');
    $this->createParagraphField('node', 'page', self::LAYOUTS);
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    // The import logs each pivot to private files; not needed here.
    $this->container->get('state')->set('icms_mcp.log_pivots', FALSE);

    $this->operations = $this->container->get('icms_mcp.operations');
  }

  /**
   * The import stores bare paths as `internal:` and leaves out the rest.
   *
   * Default language, nested paragraph and a detached translation's own tree
   * alike; what cannot be made valid is a warning, not a stored value.
   */
  public function testImportStoresOnlyValidUris(): void {
    $links = [
      ['uri' => self::TARGET_PDF, 'title' => 'Report'],
      ['uri' => '#kontakt'],
      ['uri' => '?page=2'],
      ['uri' => 'https://example.com/x'],
      ['uri' => 'entity:node/1'],
      ['uri' => '//cdn.example/x.pdf'],
      ['uri' => 'mailto:info@example.com'],
      ['uri' => '<front>'],
      ['uri' => '<nolink>'],
      ['uri' => 'www.example.com'],
      ['uri' => 'tel:117'],
    ];
    $result = $this->importDefault($links, '/nested/path');

    $node = $this->reload((int) $result['nid']);
    [$button, $nested] = $this->buttons($node);
    $this->assertSame([
      'internal:' . self::TARGET_PDF,
      'internal:#kontakt',
      'internal:?page=2',
      'https://example.com/x',
      'entity:node/1',
      '//cdn.example/x.pdf',
      'mailto:info@example.com',
      'internal:/',
      'route:<nolink>',
    ], $this->uris($button));
    $this->assertSame('Report', $button->get('field_icms_link')->first()->get('title')->getValue());
    $this->assertSame(['internal:/nested/path'], $this->uris($nested));
    $this->assertCount(2, $result['warnings']);
    $this->assertStringContainsString("'www.example.com'", $result['warnings'][0]);
    $this->assertStringContainsString('field_icms_link', $result['warnings'][0]);
    $this->assertStringContainsString("'tel:117'", $result['warnings'][1]);
    $this->assertStringContainsString('host and port', $result['warnings'][1]);
    $this->assertUrisResolve($button, $nested);

    // A detached translation's own tree goes through the same writer.
    $it_links = [['uri' => '/it/documento.pdf'], ['uri' => 'internal:https://evil.example']];
    $summary = $this->writeItalian((int) $result['nid'], $it_links, '#it');
    $this->assertSame('detached', $summary[0]['paragraphs_mode']);
    [$it_button, $it_nested] = $this->buttons($this->reload((int) $result['nid'])->getTranslation('it'));
    $this->assertSame('it', $it_button->language()->getId());
    $this->assertSame(['internal:/it/documento.pdf'], $this->uris($it_button));
    $this->assertSame(['internal:#it'], $this->uris($it_nested));
    $this->assertUrisResolve($it_button, $it_nested);
  }

  /**
   * Through the tool: every language's link warnings reach `warnings`.
   *
   * A node link field, the default language's sections and a detached
   * translation's own tree report what they left out, next to the redirect
   * warning; a translation whose tree rolls back loses its link warning with
   * it (its sections are cloned, nothing of the failed tree was stored).
   */
  public function testImportPivotReportsLinkWarnings(): void {
    $italian = $this->translationSpec('it', [['uri' => '/it/doc.pdf'], ['uri' => 'www.it.example']], '/it/n');
    $french = $this->translationSpec('fr', [['uri' => 'www.fr.example']], '/fr/n');
    // The second section fails after the first recorded its link warning.
    $french['paragraphs'][1]['attributes']['field_missing'] = 'x';

    $result = $this->operations->execute('import_pivot', [
      'pivot' => [
        'format' => 'icms-drupal-import-handoff-v1',
        'metadata' => ['idempotence_key' => 'https://www.source.example/de/seite#1', 'strategy' => 'update'],
        'drupal_import' => [
          'node' => [
            'type' => 'node--page',
            'attributes' => [
              'title' => 'DE',
              'langcode' => 'de',
              IcmsMcpOperations::DETACHED_CONTENT_FLAG => TRUE,
              'field_page_link' => [['uri' => '/kontakt'], ['uri' => 'www.node.example']],
            ],
            'redirects' => [['source' => '/alt/seite', 'langcode' => 'de']],
          ],
          'paragraphs' => $this->paragraphSpecs([['uri' => self::SOURCE_PDF]], '/n'),
          'translations' => [$italian, $french],
        ],
      ],
    ]);

    $this->assertSame('ok', $result['status'], print_r($result, TRUE));
    $warnings = implode("\n", $result['warnings']);
    $this->assertStringContainsString("'www.node.example'", $warnings);
    $this->assertStringContainsString("'www.it.example'", $warnings);
    $this->assertStringContainsString('redirects:', $warnings, 'The redirect warning is kept.');
    $this->assertStringNotContainsString('www.fr.example', $warnings, 'Rolled back with the failed tree.');
    $summary = array_column($result['translations'], NULL, 'langcode');
    $this->assertSame('detached', $summary['it']['paragraphs_mode']);
    $this->assertSame('cloned', $summary['fr']['paragraphs_mode']);

    $node = $this->reload((int) $result['nid']);
    $this->assertSame(['internal:/kontakt'], array_column($node->get('field_page_link')->getValue(), 'uri'));
    [$it_button, $it_nested] = $this->buttons($node->getTranslation('it'));
    $this->assertSame(['internal:/it/doc.pdf'], $this->uris($it_button));
    $this->assertSame(['internal:/it/n'], $this->uris($it_nested));
    $this->assertUrisResolve($it_button, $it_nested);
  }

  /**
   * A translation of an attached node writes the shared paragraphs' links.
   */
  public function testAttachedTranslationStoresOnlyValidUris(): void {
    $nid = (int) $this->importDefault([['uri' => 'https://example.com/de']], '/de/n', FALSE)['nid'];

    $summary = $this->writeItalian($nid, [['uri' => '/it/doc.pdf'], ['uri' => 'www.example.com']], '#it');

    $this->assertSame('symmetric', $summary[0]['paragraphs_mode']);
    $node = $this->reload($nid);
    [$button, $nested] = $this->buttons($node);
    [$it_button, $it_nested] = $this->buttons($node->getTranslation('it'));
    $this->assertSame($button->id(), $it_button->id(), 'Italian translates the shared paragraph.');
    $this->assertSame('it', $it_button->language()->getId());
    $this->assertSame(['internal:/it/doc.pdf'], $this->uris($it_button));
    $this->assertSame(['internal:#it'], $this->uris($it_nested));
    $this->assertSame(['https://example.com/de'], $this->uris($button));
    $this->assertSame(['internal:/de/n'], $this->uris($nested));
    $this->assertUrisResolve($it_button, $it_nested);
  }

  /**
   * Menu links are stored as a link field stores them; the rest is reported.
   */
  public function testMenuLinksStoreOnlyValidUris(): void {
    $result = $this->invoke('doImportMenuLinks', ['main', [
      ['title' => 'Mail', 'uri' => 'mailto:info@example.com'],
      ['title' => 'Kontakt', 'uri' => '/kontakt'],
      ['title' => 'Web', 'uri' => 'www.example.com'],
      ['title' => 'Notruf', 'uri' => 'tel:117'],
    ], 'https://www.source.example',
    ]);

    $by_title = array_column($result['links'], NULL, 'title');
    $this->assertSame('unresolved', $by_title['Web']['status']);
    $this->assertStringContainsString("'www.example.com'", $by_title['Web']['reason']);
    $this->assertSame('unresolved', $by_title['Notruf']['status']);
    $this->assertStringContainsString('host and port', $by_title['Notruf']['reason']);
    $stored = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('menu_link_content')->loadMultiple() as $link) {
      $stored[$link->label()] = $link->get('link')->uri;
    }
    ksort($stored);
    $this->assertSame(['Kontakt' => 'internal:/kontakt', 'Mail' => 'mailto:info@example.com'], $stored);
  }

  /**
   * The link pass stores a document's root-relative file URL as `internal:`.
   *
   * On the default language, a nested paragraph and a detached translation's
   * own tree; a bare value an earlier import left behind is healed on the
   * way, and a replacement that cannot be stored is not applied.
   */
  public function testLinkPassStoresValidUris(): void {
    $nid = (int) $this->importDefault([['uri' => self::SOURCE_PDF]], self::SOURCE_PDF)['nid'];
    $this->writeItalian($nid, [['uri' => self::SOURCE_PDF]], '/it/alt');
    // What the faulty pass stored before: a bare path, written behind the
    // import's back.
    $it_nested = $this->buttons($this->reload($nid)->getTranslation('it'))[1];
    $it_nested->set('field_icms_link', [
      ['uri' => '/sites/default/files/old.pdf'],
      ['uri' => 'https://www.source.example/alt'],
    ])->save();

    $result = $this->invoke('doRewriteNodeLinks', [$nid, [
      ['from' => self::SOURCE_PDF, 'to' => self::TARGET_PDF],
      ['from' => 'https://www.source.example/alt', 'to' => 'www.example.com'],
    ],
    ]);

    $this->assertSame('ok', $result['status']);
    $this->assertSame(3, $result['replaced']);
    $this->assertSame(1, $result['normalized']);
    $this->assertSame([], $result['unmatched']);
    $this->assertCount(1, $result['warnings']);
    $this->assertStringContainsString("'www.example.com'", $result['warnings'][0]);

    $node = $this->reload($nid);
    [$button, $nested] = $this->buttons($node);
    $this->assertSame(['internal:' . self::TARGET_PDF], $this->uris($button));
    $this->assertSame(['internal:' . self::TARGET_PDF], $this->uris($nested));
    [$it_button, $it_nested] = $this->buttons($node->getTranslation('it'));
    $this->assertSame(['internal:' . self::TARGET_PDF], $this->uris($it_button));
    $this->assertSame(['internal:/sites/default/files/old.pdf', 'https://www.source.example/alt'], $this->uris($it_nested));
    $this->assertUrisResolve($button, $nested, $it_button, $it_nested);

    // Idempotent: a second run finds nothing left to change.
    $again = $this->invoke('doRewriteNodeLinks', [$nid, [['from' => self::SOURCE_PDF, 'to' => self::TARGET_PDF]]]);
    $this->assertSame(0, $again['replaced']);
    $this->assertSame(0, $again['normalized']);
  }

  /**
   * The reference pass writes a bare path as `internal:`, rejects the rest.
   */
  public function testReferencePassStoresOnlyValidUris(): void {
    $nid = (int) $this->importDefault([['uri' => 'https://example.com/a']], '/b')['nid'];

    $result = $this->invoke('doUpdateNodeReferences', [$nid, [
      ['path' => [['index' => 0]], 'field' => 'field_icms_link', 'value' => ['uri' => self::TARGET_PDF]],
      [
        'path' => [['index' => 1], ['field' => 'field_children', 'index' => 0]],
        'field' => 'field_icms_link',
        'value' => ['uri' => 'www.example.com'],
      ],
    ],
    ]);

    $this->assertSame(1, $result['applied']);
    $this->assertCount(1, $result['rejected']);
    $this->assertSame(1, $result['rejected'][0]['index']);
    $this->assertStringContainsString("'www.example.com'", $result['rejected'][0]['reason']);
    [$button, $nested] = $this->buttons($this->reload($nid));
    $this->assertSame(['internal:' . self::TARGET_PDF], $this->uris($button));
    $this->assertSame(['internal:/b'], $this->uris($nested), 'A rejected update leaves the field as it was.');
  }

  /**
   * Import the default language: a button, and a container with a button.
   */
  private function importDefault(array $links, string $nested_uri, bool $detached = TRUE): array {
    $attributes = ['title' => 'DE', 'langcode' => 'de'];
    if ($detached) {
      $attributes[IcmsMcpOperations::DETACHED_CONTENT_FLAG] = TRUE;
    }
    return $this->invoke('writeNodeAndParagraphs', [
      'page',
      $attributes,
      $this->paragraphSpecs($links, $nested_uri),
      'source-key-1',
      NULL,
    ]);
  }

  /**
   * Write the Italian translation, structure-matched, as its own tree.
   */
  private function writeItalian(int $nid, array $links, string $nested_uri): array {
    return $this->invoke('writeTranslations', [$nid, [$this->translationSpec('it', $links, $nested_uri)]]);
  }

  /**
   * A structure-matched translation with the given links.
   */
  private function translationSpec(string $langcode, array $links, string $nested_uri): array {
    return [
      'langcode' => $langcode,
      'structureMatch' => TRUE,
      'node' => ['attributes' => ['title' => strtoupper($langcode)]],
      'paragraphs' => $this->paragraphSpecs($links, $nested_uri),
    ];
  }

  /**
   * Section specs: a button with the links, a container with one button.
   */
  private function paragraphSpecs(array $links, string $nested_uri): array {
    return [
      [
        'sequence' => 0,
        'type' => 'paragraph--button',
        'attributes' => ['field_icms_link' => $links],
        'options' => [],
      ],
      [
        'sequence' => 1,
        'type' => 'paragraph--container',
        'attributes' => [
          'field_children' => [
            ['type' => 'paragraph--button', 'fields' => ['field_icms_link' => ['uri' => $nested_uri]]],
          ],
        ],
        'options' => [],
      ],
    ];
  }

  /**
   * The top-level button and the nested one of a translation.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   The two buttons, each in the translation's language.
   */
  private function buttons(NodeInterface $translation): array {
    $langcode = $translation->language()->getId();
    [$button, $container] = $translation->get(self::LAYOUTS)->referencedEntities();
    $nested = $container->get('field_children')->entity;
    return array_map(
      fn(ParagraphInterface $paragraph): ParagraphInterface => $paragraph->hasTranslation($langcode) ? $paragraph->getTranslation($langcode) : $paragraph,
      [$button, $nested],
    );
  }

  /**
   * The stored link uris of a paragraph.
   */
  private function uris(ParagraphInterface $paragraph): array {
    return array_column($paragraph->get('field_icms_link')->getValue(), 'uri');
  }

  /**
   * Every stored link uri resolves through Url::fromUri().
   */
  private function assertUrisResolve(ParagraphInterface ...$paragraphs): void {
    foreach ($paragraphs as $paragraph) {
      foreach ($this->uris($paragraph) as $uri) {
        $this->assertInstanceOf(Url::class, Url::fromUri($uri), $uri);
      }
    }
  }

  /**
   * Call a protected method of the (final) import service.
   */
  private function invoke(string $method, array $arguments): mixed {
    return (new \ReflectionMethod($this->operations, $method))->invokeArgs($this->operations, $arguments);
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

}
