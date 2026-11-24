<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/**
 * How a translation spec selects its paragraph write mode.
 *
 * @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations
 */
final class IcmsMcpTranslationModeTest extends UnitTestCase {

  /**
   * A detached spec needs a translatable field; the spec decides.
   *
   * @covers ::translationParagraphMode
   */
  public function testDetachedNeedsTranslatableField(): void {
    $spec = ['langcode' => 'fr', 'mode' => 'detached', 'paragraphs' => [['type' => 'paragraph--icms_layout_text']]];
    self::assertSame('detached', IcmsMcpOperations::translationParagraphMode($spec, TRUE));
    self::assertSame('detached_unsupported', IcmsMcpOperations::translationParagraphMode($spec, FALSE));
  }

  /**
   * An attached page on a translatable field still translates in place.
   *
   * On a target that ships detached content the field is translatable for EVERY page, so
   * structureMatch must win over the field's translatability.
   *
   * @covers ::translationParagraphMode
   */
  public function testStructureMatchStaysSymmetricOnTranslatableField(): void {
    $spec = ['langcode' => 'fr', 'structureMatch' => TRUE, 'paragraphs' => [['type' => 'paragraph--icms_layout_text']]];
    self::assertSame('symmetric', IcmsMcpOperations::translationParagraphMode($spec, TRUE));
    self::assertSame('symmetric', IcmsMcpOperations::translationParagraphMode($spec, FALSE));
  }

  /**
   * On a detached node a structure-matched translation gets its own tree.
   *
   * Every translation of a flagged node renders its own list: text written
   * into the original's shared paragraphs never reaches it, and a new
   * translation would be saved without sections.
   *
   * @covers ::translationParagraphMode
   */
  public function testStructureMatchOnDetachedNodeOwnsItsTree(): void {
    $spec = [
      'langcode' => 'fr',
      'structureMatch' => TRUE,
      'paragraphs' => [['type' => 'paragraph--icms_layout_text']],
    ];
    self::assertSame('detached', IcmsMcpOperations::translationParagraphMode($spec, TRUE, TRUE));
    // The flag without a translatable field cannot hold per-language rows:
    // the field is shared, so in-place translation is still right.
    self::assertSame('symmetric', IcmsMcpOperations::translationParagraphMode($spec, FALSE, TRUE));
    // An attached node keeps translating in place.
    self::assertSame('symmetric', IcmsMcpOperations::translationParagraphMode($spec, TRUE, FALSE));
  }

  /**
   * The node's flag never changes a detached, mismatched or unstamped spec.
   *
   * @covers ::translationParagraphMode
   */
  public function testDetachedNodeKeepsOtherModes(): void {
    $detached = ['langcode' => 'fr', 'mode' => 'detached'];
    self::assertSame('detached', IcmsMcpOperations::translationParagraphMode($detached, TRUE, TRUE));
    self::assertSame('detached_unsupported', IcmsMcpOperations::translationParagraphMode($detached, FALSE, TRUE));
    $mismatched = ['langcode' => 'fr', 'structureMatch' => FALSE];
    self::assertSame('shared', IcmsMcpOperations::translationParagraphMode($mismatched, TRUE, TRUE));
    self::assertSame('shared', IcmsMcpOperations::translationParagraphMode(['langcode' => 'fr'], TRUE, TRUE));
  }

  /**
   * A mismatched or unstamped translation writes no paragraphs.
   *
   * @covers ::translationParagraphMode
   */
  public function testMismatchedOrUnstampedIsShared(): void {
    $mismatched = ['langcode' => 'fr', 'structureMatch' => FALSE];
    self::assertSame('shared', IcmsMcpOperations::translationParagraphMode($mismatched, TRUE));
    self::assertSame('shared', IcmsMcpOperations::translationParagraphMode(['langcode' => 'fr'], TRUE));
    self::assertSame('shared', IcmsMcpOperations::translationParagraphMode(['langcode' => 'fr', 'mode' => 'symmetric'], TRUE));
  }

  /**
   * The flag the default-language import sets is the one ICMS reads.
   */
  public function testFlagFieldName(): void {
    self::assertSame('icms_detached_content', IcmsMcpOperations::DETACHED_CONTENT_FLAG);
  }

}
