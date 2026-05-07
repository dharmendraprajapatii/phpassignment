<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeocodingService
{
    public function resolve(string $address, string $countryCode): array
    {
        foreach ($this->buildQueries($address) as $query) {
            $result = $this->search($query, $countryCode);

            if ($result) {
                return $result;
            }
        }

        return $this->fallbackLocation($address, $countryCode);
    }

    private function search(string $query, string $countryCode): ?array
    {
        try {
            $response = Http::timeout(5)->retry(2, 200)->get(
                config('services.open_meteo.geocoding_url'),
                [
                    'name' => $query,
                    'count' => 5,
                    'language' => 'en',
                    'format' => 'json',
                    'countryCode' => strtoupper($countryCode),
                ]
            );

            if (!$response->ok()) {
                return null;
            }

            $results = $response->json('results') ?? [];

            foreach ($results as $result) {
                if (($result['country_code'] ?? null) !== strtoupper($countryCode)) {
                    continue;
                }

                return [
                    'city' => $result['name'] ?? $query,
                    'latitude' => (float) ($result['latitude'] ?? 0),
                    'longitude' => (float) ($result['longitude'] ?? 0),
                    'timezone' => $result['timezone'] ?? $this->fallbackTimezone($countryCode),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('Geocoding lookup failed', [
                'query' => $query,
                'country' => $countryCode,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function buildQueries(string $address): array
    {
        $parts = array_map(
            fn ($part) => $this->normalizeFragment($part),
            explode(',', $address)
        );

        $parts = array_values(array_filter($parts));

        $queries = [];
        $queries[] = $this->normalizeFragment($address);

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $queries[] = $parts[$i];
        }

        $queries[] = $this->extractPostalCityFragment($address);

        return array_values(array_unique(array_filter($queries, fn ($query) => mb_strlen($query) >= 2)));
    }

    private function normalizeFragment(string $value): string
    {
        $value = preg_replace('/\b\d{4,6}\b/u', ' ', $value);
        $value = preg_replace('/[^[:alpha:]\s.-]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));

        return trim((string) $value, " .-\t\n\r\0\x0B");
    }

    private function extractPostalCityFragment(string $address): ?string
    {
        if (preg_match('/\b\d{4,6}\s+([[:alpha:]][[:alpha:]\s.-]+)/u', $address, $matches)) {
            return $this->normalizeFragment($matches[1]);
        }

        return null;
    }

    private function fallbackLocation(string $address, string $countryCode): array
    {
        $fallbacks = [
            'IN' => ['city' => 'Mumbai', 'latitude' => 19.07, 'longitude' => 72.87, 'timezone' => 'Asia/Kolkata'],
            'DE' => ['city' => 'Berlin', 'latitude' => 52.52, 'longitude' => 13.41, 'timezone' => 'Europe/Berlin'],
        ];

        $fallback = $fallbacks[strtoupper($countryCode)] ?? [
            'city' => 'Unknown',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'UTC',
        ];

        $parts = explode(',', $address);
        $lastPart = $parts[count($parts) - 1] ?? '';
        $fallback['city'] = $this->extractPostalCityFragment($address)
            ?? $this->normalizeFragment($lastPart)
            ?: $fallback['city'];

        return $fallback;
    }

    private function fallbackTimezone(string $countryCode): string
    {
        return strtoupper($countryCode) === 'DE'
            ? 'Europe/Berlin'
            : 'Asia/Kolkata';
    }
}
