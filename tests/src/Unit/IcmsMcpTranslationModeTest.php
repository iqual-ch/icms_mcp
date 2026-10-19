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
   * On an ICMS 1.7 target the field is translatable for EVERY page, so
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
