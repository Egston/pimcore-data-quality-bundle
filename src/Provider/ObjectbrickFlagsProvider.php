<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Provider;

use Pimcore\Model\DataObject\Concrete;

/**
 * Reads per-language boolean flags off a Pimcore objectbrick attached
 * to the validated object. The brick chain is followed by getter
 * name — `$object->{$brickContainerGetter}()->{$brickClassGetter}()`
 * yields the brick instance, then `$langGetters[$lang]` is called on
 * that instance for each language to produce the flag map.
 *
 * Either step returning `null` (no container, no brick attached) maps
 * to an empty `[]` result — "all languages unknown" semantically,
 * which is what the rule expects when the brick is absent.
 */
final class ObjectbrickFlagsProvider implements LanguageFlagsProvider
{
    /**
     * @param array<string, string> $langGetters    Map<language, getter-method-name> on the brick instance.
     * @param string[]              $supportedClassIds
     */
    public function __construct(
        private readonly string $brickContainerGetter,
        private readonly string $brickClassGetter,
        private readonly array $langGetters,
        private readonly string $name,
        private readonly array $supportedClassIds = [],
    ) {
        if ($brickContainerGetter === '') {
            throw new \InvalidArgumentException('ObjectbrickFlagsProvider: brickContainerGetter must not be empty.');
        }
        if ($brickClassGetter === '') {
            throw new \InvalidArgumentException('ObjectbrickFlagsProvider: brickClassGetter must not be empty.');
        }
        if ($langGetters === []) {
            throw new \InvalidArgumentException('ObjectbrickFlagsProvider: langGetters must not be empty.');
        }
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
        $container = $object->{$this->brickContainerGetter}();
        if ($container === null) {
            return [];
        }

        $brick = $container->{$this->brickClassGetter}();
        if ($brick === null) {
            return [];
        }

        $flags = [];
        foreach ($this->langGetters as $language => $getter) {
            $value = $brick->{$getter}();
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
