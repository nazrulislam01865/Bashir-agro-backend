<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\EvidencePhoto;
use App\Models\FarmLocation;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\SubmissionReceipt;
use App\Models\TaskTemplate;
use App\Services\DashboardService;
use App\Services\GeofenceService;
use App\Services\TaskAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubmissionController extends Controller
{
    public function __construct(
        protected GeofenceService $geofenceService,
        protected TaskAssignmentService $assignmentService
    ) {}

    /**
     * Submit task completion with notes, GPS coordinates, geofence validation, and evidence photos.
     */
    public function submit(Request $request, TaskAssignment $assignment): JsonResponse
    {
        // Legacy clients may omit the key; keyed clients receive replayable receipts.
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! Str::isUuid($key)) {
            return response()->json(['success' => false, 'message' => 'Invalid Idempotency-Key.'], 422);
        }

        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isFarmer(), 403);

        // Check ownership if farmer
        if ($user->isFarmer() && (! $user->farmerProfile || $assignment->farmer_id !== $user->farmerProfile->id)) {
            return response()->json([
                'success' => false,
                'message' => 'এই কাজ জমা দেওয়ার অনুমতি আপনার নেই।',
            ], 403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240', 'dimensions:min_width=100,min_height=100'],
            'override_geofence' => ['nullable'],
            'photo_metadata' => ['nullable', 'string', 'max:20000'],
        ]);

        // Backend controls authorization regardless of user-supplied flags.
        if ($request->boolean('override_geofence') && ! $user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Geofence override not authorized.'], 403);
        }

        // Hash the canonical validated payload and uploaded bytes. Hashing streams
        // disk-backed temp uploads; it never buffers all photos into PHP memory.
        $payloadHash = $key ? hash('sha256', json_encode([
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            'notes' => $validated['notes'] ?? null,
            'override_geofence' => $request->boolean('override_geofence'),
            'photo_metadata' => $request->input('photo_metadata'),
            'photos' => array_map(
                fn ($file) => hash_file('sha256', $file->getRealPath()),
                $request->file('photos', [])
            ),
        ], JSON_THROW_ON_ERROR)) : null;

        $assignment->load(['taskTemplate', 'location', 'farmer']);

        if (! $assignment->location) {
            return response()->json(['success' => false, 'message' => 'Assignment farm boundary is missing.'], 422);
        }

        // Geofence / Polygon Boundary validation
        $accuracy = isset($validated['accuracy']) ? (float) $validated['accuracy'] : 0.0;
        $geofenceResult = $this->geofenceService->validate(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $assignment->location,
            $accuracy
        );

        $isWithinGeofence = (bool) ($geofenceResult['is_within'] ?? true);
        $dist = (float) ($geofenceResult['distance_meters'] ?? 0.0);
        $radius = (float) ($geofenceResult['radius_meters'] ?? 0.0);
        $isPolygon = ! empty($geofenceResult['polygon_validated']);

        // Check required photo count
        $uploadedFiles = $request->file('photos', []);
        $minPhotos = (int) ($assignment->taskTemplate->min_photos_required
            ?? $assignment->taskTemplate->required_photo_count
            ?? 0);

        if ($minPhotos > 0 && count($uploadedFiles) < $minPhotos) {
            return response()->json([
                'success' => false,
                'message' => "এই কাজের জন্য কমপক্ষে {$minPhotos} টি প্রামাণ্য ছবি জমা দেওয়া আবশ্যক।",
            ], 422);
        }

        $savedPhotoPaths = [];
        try {
            [$submission, $receipt, $replayed] = DB::transaction(function () use ($validated, $assignment, $isWithinGeofence, $dist, $uploadedFiles, $user, $request, $key, $payloadHash, &$savedPhotoPaths) {
                // Serialize submissions for the same assignment, even across workers.
                TaskAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ($key !== null) {
                    $existing = SubmissionReceipt::where('user_id', $user->id)
                        ->where('task_assignment_id', $assignment->id)
                        ->where('idempotency_key', $key)->first();
                    if ($existing) {
                        if (! hash_equals($existing->payload_sha256, $payloadHash)) {
                            return [null, $existing, false];
                        }
                        return [$existing->submission, $existing, true];
                    }
                }
                $photoPaths = [];

                $farmerNote = $validated['notes'] ?? null;
                if (! $isWithinGeofence) {
                    $tag = '[মাঠের বাইরে থেকে জমা দেওয়া]';
                    $farmerNote = $farmerNote ? "{$farmerNote} {$tag}" : $tag;
                }

                $isStartedLate = false;
                if ($assignment->start_time && $assignment->started_at && $assignment->scheduled_date) {
                    try {
                        $scheduledStart = \Illuminate\Support\Carbon::parse($assignment->scheduled_date->format('Y-m-d').' '.$assignment->start_time);
                        $isStartedLate = $assignment->started_at->isAfter($scheduledStart);
                    } catch (\Throwable) {}
                }
                $isFinishedLate = (bool) $assignment->is_past_deadline;
                $isSubmissionLate = $isStartedLate || $isFinishedLate;

                $taskSubmission = TaskSubmission::updateOrCreate(
                    ['task_assignment_id' => $assignment->id],
                    [
                        'farmer_id' => $assignment->farmer_id,
                        'submitted_at' => now(),
                        'latitude' => $validated['latitude'],
                        'longitude' => $validated['longitude'],
                        'accuracy' => $validated['accuracy'] ?? null,
                        'geofence_passed' => $isWithinGeofence,
                        'distance_meters' => $dist,
                        'is_late' => $isSubmissionLate,
                        'status' => 'submitted',
                        'verification_status' => $isWithinGeofence ? 'pending' : 'flagged',
                        'validation_status' => $isWithinGeofence ? 'pending' : 'flagged',
                        'notes' => $farmerNote,
                        'farmer_note' => $farmerNote,
                    ]
                );

                $photosMeta = [];
                if ($request->filled('photo_metadata')) {
                    $rawMeta = $request->input('photo_metadata');
                    if (is_string($rawMeta)) {
                        $decoded = json_decode($rawMeta, true);
                        if (is_array($decoded)) {
                            $photosMeta = $decoded;
                        }
                    } elseif (is_array($rawMeta)) {
                        $photosMeta = $rawMeta;
                    }
                }

                // Store uploaded photos with exact per-photo GPS coordinates
                foreach ($uploadedFiles as $index => $file) {
                    $path = $file->store('submissions/photos', 'local');
                    if (! $path) {
                        throw new \RuntimeException('Photo storage failed; submission rolled back.');
                    }
                    $photoPaths[] = $path;
                    $savedPhotoPaths[] = $path;

                    $meta = $photosMeta[$index] ?? [];
                    // Client metadata is a claim, never a geofence authority.
                    $metaLat = isset($meta['latitude']) && is_numeric($meta['latitude']) ? (float) $meta['latitude'] : null;
                    $metaLng = isset($meta['longitude']) && is_numeric($meta['longitude']) ? (float) $meta['longitude'] : null;
                    $photoLat = $metaLat !== null && is_finite($metaLat) && abs($metaLat) <= 90
                        ? $metaLat : (float) $validated['latitude'];
                    $photoLng = $metaLng !== null && is_finite($metaLng) && abs($metaLng) <= 180
                        ? $metaLng : (float) $validated['longitude'];
                    try {
                        $capturedAt = ! empty($meta['captured_at'])
                            ? Carbon::parse($meta['captured_at']) : now();
                        if ($capturedAt->isFuture()) $capturedAt = now();
                    } catch (\Throwable) {
                        $capturedAt = now();
                    }

                    EvidencePhoto::create([
                        'task_submission_id' => $taskSubmission->id,
                        'file_path' => $path,
                        'source' => 'camera',
                        'captured_at' => $capturedAt,
                        'latitude' => $photoLat,
                        'longitude' => $photoLng,
                    ]);
                }

                if (! empty($photoPaths)) {
                    $taskSubmission->photos = $photoPaths;
                    $taskSubmission->save();
                }

                // Mark assignment as completed
                $this->assignmentService->updateStatus($assignment, 'completed', [], $user);

                $receipt = $key !== null ? SubmissionReceipt::create([
                    'user_id' => $user->id,
                    'task_assignment_id' => $assignment->id,
                    'task_submission_id' => $taskSubmission->id,
                    'idempotency_key' => $key,
                    'receipt_id' => (string) Str::uuid(),
                    'payload_sha256' => $payloadHash,
                ]) : null;
                return [$taskSubmission, $receipt, false];
            }, 1);
        } catch (\Throwable $e) {
            // DB rollbacks do not roll back filesystem operations.
            if ($savedPhotoPaths) {
                Storage::disk('local')->delete($savedPhotoPaths);
            }
            throw $e;
        }

        if ($submission === null) {
            Log::warning('submission.idempotency_conflict', [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'This idempotency key was used for different evidence.',
            ], 409);
        }

        if ($receipt && ! $replayed) {
            Log::info('submission.receipt_created', [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
                'submission_id' => $submission->id,
            ]);
        }
        $submission->load(['evidencePhotos', 'farmer', 'taskAssignment']);

        $message = $isWithinGeofence
            ? 'কাজের প্রমাণ ও প্রতিবেদন সফলভাবে জমা হয়েছে।'
            : ($isPolygon
                ? "কাজের প্রমাণ সফলভাবে জমা হয়েছে। (নির্ধারিত ফার্ম সীমানা হতে {$dist} মিটার বাইরে অবস্থান করায় সতর্কবার্তা সহ চিহ্নিত করা হয়েছে)"
                : "কাজের প্রমাণ সফলভাবে জমা হয়েছে। (নির্ধারিত ফার্ম লোকেশনের বাইরে অবস্থান করায় সতর্কবার্তা সহ চিহ্নিত করা হয়েছে)");

        return response()->json([
            'success' => true,
            'message' => $message,
            'geofence' => [
                'passed' => $isWithinGeofence,
                'distance_meters' => $dist,
                'radius_meters' => $radius,
                'polygon_boundary' => $isPolygon,
            ],
            'data' => new SubmissionResource($submission),
            'receipt' => $receipt ? [
                'receipt_id' => $receipt->receipt_id,
                'idempotency_key' => $receipt->idempotency_key,
                'assignment_id' => $assignment->id,
                'submission_id' => $submission->id,
                'payload_sha256' => $receipt->payload_sha256,
            ] : null,
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    /** Resolve a timed-out mobile request using its receipt key; never guess from 409. */
    public function receipt(Request $request, TaskAssignment $assignment, string $key): JsonResponse
    {
        $user = $request->user();
        if ($user->isFarmer() && (! $user->farmerProfile || $assignment->farmer_id !== $user->farmerProfile->id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }
        if (! Str::isUuid($key)) {
            return response()->json(['success' => false, 'message' => 'Invalid key.'], 422);
        }
        $receipt = SubmissionReceipt::where('user_id', $user->id)
            ->where('task_assignment_id', $assignment->id)
            ->where('idempotency_key', $key)->first();
        if (! $receipt) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }
        return response()->json([
            'success' => true,
            'receipt' => [
                'receipt_id' => $receipt->receipt_id,
                'idempotency_key' => $receipt->idempotency_key,
                'assignment_id' => $assignment->id,
                'submission_id' => $receipt->task_submission_id,
                'payload_sha256' => $receipt->payload_sha256,
            ],
        ]);
    }

    /**
     * View submission details for a specific assignment.
     */
    public function show(TaskAssignment $assignment): JsonResponse
    {
        $user = request()->user();
        abort_if($user->isFarmer() && (! $user->farmerProfile || $assignment->farmer_id !== $user->farmerProfile->id), 403);
        $submission = $assignment->submission;

        if (! $submission) {
            return response()->json([
                'success' => false,
                'message' => 'এই কাজের কোনো জমা রেকর্ড খুঁজে পাওয়া যায়নি।',
            ], 404);
        }

        $submission->load(['evidencePhotos', 'farmer', 'taskAssignment']);

        return response()->json([
            'success' => true,
            'data' => new SubmissionResource($submission),
        ]);
    }

    /**
     * Create and submit a farmer self-reported completed task (e.g. from voice or text input).
     */
    public function selfReport(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isFarmer() || ! $user->farmerProfile) {
            return response()->json([
                'success' => false,
                'message' => 'শুধুমাত্র কৃষকরা নতুন কাজ রিপোর্ট করতে পারবেন।',
            ], 403);
        }

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'farm_location_id' => ['nullable', 'exists:farm_locations,id'],
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240', 'dimensions:min_width=100,min_height=100'],
        ], [
            'photos.required' => 'কাজের ছবি তোলা বাধ্যতামূলক। অনুগ্রহ করে অন্তত একটি ছবি তুলুন।',
            'photos.min' => 'কাজের ছবি তোলা বাধ্যতামূলক। অনুগ্রহ করে অন্তত একটি ছবি তুলুন।',
            'description.required' => 'কাজের বিবরণ লিখুন বা মুখে বলুন।',
        ]);

        $farmerId = $user->farmerProfile->id;

        // Dedicated Self-Reported Task Template (TK-SELF)
        $template = TaskTemplate::where('task_code', 'TK-SELF')->first()
            ?? TaskTemplate::where('name', 'LIKE', '%স্ব-প্রতিবেদিত%')->first();

        if (! $template) {
            $template = TaskTemplate::create([
                'task_code' => 'TK-SELF',
                'name' => 'স্ব-প্রতিবেদিত কাজ',
                'category' => 'মাঠ পর্যায়ের কাজ',
                'description' => 'কৃষক কর্তৃক মাঠ থেকে সরাসরি ছবি ও বিবরণ সহ রিপোর্টকৃত কাজ।',
                'instructions' => 'কাজের লাইভ ছবি ও বিস্তারিত বিবরণ প্রদান করুন।',
                'required_photo_count' => 1,
                'require_realtime_photo' => true,
                'allow_gallery_upload' => false,
                'require_geofence' => false,
                'recurrence_type' => 'once',
                'is_active' => true,
            ]);
        }

        // A self-reported task can only reference farms owned by or assigned
        // to this authenticated farmer. Never use an arbitrary first location.
        $locationId = $validated['farm_location_id'] ?? null;
        $ownedFarms = FarmLocation::query()->where(function ($q) use ($farmerId) {
            $q->where('farmer_id', $farmerId)
                ->orWhereHas('farmers', fn ($q) => $q->where('farmers.id', $farmerId));
        });
        if ($locationId) $ownedFarms->whereKey($locationId);
        $location = $ownedFarms->first();
        if (! $location) {
            return response()->json(['success' => false, 'message' => 'No authorized farm is available for this report.'], 422);
        }
        $locationId = $location->id;

        $now = now();
        $lat = $validated['latitude'] ?? null;
        $lng = $validated['longitude'] ?? null;
        $accuracy = $validated['accuracy'] ?? null;

        // Perform authoritative server-side geofence calculation against the selected plot
        $isWithin = null;
        $distanceMeters = 0.0;
        if ($lat !== null && $lng !== null && $location) {
            $geofenceResult = $this->geofenceService->validate(
                (float) $lat,
                (float) $lng,
                $location,
                (float) ($accuracy ?? 0.0)
            );
            $isWithin = (bool) $geofenceResult['is_within'];
            $distanceMeters = (float) $geofenceResult['distance_meters'];
        }

        $assignment = DB::transaction(function () use ($farmerId, $template, $locationId, $now, $validated, $lat, $lng, $accuracy, $isWithin, $distanceMeters, $request) {
            $taskAssignment = TaskAssignment::create([
                'task_template_id' => $template->id,
                'farmer_id' => $farmerId,
                'farm_location_id' => $locationId,
                'scheduled_date' => $now->toDateString(),
                'start_time' => $now->copy()->subMinutes(30)->format('H:i:s'),
                'end_time' => $now->format('H:i:s'),
                'priority' => 'normal',
                'notes' => $validated['description'],
                'status' => 'completed',
                'assigned_by' => null,
                'assigned_at' => $now,
                'started_at' => $now->copy()->subMinutes(30),
                'completed_at' => $now,
            ]);

            $taskSubmission = TaskSubmission::create([
                'task_assignment_id' => $taskAssignment->id,
                'farmer_id' => $farmerId,
                'submitted_at' => $now,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $accuracy,
                'geofence_passed' => $isWithin,
                'distance_meters' => $distanceMeters,
                'is_late' => false,
                'status' => 'completed',
                'verification_status' => 'verified',
                'validation_status' => 'verified',
                'notes' => $validated['description'],
                'farmer_note' => $validated['description'],
            ]);

            $uploadedFiles = $request->file('photos', []);
            if (! empty($uploadedFiles)) {
                foreach ($uploadedFiles as $photo) {
                    $path = $photo->store('submissions/'.$taskSubmission->id, 'local');
                    EvidencePhoto::create([
                        'task_submission_id' => $taskSubmission->id,
                        'file_path' => $path,
                        'captured_at' => $now,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'accuracy' => $accuracy,
                        'mime_type' => $photo->getClientMimeType(),
                        'file_size' => $photo->getSize(),
                    ]);
                }
            }

            return $taskAssignment;
        });

        DashboardService::clearKpiCache();
        $assignment->load(['taskTemplate', 'location', 'farmer', 'submission.evidencePhotos']);

        return response()->json([
            'success' => true,
            'message' => 'কাজটি সফলভাবে সম্পন্ন হিসেবে যুক্ত করা হয়েছে।',
            'data' => new AssignmentResource($assignment),
        ], 201);
    }
}
