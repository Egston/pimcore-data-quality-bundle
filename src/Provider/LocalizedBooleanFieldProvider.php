<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Provider;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tool;

/**
 * Reads a configurable localized boolean field directly off a Pimcore
 * object — no brick required. The constructor name identifies the
 * provider in the admin dropdown.
 *
 * `Tool::getValidLanguages()` is kernel-bound; deferred to `getFlags()`
 * so constructor calls at container-compile time stay safe.
 */
final class LocalizedBooleanFieldProvider implements LanguageFlagsProvider
{
    private readonly string $getter;

    /**
     * @param string[] $supportedClassIds
     */
    public function __construct(
        string $fieldName,
        private readonly string $name,
        private readonly array $supportedClassIds = [],
    ) {
        if ($fieldName === '') {
            throw new \InvalidArgumentException('LocalizedBooleanFieldProvider: fieldName must not be empty.');
        }
        $this->getter = 'get' . ucfirst($fieldName);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, bool>
     */
    public function getFlags(Concrete $object): array
    {
        $flags = [];
        foreach (Tool::getValidLanguages() as $language) {
            $value = $object->{$this->getter}($language);
            if ($value === null) {
                continue;
            }
            $flags[$language] = (bool) $value;
        }

        return $flags;
    }

    /**
     * @return string[]
     */
    public function supportedClassIds(): array
    {
        return $this->supportedClassIds;
    }
}
