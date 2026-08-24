<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorDeliveryRate;
use App\Services\DeliveryFeeService;
use App\Support\PhilippineRegions;
use Illuminate\Http\Request;

class DeliveryAdminController extends Controller
{
    public function __construct(private DeliveryFeeService $delivery)
    {
    }

    public function show()
    {
        $defaults = $this->delivery->defaults();
        $vendors = Vendor::with('deliveryRate')
            ->orderBy('store_name')
            ->get()
            ->map(function (Vendor $vendor) {
                $rates = $this->delivery->resolvedRates($vendor);

                return [
                    'id' => $vendor->id,
                    'store_name' => $vendor->store_name,
                    'city' => $vendor->city,
                    'province' => $vendor->province,
                    'island_group' => PhilippineRegions::islandGroup($vendor->province, $vendor->island_group),
                    'local_area' => $vendor->local_area,
                    'address' => $vendor->address,
                    'rates' => $rates,
                    'overrides' => $vendor->deliveryRate,
                ];
            });

        return response()->json([
            'defaults' => $defaults,
            'vendors' => $vendors,
            'provinces' => PhilippineRegions::provincesByIsland(),
        ]);
    }

    public function updateDefaults(Request $request)
    {
        $data = $this->validateFees($request, false);
        $row = $this->delivery->defaults();
        $row->update($data);

        return response()->json([
            'message' => 'Default delivery fees saved.',
            'defaults' => $row->fresh(),
        ]);
    }

    public function updateVendor(Request $request, $vendorId)
    {
        $vendor = Vendor::findOrFail($vendorId);
        $data = $request->validate([
            'city' => 'nullable|string|max:80',
            'province' => 'nullable|string|max:80',
            'island_group' => 'nullable|in:luzon,visayas,mindanao',
            'local_area' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:255',
            'same_city_fee' => 'nullable|numeric|min:0',
            'same_province_fee' => 'nullable|numeric|min:0',
            'luzon_fee' => 'nullable|numeric|min:0',
            'visayas_fee' => 'nullable|numeric|min:0',
            'mindanao_fee' => 'nullable|numeric|min:0',
            'pickup_enabled' => 'nullable|boolean',
            'delivery_enabled' => 'nullable|boolean',
        ]);

        $island = PhilippineRegions::islandGroup($data['province'] ?? $vendor->province, $data['island_group'] ?? null);

        $vendor->update([
            'city' => $data['city'] ?? $vendor->city,
            'province' => $data['province'] ?? $vendor->province,
            'island_group' => $island,
            'local_area' => $data['local_area'] ?? $vendor->local_area,
            'address' => array_key_exists('address', $data) ? $data['address'] : $vendor->address,
        ]);

        $rate = VendorDeliveryRate::updateOrCreate(
            ['vendor_id' => $vendor->id],
            [
                'same_city_fee' => $data['same_city_fee'] ?? null,
                'same_province_fee' => $data['same_province_fee'] ?? null,
                'luzon_fee' => $data['luzon_fee'] ?? null,
                'visayas_fee' => $data['visayas_fee'] ?? null,
                'mindanao_fee' => $data['mindanao_fee'] ?? null,
                'pickup_enabled' => $data['pickup_enabled'] ?? null,
                'delivery_enabled' => $data['delivery_enabled'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Store location and delivery fees saved.',
            'vendor' => $vendor->fresh(),
            'rates' => $this->delivery->resolvedRates($vendor->fresh()),
            'overrides' => $rate,
        ]);
    }

    private function validateFees(Request $request, bool $nullable): array
    {
        $rule = $nullable ? 'nullable|numeric|min:0' : 'required|numeric|min:0';

        return $request->validate([
            'same_city_fee' => $rule,
            'same_province_fee' => $rule,
            'luzon_fee' => $rule,
            'visayas_fee' => $rule,
            'mindanao_fee' => $rule,
            'pickup_enabled' => 'sometimes|boolean',
            'delivery_enabled' => 'sometimes|boolean',
        ]);
    }
}
