<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransportController extends Controller
{
    public function index(Request $request)
    {
        $this->admin($request);
        $schoolId = (int) $request->user()->school_id;
        $routes = TransportRoute::query()->with(['stops', 'vehicles' => fn ($q) => $q->withCount(['assignments as occupied' => fn ($a) => $a->where('status', 'active')])])
            ->withCount(['assignments as passengers' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get();
        $assignments = TransportAssignment::query()->where('status', 'active')->with([
            'student:id,firstname,surname,email,reg_no,level_id', 'route:id,name', 'stop:id,name',
            'vehicle:id,name,registration_number,capacity',
        ])->latest('assigned_at')->get();
        $students = User::query()->forSchool($schoolId)->withRole('Student')->whereNotIn('id', $assignments->pluck('student_id'))
            ->orderBy('firstname')->orderBy('surname')->get(['id', 'firstname', 'surname', 'email', 'reg_no', 'level_id']);
        $vehicles = $routes->flatMap->vehicles;
        return response()->json(['routes' => $routes, 'assignments' => $assignments, 'students' => $students, 'summary' => [
            'routes' => $routes->count(), 'stops' => $routes->sum(fn ($route) => $route->stops->count()),
            'vehicles' => $vehicles->count(), 'capacity' => $vehicles->sum('capacity'), 'passengers' => $assignments->count(),
        ]]);
    }

    public function storeRoute(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('transport_routes')->where('school_id', $request->user()->school_id)], 'start_location' => ['nullable', 'string', 'max:180'], 'end_location' => ['nullable', 'string', 'max:180'], 'default_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999']]);
        return response()->json(['message' => 'Transport route created.', 'route' => TransportRoute::create($data)], 201);
    }

    public function updateRoute(Request $request, TransportRoute $transportRoute)
    {
        $this->admin($request);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('transport_routes')->where('school_id', $request->user()->school_id)->ignore($transportRoute)], 'start_location' => ['nullable', 'string', 'max:180'], 'end_location' => ['nullable', 'string', 'max:180'], 'default_fee' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']]);
        $transportRoute->update($data);
        return response()->json(['message' => 'Transport route updated.', 'route' => $transportRoute->fresh()]);
    }

    public function storeVehicle(Request $request)
    {
        $this->admin($request);
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate(['transport_route_id' => ['required', 'integer'], 'registration_number' => ['required', 'string', 'max:80', Rule::unique('transport_vehicles')->where('school_id', $schoolId)], 'name' => ['nullable', 'string', 'max:120'], 'capacity' => ['required', 'integer', 'min:1', 'max:500'], 'driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:30']]);
        TransportRoute::query()->findOrFail($data['transport_route_id']);
        return response()->json(['message' => 'Vehicle added.', 'vehicle' => TransportVehicle::create($data + ['school_id' => $schoolId])], 201);
    }

    public function updateVehicle(Request $request, TransportVehicle $vehicle)
    {
        $this->admin($request);
        $occupied = $vehicle->assignments()->where('status', 'active')->count();
        $data = $request->validate(['transport_route_id' => ['sometimes', 'integer'], 'registration_number' => ['sometimes', 'required', 'string', 'max:80'], 'name' => ['nullable', 'string', 'max:120'], 'capacity' => ['sometimes', 'integer', 'min:'.$occupied, 'max:500'], 'driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:30'], 'is_active' => ['sometimes', 'boolean']]);
        $vehicle->update($data);
        return response()->json(['message' => 'Vehicle updated.', 'vehicle' => $vehicle->fresh()]);
    }

    public function storeStop(Request $request, TransportRoute $transportRoute)
    {
        $this->admin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('transport_stops')->where('transport_route_id', $transportRoute->id)], 'pickup_time' => ['nullable', 'date_format:H:i'], 'dropoff_time' => ['nullable', 'date_format:H:i'], 'fee' => ['nullable', 'numeric', 'min:0'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000']]);
        $stop = $transportRoute->stops()->create($data + ['school_id' => $request->user()->school_id]);
        return response()->json(['message' => 'Route stop added.', 'stop' => $stop], 201);
    }

    public function allocate(Request $request)
    {
        $this->admin($request);
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate(['student_id' => ['required', 'integer'], 'transport_vehicle_id' => ['required', 'integer'], 'transport_stop_id' => ['nullable', 'integer'], 'trip_type' => ['required', Rule::in(['pickup', 'dropoff', 'both'])], 'notes' => ['nullable', 'string', 'max:1000']]);
        $assignment = DB::transaction(function () use ($data, $schoolId, $request) {
            $student = User::query()->forSchool($schoolId)->withRole('Student')->lockForUpdate()->findOrFail($data['student_id']);
            abort_if(TransportAssignment::query()->where('student_id', $student->id)->where('status', 'active')->exists(), 422, 'This student already has an active transport assignment.');
            $vehicle = TransportVehicle::query()->with('route')->lockForUpdate()->findOrFail($data['transport_vehicle_id']);
            abort_if(! $vehicle->is_active || ! $vehicle->route?->is_active, 422, 'This vehicle or route is not active.');
            $occupied = TransportAssignment::query()->where('transport_vehicle_id', $vehicle->id)->where('status', 'active')->count();
            abort_if($occupied >= $vehicle->capacity, 422, 'This vehicle is already full.');
            $stopId = $data['transport_stop_id'] ?? null;
            if ($stopId) TransportStop::query()->where('transport_route_id', $vehicle->transport_route_id)->findOrFail($stopId);
            return TransportAssignment::create(['school_id' => $schoolId, 'student_id' => $student->id, 'transport_route_id' => $vehicle->transport_route_id, 'transport_vehicle_id' => $vehicle->id, 'transport_stop_id' => $stopId, 'trip_type' => $data['trip_type'], 'notes' => $data['notes'] ?? null, 'assigned_by' => $request->user()->id, 'status' => 'active', 'assigned_at' => now()]);
        });
        return response()->json(['message' => 'Student assigned to transport.', 'assignment' => $assignment], 201);
    }

    public function end(Request $request, TransportAssignment $assignment)
    {
        $this->admin($request);
        abort_if($assignment->status !== 'active', 422, 'This transport assignment has already ended.');
        $assignment->update(['status' => 'ended', 'ended_at' => now()]);
        return response()->json(['message' => 'Transport assignment ended.']);
    }

    private function admin(Request $request): void
    {
        abort_unless(strtolower((string) $request->user()?->role) === 'admin', 403, 'Only the school administrator can manage transport.');
    }
}
