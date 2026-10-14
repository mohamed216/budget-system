<?php

namespace Tests\Unit;

use App\OperationalAccounting\OperationalAssetReservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalAssetReservationTest extends TestCase
{
    public static function facts(): array
    {
        return [
            'active selected owned asset' => ['active', [10 => true], 1, 'asset', true],
            'legacy' => ['legacy', [10 => true], 1, 'asset', false],
            'reconciling' => ['reconciling', [10 => true], 1, 'asset', false],
            'ready' => ['ready', [10 => true], 1, 'asset', false],
            'absent gate' => [null, [10 => true], 1, 'asset', false],
            'unselected target' => ['active', [20 => true], 1, 'asset', false],
            'foreign chart' => ['active', [10 => true], 2, 'asset', false],
            'mapped nonasset' => ['active', [10 => true], 1, 'revenue', false],
        ];
    }

    #[DataProvider('facts')]
    public function test_only_active_selected_owned_assets_are_reserved(?string $state, array $targets, int $chartOwner, string $type, bool $expected): void
    {
        $chart = (object) ['id' => 10, 'user_id' => $chartOwner, 'type' => $type, 'is_active' => false];
        $this->assertSame($expected, (new OperationalAssetReservation)->touchesReservedAsset(1, $state, $targets, [$chart]));
    }
}
