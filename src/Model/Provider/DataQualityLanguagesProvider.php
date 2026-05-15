<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Model\Provider;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;
use Pimcore\Tool;

/**
 * Multiselect options provider for `DataQualityConfig.dataQualityLanguages`.
 *
 * Returns the full Pimcore valid-languages set; an empty selection on
 * the config is the user-facing intent "score every configured
 * language" and is honored by `DataQualityProvider::resolveScoredLanguages`.
 */
class DataQualityLanguagesProvider implements SelectOptionsProviderInterface
{
    public function getOptions(array $context, Data $fieldDefinition): array
    {
        $result = [];
        foreach (Tool::getValidLanguages() as $language) {
            $result[] = [
                'key'   => $language,
                'value' => $language,
            ];
        }

        return $result;
    }

    public function hasStaticOptions(array $context, Data $fieldDefinition): bool
    {
        return false;
    }

    public function getDefaultValue(array $context, Data $fieldDefinition): string|array|null
    {
        return null;
    }
}
