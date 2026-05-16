<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Definition;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\DataQualityConfig;

/**
 * Weighted blend of sibling DataQualityConfig output columns on the same
 * DataObject — a "headline" rule that derives from per-axis configs
 * without re-running the full evaluator.
 *
 * The `columns=` parameter is a `;`-separated list of `<column>:<weight>`
 * pairs. The rule reads each column off the DataObject (via its generated
 * `get<Column>()` getter), drops NULL inputs from the blend, scales up the
 * surviving weights so they sum back to 1.0, and compares the resulting
 * weighted average against an internal pass threshold of 100.0 (the rule
 * passes when the blended score equals 100).
 *
 * NULL semantics are load-bearing: NULL signals "this axis hasn't been
 * scored yet" (a producer config emitted SQL NULL via the cast-conditional
 * writeback), not "this axis scored 0". The all-NULL case therefore
 * returns `false` from `validate()` AND `true` from `skipFromScore()`,
 * marking the row N/A so the parent config writes SQL NULL rather than
 * counting as a numerator-zero fail.
 *
 * The row's `field` value is decorative — this rule reads its inputs
 * from the `columns=` parameter, not from the row's `field`. Container-
 * path rejection therefore does not apply here.
 *
 * Class-scoped (not language-scoped): the `LocalizedAwareDefinition` marker
 * fires the once-per-object dispatch branch; `validLanguages` is always `[]`.
 *
 * The `columns=` entries must name class-level (non-localized) fields.
 * Pointing at a Localizedfield-backed score column causes `$object->$getter()`
 * to return `null` (no language argument) and silently treats that axis as
 * unscored (N/A), which is wrong. A future hardening pass should detect and
 * reject localized fields at config-save time.
 */
final class WeightedColumnBlend extends DefinitionAbstract implements LocalizedAwareDefinition, DependentDefinition
{
    /** Matched at `>=` so a perfect 100 always passes; partial scores fail. */
    private const PASS_THRESHOLD = 100.0;

    private bool $skipFromScore = false;

    /**
     * @throws DefinitionException
     */
    public function validate($content, Data $fieldDefinition, array $parameters, RuleContext $context): bool
    {
        $this->skipFromScore = false;

        try {
            $weights = $this->parseColumnsParameter($parameters);

            $object = $context->getObject();

            $totalWeight = 0.0;
            $weightedSum = 0.0;
            $nullCount = 0;
            foreach ($weights as $column => $weight) {
                $getter = 'get' . ucfirst($column);
                if (!method_exists($object, $getter)) {
                    throw new DefinitionException(sprintf(
                        'WeightedColumnBlend: column "%s" has no getter "%s" on %s.',
                        $column,
                        $getter,
                        $object::class,
                    ));
                }

                $value = $object->$getter();
                if ($value === null) {
                    $nullCount++;

                    continue;
                }
                if (!is_numeric($value)) {
                    throw new DefinitionException(sprintf(
                        'WeightedColumnBlend: column "%s" on oo_id=%d returned non-numeric value of type %s.',
                        $column,
                        (int) $object->getId(),
                        get_debug_type($value),
                    ));
                }

                $totalWeight += $weight;
                $weightedSum += $weight * (float) $value;
            }

            if ($nullCount === count($weights)) {
                $this->skipFromScore = true;

                return false;
            }

            $blended = $weightedSum / $totalWeight;

            return $blended >= self::PASS_THRESHOLD;
        } catch (\Throwable $e) {
            $this->skipFromScore = false;

            throw $e;
        }
    }

    public function dependsOnColumns(array $parameters, DataQualityConfig $config): array
    {
        $weights = $this->parseColumnsParameter($parameters);
        $class = (string) $config->getDataQualityClass();

        $entries = [];
        foreach ($weights as $column => $_weight) {
            $entries[] = ['class' => $class, 'column' => $column];
        }

        return $entries;
    }

    public function skipFromScore(): bool
    {
        return $this->skipFromScore;
    }

    /**
     * Tolerates both forms of the parsed parameter shape:
     *
     *   - associative `['columns' => 'col1:0.4;col2:0.3']` — what tests
     *     pass directly, what a paramSchema-driven admin form would produce;
     *   - positional `[0 => 'columns=col1:0.4', 1 => 'col2:0.3']` — what
     *     `FieldDefinitionFactory::parameterStringToArray()`'s `;`-CSV
     *     split currently produces from a persisted
     *     `columns=col1:0.4;col2:0.3` raw string.
     *
     * Joining the positional pieces with `;` recovers the original raw
     * text; the `columns=` prefix is then stripped before the
     * `<col>:<weight>` walk.
     *
     * @param array<int|string, mixed> $parameters
     */
    private function extractColumnsValue(array $parameters): mixed
    {
        if (array_key_exists('columns', $parameters)) {
            return $parameters['columns'];
        }

        if ($parameters !== [] && array_is_list($parameters)) {
            $joined = implode(';', array_map('strval', $parameters));
            if (preg_match('/^\s*columns\s*=\s*(.*)$/s', $joined, $matches) === 1) {
                return $matches[1];
            }

            throw new DefinitionException(sprintf(
                'WeightedColumnBlend: positional parameter list received but no "columns=" prefix found. '
                . 'Got keys: [%s]. Expected format: "columns=col1:0.4;col2:0.3".',
                implode(', ', array_keys($parameters)),
            ));
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $parameters
     *
     * @return array<string, float> Map of column name → positive weight,
     *                              normalised so the values sum to 1.0.
     *
     * @throws DefinitionException
     */
    private function parseColumnsParameter(array $parameters): array
    {
        $raw = $this->extractColumnsValue($parameters);
        if ($raw === null) {
            throw new DefinitionException(
                'WeightedColumnBlend requires the "columns" parameter (format: "col1:0.4;col2:0.3;col3:0.3").',
            );
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new DefinitionException(
                'WeightedColumnBlend "columns" must be a non-empty string '
                . '(format: "col1:0.4;col2:0.3;col3:0.3").',
            );
        }

        $weights = [];
        $sum = 0.0;
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, ':')) {
                throw new DefinitionException(sprintf(
                    'WeightedColumnBlend "columns" entry "%s" must be of the form "<column>:<weight>".',
                    $pair,
                ));
            }
            [$column, $weightRaw] = explode(':', $pair, 2);
            $column = trim($column);
            $weightRaw = trim($weightRaw);
            if ($column === '') {
                throw new DefinitionException(sprintf(
                    'WeightedColumnBlend "columns" entry "%s" has an empty column name.',
                    $pair,
                ));
            }
            if (!is_numeric($weightRaw)) {
                throw new DefinitionException(sprintf(
                    'WeightedColumnBlend "columns" entry "%s" has a non-numeric weight "%s".',
                    $pair,
                    $weightRaw,
                ));
            }
            $weight = (float) $weightRaw;
            if ($weight <= 0.0) {
                throw new DefinitionException(sprintf(
                    'WeightedColumnBlend "columns" entry "%s" has non-positive weight %s; weights must be > 0.',
                    $pair,
                    $weightRaw,
                ));
            }
            $weights[$column] = $weight;
            $sum += $weight;
        }

        if ($weights === []) {
            throw new DefinitionException(
                'WeightedColumnBlend "columns" must contain at least one "<column>:<weight>" entry.',
            );
        }
        if ($sum <= 0.0) {
            throw new DefinitionException(
                'WeightedColumnBlend "columns" weights sum to zero; at least one weight must be positive.',
            );
        }

        foreach ($weights as $column => $weight) {
            $weights[$column] = $weight / $sum;
        }

        return $weights;
    }
}
