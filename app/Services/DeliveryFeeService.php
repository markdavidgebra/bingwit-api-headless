<?php

namespace App\Services;

use App\Models\DeliverySetting;
use App\Models\Vendor;
use App\Models\VendorDeliveryRate;
use App\Support\PhilippineRegions;
use RuntimeException;

class DeliveryFeeService
{
    public const ZONES = [
        'pickup' => 'Store pickup',
        'same_city' => 'Local delivery',
        'same_province' => 'Same province',
        'luzon' => 'Luzon',
        'visayas' => 'Visayas',
        'mindanao' => 'Mindanao',
    ];

    public function defaults(): DeliverySetting
    {
        return DeliverySetting::current();
    }

    public function resolvedRates(?Vendor $vendor): array
    {
        $defaults = $this->defaults();
        $override = $vendor?->id
            ? VendorDeliveryRate::where('vendor_id', $vendor->id)->first()
            : null;

        $fee = function (string $key) use ($defaults, $override) {
            $value = $override?->{$key};
            if ($value !== null && $value !== '') {
                return (float) $value;
            }

            return (float) $defaults->{$key};
        };

        $flag = function (string $key) use ($defaults, $override) {
            if ($override && $override->{$key} !== null) {
                return (bool) $override->{$key};
            }

            return (bool) $defaults->{$key};
        };

        return [
            'same_city_fee' => $fee('same_city_fee'),
            'same_province_fee' => $fee('same_province_fee'),
            'luzon_fee' => $fee('luzon_fee'),
            'visayas_fee' => $fee('visayas_fee'),
            'mindanao_fee' => $fee('mindanao_fee'),
            'pickup_enabled' => $flag('pickup_enabled'),
            'delivery_enabled' => $flag('delivery_enabled'),
        ];
    }

    public function quote(?Vendor $vendor, string $fulfillment, ?string $city = null, ?string $province = null): array
    {
        $rates = $this->resolvedRates($vendor);

        if ($fulfillment === 'pickup') {
            if (! $rates['pickup_enabled']) {
                throw new RuntimeException('Pickup is not available from this store.');
            }

            return $this->result('pickup', 0, $rates);
        }

        if ($fulfillment !== 'delivery') {
            throw new RuntimeException('Choose pickup or delivery.');
        }

        if (! $rates['delivery_enabled']) {
            throw new RuntimeException('Delivery is not available from this store.');
        }

        $zone = $this->zoneFor($vendor, $city, $province);
        $feeKey = $zone === 'same_city' || $zone === 'same_province'
            ? $zone.'_fee'
            : $zone.'_fee';

        return $this->result($zone, (float) $rates[$feeKey], $rates, $city, $province);
    }

    public function zoneFor(?Vendor $vendor, ?string $city, ?string $province): string
    {
        $destIsland = PhilippineRegions::islandGroup($province);
        if (! $destIsland) {
            throw new RuntimeException('Select a valid Philippine province.');
        }

        if ($vendor) {
            $vendorCity = $vendor->city;
            $localArea = $vendor->local_area ?: $vendor->city;
            $sameCity = PhilippineRegions::samePlace($city, $vendorCity)
                || PhilippineRegions::samePlace($city, $localArea)
                || PhilippineRegions::samePlace($localArea, $city);

            if ($sameCity) {
                return 'same_city';
            }

            if (PhilippineRegions::samePlace($province, $vendor->province)) {
                return 'same_province';
            }
        }

        return $destIsland;
    }

    public function options(?Vendor $vendor): array
    {
        $rates = $this->resolvedRates($vendor);
        $vendorIsland = PhilippineRegions::islandGroup($vendor?->province, $vendor?->island_group);

        return [
            'pickup_enabled' => $rates['pickup_enabled'],
            'delivery_enabled' => $rates['delivery_enabled'],
            'vendor' => $vendor ? [
                'id' => $vendor->id,
                'store_name' => $vendor->store_name,
                'city' => $vendor->city,
                'province' => $vendor->province,
                'island_group' => $vendorIsland,
                'local_area' => $vendor->local_area,
                'address' => $vendor->address,
            ] : null,
            'fees' => [
                'same_city' => $rates['same_city_fee'],
                'same_province' => $rates['same_province_fee'],
                'luzon' => $rates['luzon_fee'],
                'visayas' => $rates['visayas_fee'],
                'mindanao' => $rates['mindanao_fee'],
            ],
            'provinces' => PhilippineRegions::provincesByIsland(),
        ];
    }

    private function result(string $zone, float $fee, array $rates, ?string $city = null, ?string $province = null): array
    {
        return [
            'fulfillment' => $zone === 'pickup' ? 'pickup' : 'delivery',
            'zone' => $zone,
            'zone_label' => self::ZONES[$zone] ?? $zone,
            'fee' => round($fee, 2),
            'city' => $city,
            'province' => $province,
            'island_group' => in_array($zone, ['luzon', 'visayas', 'mindanao'], true)
                ? $zone
                : PhilippineRegions::islandGroup($province),
            'pickup_enabled' => $rates['pickup_enabled'],
            'delivery_enabled' => $rates['delivery_enabled'],
        ];
    }
}
