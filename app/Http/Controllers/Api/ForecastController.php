<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ForecastController extends Controller
{
    // GET FISHING FORECAST BY COORDINATES
    public function getForecast(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $lat = $request->latitude;
        $lon = $request->longitude;

        // Call the free Open-Meteo weather API
        $response = Http::get('https://api.open-meteo.com/v1/forecast', [
            'latitude'      => $lat,
            'longitude'     => $lon,
            'current'       => [
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
            ],
            'timezone'      => 'auto',
            'forecast_days' => 3,
        ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Could not fetch weather data. Try again later.',
            ], 500);
        }

        $weather = $response->json();
        $current = $weather['current'];

        // Calculate the fishing score
        $score = $this->calculateFishingScore(
            $current['windspeed_10m'],
            $current['precipitation'],
            $current['weathercode']
        );

        // Get today's moon phase
        $moonPhase = $this->getMoonPhase();

        return response()->json([
            'location' => [
                'latitude'  => $lat,
                'longitude' => $lon,
            ],
            'current' => [
                'temperature_c'    => $current['temperature_2m'],
                'weather'          => $this->describeWeather($current['weathercode']),
                'wind_speed_kph'   => $current['windspeed_10m'],
                'wind_direction'   => $current['winddirection_10m'],
                'precipitation_mm' => $current['precipitation'],
            ],
            'moon_phase'    => $moonPhase,
            'fishing_score' => $score,
            'fishing_tip'   => $this->getFishingTip($score),
            'forecast_days' => $this->formatDailyForecast($weather['daily']),
        ]);
    }

    // ─── HELPER FUNCTIONS ─────────────────────────────────

    // Calculate fishing score from 0 to 100
    private function calculateFishingScore($windSpeed, $precipitation, $weatherCode)
    {
        $score = 100;

        // Deduct for strong wind
        if ($windSpeed > 30)      $score -= 30;
        elseif ($windSpeed > 20)  $score -= 15;
        elseif ($windSpeed > 10)  $score -= 5;

        // Deduct for rain
        if ($precipitation > 5)      $score -= 30;
        elseif ($precipitation > 2)  $score -= 15;
        elseif ($precipitation > 0)  $score -= 5;

        // Deduct for bad weather
        if ($weatherCode >= 95)      $score -= 40; // Thunderstorm
        elseif ($weatherCode >= 80)  $score -= 20; // Heavy showers
        elseif ($weatherCode >= 61)  $score -= 15; // Rain

        // Never go below 0 or above 100
        return max(0, min(100, $score));
    }

    // Turn weather code numbers into plain English
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

    // Give a tip based on the score
    private function getFishingTip($score)
    {
        if ($score >= 80)      return 'Excellent day to fish! Get your gear ready.';
        elseif ($score >= 60)  return 'Good conditions. Fish should be active.';
        elseif ($score >= 40)  return 'Fair conditions. Try sheltered spots.';
        elseif ($score >= 20)  return 'Tough conditions. Fish deeper or wait it out.';
        else                   return 'Poor conditions. Best to stay home today.';
    }

    // Calculate today's moon phase
    private function getMoonPhase()
    {
        $now   = new \DateTime();
        $known = new \DateTime('2000-01-06');
        $diff  = $known->diff($now)->days;
        $cycle = 29.53058867;
        $phase = fmod($diff, $cycle);

        if ($phase < 1.85)      return ['name' => 'New Moon',        'icon' => '🌑'];
        elseif ($phase < 7.38)  return ['name' => 'Waxing Crescent', 'icon' => '🌒'];
        elseif ($phase < 9.22)  return ['name' => 'First Quarter',   'icon' => '🌓'];
        elseif ($phase < 14.77) return ['name' => 'Waxing Gibbous',  'icon' => '🌔'];
        elseif ($phase < 16.61) return ['name' => 'Full Moon',       'icon' => '🌕'];
        elseif ($phase < 22.15) return ['name' => 'Waning Gibbous',  'icon' => '🌖'];
        elseif ($phase < 23.99) return ['name' => 'Last Quarter',    'icon' => '🌗'];
        else                    return ['name' => 'Waning Crescent', 'icon' => '🌘'];
    }

    // Format the 3-day forecast into a clean array
    private function formatDailyForecast($daily)
    {
        $days = [];

        foreach ($daily['time'] as $index => $date) {
            $days[] = [
                'date'          => $date,
                'weather'       => $this->describeWeather(
                                        $daily['weathercode'][$index]
                                   ),
                'temp_max_c'    => $daily['temperature_2m_max'][$index],
                'temp_min_c'    => $daily['temperature_2m_min'][$index],
                'wind_max_kph'  => $daily['windspeed_10m_max'][$index],
                'rain_mm'       => $daily['precipitation_sum'][$index],
                'fishing_score' => $this->calculateFishingScore(
                                        $daily['windspeed_10m_max'][$index],
                                        $daily['precipitation_sum'][$index],
                                        $daily['weathercode'][$index]
                                   ),
            ];
        }

        return $days;
    }
}