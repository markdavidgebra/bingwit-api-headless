<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ForecastController extends Controller
{
    private const FORECAST_DAYS = 7;

    public function getForecast(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $lat = $request->latitude;
        $lon = $request->longitude;

        $weatherResponse = Http::timeout(15)->get('https://api.open-meteo.com/v1/forecast', [
            'latitude'  => $lat,
            'longitude' => $lon,
            'current'   => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'apparent_temperature',
                'weathercode',
                'windspeed_10m',
                'winddirection_10m',
                'precipitation',
            ]),
            'hourly'    => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'weathercode',
                'windspeed_10m',
                'winddirection_10m',
                'precipitation',
            ]),
            'daily'     => implode(',', [
                'weathercode',
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_sum',
                'windspeed_10m_max',
                'winddirection_10m_dominant',
                'sunrise',
                'sunset',
                'uv_index_max',
            ]),
            'timezone'      => 'auto',
            'forecast_days' => self::FORECAST_DAYS,
        ]);

        if ($weatherResponse->failed()) {
            return response()->json([
                'message' => 'Could not fetch weather data. Try again later.',
            ], 500);
        }

        $marine = $this->fetchMarine($lat, $lon);
        $weather = $weatherResponse->json();
        $current = $weather['current'];
        $currentTime = $current['time'] ?? null;
        $weatherCode = $this->pick($current, ['weathercode', 'weather_code']) ?? 0;
        $windSpeed = $this->pick($current, ['windspeed_10m', 'wind_speed_10m']);
        $windDir = $this->pick($current, ['winddirection_10m', 'wind_direction_10m']);

        $waveHeight = $this->valueAtTime($marine['hourly'] ?? null, 'wave_height', $currentTime);
        $sst = $this->valueAtTime($marine['hourly'] ?? null, 'sea_surface_temperature', $currentTime);

        $currentPayload = [
            'temperature_c'         => $this->roundNum($current['temperature_2m'] ?? null, 1),
            'feels_like_c'          => $this->roundNum($current['apparent_temperature'] ?? null, 1),
            'humidity_pct'          => $this->roundNum($this->pick($current, ['relative_humidity_2m', 'relativehumidity_2m']), 0),
            'weather'               => $this->describeWeather($weatherCode),
            'weather_code'          => $weatherCode,
            'wind_speed_kph'        => $this->roundNum($windSpeed, 1),
            'wind_direction_deg'    => $this->roundNum($windDir, 0),
            'wind_direction'        => $this->degreesToCompass($windDir),
            'precipitation_mm'      => $this->roundNum($current['precipitation'] ?? null, 1),
            'wave_height_m'         => $this->roundNum($waveHeight, 2),
            'sea_surface_temp_c'    => $this->roundNum($sst, 1),
            'sunrise'               => $this->timeFromIso($weather['daily']['sunrise'][0] ?? null),
            'sunset'                => $this->timeFromIso($weather['daily']['sunset'][0] ?? null),
            'uv_index'              => $this->roundNum($weather['daily']['uv_index_max'][0] ?? null, 1),
        ];

        $forecastDays = $this->formatDailyForecast(
            $weather['daily'],
            $weather['hourly'] ?? [],
            $marine['hourly'] ?? null
        );

        $today = $forecastDays[0] ?? null;

        return response()->json([
            'location'      => [
                'latitude'  => $lat,
                'longitude' => $lon,
            ],
            'current'       => $currentPayload,
            'today'         => $currentPayload,
            'outlook'       => $today['outlook'] ?? $this->getOutlook(
                $currentPayload['wind_speed_kph'],
                $currentPayload['precipitation_mm'],
                $currentPayload['weather_code']
            ),
            'moon_phase'    => $this->getMoonPhase(),
            'forecast_days' => $forecastDays,
        ]);
    }

    private function fetchMarine($lat, $lon): ?array
    {
        $attempts = [
            'sea_level_height_msl,wave_height,sea_surface_temperature',
            'sea_level_height_msl,sea_surface_temperature',
            'sea_level_height_msl',
        ];

        foreach ($attempts as $hourly) {
            $response = Http::timeout(15)->get('https://marine-api.open-meteo.com/v1/marine', [
                'latitude'      => $lat,
                'longitude'     => $lon,
                'hourly'        => $hourly,
                'timezone'      => 'auto',
                'forecast_days' => self::FORECAST_DAYS,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        }

        return null;
    }

    private function describeWeather($code)
    {
        $descriptions = [
            0  => 'Clear sky',
            1  => 'Mainly clear',
            2  => 'Partly cloudy',
            3  => 'Overcast',
            45 => 'Foggy',
            48 => 'Icy fog',
            51 => 'Light drizzle',
            53 => 'Moderate drizzle',
            55 => 'Heavy drizzle',
            61 => 'Slight rain',
            63 => 'Moderate rain',
            65 => 'Heavy rain',
            80 => 'Slight showers',
            81 => 'Moderate showers',
            82 => 'Heavy showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with hail',
            99 => 'Thunderstorm with heavy hail',
        ];

        return $descriptions[$code] ?? 'Unknown conditions';
    }

    private function getOutlook($windSpeed, $precipitation, $weatherCode)
    {
        $wind = (float) ($windSpeed ?? 0);
        $rain = (float) ($precipitation ?? 0);
        $code = (int) ($weatherCode ?? 0);

        if ($code >= 95) {
            return 'Thunderstorms in the area. Stay off open water.';
        }
        if ($wind > 30) {
            return 'Strong winds. Stay near sheltered water if you go.';
        }
        if ($rain > 8 || $code >= 65) {
            return 'Heavy rain expected. Wait for a break between showers.';
        }
        if ($wind > 20) {
            return 'Breezy. Look for leeward banks and cover.';
        }
        if ($rain > 0 || $code >= 51) {
            return 'Some rain in the forecast. Fish the edges of weather.';
        }
        if ($code <= 2 && $wind <= 12) {
            return 'Settled and light. Comfortable conditions on the water.';
        }

        return 'Typical mixed conditions. Watch wind and cloud cover.';
    }

    private function degreesToCompass($degrees)
    {
        if ($degrees === null) {
            return null;
        }

        $directions = [
            'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
            'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW',
        ];

        $index = (int) round(fmod((float) $degrees, 360) / 22.5) % 16;

        return $directions[$index];
    }

    private function getMoonPhase()
    {
        $now   = new \DateTime();
        $known = new \DateTime('2000-01-06');
        $diff  = $known->diff($now)->days;
        $cycle = 29.53058867;
        $phase = fmod($diff, $cycle);

        if ($phase < 1.85)      return ['name' => 'New Moon',        'icon' => '🌑'];
        if ($phase < 7.38)      return ['name' => 'Waxing Crescent', 'icon' => '🌒'];
        if ($phase < 9.22)      return ['name' => 'First Quarter',   'icon' => '🌓'];
        if ($phase < 14.77)     return ['name' => 'Waxing Gibbous',  'icon' => '🌔'];
        if ($phase < 16.61)     return ['name' => 'Full Moon',       'icon' => '🌕'];
        if ($phase < 22.15)     return ['name' => 'Waning Gibbous',  'icon' => '🌖'];
        if ($phase < 23.99)     return ['name' => 'Last Quarter',    'icon' => '🌗'];

        return ['name' => 'Waning Crescent', 'icon' => '🌘'];
    }

    private function formatDailyForecast(array $daily, array $hourly, ?array $marineHourly)
    {
        $hourlyByDate = $this->groupHourlyWeather($hourly);
        $tidesByDate  = $marineHourly
            ? $this->groupTidesByDate($marineHourly['time'] ?? [], $marineHourly['sea_level_height_msl'] ?? [])
            : [];
        $marineByDate = $this->groupMarineByDate($marineHourly);

        $days = [];

        foreach ($daily['time'] as $index => $date) {
            $windMax = $this->pickAt($daily, ['windspeed_10m_max', 'wind_speed_10m_max'], $index);
            $rainMm  = $daily['precipitation_sum'][$index] ?? null;
            $code    = $this->pickAt($daily, ['weathercode', 'weather_code'], $index) ?? 0;
            $windDeg = $this->pickAt($daily, ['winddirection_10m_dominant', 'wind_direction_10m_dominant'], $index);
            $marine  = $marineByDate[$date] ?? [];
            $hours   = $hourlyByDate[$date] ?? [];

            $humidity = null;
            if ($hours) {
                $vals = array_filter(array_column($hours, 'humidity_pct'), fn ($v) => $v !== null);
                $humidity = $vals ? round(array_sum($vals) / count($vals)) : null;
            }

            $days[] = [
                'date'               => $date,
                'day_name'           => date('D', strtotime($date)),
                'label'              => $index === 0 ? 'Today' : date('D', strtotime($date)),
                'weather'            => $this->describeWeather($code),
                'weather_code'       => $code,
                'temp_max_c'         => $this->roundNum($daily['temperature_2m_max'][$index] ?? null, 1),
                'temp_min_c'         => $this->roundNum($daily['temperature_2m_min'][$index] ?? null, 1),
                'wind_max_kph'       => $this->roundNum($windMax, 1),
                'wind_direction_deg' => $this->roundNum($windDeg, 0),
                'wind_direction'     => $this->degreesToCompass($windDeg),
                'rain_mm'            => $this->roundNum($rainMm, 1),
                'precipitation_mm'   => $this->roundNum($rainMm, 1),
                'humidity_pct'       => $humidity,
                'uv_index'           => $this->roundNum($daily['uv_index_max'][$index] ?? null, 1),
                'sunrise'            => $this->timeFromIso($daily['sunrise'][$index] ?? null),
                'sunset'             => $this->timeFromIso($daily['sunset'][$index] ?? null),
                'wave_height_m'      => $marine['wave_height_m'] ?? null,
                'sea_surface_temp_c' => $marine['sea_surface_temp_c'] ?? null,
                'outlook'            => $this->getOutlook($windMax, $rainMm, $code),
                'tides'              => $tidesByDate[$date] ?? [],
                'hourly'             => $hours,
            ];
        }

        return $days;
    }

    private function groupHourlyWeather(array $hourly)
    {
        $grouped = [];

        if (empty($hourly['time'])) {
            return $grouped;
        }

        foreach ($hourly['time'] as $index => $timestamp) {
            $date = substr($timestamp, 0, 10);
            $time = substr($timestamp, 11, 5);
            $windDeg = $this->pickAt($hourly, ['winddirection_10m', 'wind_direction_10m'], $index);
            $code = $this->pickAt($hourly, ['weathercode', 'weather_code'], $index) ?? 0;

            $grouped[$date][] = [
                'time'               => $time,
                'temperature_c'      => $this->roundNum($hourly['temperature_2m'][$index] ?? null, 1),
                'humidity_pct'       => $this->roundNum($this->pickAt($hourly, ['relative_humidity_2m', 'relativehumidity_2m'], $index), 0),
                'weather'            => $this->describeWeather($code),
                'weather_code'       => $code,
                'wind_speed_kph'     => $this->roundNum($this->pickAt($hourly, ['windspeed_10m', 'wind_speed_10m'], $index), 1),
                'wind_direction_deg' => $this->roundNum($windDeg, 0),
                'wind_direction'     => $this->degreesToCompass($windDeg),
                'precipitation_mm'   => $this->roundNum($hourly['precipitation'][$index] ?? null, 1),
            ];
        }

        return $grouped;
    }

    private function groupMarineByDate(?array $marineHourly): array
    {
        if (empty($marineHourly['time'])) {
            return [];
        }

        $byDate = [];

        foreach ($marineHourly['time'] as $index => $timestamp) {
            $date = substr($timestamp, 0, 10);
            $byDate[$date]['waves'][] = $marineHourly['wave_height'][$index] ?? null;
            $byDate[$date]['sst'][] = $marineHourly['sea_surface_temperature'][$index] ?? null;
        }

        $result = [];

        foreach ($byDate as $date => $vals) {
            $waves = array_filter($vals['waves'], fn ($v) => $v !== null);
            $sst = array_filter($vals['sst'], fn ($v) => $v !== null);

            $result[$date] = [
                'wave_height_m'      => $waves ? $this->roundNum(max($waves), 2) : null,
                'sea_surface_temp_c' => $sst ? $this->roundNum(array_sum($sst) / count($sst), 1) : null,
            ];
        }

        return $result;
    }

    private function groupTidesByDate(array $times, array $heights)
    {
        $grouped = [];

        foreach ($times as $index => $timestamp) {
            $height = $heights[$index] ?? null;
            if ($height === null) {
                continue;
            }

            $date = substr($timestamp, 0, 10);
            $grouped[$date][] = [
                'time'     => substr($timestamp, 11, 5),
                'height_m' => round((float) $height, 2),
            ];
        }

        foreach ($grouped as $date => $points) {
            $grouped[$date] = $this->extractTideEvents($points);
        }

        return $grouped;
    }

    private function extractTideEvents(array $points)
    {
        if (count($points) < 3) {
            return [];
        }

        $events = [];

        for ($i = 1; $i < count($points) - 1; $i++) {
            $prev = $points[$i - 1]['height_m'];
            $curr = $points[$i]['height_m'];
            $next = $points[$i + 1]['height_m'];

            if ($curr >= $prev && $curr >= $next && ($curr - min($prev, $next)) >= 0.03) {
                $events[] = [
                    'time'     => $points[$i]['time'],
                    'type'     => 'high',
                    'height_m' => $curr,
                ];
            } elseif ($curr <= $prev && $curr <= $next && (max($prev, $next) - $curr) >= 0.03) {
                $events[] = [
                    'time'     => $points[$i]['time'],
                    'type'     => 'low',
                    'height_m' => $curr,
                ];
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['time'], $b['time']));

        return $events;
    }

    private function valueAtTime(?array $hourly, string $key, ?string $isoTime)
    {
        if (!$hourly || empty($hourly['time']) || empty($hourly[$key]) || !$isoTime) {
            return null;
        }

        $index = array_search($isoTime, $hourly['time'], true);
        if ($index === false) {
            $want = substr($isoTime, 0, 13);
            foreach ($hourly['time'] as $i => $stamp) {
                if (str_starts_with($stamp, $want)) {
                    $index = $i;
                    break;
                }
            }
        }

        if ($index === false) {
            return null;
        }

        return $hourly[$key][$index] ?? null;
    }

    private function timeFromIso(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }

        $time = substr($iso, 11, 5);

        return $time !== '' ? $time : null;
    }

    private function roundNum($value, int $precision)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, $precision);
    }

    private function pick(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return null;
    }

    private function pickAt(array $row, array $keys, int $index)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && array_key_exists($index, $row[$key] ?? [])) {
                return $row[$key][$index];
            }
        }

        return null;
    }
}
