<?php

declare(strict_types=1);

namespace Drupal\icms_mcp\Catalog;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\paragraphs_blokkli\BlokkliOptionsSchemaHelper;
use Symfony\Component\Yaml\Yaml;

/** Builds compact ICMS catalog manifests and targeted component contracts. */
final class IcmsCatalogBuilder {

  private ?array $descriptionCache = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ?BlokkliOptionsSchemaHelper $schemaHelper = NULL,
  ) {}

  /**
   * Languages enabled on this target, and the default one.
   *
   * A migration's source languages have to exist here before translations can
   * land; without this the "enable these languages" checklist item has no
   * closing test.
   */
  private function languages(): array {
    $enabled = array_keys($this->languageManager->getLanguages());
    sort($enabled);
    return [
      'enabled' => array_values($enabled),
      'default' => $this->languageManager->getDefaultLanguage()->getId(),
    ];
  }

  /**
   * The roles a migrated account can be given on this site.
   *
   * The migration's role gate maps each SOURCE role onto one of these, so the
   * gate needs the list before any user is imported. `anonymous` and
   * `authenticated` are omitted: they are implicit on every account and never
   * a mapping decision.
   */
  private function webforms(): array {
    if (!$this->entityTypeManager->hasDefinition('webform')) {
      return [];
    }
    return self::webformEntries($this->entityTypeManager->getStorage('webform')->loadMultiple());
  }

  /**
   * `[{id, label}]` for webform config entities, sorted by id; example and
   * template forms the module ships are left out.
   *
   * @param iterable<\Drupal\Core\Entity\EntityInterface> $webforms
   *   Loaded webform entities.
   */
  public static function webformEntries(iterable $webforms): array {
    $entries = [];
    foreach ($webforms as $webform) {
      $id = (string) $webform->id();
      if ($id === '' || str_starts_with($id, 'example_') || str_starts_with($id, 'template_')) {
        continue;
      }
      $entries[$id] = ['id' => $id, 'label' => (string) $webform->label()];
    }
    ksort($entries);
    return array_values($entries);
  }

  private function roles(): array {
    if (!$this->entityTypeManager->hasDefinition('user_role')) {
      return [];
    }
    $roles = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if (in_array($role->id(), ['anonymous', 'authenticated'], TRUE)) {
        continue;
      }
      $roles[] = ['id' => $role->id(), 'label' => (string) $role->label()];
    }
    return $roles;
  }

  /**
   * Whether content translation is enabled for a bundle.
   *
   * Read from config rather than the content_translation API so the catalog
   * still builds on a site without that module installed. A translatable field
   * on a bundle that has translation switched off never gets translated.
   */
  private function contentTranslationEnabled(string $entityType, string $bundle): bool {
    $settings = $this->configFactory
      ->get('language.content_settings.' . $entityType . '.' . $bundle)
      ->get('third_party_settings');
    return (bool) ($settings['content_translation']['enabled'] ?? FALSE);
  }

  /** Return the compact, normalized catalog manifest. */
  public function buildManifest(string $sourceField, string $layoutsField): array {
    $descriptions = $this->descriptions();
    $options = $this->options();
    $fieldDefinitions = [];
    $indexes = [];
    foreach (['node', 'paragraph', 'media'] as $entityType) {
      if (!$this->entityTypeManager->hasDefinition($entityType)) {
        $indexes[$entityType . 'Types'] = [];
        continue;
      }
      $bundles = $this->bundleInfo->getBundleInfo($entityType);
      $index = [];
      foreach ($bundles as $bundle => $info) {
        $fields = $this->configuredFields($entityType, $bundle);
        foreach ($fields as $name => $definition) {
          $key = $entityType . '.' . $name;
          $fieldDefinitions[$key] ??= $this->storageDefinition($definition);
        }
        $editorial = $descriptions[$entityType][$bundle] ?? [];
        $entry = [
          'id' => $bundle,
          'label' => (string) ($info['label'] ?? $bundle),
          'description' => (string) ($editorial['summary'] ?? ''),
          'fieldRefs' => array_map(fn(string $name): string => $entityType . '.' . $name, array_keys($fields)),
          'capabilities' => $this->capabilities($fields, $editorial),
          'contentTranslationEnabled' => $this->contentTranslationEnabled($entityType, $bundle),
        ];
        if ($entityType === 'paragraph') {
          $entry['role'] = (string) ($editorial['role'] ?? 'component');
          // The bundle's real options come from the frontend-generated blökkli
          // schema (`paragraphs_blokkli.settings: schema_file`): a hero has no
          // spacing, a media block no alignment, a button its own `buttonStyle`.
          // Without a schema on this site the old approximation stands —
          // every global option on every layout bundle, none on elements.
          $schemaOptions = $this->schemaOptions($bundle);
          if ($schemaOptions !== NULL) {
            $entry['optionRefs'] = array_keys($schemaOptions);
            $entry['optionDefinitions'] = $schemaOptions;
          }
          else {
            $entry['optionRefs'] = str_starts_with($bundle, 'icms_layout_') ? array_keys($options) : [];
          }
          $entry['childBundles'] = $this->childBundles($fields);
        }
        $index[$bundle] = $entry;
      }
      ksort($index);
      $indexes[$entityType . 'Types'] = $index;
    }
    ksort($fieldDefinitions);

    $allowedByNodeType = [];
    foreach (array_keys($indexes['nodeTypes']) as $nodeBundle) {
      $nodeDefinitions = $this->fieldManager->getFieldDefinitions('node', $nodeBundle);
      $paragraphSlots = [];
      if (isset($nodeDefinitions[$layoutsField])) {
        $allowed = array_keys($nodeDefinitions[$layoutsField]->getSetting('handler_settings')['target_bundles'] ?? []);
        sort($allowed);
        $paragraphSlots[$layoutsField] = [
          'fieldRef' => 'node.' . $layoutsField,
          'targetBundles' => $allowed,
        ];
        $allowedByNodeType[$nodeBundle] = $allowed;
      }
      $indexes['nodeTypes'][$nodeBundle]['paragraphSlots'] = $paragraphSlots;
    }
    ksort($allowedByNodeType);
    $allowed = $allowedByNodeType['icms_page'] ?? [];

    // Existing taxonomy vocabularies: the migration tool's vocabulary-mapping
    // HITL offers these as "map to" targets. Creating vocabularies is target
    // setup (config-in-git via the dev skill), never an MCP write.
    $vocabularies = [];
    if ($this->entityTypeManager->hasDefinition('taxonomy_vocabulary')) {
      foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $vocabulary) {
        $vocabularies[(string) $vocabulary->id()] = (string) $vocabulary->label();
      }
      ksort($vocabularies);
    }

    // The hand-maintained option list documents; the schema knows the values.
    // A global option whose values the yml leaves empty (`cardVariant`,
    // `teaserVariant`, …) takes them from the first bundle that declares it,
    // so the migration can spell a value the target actually accepts.
    foreach ($indexes['paragraphTypes'] as $entry) {
      foreach ($entry['optionDefinitions'] ?? [] as $name => $definition) {
        if (!isset($options[$name])) {
          continue;
        }
        if (empty($options[$name]['values']) && !empty($definition['values'])) {
          $options[$name]['values'] = $definition['values'];
        }
      }
    }

    $manifest = [
      'status' => 'ok',
      // v3 adds `languages`, per-bundle `contentTranslationEnabled` and a
      // `translatable` flag on each field, so the translation checklist items
      // become verifiable instead of advisory. Readers tolerate v2.
      'format' => 'icms-target-catalog-v3',
      'site' => ['sourceKeyField' => $sourceField, 'layoutsField' => $layoutsField],
      'languages' => $this->languages(),
      'roles' => $this->roles(),
      // The target's webforms, for the section gate's "bind to" dropdown, and
      // whether the module exists at all — a source that ships forms needs it.
      'webforms' => $this->webforms(),
      'webformModuleInstalled' => $this->entityTypeManager->hasDefinition('webform'),
      'vocabularies' => $vocabularies,
      'fieldDefinitions' => $fieldDefinitions,
      'optionDefinitions' => $options,
      'nodeTypes' => $indexes['nodeTypes'],
      'paragraphTypes' => $indexes['paragraphTypes'],
      'mediaTypes' => $indexes['mediaTypes'],
      'allowedParagraphBundles' => $allowed,
      'allowedParagraphBundlesByNodeType' => $allowedByNodeType,
    ];
    $manifest['catalogHash'] = $this->stableHash($manifest);
    return $manifest;
  }

  /** Return resolved contracts for selected bundles, including child bundles. */
  public function buildComponentContracts(string $entityType, array $bundles, bool $includeChildren = TRUE): array {
    if (!in_array($entityType, ['node', 'paragraph', 'media'], TRUE) || !$this->entityTypeManager->hasDefinition($entityType)) {
      throw new \InvalidArgumentException("Unsupported entity type '{$entityType}'.");
    }
    $known = $this->bundleInfo->getBundleInfo($entityType);
    $queue = array_values(array_unique(array_filter(array_map('strval', $bundles))));
    $components = [];
    while ($queue) {
      $bundle = array_shift($queue);
      if (isset($components[$bundle]) || !isset($known[$bundle])) {
        continue;
      }
      $fields = $this->configuredFields($entityType, $bundle);
      $editorial = $this->descriptions()[$entityType][$bundle] ?? [];
      $resolved = [];
      foreach ($fields as $name => $definition) {
        $resolved[$name] = $this->describeField($definition);
      }
      $components[$bundle] = [
        'id' => $bundle,
        'label' => (string) ($known[$bundle]['label'] ?? $bundle),
        'description' => (string) ($editorial['summary'] ?? ''),
        'guidance' => [
          'useWhen' => array_values($editorial['useWhen'] ?? []),
          'avoidWhen' => array_values($editorial['avoidWhen'] ?? []),
          'similarTo' => array_values($editorial['similarTo'] ?? []),
        ],
        'capabilities' => $this->capabilities($fields, $editorial),
        'fields' => $resolved,
        'childBundles' => $this->childBundles($fields),
      ];
      if ($includeChildren && $entityType === 'paragraph') {
        foreach ($components[$bundle]['childBundles'] as $children) {
          foreach ($children as $child) {
            if (!isset($components[$child])) {
              $queue[] = $child;
            }
          }
        }
      }
    }
    ksort($components);
    return [
      'status' => 'ok',
      'format' => 'icms-component-contract-v2',
      'entityType' => $entityType,
      'components' => $components,
    ];
  }

  private function configuredFields(string $entityType, string $bundle): array {
    return array_filter(
      $this->fieldManager->getFieldDefinitions($entityType, $bundle),
      fn(FieldDefinitionInterface $definition): bool => $definition->getFieldStorageDefinition() instanceof FieldStorageConfigInterface,
    );
  }

  private function storageDefinition(FieldDefinitionInterface $definition): array {
    $storage = $definition->getFieldStorageDefinition();
    $maxLength = $definition->getSetting('max_length');
    return array_filter([
      'fieldName' => $definition->getName(),
      'fieldType' => $definition->getType(),
      'cardinality' => $storage->getCardinality(),
      'targetType' => $definition->getSetting('target_type') ?: NULL,
      'maxLength' => is_numeric($maxLength) && (int) $maxLength > 0 ? (int) $maxLength : NULL,
    ], fn(mixed $value): bool => $value !== NULL);
  }

  private function describeField(FieldDefinitionInterface $definition): array {
    $data = $this->storageDefinition($definition) + [
      'label' => (string) $definition->getLabel(),
      // The editor-facing help text: what the field is FOR. The node-field
      // mapping gate shows it beside the label so a reviewer picking a target
      // for an unfamiliar source field has more than a machine name to go on.
      'description' => trim(strip_tags((string) $definition->getDescription())),
      'required' => $definition->isRequired(),
      // The symmetric translation model needs the fields INSIDE the paragraph
      // types translatable, or a matched language still degrades to node scalar
      // fields. Without this flag that checklist item has no closing test.
      'translatable' => $definition->isTranslatable(),
    ];
    $handler = $definition->getSetting('handler_settings') ?? [];
    $targets = array_keys($handler['target_bundles'] ?? []);
    if ($targets) {
      $data['targetBundles'] = array_values($targets);
    }
    $allowedFormats = $definition->getSetting('allowed_formats') ?? [];
    if ($allowedFormats) {
      $data['allowedFormats'] = array_values($allowedFormats);
    }
    $propertyMaxLengths = $this->propertyMaxLengths($definition);
    if ($propertyMaxLengths) {
      $data['propertyMaxLengths'] = $propertyMaxLengths;
    }
    if ($data['description'] === '') {
      unset($data['description']);
    }
    return $data;
  }

  /** Return per-property string limits exposed by Drupal typed data. */
  private function propertyMaxLengths(FieldDefinitionInterface $definition): array {
    $limits = [];
    foreach ($definition->getItemDefinition()->getPropertyDefinitions() as $name => $property) {
      foreach ($property->getConstraints() as $constraint => $options) {
        if ($constraint !== 'Length' || !is_array($options) || empty($options['max'])) {
          continue;
        }
        $limits[(string) $name] = (int) $options['max'];
      }
    }
    // Core's media thumbnail/image schema uses these database limits even on
    // installations where typed-data constraints do not expose them.
    if ($definition->getType() === 'image') {
      $limits += ['alt' => 512, 'title' => 1024];
    }
    ksort($limits);
    return $limits;
  }

  private function childBundles(array $fields): array {
    $children = [];
    foreach ($fields as $name => $definition) {
      if ($definition->getType() === 'entity_reference_revisions' && $definition->getSetting('target_type') === 'paragraph') {
        $children[$name] = array_keys($definition->getSetting('handler_settings')['target_bundles'] ?? []);
      }
    }
    return $children;
  }

  private function capabilities(array $fields, array $editorial): array {
    $names = array_keys($fields);
    $mediaTargets = [];
    $children = $this->childBundles($fields);
    foreach ($fields as $definition) {
      if ($definition->getSetting('target_type') === 'media') {
        $mediaTargets = array_merge($mediaTargets, array_keys($definition->getSetting('handler_settings')['target_bundles'] ?? []));
      }
    }
    return [
      'hasTitle' => in_array('field_icms_title', $names, TRUE),
      'hasText' => in_array('field_icms_text', $names, TRUE) || in_array('field_icms_text_plain', $names, TRUE),
      'hasMedia' => (bool) $mediaTargets,
      'supportsSvg' => in_array('icon', $mediaTargets, TRUE) || (bool) array_filter($children, fn(array $bundles): bool => in_array('icms_icon_text_element', $bundles, TRUE) || in_array('icms_card_element', $bundles, TRUE)),
      'isCollection' => (bool) $children || (($editorial['minItems'] ?? 0) >= 2),
      'minItems' => (int) ($editorial['minItems'] ?? 0),
      'mediaBundles' => array_values(array_unique($mediaTargets)),
    ];
  }

  /**
   * A bundle's options from the blökkli schema, in catalog shape; NULL = no schema.
   *
   * `{name: {type, default, values, label}}`. `values` is what the migration
   * normalises against: the radio/checkbox keys, `[true, false]` for a
   * checkbox, the integer range for a number. An empty array from the helper
   * is a bundle the schema knows nothing about, which is also "no schema"
   * for that bundle (blökkli then offers no options on it).
   */
  public function optionDefinitionsForBundle(string $bundle): ?array {
    return $this->schemaOptions($bundle);
  }

  private function schemaOptions(string $bundle): ?array {
    if ($this->schemaHelper === NULL) {
      return NULL;
    }
    try {
      $schema = $this->schemaHelper->getOptionsForBundle($bundle);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!is_array($schema)) {
      return NULL;
    }
    return self::definitionsFromSchema($schema);
  }

  /**
   * Convert one bundle's blökkli schema block to catalog option definitions.
   */
  public static function definitionsFromSchema(array $schema): array {
    $definitions = [];
    foreach ($schema as $name => $spec) {
      if (!is_array($spec)) {
        continue;
      }
      $type = (string) ($spec['type'] ?? 'radios');
      $values = [];
      if (isset($spec['options']) && is_array($spec['options'])) {
        $values = array_map('strval', array_keys($spec['options']));
      }
      elseif ($type === 'checkbox') {
        $values = [TRUE, FALSE];
      }
      elseif ($type === 'number' && isset($spec['min'], $spec['max'])) {
        $values = range((int) $spec['min'], (int) $spec['max']);
      }
      $definitions[(string) $name] = [
        'type' => $type,
        'default' => $spec['default'] ?? NULL,
        'values' => array_values($values),
        'label' => (string) ($spec['label'] ?? $name),
      ];
    }
    ksort($definitions);
    return $definitions;
  }

  private function descriptions(): array {
    if ($this->descriptionCache !== NULL) {
      return $this->descriptionCache;
    }
    $descriptions = $this->resource('component-descriptions.yml')['descriptions'] ?? [];
    $overrides = $this->configFactory->get('icms_mcp.catalog_descriptions')->get('descriptions') ?? [];
    $descriptions = array_replace_recursive($descriptions, is_array($overrides) ? $overrides : []);
    $this->moduleHandler->alter('icms_mcp_catalog_descriptions', $descriptions);
    return $this->descriptionCache = $descriptions;
  }

  private function options(): array {
    $options = $this->resource('blokkli-options.yml')['options'] ?? [];
    $overrides = $this->configFactory->get('icms_mcp.catalog_options')->get('options') ?? [];
    $options = array_replace_recursive($options, is_array($overrides) ? $overrides : []);
    $this->moduleHandler->alter('icms_mcp_catalog_options', $options);
    return $options;
  }

  private function resource(string $filename): array {
    $path = $this->moduleExtensionList->getPath('icms_mcp') . '/resources/' . $filename;
    return is_file($path) ? (Yaml::parseFile($path) ?? []) : [];
  }

  private function stableHash(array $value): string {
    $sort = function (&$item) use (&$sort): void {
      if (!is_array($item)) return;
      foreach ($item as &$child) $sort($child);
      if (!array_is_list($item)) ksort($item);
    };
    $copy = $value;
    $sort($copy);
    return hash('sha256', json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

}
