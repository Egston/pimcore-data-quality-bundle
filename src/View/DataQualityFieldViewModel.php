<?php

namespace Basilicom\DataQualityBundle\View;

class DataQualityFieldViewModel
{
    private string $name;
    private int $weight;
    private bool $valid;
    private ?string $language;
    private ?array $validFields;
    private bool $applied;

    public function __construct(string $name, int $weight, bool $valid, ?string $language = null, ?array $validFields = null, bool $applied = true)
    {
        if (!$applied && !$valid) {
            throw new \LogicException('N/A rows must carry valid=true');
        }

        $this->name        = $name;
        $this->weight      = $weight;
        $this->valid       = $valid;
        $this->language    = $language;
        $this->validFields = $validFields;
        $this->applied     = $applied;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getValidFields(): ?array
    {
        return $this->validFields;
    }

    /**
     * Whether the rule was applied for this object. A gate that returns
     * `false` (or throws and is treated as N/A) yields `applied=false`;
     * the rule contributes zero to both the numerator and denominator
     * of the final percentage. Default `true` preserves behaviour for
     * callers that construct view models without an explicit gate
     * evaluation step.
     */
    public function isApplied(): bool
    {
        return $this->applied;
    }
}
