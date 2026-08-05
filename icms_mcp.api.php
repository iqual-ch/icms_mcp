<?php

/**
 * @file
 * Hooks provided by the ICMS MCP module.
 */

/**
 * Alter catalog descriptions after packaged defaults and config overrides.
 *
 * @param array $descriptions
 *   Descriptions keyed by entity type and bundle.
 */
function hook_icms_mcp_catalog_descriptions_alter(array &$descriptions): void {
  $descriptions['paragraph']['icms_layout_text']['summary'] = 'Site-specific text layout guidance.';
}

/** Alter normalized Blökkli option definitions. */
function hook_icms_mcp_catalog_options_alter(array &$options): void {
  $options['width']['values'] = ['narrow', 'medium', 'wide'];
}
