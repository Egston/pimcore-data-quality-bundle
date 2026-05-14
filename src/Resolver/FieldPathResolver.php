<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Resolver;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData as FieldcollectionItem;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\DataObject\Objectbrick\Data\AbstractData as ObjectbrickItem;

/**
 * Walks a dotted path against a Pimcore `Concrete` object and returns the
 * set of `ResolvedLeaf`s the path expands to in one target language.
 *
 * Path grammar:
 *
 *  - `name`                            simple field on the current container
 *  - `name[]`                          iterate every item of a fieldcollection
 *  - `name` as intermediate brick      step into every item of an objectbrick container
 *  - `name` as terminal container      fan out across every localized leaf reachable under the container (caller must implement `LocalizedAwareDefinition`)
 *
 * Inheritance semantics:
 *  - Fieldcollection container reads are taken with inheritance disabled, so a
 *    child object with no own FC items reports zero leaves regardless of its
 *    parent's items. The global `AbstractObject::getGetInheritedValues()`
 *    flag is restored before the resolver returns.
 *  - Leaf reads inside FC items happen with inheritance enabled (this
 *    matches the caller's existing `setGetInheritedValues(true)` discipline
 *    on top-level localized fields).
 *  - Objectbricks have their own `getItems()`-level inheritance toggle; the
 *    resolver iterates them without injecting inherited items, matching FCs.
 *
 * Container-terminated paths are gated by `LocalizedAwareDefinition` at the
 * caller (`FieldDefinitionFactory::get()` rejects them for non-marker rules).
 * The resolver itself trusts the path — non-existent fields throw
 * `InvalidFieldPathException`.
 */
final class FieldPathResolver implements FieldPathResolverInterface
{
    /**
     * @return ResolvedLeaf[]
     *
     * @throws InvalidFieldPathException
     */
    public function resolve(Concrete $object, string $path, string $language): array
    {
        $inheritedBefore = AbstractObject::getGetInheritedValues();
        try {
            return $this->walk(
                container: $object,
                classDef: $this->fieldDefinitionsHolderFor($object),
                segments: $this->parseSegments($path),
                language: $language,
                originalPath: $path,
                leafPathPrefix: '',
            );
        } finally {
            AbstractObject::setGetInheritedValues($inheritedBefore);
        }
    }

    /**
     * @return array<int, array{name: string, iterate: bool}>
     */
    private function parseSegments(string $path): array
    {
        if ($path === '') {
            throw new InvalidFieldPathException($path, 'path is empty');
        }

        $segments = [];
        foreach (explode('.', $path) as $raw) {
            $iterate = false;
            $name = $raw;
            if (str_ends_with($name, '[]')) {
                $iterate = true;
                $name = substr($name, 0, -2);
            }
            if ($name === '') {
                throw new InvalidFieldPathException($path, 'segment has no name');
            }
            $segments[] = ['name' => $name, 'iterate' => $iterate];
        }

        return $segments;
    }

