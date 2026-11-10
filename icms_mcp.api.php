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

/**
 * Alter normalized Blökkli option definitions.
 *
 * One entry per option: `values` (fallback for a site without a blökkli
 * schema), `description`, `effects` (per value, what it does to the layout)
 * and `bundles` (`description` / `effects` for one bundle).
 */
function hook_icms_mcp_catalog_options_alter(array &$options): void {
  $options['width']['values'] = ['narrow', 'medium', 'wide'];
  $options['width']['effects']['narrow'] = 'Content sits in a narrow centred column.';
  $options['width']['bundles']['example_layout_text']['effects']['narrow'] = 'A single reading column.';
}
