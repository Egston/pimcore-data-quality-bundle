<?php

namespace Basilicom\DataQualityBundle\DefinitionsCollection;

use Basilicom\DataQualityBundle\Definition\DefinitionInterface;

class FieldDefinition
{
    protected DefinitionInterface $conditionClass;
    protected string $fieldName;
    protected string $title;
    protected int $weight;
    protected array $parameters;
    protected ?string $language;
    protected ?string $gate;

    public function __construct(DefinitionInterface $conditionClass, string $fieldName, string $title, int $weight, array $parameters, ?string $language = null, ?string $gate = null)
    {
        $this->conditionClass = $conditionClass;
        $this->fieldName      = $fieldName;
        $this->title          = $title;
        $this->weight         = $weight;
        $this->parameters     = $parameters;
        $this->language       = $language;
        $this->gate           = $gate;
    }

    /**
     * @return DefinitionInterface
     */
    public function getConditionClass(): DefinitionInterface
    {
        return $this->conditionClass;
    }

    /**
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return int
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return string|null
     */
    public function getLanguage(): ?string
    {
        return $this->language;
    }

    /**
     * Raw gate string as configured on the fieldcollection row, or
     * `null` when the row carries no gate. The caller resolves it
     * through `GateFactory::fromString()`.
     */
    public function getGate(): ?string
    {
        return $this->gate;
    }
}
