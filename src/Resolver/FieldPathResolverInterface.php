<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Resolver;

use Pimcore\Model\DataObject\Concrete;

interface FieldPathResolverInterface
{
    /**
     * Walk $path against $object and return the set of matching leaves in
     * $language.
     *
     * @return ResolvedLeaf[]
     *
     * @throws InvalidFieldPathException
     */
    public function resolve(Concrete $object, string $path, string $language): array;
}
