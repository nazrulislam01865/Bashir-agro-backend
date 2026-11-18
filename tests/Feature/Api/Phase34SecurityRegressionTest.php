<?php

namespace Tests\Feature\Api;

use App\Models\EvidencePhoto;
use App\Models\Farmer;
use App\Models\FarmLocation;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class Phase34SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_farmer_cannot_read_owner_dashboard_or_farmer_directory(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $this->actingAs($farmer, 'sanctum')->getJson('/api/v1/owner/dashboard')->assertForbidden();
        $this->actingAs($farmer, 'sanctum')->getJson('/api/v1/farmers')->assertForbidden();
        $this->actingAs($farmer, 'sanctum')->getJson('/api/v1/assignments')->assertForbidden();
        $this->actingAs($farmer, 'sanctum')->getJson('/api/v1/farms')->assertForbidden();
    }

    public function test_a_farmer_cannot_view_another_farm_or_assignment(): void
    {
        $userA = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $userB = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $farmerA = Farmer::create([
            'user_id' => $userA->id, 'farmer_code' => 'F-P34-A',
            'full_name' => 'Farmer A', 'phone' => '01800000041',
            'primary_activity' => 'Crop farming', 'status' => 'active',
        ]);
        $farmerB = Farmer::create([
            'user_id' => $userB->id, 'farmer_code' => 'F-P34-B',
            'full_name' => 'Farmer B', 'phone' => '01800000042',
            'primary_activity' => 'Crop farming', 'status' => 'active',
        ]);
        $farm = FarmLocation::create([
            'location_code' => 'LOC-P34-B', 'farmer_id' => $farmerB->id,
            'name' => 'B Plot', 'type' => 'field', 'status' => 'active',
            'latitude' => 23.7, 'longitude' => 90.4,
        ]);
        $template = TaskTemplate::create([
            'task_code' => 'TK-P34-B', 'name' => 'Assigned to B',
            'category' => 'inspection', 'instructions' => 'Check field', 'status' => 'active',
        ]);
        $assignment = TaskAssignment::create([
            'task_template_id' => $template->id, 'farmer_id' => $farmerB->id,
            'farm_location_id' => $farm->id, 'scheduled_date' => today(),
            'start_time' => '08:00', 'status' => 'assigned',
        ]);
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/farms/'.$farm->id)->assertForbidden();
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/locations/'.$farm->id)->assertForbidden();
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/assignments/'.$assignment->id)->assertForbidden();
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/assignments/'.$assignment->id.'/submission')->assertForbidden();

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/v1/assignments/'.$assignment->id)->assertOk();
    }

    public function test_missing_farm_center_is_not_treated_as_verified_geofence(): void
    {
        $farm = FarmLocation::create([
            'location_code' => 'LOC-P34-NOCOORD', 'name' => 'Incomplete plot',
            'type' => 'field', 'status' => 'active',
            'geofence_radius_meters' => 25,
        ]);
        $result = app(GeofenceService::class)->validate(23.7808, 90.4152, $farm, 5.0);
        $this->assertFalse($result['is_within']);
    }

    public function test_evidence_is_private_and_requires_a_valid_time_limited_signature(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $farmer = Farmer::create([
            'user_id' => $user->id, 'farmer_code' => 'F-P34-1',
            'full_name' => 'Farmer P34', 'phone' => '01800000034',
            'primary_activity' => 'Crop farming', 'status' => 'active',
        ]);
        $farm = FarmLocation::create([
            'location_code' => 'LOC-P34-1', 'name' => 'Plot', 'type' => 'field',
            'latitude' => 23.7808, 'longitude' => 90.4152, 'status' => 'active',
        ]);
        $template = TaskTemplate::create([
            'task_code' => 'TK-P34-1', 'name' => 'Photo proof', 'category' => 'inspection',
            'instructions' => 'Capture proof', 'status' => 'active',
        ]);
        $assignment = TaskAssignment::create([
            'task_template_id' => $template->id, 'farmer_id' => $farmer->id,
            'farm_location_id' => $farm->id, 'scheduled_date' => today(),
            'start_time' => '08:00', 'status' => 'completed',
        ]);
        $submission = TaskSubmission::create([
            'task_assignment_id' => $assignment->id, 'farmer_id' => $farmer->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        // A tiny valid JPEG byte stream so mime_content_type can identify it.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFwABAQEBAAAAAAAAAAAAAAAAAAUGB//EABUQAQEAAAAAAAAAAAAAAAAAAAAB/9oADAMBAAIQAxAAAAFaQf/EABgQAAMBAQAAAAAAAAAAAAAAAAABAhES/9oACAEBAAEFAmt4v//EABQRAQAAAAAAAAAAAAAAAAAAABD/9oACAEDAQE/AR//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAECAQE/AR//xAAZEAEBAAMBAAAAAAAAAAAAAAABABEhMUH/2gAIAQEABj8CmMGYrr//xAAaEAACAgMAAAAAAAAAAAAAAAAAAQIREiEx/9oACAEBAAE/IYyW2aiNSX//2gAMAwEAAgADAAAAEB//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAECAQE/EH//xAAdEAEAAQUBAAAAAAAAAAAAAAABABEhMVFhcaHB/9oACAEBAAE/EEQrOOrSxGC3lYV5rU//2Q==');
        Storage::disk('local')->put('submissions/photos/verified.jpg', $jpeg);
        $photo = EvidencePhoto::create([
            'task_submission_id' => $submission->id,
            'file_path' => 'submissions/photos/verified.jpg',
            'captured_at' => now(),
        ]);
        $this->get('/evidence/'.$photo->id)->assertForbidden();
        $url = URL::temporarySignedRoute('evidence.show', now()->addMinutes(5), ['photo' => $photo->id]);
        $this->get($url)->assertOk();
        $expired = URL::temporarySignedRoute('evidence.show', now()->subMinute(), ['photo' => $photo->id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_farmer_cannot_access_admin_web_routes_and_owner_can(): void
    {
        $farmer = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($farmer)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($owner)->get('/admin/dashboard')->assertOk();
    }

    public function test_small_photo_is_rejected_without_changing_assignment_state(): void
    {
        $user = User::factory()->create(['role' => 'farmer', 'is_active' => true]);
        $farmer = Farmer::create([
            'user_id' => $user->id, 'farmer_code' => 'F-P34-S',
            'full_name' => 'Farmer Small Image', 'phone' => '01800000043',
            'primary_activity' => 'Crop farming', 'status' => 'active',
        ]);
        $farm = FarmLocation::create([
            'location_code' => 'LOC-P34-S', 'name' => 'Small photo plot',
            'type' => 'field', 'latitude' => 23.7808,
            'longitude' => 90.4152, 'status' => 'active',
        ]);
        $template = TaskTemplate::create([
            'task_code' => 'TK-P34-S', 'name' => 'Image gate',
            'category' => 'inspection', 'status' => 'active',
        ]);
        $assignment = TaskAssignment::create([
            'task_template_id' => $template->id, 'farmer_id' => $farmer->id,
            'farm_location_id' => $farm->id, 'scheduled_date' => today(),
            'start_time' => '08:00', 'status' => 'assigned',
        ]);
        // Use a real decodable 16x16 JPEG; no GD-based test-image generator needed.
        $photoBytes = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFwABAQEBAAAAAAAAAAAAAAAAAAUGB//EABUQAQEAAAAAAAAAAAAAAAAAAAAB/9oADAMBAAIQAxAAAAFaQf/EABgQAAMBAQAAAAAAAAAAAAAAAAABAhES/9oACAEBAAEFAmt4v//EABQRAQAAAAAAAAAAAAAAAAAAABD/9oACAEDAQE/AR//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAECAQE/AR//xAAdEAEAAQUBAAAAAAAAAAAAAAABABEhMVFhcaHB/9oACAEBAAE/EEQrOOrSxGC3lYV5rU//2Q==');
        $photo = \Illuminate\Http\UploadedFile::fake()->createWithContent('tiny.jpg', $photoBytes);
        $this->actingAs($user, 'sanctum')->post('/api/v1/assignments/'.$assignment->id.'/submit', [
            'latitude' => 23.7808, 'longitude' => 90.4152, 'accuracy' => 5,
            'photos' => [$photo],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('assigned', $assignment->fresh()->status);
        $this->assertDatabaseMissing('task_submissions', ['task_assignment_id' => $assignment->id]);
    }

    public function test_public_privacy_page_is_available_without_authentication(): void
    {
        $this->get('/privacy-policy')->assertOk()->assertSee('গোপনীয়তা নীতি');
    }
}
