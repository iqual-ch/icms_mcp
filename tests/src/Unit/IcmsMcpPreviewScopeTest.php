<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_mcp\Unit;

use Drupal\icms_mcp\Service\IcmsMcpOperations;
use Drupal\Tests\UnitTestCase;

/** @coversDefaultClass \Drupal\icms_mcp\Service\IcmsMcpOperations */
final class IcmsMcpPreviewScopeTest extends UnitTestCase {

  /** @covers ::previewKeyPrefix */
  public function testAScopeNamesOnlyItsOwnPreviews(): void {
    self::assertSame(
      'https://icms-preview.invalid/run_Ab12-cd/',
      IcmsMcpOperations::previewKeyPrefix('run_Ab12-cd'),
    );
    // The trailing slash keeps `run1234` from matching `run12345`'s pages.
    self::assertFalse(str_starts_with(
      'https://icms-preview.invalid/run12345/row-1#hash',
      IcmsMcpOperations::previewKeyPrefix('run1234'),
    ));
  }

  /** @covers ::previewKeyPrefix */
  public function testAScopeThatCouldWidenThePrefixIsRefused(): void {
    foreach (['', 'abc', 'a/b/c/d/e', '../../..', 'run 12345', 'run%12345', str_repeat('a', 129)] as $scope) {
      self::assertSame('', IcmsMcpOperations::previewKeyPrefix($scope), $scope);
    }
  }

  /** @covers ::previewKeyPrefix */
  public function testNoRealSourcePageCanLookLikeAPreview(): void {
    // `.invalid` is reserved: it never resolves, so no crawled or exported
    // page has a source URL on that host.
    self::assertStringEndsWith('.invalid', IcmsMcpOperations::PREVIEW_SOURCE_HOST);
  }

}
