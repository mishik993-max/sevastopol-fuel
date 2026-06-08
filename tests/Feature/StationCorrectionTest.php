<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationCorrection;
use App\Models\StationCorrectionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Старое имя',
            'network' => 'Атан',
            'address' => 'ул. Старая, 1',
            'latitude' => 44.605,
            'longitude' => 33.522,
        ]);
    }

    public function test_user_can_propose_name_correction(): void
    {
        $station = $this->station();

        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson("/api/stations/{$station->id}/corrections?fuel=a95", [
                'corrections' => [
                    ['field' => 'name', 'name' => 'Новое имя'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.corrections.0.field', 'name')
            ->assertJsonPath('data.corrections.0.proposed_value', 'Новое имя')
            ->assertJsonPath('data.corrections.0.confirmations_count', 1);

        $this->assertDatabaseHas('station_corrections', [
            'station_id' => $station->id,
            'field' => 'name',
            'proposed_name' => 'Новое имя',
            'status' => 'pending',
        ]);
    }

    public function test_correction_applies_after_confirmations(): void
    {
        config(['stations.correction.confirmations_required' => 2]);

        $station = $this->station();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson("/api/stations/{$station->id}/corrections?fuel=a95", [
                'corrections' => [
                    ['field' => 'name', 'name' => 'Новое имя'],
                ],
            ])
            ->assertCreated();

        $correctionId = $response->json('data.corrections.0.id');

        $this->withServerVariables(['REMOTE_ADDR' => '2.2.2.2'])
            ->postJson("/api/stations/{$station->id}/corrections/{$correctionId}/confirm?fuel=a95")
            ->assertOk()
            ->assertJsonPath('data.applied', true)
            ->assertJsonPath('data.station.name', 'Новое имя');

        $this->assertSame('Новое имя', $station->fresh()->name);
    }

    public function test_location_correction_moves_station(): void
    {
        config(['stations.correction.confirmations_required' => 1]);

        $station = $this->station();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson("/api/stations/{$station->id}/corrections?fuel=a95", [
                'corrections' => [
                    ['field' => 'location', 'latitude' => 44.610, 'longitude' => 33.530],
                ],
            ])
            ->assertCreated();

        $correctionId = $response->json('data.corrections.0.id');

        $this->postJson("/api/stations/{$station->id}/corrections/{$correctionId}/confirm?fuel=a95")
            ->assertOk()
            ->assertJsonPath('data.applied', true);

        $station->refresh();
        $this->assertEqualsWithDelta(44.610, $station->latitude, 0.0001);
        $this->assertEqualsWithDelta(33.530, $station->longitude, 0.0001);
    }

    public function test_station_show_includes_pending_corrections(): void
    {
        $station = $this->station();
        $correction = StationCorrection::query()->create([
            'station_id' => $station->id,
            'field' => 'name',
            'proposed_name' => 'Ждёт подтверждения',
            'status' => 'pending',
            'proposer_hash' => hash('sha256', 'x'),
            'created_at' => now(),
        ]);

        StationCorrectionReport::query()->create([
            'correction_id' => $correction->id,
            'reporter_hash' => hash('sha256', 'x'),
            'created_at' => now(),
        ]);

        $this->getJson("/api/stations/{$station->id}?fuel=a95")
            ->assertOk()
            ->assertJsonPath('data.pending_corrections.0.id', $correction->id)
            ->assertJsonPath('data.pending_corrections.0.confirmations_count', 1);
    }
}
