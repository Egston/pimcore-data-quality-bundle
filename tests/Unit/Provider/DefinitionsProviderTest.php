<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Provider;

use Basilicom\DataQualityBundle\Definition\MinimumStringLengthDefinition;
use Basilicom\DataQualityBundle\Definition\NotEmptyDefinition;
use Basilicom\DataQualityBundle\Model\Provider\DefinitionsProvider;
use Basilicom\DataQualityBundle\Registry\RuleRegistry;
use PHPUnit\Framework\TestCase;

final class DefinitionsProviderTest extends TestCase
{
    public function test_dropdown_options_match_previous_static_array_output(): void
    {
        $registry = new RuleRegistry();
        $registry->register('Not Empty', new NotEmptyDefinition());
        $registry->register('Minimum String Length', new MinimumStringLengthDefinition());

        $provider = new DefinitionsProvider($registry);

        $expected = [
            ['value' => NotEmptyDefinition::class, 'key' => 'Not Empty'],
            ['value' => MinimumStringLengthDefinition::class, 'key' => 'Minimum String Length'],
        ];
        self::assertSame($expected, $provider->getOptions(null, null));
    }

    public function test_empty_registry_yields_empty_options(): void
    {
        $provider = new DefinitionsProvider(new RuleRegistry());

        self::assertSame([], $provider->getOptions(null, null));
    }
}
