<?php

namespace App\Support;

class PhilippineRegions
{
    /** @var array<string, string> province/city key => island group */
    public const PROVINCES = [
        // Luzon
        'abra' => 'luzon',
        'albay' => 'luzon',
        'apayao' => 'luzon',
        'aurora' => 'luzon',
        'bataan' => 'luzon',
        'batanes' => 'luzon',
        'batangas' => 'luzon',
        'benguet' => 'luzon',
        'bulacan' => 'luzon',
        'cagayan' => 'luzon',
        'camarines norte' => 'luzon',
        'camarines sur' => 'luzon',
        'catanduanes' => 'luzon',
        'cavite' => 'luzon',
        'ifugao' => 'luzon',
        'ilocos norte' => 'luzon',
        'ilocos sur' => 'luzon',
        'isabela' => 'luzon',
        'kalinga' => 'luzon',
        'la union' => 'luzon',
        'laguna' => 'luzon',
        'marinduque' => 'luzon',
        'masbate' => 'luzon',
        'metro manila' => 'luzon',
        'ncr' => 'luzon',
        'manila' => 'luzon',
        'quezon city' => 'luzon',
        'mountain province' => 'luzon',
        'nueva ecija' => 'luzon',
        'nueva vizcaya' => 'luzon',
        'occidental mindoro' => 'luzon',
        'oriental mindoro' => 'luzon',
        'palawan' => 'luzon',
        'pampanga' => 'luzon',
        'pangasinan' => 'luzon',
        'quezon' => 'luzon',
        'quirino' => 'luzon',
        'rizal' => 'luzon',
        'romblon' => 'luzon',
        'sorsogon' => 'luzon',
        'tarlac' => 'luzon',
        'zambales' => 'luzon',
        // Visayas
        'aklan' => 'visayas',
        'antique' => 'visayas',
        'biliran' => 'visayas',
        'bohol' => 'visayas',
        'capiz' => 'visayas',
        'cebu' => 'visayas',
        'eastern samar' => 'visayas',
        'guimaras' => 'visayas',
        'iloilo' => 'visayas',
        'leyte' => 'visayas',
        'negros occidental' => 'visayas',
        'negros oriental' => 'visayas',
        'northern samar' => 'visayas',
        'samar' => 'visayas',
        'siquijor' => 'visayas',
        'southern leyte' => 'visayas',
        // Mindanao
        'agusan del norte' => 'mindanao',
        'agusan del sur' => 'mindanao',
        'basilan' => 'mindanao',
        'bukidnon' => 'mindanao',
        'camiguin' => 'mindanao',
        'cotabato' => 'mindanao',
        'north cotabato' => 'mindanao',
        'davao de oro' => 'mindanao',
        'compostela valley' => 'mindanao',
        'davao del norte' => 'mindanao',
        'davao del sur' => 'mindanao',
        'davao occidental' => 'mindanao',
        'davao oriental' => 'mindanao',
        'dinagat islands' => 'mindanao',
        'lanao del norte' => 'mindanao',
        'lanao del sur' => 'mindanao',
        'maguindanao' => 'mindanao',
        'maguindanao del norte' => 'mindanao',
        'maguindanao del sur' => 'mindanao',
        'misamis occidental' => 'mindanao',
        'misamis oriental' => 'mindanao',
        'sarangani' => 'mindanao',
        'south cotabato' => 'mindanao',
        'sultan kudarat' => 'mindanao',
        'sulu' => 'mindanao',
        'surigao del norte' => 'mindanao',
        'surigao del sur' => 'mindanao',
        'tawi-tawi' => 'mindanao',
        'zamboanga del norte' => 'mindanao',
        'zamboanga del sur' => 'mindanao',
        'zamboanga sibugay' => 'mindanao',
        'davao city' => 'mindanao',
    ];

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['.', ',', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function islandGroup(?string $province, ?string $fallback = null): ?string
    {
        if ($fallback && in_array($fallback, ['luzon', 'visayas', 'mindanao'], true)) {
            $explicit = $fallback;
        } else {
            $explicit = null;
        }

        $key = self::normalize((string) $province);
        if ($key === '') {
            return $explicit;
        }

        if (isset(self::PROVINCES[$key])) {
            return self::PROVINCES[$key];
        }

        foreach (self::PROVINCES as $name => $group) {
            if (str_contains($key, $name) || str_contains($name, $key)) {
                return $group;
            }
        }

        return $explicit;
    }

    public static function provincesByIsland(): array
    {
        $grouped = ['luzon' => [], 'visayas' => [], 'mindanao' => []];
        foreach (self::PROVINCES as $name => $group) {
            if (in_array($name, ['ncr', 'compostela valley', 'north cotabato'], true)) {
                continue;
            }
            $grouped[$group][] = ucwords($name);
        }

        return $grouped;
    }

    public static function samePlace(?string $a, ?string $b): bool
    {
        $left = self::normalize((string) $a);
        $right = self::normalize((string) $b);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        return str_contains($left, $right) || str_contains($right, $left);
    }
}