    /**
     * @param array<int, array{name: string, iterate: bool}> $segments
     *
     * @return ResolvedLeaf[]
     */
    private function walk(
        Concrete|FieldcollectionItem|ObjectbrickItem $container,
        object $classDef,
        array $segments,
        string $language,
        string $originalPath,
        string $leafPathPrefix,
    ): array {
        if ($segments === []) {
            throw new InvalidFieldPathException($originalPath, 'walk reached empty segment list');
        }

        $segment = array_shift($segments);
        $name = $segment['name'];
        $iterate = $segment['iterate'];
        $isLast = $segments === [];

        $resolved = $this->resolveFieldDefinition($classDef, $name, $originalPath);
        $segmentDef = $resolved['def'];
        $isLocalizedLeaf = $resolved['localized'];
        $leafPath = $leafPathPrefix === '' ? $name : $leafPathPrefix . '.' . $name;

        if ($iterate) {
            return $this->walkFieldcollection(
                container: $container,
                segmentDef: $segmentDef,
                segmentName: $name,
                remaining: $segments,
                language: $language,
                originalPath: $originalPath,
                leafPath: $leafPath,
            );
        }

        if ($isLast) {
            return $this->resolveTerminal(
                container: $container,
                segmentDef: $segmentDef,
                segmentName: $name,
                isLocalizedLeaf: $isLocalizedLeaf,
                language: $language,
                originalPath: $originalPath,
                leafPath: $leafPath,
            );
        }

        if ($segmentDef instanceof Objectbricks) {
            return $this->walkObjectbrick(
                container: $container,
                segmentDef: $segmentDef,
                segmentName: $name,
                remaining: $segments,
                language: $language,
                originalPath: $originalPath,
                leafPath: $leafPath,
            );
        }

        if ($segmentDef instanceof Fieldcollections) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf('segment "%s" is a fieldcollection but is not iterated; use "%s[]" to fan out across items', $name, $name),
            );
        }

        throw new InvalidFieldPathException(
            $originalPath,
            sprintf('intermediate segment "%s" (type "%s") cannot be traversed; only fieldcollections (with []) and objectbricks are walkable', $name, $segmentDef->getFieldtype()),
        );
    }

    /**
     * @param array<int, array{name: string, iterate: bool}> $remaining
     *
     * @return ResolvedLeaf[]
     */
    private function walkFieldcollection(
        Concrete|FieldcollectionItem|ObjectbrickItem $container,
        Data $segmentDef,
        string $segmentName,
        array $remaining,
        string $language,
        string $originalPath,
        string $leafPath,
    ): array {
        if (!$segmentDef instanceof Fieldcollections) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf('segment "%s[]" expects a fieldcollection but found type "%s"', $segmentName, $segmentDef->getFieldtype()),
            );
        }

        $inheritedBefore = AbstractObject::getGetInheritedValues();
        AbstractObject::setGetInheritedValues(false);
        try {
            $fc = $this->callGetter($container, $segmentName, null, $originalPath);
        } finally {
            AbstractObject::setGetInheritedValues($inheritedBefore);
        }

        if ($fc === null) {
            return [];
        }
        if (!$fc instanceof Fieldcollection) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf('segment "%s" getter returned %s, expected Fieldcollection or null', $segmentName, get_debug_type($fc)),
            );
        }

        $items = $fc->getItems();
        if ($items === []) {
            return [];
        }

        $leaves = [];
        foreach ($items as $index => $item) {
            $itemDef = $item->getDefinition();
            $itemLeafPrefix = $leafPath . '[' . $index . ']';

            if ($remaining === []) {
                $leaves = array_merge(
                    $leaves,
                    $this->expandContainerLeaves(
                        container: $item,
                        classDef: $itemDef,
                        language: $language,
                        originalPath: $originalPath,
                        leafPathPrefix: $itemLeafPrefix,
                    ),
                );
                continue;
            }

            $leaves = array_merge(
                $leaves,
                $this->walk(
                    container: $item,
                    classDef: $itemDef,
                    segments: $remaining,
                    language: $language,
                    originalPath: $originalPath,
                    leafPathPrefix: $itemLeafPrefix,
                ),
            );
        }

        return $leaves;
    }

    /**
     * @param array<int, array{name: string, iterate: bool}> $remaining
     *
     * @return ResolvedLeaf[]
     */
    private function walkObjectbrick(
        Concrete|FieldcollectionItem|ObjectbrickItem $container,
        Objectbricks $segmentDef,
        string $segmentName,
        array $remaining,
        string $language,
        string $originalPath,
        string $leafPath,
    ): array {
        $brickContainer = $this->callGetter($container, $segmentName, null, $originalPath);
        if ($brickContainer === null) {
            return [];
        }
        if (!$brickContainer instanceof Objectbrick) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf('segment "%s" getter returned %s, expected Objectbrick or null', $segmentName, get_debug_type($brickContainer)),
            );
        }

        $items = $brickContainer->getItems();
        if ($items === []) {
            return [];
        }

        $leaves = [];
        foreach ($items as $brickItem) {
            if (!$brickItem instanceof ObjectbrickItem) {
                throw new InvalidFieldPathException(
                    $originalPath,
                    sprintf('objectbrick container "%s" returned an item of type %s, expected ObjectbrickItem', $segmentName, get_debug_type($brickItem)),
                );
            }
            $brickDef = $brickItem->getDefinition();
            $brickLeafPrefix = $leafPath . '.' . $brickDef->getKey();

            $leaves = array_merge(
                $leaves,
                $this->walk(
                    container: $brickItem,
                    classDef: $brickDef,
                    segments: $remaining,
                    language: $language,
                    originalPath: $originalPath,
                    leafPathPrefix: $brickLeafPrefix,
                ),
            );
        }

        return $leaves;
    }

    /**
     * Last segment of the path — either a simple field (localized or
     * not), or a container (fieldcollection / objectbrick) that fans out
     * to every reachable localized leaf.
     *
     * @return ResolvedLeaf[]
     */
    private function resolveTerminal(
        Concrete|FieldcollectionItem|ObjectbrickItem $container,
        Data $segmentDef,
        string $segmentName,
        bool $isLocalizedLeaf,
        string $language,
        string $originalPath,
        string $leafPath,
    ): array {
        if ($segmentDef instanceof Fieldcollections) {
            $inheritedBefore = AbstractObject::getGetInheritedValues();
            AbstractObject::setGetInheritedValues(false);
            try {
                $fc = $this->callGetter($container, $segmentName, null, $originalPath);
            } finally {
                AbstractObject::setGetInheritedValues($inheritedBefore);
            }
            if ($fc === null) {
                return [];
            }
            if (!$fc instanceof Fieldcollection) {
                throw new InvalidFieldPathException(
                    $originalPath,
                    sprintf('segment "%s" getter returned %s, expected Fieldcollection or null', $segmentName, get_debug_type($fc)),
                );
            }

            $leaves = [];
            foreach ($fc->getItems() as $index => $item) {
                $leaves = array_merge(
                    $leaves,
                    $this->expandContainerLeaves(
                        container: $item,
                        classDef: $item->getDefinition(),
                        language: $language,
                        originalPath: $originalPath,
                        leafPathPrefix: $leafPath . '[' . $index . ']',
                    ),
                );
            }

            return $leaves;
        }

        if ($segmentDef instanceof Objectbricks) {
            $brickContainer = $this->callGetter($container, $segmentName, null, $originalPath);
            if ($brickContainer === null) {
                return [];
            }
            if (!$brickContainer instanceof Objectbrick) {
                throw new InvalidFieldPathException(
                    $originalPath,
                    sprintf('segment "%s" getter returned %s, expected Objectbrick or null', $segmentName, get_debug_type($brickContainer)),
                );
            }

            $leaves = [];
            foreach ($brickContainer->getItems() as $brickItem) {
                if (!$brickItem instanceof ObjectbrickItem) {
                    throw new InvalidFieldPathException(
                        $originalPath,
                        sprintf('objectbrick container "%s" returned an item of type %s, expected ObjectbrickItem', $segmentName, get_debug_type($brickItem)),
                    );
                }
                $leaves = array_merge(
                    $leaves,
                    $this->expandContainerLeaves(
                        container: $brickItem,
                        classDef: $brickItem->getDefinition(),
                        language: $language,
                        originalPath: $originalPath,
                        leafPathPrefix: $leafPath . '.' . $brickItem->getDefinition()->getKey(),
                    ),
                );
            }

            return $leaves;
        }

        $value = $isLocalizedLeaf
            ? $this->callGetter($container, $segmentName, $language, $originalPath)
            : $this->callGetter($container, $segmentName, null, $originalPath);

        return [new ResolvedLeaf($leafPath, $language, $value)];
    }

    /**
     * Fan out across every `Localizedfields` child of the container's
     * class definition, emitting one `ResolvedLeaf` per localized field
     * per item — the container-terminated path expansion.
     *
     * @return ResolvedLeaf[]
     */
    private function expandContainerLeaves(
        Concrete|FieldcollectionItem|ObjectbrickItem $container,
        object $classDef,
        string $language,
        string $originalPath,
        string $leafPathPrefix,
    ): array {
        $leaves = [];
        foreach ($this->fieldDefinitionsOf($classDef, $originalPath) as $fieldName => $fieldDef) {
            if (!$fieldDef instanceof Localizedfields) {
                continue;
            }
            foreach ($fieldDef->getFieldDefinitions() as $localizedFieldName => $localizedFieldDef) {
                $value = $this->callGetter($container, $localizedFieldName, $language, $originalPath);
                $leaves[] = new ResolvedLeaf(
                    $leafPathPrefix . '.' . $localizedFieldName,
                    $language,
                    $value,
                );
            }
        }

        return $leaves;
    }

    /**
     * Pimcore's `getFieldDefinition($name)` transparently descends into a
     * `Localizedfields` container when looking up a name not found at the
     * top level. The resolver needs to know *which* level the field came
     * from so it can call the localized-aware getter signature, so the
     * localized lookup runs explicitly first.
     *
     * @return array{def: Data, localized: bool}
     */
    private function resolveFieldDefinition(object $classDef, string $name, string $originalPath): array
    {
        foreach ($this->fieldDefinitionsOf($classDef, $originalPath) as $topLevelName => $fieldDef) {
            if ($topLevelName === $name) {
                return ['def' => $fieldDef, 'localized' => false];
            }
            if ($fieldDef instanceof Localizedfields) {
                $nested = $fieldDef->getFieldDefinition($name);
                if ($nested instanceof Data) {
                    return ['def' => $nested, 'localized' => true];
                }
            }
        }

        throw new InvalidFieldPathException(
            $originalPath,
            sprintf('segment "%s" does not exist on the current container', $name),
        );
    }

    private function fieldDefinitionsHolderFor(Concrete $object): object
    {
        return $object->getClass();
    }

    /**
     * @return array<string, Data>
     */
    private function fieldDefinitionsOf(object $classDef, string $originalPath): array
    {
        if (!method_exists($classDef, 'getFieldDefinitions')) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf(
                    'class definition object of type "%s" does not implement getFieldDefinitions(); the resolver cannot walk it',
                    get_debug_type($classDef),
                ),
            );
        }

        /** @var array<string, Data> $defs */
        $defs = $classDef->getFieldDefinitions();

        return $defs;
    }

    private function callGetter(object $object, string $fieldName, ?string $language, string $originalPath): mixed
    {
        $getter = 'get' . ucfirst($fieldName);
        if (!method_exists($object, $getter)) {
            throw new InvalidFieldPathException(
                $originalPath,
                sprintf(
                    'getter "%s" is absent on "%s" — the generated class file may be stale; try regenerating Pimcore classes',
                    $getter,
                    get_debug_type($object),
                ),
            );
        }

        return $language === null ? $object->{$getter}() : $object->{$getter}($language);
    }
}
