<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Resources\views;

use PHPUnit\Framework\TestCase;

/**
 * Source-pattern pin on `Resources/views/data-quality.html.twig`. The
 * template is the only operator-visible signal of the null-score
 * contract — a "cleanup" that swaps explicit `is null` for an inline
 * `|default(0)` coercion would silently render `0%` for unscored rows,
 * undoing the writeback plumbing in one line. A render-time test would
 * be more durable but would require booting Twig with Pimcore's
 * extensions — out of scope for this kernel-free suite.
 */
final class DataQualityTemplateNullRenderingTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../../../src/Resources/views/data-quality.html.twig';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, 'data-quality.html.twig must exist at expected path');
        $this->template = $contents;
    }

    public function test_branches_on_is_null_and_renders_em_dash(): void
    {
        self::assertMatchesRegularExpression(
            '/qualityConfig\.percentage\s+is\s+null\s*%}\s*—/u',
            $this->template,
            'Null branch must render an em-dash, not "0%" or empty'
        );
    }

    public function test_complete_class_guarded_by_is_not_null(): void
    {
        self::assertMatchesRegularExpression(
            '/qualityConfig\.percentage\s+is\s+not\s+null\s+and\s+qualityConfig\.percentage\s*==\s*100/u',
            $this->template,
            'The data-quality__percent--complete CSS class must be guarded by `is not null and ... == 100`'
        );
    }

    public function test_no_default_zero_coercion(): void
    {
        self::assertStringNotContainsString(
            '|default(0)',
            $this->template,
            '`|default(0)` would silently collapse null to "0%"; null must be rendered as em-dash'
        );
        self::assertStringNotContainsString(
            '?? 0',
            $this->template,
            '`?? 0` would silently collapse null to "0%"; null must be rendered as em-dash'
        );
    }

    public function test_renders_na_class_on_not_applied_branch(): void
    {
        self::assertMatchesRegularExpression(
            '/not\s+field\.applied/u',
            $this->template,
            'Template must branch on `not field.applied` so N/A rows render distinctly'
        );
        self::assertStringContainsString(
            'data-quality__column--na',
            $this->template,
            'N/A branch must use the dedicated --na CSS class'
        );
    }

    public function test_na_label_translation_key_is_emitted(): void
    {
        self::assertStringContainsString(
            "'dataQuality.label.notApplicable'",
            $this->template,
            'N/A branch must emit the translation key for the user-visible "(N/A)" tag'
        );
    }
}
