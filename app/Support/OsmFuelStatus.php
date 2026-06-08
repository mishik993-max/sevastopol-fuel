<?php

namespace App\Support;

class OsmFuelStatus
{
    /** @param array<string, string> $tags */
    public static function closedReason(array $tags): ?string
    {
        if (($tags['disused:amenity'] ?? null) === 'fuel') {
            return 'OSM: disused:amenity=fuel';
        }

        if (($tags['abandoned:amenity'] ?? null) === 'fuel') {
            return 'OSM: abandoned:amenity=fuel';
        }

        if (($tags['demolished:amenity'] ?? null) === 'fuel') {
            return 'OSM: demolished:amenity=fuel';
        }

        foreach (['disused', 'abandoned', 'demolished'] as $flag) {
            if (($tags[$flag] ?? null) === 'yes') {
                return "OSM: {$flag}=yes";
            }
        }

        $operational = mb_strtolower((string) ($tags['operational_status'] ?? ''));

        if (in_array($operational, ['closed', 'non_operational', 'disused', 'abandoned'], true)) {
            return 'OSM: operational_status='.$tags['operational_status'];
        }

        if (($tags['lifecycle'] ?? null) === 'abandoned') {
            return 'OSM: lifecycle=abandoned';
        }

        if (($tags['access'] ?? null) === 'no' && ($tags['amenity'] ?? null) !== 'fuel') {
            return 'OSM: access=no';
        }

        return null;
    }

    public static function isOsmClosureReason(?string $reason): bool
    {
        return str_starts_with((string) $reason, 'OSM:');
    }
}
