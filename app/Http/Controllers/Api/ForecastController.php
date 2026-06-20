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
            'latitude'      => $lat,
            'longitude'     => $lon,
            'current'       => [
                'temperature_2m',
                'weathercode',
                'windspeed_10m',
                'winddirection_10m',
                'precipitation',
            ],
            'hourly'        => [
                'temperature_2m',
                'weathercode',
                'windspeed_10m',
                'winddirection_10m',
                'precipitation',
            ],
            'daily'         => [
                'weathercode',
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_sum',
                'windspeed_10m_max',
                'winddirection_10m_dominant',
            ],
            'timezone'      => 'auto',
            'forecast_days' => self::FORECAST_DAYS,
        ]);

        if ($weatherResponse->failed()) {
            return response()->json([
                'message' => 'Could not fetch weather data. Try again later.',
            ], 500);
        }

        $marineResponse = Http::timeout(15)->get('https://marine-api.open-meteo.com/v1/marine', [
            'latitude'      => $lat,
            'longitude'     => $lon,
            'hourly'        => 'sea_level_height_msl',
            'timezone'      => 'auto',
            'forecast_days' => self::FORECAST_DAYS,
        ]);

        $weather = $weatherResponse->json();
        $current = $weather['current'];
        $marine  = $marineResponse->successful() ? $marineResponse->json() : null;

        $score = $this->calculateFishingScore(
            $current['windspeed_10m'],
            $current['precipitation'],
            $current['weathercode']
        );

        $forecastDays = $this->formatDailyForecast(
            $weather['daily'],
            $weather['hourly'] ?? [],
            $marine['hourly'] ?? null
        );

        $currentPayload = [
            'temperature_c'         => $current['temperature_2m'],
            'weather'               => $this->describeWeather($current['weathercode']),
            'weather_code'          => $current['weathercode'],
            'wind_speed_kph'        => $current['windspeed_10m'],
            'wind_direction_deg'    => $current['winddirection_10m'],
            'wind_direction'        => $this->degreesToCompass($current['winddirection_10m']),
            'precipitation_mm'      => $current['precipitation'],
        ];

        return response()->json([
            'location'      => [
                'latitude'  => $lat,
                'longitude' => $lon,
            ],
            'current'       => $currentPayload,
            'today'         => $currentPayload,
            'moon_phase'    => $this->getMoonPhase(),
            'fishing_score' => $score,
            'score_label'   => $this->getScoreLabel($score),
            'fishing_tip'   => $this->getFishingTip($score),
            'tip'           => $this->getFishingTip($score),
            'forecast_days' => $forecastDays,
        ]);
    }

    private function calculateFishingScore($windSpeed, $precipitation, $weatherCode)
    {
        $score = 100;

        if ($windSpeed > 30)      $score -= 30;
        elseif ($windSpeed > 20)  $score -= 15;
        elseif ($windSpeed > 10)  $score -= 5;

        if ($precipitation > 5)      $score -= 30;
        elseif ($precipitation > 2)  $score -= 15;
        elseif ($precipitation > 0)  $score -= 5;

        if ($weatherCode >= 95)      $score -= 40;
        elseif ($weatherCode >= 80)  $score -= 20;
        elseif ($weatherCode >= 61)  $score -= 15;

        return max(0, min(100, $score));
    }

    private function getScoreLabel($score)
    {
        if ($score >= 80)      return 'Excellent';
        if ($score >= 60)      return 'Good';
        if ($score >= 40)      return 'Fair';
        if ($score >= 20)      return 'Tough';
        return 'Poor';
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

    private function getFishingTip($score)
    {
        if ($score >= 80)      return 'Excellent day to fish! Get your gear ready.';
        if ($score >= 60)      return 'Good conditions. Fish should be active.';
        if ($score >= 40)      return 'Fair conditions. Try sheltered spots.';
        if ($score >= 20)      return 'Tough conditions. Fish deeper or wait it out.';
        return 'Poor conditions. Best to stay home today.';
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

        $days = [];

        foreach ($daily['time'] as $index => $date) {
            $score = $this->calculateFishingScore(
                $daily['windspeed_10m_max'][$index],
                $daily['precipitation_sum'][$index],
                $daily['weathercode'][$index]
            );

            $windDeg = $daily['winddirection_10m_dominant'][$index] ?? null;

            $days[] = [
                'date'               => $date,
                'day_name'           => date('D', strtotime($date)),
                'label'              => $index === 0 ? 'Today' : date('D', strtotime($date)),
                'weather'            => $this->describeWeather($daily['weathercode'][$index]),
                'weather_code'       => $daily['weathercode'][$index],
                'temp_max_c'         => $daily['temperature_2m_max'][$index],
                'temp_min_c'         => $daily['temperature_2m_min'][$index],
                'wind_max_kph'       => $daily['windspeed_10m_max'][$index],
                'wind_direction_deg' => $windDeg,
                'wind_direction'     => $this->degreesToCompass($windDeg),
                'rain_mm'            => $daily['precipitation_sum'][$index],
                'precipitation_mm'   => $daily['precipitation_sum'][$index],
                'fishing_score'      => $score,
                'score_label'        => $this->getScoreLabel($score),
                'fishing_tip'        => $this->getFishingTip($score),
                'tides'              => $tidesByDate[$date] ?? [],
                'hourly'             => $hourlyByDate[$date] ?? [],
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
            $windDeg = $hourly['winddirection_10m'][$index] ?? null;

            $grouped[$date][] = [
                'time'               => $time,
                'temperature_c'      => $hourly['temperature_2m'][$index] ?? null,
                'weather'            => $this->describeWeather($hourly['weathercode'][$index] ?? 0),
                'weather_code'       => $hourly['weathercode'][$index] ?? null,
                'wind_speed_kph'     => $hourly['windspeed_10m'][$index] ?? null,
                'wind_direction_deg' => $windDeg,
                'wind_direction'     => $this->degreesToCompass($windDeg),
                'precipitation_mm'   => $hourly['precipitation'][$index] ?? null,
            ];
        }

        return $grouped;
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
                'time'      => substr($timestamp, 11, 5),
                'height_m'  => round((float) $height, 2),
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
                    'time'      => $points[$i]['time'],
                    'type'      => 'high',
                    'height_m'  => $curr,
                ];
            } elseif ($curr <= $prev && $curr <= $next && (max($prev, $next) - $curr) >= 0.03) {
                $events[] = [
                    'time'      => $points[$i]['time'],
                    'type'      => 'low',
                    'height_m'  => $curr,
                ];
            }
        }

        usort($events, fn ($a, $b) => strcmp($a['time'], $b['time']));

        return $events;
    }
}
