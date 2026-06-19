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
use Drupal\field\FieldStorageConfigInterface;
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
  ) {}

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
        ];
        if ($entityType === 'paragraph') {
          $entry['role'] = (string) ($editorial['role'] ?? 'component');
          $entry['optionRefs'] = str_starts_with($bundle, 'icms_layout_') ? array_keys($options) : [];
          $entry['childBundles'] = $this->childBundles($fields);
        }
        $index[$bundle] = $entry;
      }
      ksort($index);
      $indexes[$entityType . 'Types'] = $index;
    }
    ksort($fieldDefinitions);

    $allowed = [];
    $paragraphSlots = [];
    $nodeDefinitions = $this->fieldManager->getFieldDefinitions('node', 'icms_page');
    if (isset($nodeDefinitions[$layoutsField])) {
      $allowed = array_keys($nodeDefinitions[$layoutsField]->getSetting('handler_settings')['target_bundles'] ?? []);
      sort($allowed);
      $paragraphSlots[$layoutsField] = [
        'fieldRef' => 'node.' . $layoutsField,
        'targetBundles' => $allowed,
      ];
    }
    if (isset($indexes['nodeTypes']['icms_page'])) {
      $indexes['nodeTypes']['icms_page']['paragraphSlots'] = $paragraphSlots;
    }

    $manifest = [
      'status' => 'ok',
      'format' => 'icms-target-catalog-v2',
      'site' => ['sourceKeyField' => $sourceField, 'layoutsField' => $layoutsField],
      'fieldDefinitions' => $fieldDefinitions,
      'optionDefinitions' => $options,
      'nodeTypes' => $indexes['nodeTypes'],
      'paragraphTypes' => $indexes['paragraphTypes'],
      'mediaTypes' => $indexes['mediaTypes'],
      'allowedParagraphBundles' => $allowed,
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
    return array_filter([
      'fieldName' => $definition->getName(),
      'fieldType' => $definition->getType(),
      'cardinality' => $storage->getCardinality(),
      'targetType' => $definition->getSetting('target_type') ?: NULL,
    ], fn(mixed $value): bool => $value !== NULL);
  }

  private function describeField(FieldDefinitionInterface $definition): array {
    $data = $this->storageDefinition($definition) + [
      'label' => (string) $definition->getLabel(),
      'required' => $definition->isRequired(),
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
    return $data;
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
