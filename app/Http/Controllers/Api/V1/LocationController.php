<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\FarmLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Display a listing of active farm locations.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isFarmer() && ! $user->farmerProfile) {
            abort(403);
        }
        $query = FarmLocation::withCount(['farmers', 'taskAssignments'])->where('status', 'active');
        if ($user->isFarmer()) {
            $farmerId = $user->farmerProfile->id;
            $query->where(fn ($q) => $q->where('farmer_id', $farmerId)
                ->orWhereHas('farmers', fn ($q) => $q->where('farmers.id', $farmerId)));
        }
        $locations = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => LocationResource::collection($locations),
        ]);
    }

    /**
     * Display the specified farm location.
     */
    public function show(Request $request, FarmLocation $location): JsonResponse
    {
        $user = $request->user();
        if ($user->isFarmer()) {
            $farmerId = $user->farmerProfile?->id;
            abort_unless($farmerId && ($location->farmer_id === $farmerId ||
                $location->farmers()->where('farmers.id', $farmerId)->exists()), 403);
        }
        $location->loadCount(['farmers', 'taskAssignments']);

        return response()->json([
            'success' => true,
            'data' => new LocationResource($location),
        ]);
    }
}
