<?php

namespace Tests\Unit;

use App\Support\OsmFuelStatus;
use PHPUnit\Framework\TestCase;

class OsmFuelStatusTest extends TestCase
{
    public function test_detects_disused_fuel_station(): void
    {
        $reason = OsmFuelStatus::closedReason(['disused:amenity' => 'fuel']);

        $this->assertSame('OSM: disused:amenity=fuel', $reason);
    }

    public function test_detects_operational_status_closed(): void
    {
        $reason = OsmFuelStatus::closedReason(['operational_status' => 'closed', 'amenity' => 'fuel']);

        $this->assertSame('OSM: operational_status=closed', $reason);
    }

    public function test_active_station_has_no_closure_reason(): void
    {
        $this->assertNull(OsmFuelStatus::closedReason([
            'amenity' => 'fuel',
            'brand' => 'ТЭС',
        ]));
    }
}
