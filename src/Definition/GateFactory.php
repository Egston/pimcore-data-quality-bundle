<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Basilicom\DataQualityBundle\Definition\Gate\AllLangsFilledGate;
use Basilicom\DataQualityBundle\Definition\Gate\AlwaysApplyGate;
use Basilicom\DataQualityBundle\Definition\Gate\AnyLangFilledGate;
use Basilicom\DataQualityBundle\Definition\Gate\ExpressionGate;
use Basilicom\DataQualityBundle\Definition\Gate\PublishedGate;
use Basilicom\DataQualityBundle\Definition\Gate\SourceFilledGate;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Single seam translating a raw gate string into a `Gate` instance,
 * shared by `Provider::calculateDataQuality()` (recompute-time, where
 * validation already happened on save) and the pre-save subscriber
 * (save-time, where `InvalidGateException` is the contract). Eager
 * `expr:` parsing here means a malformed expression surfaces at the
 * factory call, not at first evaluation — that is what makes the
 * save-time validation possible.
 */
class GateFactory
{
    private const KEYWORD_SOURCE_FILLED = 'source_filled';
    private const KEYWORD_ANY_LANG_FILLED = 'any_lang_filled';
    private const KEYWORD_ALL_LANGS_FILLED = 'all_langs_filled';
    private const KEYWORD_PUBLISHED = 'published';
    private const EXPRESSION_PREFIX = 'expr:';

    public function __construct(
        private readonly ExpressionLanguage $expressionLanguage,
    ) {}

    /**
     * @throws InvalidGateException when the keyword is unknown or the
     *                              `expr:` body is syntactically invalid
     */
    public function fromString(?string $gate): Gate
    {
        $normalized = $gate === null ? '' : trim($gate);
        if ($normalized === '') {
            return new AlwaysApplyGate();
        }

        if (str_starts_with($normalized, self::EXPRESSION_PREFIX)) {
            $source = substr($normalized, strlen(self::EXPRESSION_PREFIX));
            try {
                $parsed = $this->expressionLanguage->parse($source, ExpressionGate::VARIABLE_NAMES);
            } catch (SyntaxError $e) {
                throw new InvalidGateException(
                    sprintf('Gate expression "%s" failed to parse: %s', $source, $e->getMessage()),
                    $normalized,
                    $e,
                );
            }

            return new ExpressionGate($parsed, $this->expressionLanguage, $source);
        }

        return match ($normalized) {
            self::KEYWORD_SOURCE_FILLED => new SourceFilledGate(),
            self::KEYWORD_ANY_LANG_FILLED => new AnyLangFilledGate(),
            self::KEYWORD_ALL_LANGS_FILLED => new AllLangsFilledGate(),
            self::KEYWORD_PUBLISHED => new PublishedGate(),
            default => throw new InvalidGateException(
                sprintf(
                    'Unknown gate keyword "%s". Expected one of: %s, %s, %s, %s, or an "%s..." expression.',
                    $normalized,
                    self::KEYWORD_SOURCE_FILLED,
                    self::KEYWORD_ANY_LANG_FILLED,
                    self::KEYWORD_ALL_LANGS_FILLED,
                    self::KEYWORD_PUBLISHED,
                    self::EXPRESSION_PREFIX,
                ),
                $normalized,
            ),
        };
    }
}
