<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Catalog;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;

/**
 * Indexes the site's reference examples of each layout and option combination.
 *
 * A project that builds a showcase — one page per component, one section per
 * option combination — declares its root page in `icms_mcp.catalog_examples`.
 * This walks that page and its menu children and lists every top-level
 * layout paragraph with the options it is stored with, its id and where it
 * sits on the page, so a migration can show (and screenshot) what a bundle with a
 * given set of options actually looks like instead of guessing from a name.
 *
 * Only what an anonymous visitor can open is listed: the examples are meant
 * to be looked at on the public page.
 */
final class LayoutExampleIndex {

  /**
   * Behavior-settings key blökkli stores a paragraph's options under.
   */
  private const OPTIONS_KEY = 'paragraphs_blokkli_data';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly IcmsCatalogBuilder $catalogBuilder,
    private readonly ?MenuLinkTreeInterface $menuTree = NULL,
    private readonly ?MenuLinkManagerInterface $menuLinkManager = NULL,
  ) {}

  /**
   * Whether the site declares a showcase root.
   */
  public function configured(): bool {
    return trim((string) ($this->configFactory->get('icms_mcp.catalog_examples')->get('root') ?? '')) !== '';
  }

  /**
   * Build the index.
   *
   * @param string $layoutsField
   *   The node field holding the layout paragraphs.
   * @param string[] $bundles
   *   Only these paragraph bundles (empty = all).
   * @param string $langcode
   *   Language to read (empty = the configured one, then the root's own).
   * @param int $maxPages
   *   Upper bound on indexed pages.
   */
  public function build(string $layoutsField, array $bundles = [], string $langcode = '', int $maxPages = 60): array {
    $config = $this->configFactory->get('icms_mcp.catalog_examples');
    $rootSetting = trim((string) ($config->get('root') ?? ''));
    if ($rootSetting === '') {
      return [
        'status' => 'not_configured',
        'format' => 'icms-layout-examples-v1',
        'reason' => 'No showcase root is declared. Set icms_mcp.catalog_examples: root to the path or uuid of the page whose menu children show each component.',
        'examples' => [],
      ];
    }
    $root = $this->loadRoot($rootSetting);
    if ($root === NULL) {
      return [
        'status' => 'error',
        'format' => 'icms-layout-examples-v1',
        'reason' => "The configured showcase root '{$rootSetting}' does not resolve to a node on this site.",
        'examples' => [],
      ];
    }
    $langcode = $langcode !== '' ? $langcode : trim((string) ($config->get('langcode') ?? ''));
    if ($langcode === '') {
      $langcode = $root->language()->getId();
    }
    $maxPages = max(1, min($maxPages, 200));
    $wanted = array_values(array_filter(array_map('strval', $bundles)));

    $nids = [(int) $root->id()];
    foreach ($this->menuDescendants($root, (string) ($config->get('menu') ?? ''), (int) ($config->get('max_depth') ?? 3)) as $nid) {
      if (!in_array($nid, $nids, TRUE)) {
        $nids[] = $nid;
      }
    }

    $anonymous = new AnonymousUserSession();
    $prefix = self::languagePrefix($this->configFactory->get('language.negotiation')->get('url.prefixes'), $langcode);
    $aliasManager = \Drupal::hasService('path_alias.manager') ? \Drupal::service('path_alias.manager') : NULL;
    $pages = [];
    $examples = [];
    $skipped = [];
    $definitionCache = [];
    foreach ($nids as $nid) {
      if (count($pages) >= $maxPages) {
        $skipped[] = ['nid' => $nid, 'reason' => 'page_limit'];
        continue;
      }
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$node->hasTranslation($langcode)) {
        $skipped[] = ['nid' => $nid, 'reason' => 'not_translated'];
        continue;
      }
      $page = $node->getTranslation($langcode);
      if (!$page->isPublished() || !$page->access('view', $anonymous)) {
        $skipped[] = ['nid' => $nid, 'reason' => 'not_public'];
        continue;
      }
      if (!$page->hasField($layoutsField)) {
        continue;
      }
      $alias = $aliasManager !== NULL ? (string) $aliasManager->getAliasByPath('/node/' . $nid, $langcode) : '/node/' . $nid;
      $path = self::frontendPath($prefix, $alias);
      $rendered = [];
      foreach ($page->get($layoutsField) as $item) {
        $paragraph = $item->entity ?? NULL;
        if (!$paragraph instanceof FieldableEntityInterface) {
          continue;
        }
        $view = $paragraph->hasTranslation($langcode) ? $paragraph->getTranslation($langcode) : $paragraph;
        if (method_exists($view, 'isPublished') && !$view->isPublished()) {
          continue;
        }
        $rendered[] = $view;
      }
      if (!$rendered) {
        continue;
      }
      $pages[] = ['nid' => $nid, 'title' => (string) $page->label(), 'path' => $path, 'langcode' => $langcode, 'paragraphs' => count($rendered)];
      foreach ($rendered as $index => $view) {
        $bundle = $view->bundle();
        if ($wanted && !in_array($bundle, $wanted, TRUE)) {
          continue;
        }
        $definitionCache[$bundle] ??= $this->catalogBuilder->optionDefinitionsForBundle($bundle) ?? [];
        $stored = self::storedOptions($view);
        $examples[] = [
          'id' => (string) $view->uuid(),
          // The ICMS frontend renders a layout as `<section id="<paragraph
          // id>">`: the surest way to find it again on the public page.
          'paragraphId' => (int) $view->id(),
          'bundle' => $bundle,
          'options' => self::effectiveOptions($stored, $definitionCache[$bundle]),
          'storedOptions' => $stored,
          'pageNid' => $nid,
          'pagePath' => $path,
          'ordinal' => $index + 1,
          'siblingCount' => count($rendered),
          'title' => self::firstText($view),
          'children' => $this->children($view, $langcode, $definitionCache),
        ];
      }
    }
    return [
      'status' => 'ok',
      'format' => 'icms-layout-examples-v1',
      'indexHash' => self::indexHash($examples),
      'langcode' => $langcode,
      'root' => ['nid' => (int) $root->id(), 'title' => (string) $root->label()],
      'pages' => $pages,
      'examples' => $examples,
      'skipped' => $skipped,
    ];
  }

  /**
   * The root page: a node uuid, a node id, `/node/<id>` or a path alias.
   */
  private function loadRoot(string $setting): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $setting)) {
      $nodes = $storage->loadByProperties(['uuid' => $setting]);
      $node = reset($nodes);
      return $node instanceof NodeInterface ? $node : NULL;
    }
    $path = ctype_digit($setting) ? '/node/' . $setting : '/' . ltrim($setting, '/');
    if (!preg_match('#^/node/\d+$#', $path) && \Drupal::hasService('path_alias.manager')) {
      $manager = \Drupal::service('path_alias.manager');
      $internal = (string) $manager->getPathByAlias($path);
      if ($internal === $path) {
        // The alias may only exist in a language other than the current one.
        foreach (array_keys(\Drupal::languageManager()->getLanguages()) as $langcode) {
          $internal = (string) $manager->getPathByAlias($path, $langcode);
          if ($internal !== $path) {
            break;
          }
        }
      }
      $path = $internal;
    }
    if (!preg_match('#^/node/(\d+)$#', $path, $m)) {
      return NULL;
    }
    $node = $storage->load((int) $m[1]);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Node ids linked below the root in the menu, in menu order.
   *
   * @return int[]
   */
  private function menuDescendants(NodeInterface $root, string $menu, int $maxDepth): array {
    if ($this->menuTree === NULL || $this->menuLinkManager === NULL) {
      return [];
    }
    $maxDepth = max(1, min($maxDepth ?: 3, 6));
    $links = $this->menuLinkManager->loadLinksByRoute('entity.node.canonical', ['node' => $root->id()], $menu !== '' ? $menu : NULL);
    $nids = [];
    foreach ($links as $link) {
      $parameters = (new MenuTreeParameters())
        ->setRoot($link->getPluginId())
        ->excludeRoot()
        ->setMaxDepth($maxDepth)
        ->onlyEnabledLinks();
      $tree = $this->menuTree->load($link->getMenuName(), $parameters);
      $tree = $this->menuTree->transform($tree, [['callable' => 'menu.default_tree_manipulators:generateIndexAndSort']]);
      $this->collectNodeIds($tree, $nids);
    }
    return array_values(array_unique($nids));
  }

  /**
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   * @param int[] $nids
   */
  private function collectNodeIds(array $tree, array &$nids): void {
    foreach ($tree as $element) {
      $url = $element->link->getUrlObject();
      if ($url->isRouted() && $url->getRouteName() === 'entity.node.canonical') {
        $nid = (int) ($url->getRouteParameters()['node'] ?? 0);
        if ($nid > 0) {
          $nids[] = $nid;
        }
      }
      if ($element->subtree) {
        $this->collectNodeIds($element->subtree, $nids);
      }
    }
  }

  /**
   * A layout's nested paragraphs: `[{bundle, options}]`, first twelve.
   *
   * A cards section's look depends on the card it holds as much as on its
   * own options, so the children ride along.
   */
  private function children(FieldableEntityInterface $paragraph, string $langcode, array &$definitionCache): array {
    $children = [];
    foreach ($paragraph->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions' || (string) ($definition->getSetting('target_type') ?? '') !== 'paragraph') {
        continue;
      }
      foreach ($paragraph->get($name) as $item) {
        $child = $item->entity ?? NULL;
        if (!$child instanceof FieldableEntityInterface) {
          continue;
        }
        $view = $child->hasTranslation($langcode) ? $child->getTranslation($langcode) : $child;
        if (method_exists($view, 'isPublished') && !$view->isPublished()) {
          continue;
        }
        $bundle = $view->bundle();
        $definitionCache[$bundle] ??= $this->catalogBuilder->optionDefinitionsForBundle($bundle) ?? [];
        $children[] = ['bundle' => $bundle, 'options' => self::effectiveOptions(self::storedOptions($view), $definitionCache[$bundle])];
        if (count($children) >= 12) {
          return $children;
        }
      }
    }
    return $children;
  }

  /**
   * The options a paragraph is stored with, as blökkli saved them.
   */
  private static function storedOptions(FieldableEntityInterface $paragraph): array {
    if (!method_exists($paragraph, 'getAllBehaviorSettings')) {
      return [];
    }
    $stored = $paragraph->getAllBehaviorSettings()[self::OPTIONS_KEY] ?? [];
    if (!is_array($stored)) {
      return [];
    }
    ksort($stored);
    return $stored;
  }

  /**
   * The first short plain-text value of a paragraph, as a label for a reviewer.
   */
  private static function firstText(FieldableEntityInterface $paragraph): string {
    foreach ($paragraph->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'string' || $definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }
      $value = trim((string) ($paragraph->get($name)->value ?? ''));
      if ($value !== '') {
        return mb_substr($value, 0, 120);
      }
    }
    return '';
  }

  /**
   * The options a paragraph renders with: the schema defaults, then what is stored.
   *
   * blökkli stores only what an editor changed, and stores everything as a
   * string. The result names every option the bundle has, typed as the
   * catalog types it (`true` for a checkbox stored as "1", `3` for a number),
   * so two examples compare value by value. A stored option the schema no
   * longer knows is dropped; without a schema the stored values pass as-is.
   */
  public static function effectiveOptions(array $stored, array $definitions): array {
    if ($definitions === []) {
      ksort($stored);
      return $stored;
    }
    $options = [];
    foreach ($definitions as $name => $definition) {
      $type = (string) ($definition['type'] ?? 'radios');
      $value = array_key_exists($name, $stored) ? $stored[$name] : ($definition['default'] ?? NULL);
      if ($value === NULL || $value === '') {
        continue;
      }
      if ($type === 'checkbox') {
        $value = is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], TRUE);
      }
      elseif ($type === 'number' || $type === 'range') {
        $value = is_numeric($value) ? $value + 0 : $value;
      }
      elseif (is_scalar($value)) {
        $value = (string) $value;
      }
      $options[(string) $name] = $value;
    }
    ksort($options);
    return $options;
  }

  /**
   * The URL prefix of a language (`de` → `de`), '' when it has none.
   */
  public static function languagePrefix(mixed $prefixes, string $langcode): string {
    $prefix = is_array($prefixes) ? ($prefixes[$langcode] ?? '') : '';
    return is_string($prefix) ? trim($prefix, '/') : '';
  }

  /**
   * The public path of a page: language prefix, then its alias.
   */
  public static function frontendPath(string $prefix, string $alias): string {
    $alias = '/' . ltrim($alias, '/');
    return ($prefix !== '' ? '/' . $prefix : '') . ($alias === '/' ? ($prefix !== '' ? '' : '/') : $alias);
  }

  /**
   * A hash that changes when an example moves, appears, disappears or changes options.
   */
  public static function indexHash(array $examples): string {
    $rows = [];
    foreach ($examples as $example) {
      $options = is_array($example['options'] ?? NULL) ? $example['options'] : [];
      ksort($options);
      $rows[] = [
        (string) ($example['id'] ?? ''),
        (string) ($example['bundle'] ?? ''),
        (string) ($example['pagePath'] ?? ''),
        (int) ($example['ordinal'] ?? 0),
        (int) ($example['siblingCount'] ?? 0),
        $options,
        $example['children'] ?? [],
      ];
    }
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

}
