<?php

namespace Tests\Feature\Api;

use App\Models\Farmer;
use App\Models\FarmLocation;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replayed_multipart_does_not_duplicate_photos_or_status_updates(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $farmer = Farmer::create([
            'user_id' => $user->id, 'farmer_code' => 'F-P2-1',
            'full_name' => 'Test Farmer', 'phone' => '01810000001',
            'status' => 'active', 'address' => 'Test farm',
            'primary_activity' => 'Crop farming',
        ]);
        $location = FarmLocation::create([
            'location_code' => 'LOC-P2-1', 'name' => 'Farm', 'type' => 'field',
            'latitude' => 23.7808, 'longitude' => 90.4152,
            'geofence_radius_meters' => 200, 'status' => 'active',
        ]);
        $template = TaskTemplate::create([
            'task_code' => 'TASK-P2-1', 'name' => 'Inspect crop',
            'category' => 'inspection', 'instructions' => 'Take a photo',
            'required_photo_count' => 1, 'status' => 'active',
        ]);
        $assignment = TaskAssignment::create([
            'task_template_id' => $template->id, 'farmer_id' => $farmer->id,
            'farm_location_id' => $location->id, 'scheduled_date' => today(),
            'start_time' => '08:00', 'end_time' => '10:00', 'status' => 'assigned',
        ]);
        $key = 'cf0ac98e-4c34-4986-a06f-c90c433828ea';
        $url = "/api/v1/assignments/{$assignment->id}/submit";
        $photoFixture = UploadedFile::fake()->image('evidence.jpg', 160, 160);
        $photoBytes = file_get_contents($photoFixture->getPathname());
        $payload = fn () => [
            'latitude' => '23.7808', 'longitude' => '90.4152', 'accuracy' => '5',
            'notes' => 'Checked',
            'photos' => [UploadedFile::fake()->createWithContent('evidence.jpg', $photoBytes)],
        ];
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => $key];
        $first = $this->actingAs($user, 'sanctum')->post($url, $payload(), $headers);
        $first->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('receipt.idempotency_key', $key);
        $receipt = $first->json('receipt.receipt_id');
        $this->assertNotEmpty($receipt);
        $second = $this->actingAs($user, 'sanctum')->post($url, $payload(), $headers);
        $second->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('receipt.receipt_id', $receipt);
        $this->assertDatabaseCount('submission_receipts', 1);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('evidence_photos', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('submissions/photos'));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/assignments/{$assignment->id}/submission-receipts/{$key}")
            ->assertOk()->assertJsonPath('receipt.receipt_id', $receipt);
        $stranger = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/assignments/{$assignment->id}/submission-receipts/{$key}")
            ->assertForbidden();

        $changed = $payload();
        $changed['notes'] = 'Different proof';
        $this->actingAs($user, 'sanctum')->post($url, $changed, $headers)
            ->assertStatus(409);
        $this->assertDatabaseCount('evidence_photos', 1);
    }
}
