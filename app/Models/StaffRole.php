<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StaffRole extends Model
{
    public const FUNCTIONS = [
        'dashboard'         => 'Dashboard',
        'users'             => 'Users',
        'catches'           => 'Catches',
        'reports'           => 'Reports',
        'account-deletion'  => 'Deletions',
        'products'          => 'Products',
        'categories'        => 'Categories',
        'fish-points'       => 'FishPoints',
        'affiliates'        => 'Affiliates',
        'tournaments'       => 'Tournaments',
        'fishing-boats'     => 'Fishing Boats',
        'vendors'           => 'Vendors',
        'delivery'          => 'Delivery',
        'leaderboard'       => 'Leaderboard',
        'spots'             => 'Fishing Spots',
        'municipalities'    => 'Municipalities',
        'resorts'           => 'Resorts',
        'notifications'     => 'Notifications',
        'staff'             => 'Staff',
        'roles'             => 'Roles',
    ];

    public const GROUPS = [
        'Console'     => ['dashboard'],
        'Community'   => ['users', 'catches', 'reports', 'account-deletion', 'spots', 'leaderboard', 'notifications'],
        'Marketplace' => ['products', 'categories', 'vendors', 'delivery', 'fish-points', 'affiliates'],
        'Operations'  => ['tournaments', 'fishing-boats', 'municipalities', 'resorts'],
        'Access'      => ['staff', 'roles'],
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_system',
        'permissions',
    ];

    protected $casts = [
        'is_system'   => 'boolean',
        'permissions' => 'array',
    ];

    public static function allFunctionKeys(): array
    {
        return array_keys(self::FUNCTIONS);
    }

    public static function catalog(): array
    {
        return collect(self::GROUPS)
            ->map(fn (array $ids, string $group) => [
                'group' => $group,
                'items' => collect($ids)->map(fn ($id) => [
                    'id'    => $id,
                    'label' => self::FUNCTIONS[$id],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    public static function developerToolKeys(): array
    {
        return ['staff', 'roles'];
    }

    public static function sanitizePermissions(?array $permissions, bool $allowDeveloperTools = true): array
    {
        $keys = $allowDeveloperTools
            ? self::allFunctionKeys()
            : array_values(array_diff(self::allFunctionKeys(), self::developerToolKeys()));

        return array_values(array_unique(array_intersect($permissions ?? [], $keys)));
    }

    public static function permissionsFor(string $slug): array
    {
        $keys = self::allFunctionKeys();

        if ($slug === 'developer') {
            return $keys;
        }

        $role = static::query()->where('slug', $slug)->first();
        $stored = $role?->permissions;

        if (! is_array($stored)) {
            return array_values(array_diff($keys, self::developerToolKeys()));
        }

        return self::sanitizePermissions($stored);
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
