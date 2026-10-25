<?php
const RESERVE_RADIUS_KM = 5;

/** Straight-line distance between two points on Earth in km (Haversine formula). */
function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** True when the numbers are a real latitude/longitude pair. */
function validCoordinates($lat, $lon): bool
{
    return is_numeric($lat) && is_numeric($lon)
        && (float) $lat >= -90 && (float) $lat <= 90
        && (float) $lon >= -180 && (float) $lon <= 180;
}