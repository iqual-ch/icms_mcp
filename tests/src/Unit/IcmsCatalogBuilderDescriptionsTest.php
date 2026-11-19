<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Catalog\IcmsCatalogBuilder;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Catalog\IcmsCatalogBuilder */
final class IcmsCatalogBuilderDescriptionsTest extends UnitTestCase {

  /**
   * @covers ::guidanceFromEditorial
   */
  public function testGuidanceListsAreTrimmedAndAlwaysPresent(): void {
    $guidance = IcmsCatalogBuilder::guidanceFromEditorial([
      'summary' => 'Hero slider.',
      'useWhen' => [' The page starts with a header slider. ', ''],
      'similarTo' => ['icms_layout_hero_image'],
    ]);

    self::assertSame(['The page starts with a header slider.'], $guidance['useWhen']);
    self::assertSame([], $guidance['avoidWhen']);
    self::assertSame(['icms_layout_hero_image'], $guidance['similarTo']);
  }

  /**
   * Declared source components reach the catalog as a normalised list.
   *
   * A project's description entry names the source components the bundle is
   * built for; the migrator applies them as a declared mapping.
   *
   * @covers ::sourcePatternsFromEditorial
   */
  public function testSourcePatternsAreNormalisedAndOptional(): void {
    self::assertSame(
      ['slider', 'animatedslider'],
      IcmsCatalogBuilder::sourcePatternsFromEditorial(['sourcePatterns' => ['Slider', ' animatedslider ', 'slider', '']]),
    );
    self::assertSame([], IcmsCatalogBuilder::sourcePatternsFromEditorial(['summary' => 'No recipe.']));
    // A scalar by mistake is one pattern, not a crash.
    self::assertSame(['testimonial'], IcmsCatalogBuilder::sourcePatternsFromEditorial(['sourcePatterns' => 'testimonial']));
  }

  /**
   * Declared source regions reach the catalog as a normalised list.
   *
   * A project's description entry names the page region whose content the
   * bundle receives (a side column); the migrator moves sections from that
   * region into the node slot targeting the bundle, and nothing without it.
   *
   * @covers ::sourceRegionsFromEditorial
   */
  public function testSourceRegionsAreNormalisedAndOptional(): void {
    self::assertSame(
      ['side'],
      IcmsCatalogBuilder::sourceRegionsFromEditorial(['sourceRegions' => ['Side', ' side ', '']]),
    );
    self::assertSame([], IcmsCatalogBuilder::sourceRegionsFromEditorial(['summary' => 'No recipe.']));
    self::assertSame(['side'], IcmsCatalogBuilder::sourceRegionsFromEditorial(['sourceRegions' => 'side']));
  }

  /**
   * A layout's side box declaration reaches the catalog as its text field.
   *
   * The migrator folds a row's main text and its side column into one
   * component only on a layout that declares the box; a guess from field
   * names would hit layouts that merely have a description.
   *
   * @covers ::sideBoxFromEditorial
   */
  public function testSideBoxNamesItsTextFieldAndIsOptional(): void {
    self::assertSame(
      ['text' => 'field_box_description'],
      IcmsCatalogBuilder::sideBoxFromEditorial(['sideBox' => ['text' => ' field_box_description ', 'other' => 'x']]),
    );
    self::assertSame([], IcmsCatalogBuilder::sideBoxFromEditorial(['summary' => 'No box.']));
    self::assertSame([], IcmsCatalogBuilder::sideBoxFromEditorial(['sideBox' => ['text' => '']]));
    // A scalar by mistake is no declaration, not a crash.
    self::assertSame([], IcmsCatalogBuilder::sideBoxFromEditorial(['sideBox' => 'field_box_description']));
  }

  /**
   * A project's node type entry names the fields its page template shows;
   * the migrator keeps their content in the field instead of repeating it.
   *
   * @covers ::pageFieldsFromEditorial
   */
  public function testPageFieldsAreNormalisedAndOptional(): void {
    self::assertSame(
      ['body', 'field_icms_image'],
      IcmsCatalogBuilder::pageFieldsFromEditorial(['pageFields' => ['body', ' body ', '', 'field_icms_image']]),
    );
    self::assertSame([], IcmsCatalogBuilder::pageFieldsFromEditorial(['summary' => 'Paragraphs only.']));
    self::assertSame(['body'], IcmsCatalogBuilder::pageFieldsFromEditorial(['pageFields' => 'body']));
  }

  /**
   * A layout names the source row it is the home for by that row's classes.
   *
   * A band of short facts is a plain text row with a class on it; no
   * component name says what it is, so the project lists the classes.
   *
   * @covers ::sourceRowClassesFromEditorial
   */
  public function testSourceRowClassesAreTrimmedDeduplicatedAndOptional(): void {
    self::assertSame(
      ['band-primary', 'Product-Page'],
      IcmsCatalogBuilder::sourceRowClassesFromEditorial(['sourceRowClasses' => [' band-primary ', 'Product-Page', 'band-primary', '']]),
    );
    self::assertSame([], IcmsCatalogBuilder::sourceRowClassesFromEditorial(['summary' => 'No recipe.']));
    self::assertSame(['band-primary'], IcmsCatalogBuilder::sourceRowClassesFromEditorial(['sourceRowClasses' => 'band-primary']));
  }

  /**
   * A layout names the layouts it replaces where they are not allowed.
   *
   * @covers ::standsInForFromEditorial
   */
  public function testStandsInForListsBundlesAndIsOptional(): void {
    self::assertSame(
      ['icms_layout_hero_image'],
      IcmsCatalogBuilder::standsInForFromEditorial(['standsInFor' => [' icms_layout_hero_image ', 'icms_layout_hero_image', '']]),
    );
    self::assertSame([], IcmsCatalogBuilder::standsInForFromEditorial(['summary' => 'No stand-in.']));
    self::assertSame(['icms_layout_text'], IcmsCatalogBuilder::standsInForFromEditorial(['standsInFor' => 'icms_layout_text']));
  }

}
