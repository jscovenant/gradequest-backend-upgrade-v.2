<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAllocationEvent;
use App\Models\HostelRoom;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HostelController extends Controller
{
    public function index(Request $request)
    {
        $this->admin($request);
        $schoolId = (int) $request->user()->school_id;
        $sessions = AcademicSession::query()->orderByDesc('is_current')->orderByDesc('id')->get(['id', 'name', 'is_current']);
        $terms = Term::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $sessionId = (int) ($request->input('session_id') ?: optional($sessions->firstWhere('is_current', true))->id ?: optional($sessions->first())->id);
        $termId = $request->filled('term_id') ? (int) $request->input('term_id') : null;
        $period = fn ($query) => $query->where('status', 'active')->when($sessionId, fn ($q) => $q->where(fn ($p) => $p->where('session_id', $sessionId)->orWhereNull('session_id')))->when($termId, fn ($q) => $q->where('term_id', $termId));
        $hostels = Hostel::query()->with(['rooms' => fn ($q) => $q->withCount(['allocations as occupied' => $period])])
            ->withCount(['allocations as occupied' => $period])->orderBy('name')->get();
        $allocations = HostelAllocation::query()->where($period)
            ->with(['hostel:id,name,gender', 'room:id,name,capacity', 'student:id,firstname,surname,email,reg_no,level_id,sex', 'session:id,name', 'term:id,name'])
            ->latest('allocated_at')->get();
        $students = User::query()->forSchool($schoolId)->withRole('Student')
            ->whereNotIn('id', $allocations->pluck('student_id'))->orderBy('firstname')->orderBy('surname')
            ->get(['id', 'firstname', 'surname', 'email', 'reg_no', 'level_id', 'sex']);
        $history = HostelAllocationEvent::query()->with(['student:id,firstname,surname,reg_no', 'fromHostel:id,name', 'fromRoom:id,name', 'toHostel:id,name', 'toRoom:id,name', 'actor:id,firstname,surname'])
            ->latest()->limit(100)->get();

        return response()->json([
            'hostels' => $hostels,
            'allocations' => $allocations,
            'students' => $students,
            'history' => $history,
            'sessions' => $sessions,
            'terms' => $terms,
            'selected_session_id' => $sessionId ?: null,
            'selected_term_id' => $termId,
            'summary' => [
                'hostels' => $hostels->count(),
                'rooms' => $hostels->sum(fn ($h) => $h->rooms->count()),
                'capacity' => $hostels->sum(fn ($h) => $h->rooms->sum('capacity')),
                'occupied' => $allocations->count(),
            ],
        ]);
    }

    public function storeHostel(Request $request)
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('hostels')->where('school_id', $request->user()->school_id)],
            'gender' => ['required', Rule::in(['male', 'female', 'mixed'])],
            'warden_name' => ['nullable', 'string', 'max:120'], 'warden_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['message' => 'Hostel created.', 'hostel' => Hostel::create($data)], 201);
    }

    public function updateHostel(Request $request, Hostel $hostel)
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('hostels')->where('school_id', $request->user()->school_id)->ignore($hostel)],
            'gender' => ['sometimes', Rule::in(['male', 'female', 'mixed'])], 'warden_name' => ['nullable', 'string', 'max:120'],
            'warden_phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:1000'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            abort_if($hostel->allocations()->where('status', 'active')->exists(), 422, 'Check out or transfer all residents before deactivating this hostel.');
        }
        if (isset($data['gender']) && $data['gender'] !== 'mixed') {
            $hasMismatch = $hostel->allocations()->where('status', 'active')->whereHas('student', fn ($q) => $q->whereNotNull('sex')->whereRaw('LOWER(sex) <> ?', [$data['gender']]))->exists();
            abort_if($hasMismatch, 422, 'Existing residents do not match the selected hostel gender.');
        }
        $hostel->update($data);
        return response()->json(['message' => 'Hostel updated.', 'hostel' => $hostel->fresh()]);
    }

    public function storeRoom(Request $request, Hostel $hostel)
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('hostel_rooms')->where('hostel_id', $hostel->id)],
            'floor' => ['nullable', 'string', 'max:80'], 'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $room = $hostel->rooms()->create($data + ['school_id' => $request->user()->school_id]);
        return response()->json(['message' => 'Room created.', 'room' => $room], 201);
    }

    public function updateRoom(Request $request, HostelRoom $room)
    {
        $this->admin($request);
        $occupied = $room->allocations()->where('status', 'active')->count();
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:80'], 'floor' => ['nullable', 'string', 'max:80'], 'capacity' => ['sometimes', 'integer', 'min:'.$occupied, 'max:1000'], 'is_active' => ['sometimes', 'boolean']]);
        abort_if(array_key_exists('is_active', $data) && ! $data['is_active'] && $occupied > 0, 422, 'Transfer or check out all residents before deactivating this room.');
        $room->update($data);
        return response()->json(['message' => 'Room updated.', 'room' => $room->fresh()]);
    }

    public function allocate(Request $request)
    {
        $this->admin($request);
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate(['student_id' => ['required', 'integer'], 'hostel_room_id' => ['required', 'integer'], 'session_id' => ['required', 'integer', 'exists:academic_sessions,id'], 'term_id' => ['nullable', 'integer', 'exists:terms,id'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $allocation = DB::transaction(function () use ($data, $schoolId, $request) {
            $student = User::query()->forSchool($schoolId)->withRole('Student')->lockForUpdate()->findOrFail($data['student_id']);
            abort_if(HostelAllocation::query()->where('student_id', $student->id)->where('session_id', $data['session_id'])->where('status', 'active')->exists(), 422, 'This student already has an active hostel allocation for this session.');
            $room = HostelRoom::query()->with('hostel')->lockForUpdate()->findOrFail($data['hostel_room_id']);
            abort_if(! $room->is_active || ! $room->hostel->is_active, 422, 'This room is not available.');
            $studentGender = strtolower((string) $student->sex);
            abort_if($room->hostel->gender !== 'mixed' && $studentGender && $room->hostel->gender !== $studentGender, 422, 'The student gender does not match this hostel.');
            $occupied = HostelAllocation::query()->where('hostel_room_id', $room->id)->where('session_id', $data['session_id'])->where('status', 'active')->count();
            abort_if($occupied >= $room->capacity, 422, 'This room is already full.');
            $allocation = HostelAllocation::create(['school_id' => $schoolId, 'hostel_id' => $room->hostel_id, 'hostel_room_id' => $room->id, 'student_id' => $student->id, 'session_id' => $data['session_id'], 'term_id' => $data['term_id'] ?? null, 'allocated_by' => $request->user()->id, 'status' => 'active', 'allocated_at' => now(), 'notes' => $data['notes'] ?? null]);
            $this->event($allocation, 'allocated', null, null, $room->hostel_id, $room->id, $request->user()->id, $data['notes'] ?? null);
            return $allocation;
        });
        return response()->json(['message' => 'Student allocated successfully.', 'allocation' => $allocation], 201);
    }

    public function checkout(Request $request, HostelAllocation $allocation)
    {
        $this->admin($request);
        abort_if($allocation->status !== 'active', 422, 'This allocation is no longer active.');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $allocation->update(['status' => 'checked_out', 'checked_out_at' => now()]);
        $this->event($allocation, 'checked_out', $allocation->hostel_id, $allocation->hostel_room_id, null, null, $request->user()->id, $reason);
        return response()->json(['message' => 'Student checked out successfully.']);
    }

    public function transfer(Request $request, HostelAllocation $allocation)
    {
        $this->admin($request);
        $data = $request->validate(['hostel_room_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($allocation, $data, $request) {
            $locked = HostelAllocation::query()->with('student')->lockForUpdate()->findOrFail($allocation->id);
            abort_if($locked->status !== 'active', 422, 'Only an active allocation can be transferred.');
            $room = HostelRoom::query()->with('hostel')->lockForUpdate()->findOrFail($data['hostel_room_id']);
            abort_if(! $room->is_active || ! $room->hostel->is_active, 422, 'The destination room is unavailable.');
            abort_if($room->id === $locked->hostel_room_id, 422, 'Select a different room.');
            $gender = strtolower((string) $locked->student->sex);
            abort_if($room->hostel->gender !== 'mixed' && $gender && $room->hostel->gender !== $gender, 422, 'The student gender does not match the destination hostel.');
            $occupied = HostelAllocation::query()->where('hostel_room_id', $room->id)->where('session_id', $locked->session_id)->where('status', 'active')->count();
            abort_if($occupied >= $room->capacity, 422, 'The destination room is full.');
            $fromHostel = $locked->hostel_id; $fromRoom = $locked->hostel_room_id;
            $locked->update(['hostel_id' => $room->hostel_id, 'hostel_room_id' => $room->id]);
            $this->event($locked, 'transferred', $fromHostel, $fromRoom, $room->hostel_id, $room->id, $request->user()->id, $data['reason']);
        });
        return response()->json(['message' => 'Student transferred successfully.']);
    }

    private function event(HostelAllocation $allocation, string $action, ?int $fromHostel, ?int $fromRoom, ?int $toHostel, ?int $toRoom, int $actor, ?string $reason): void
    {
        HostelAllocationEvent::create(['school_id' => $allocation->school_id, 'hostel_allocation_id' => $allocation->id, 'student_id' => $allocation->student_id, 'from_hostel_id' => $fromHostel, 'from_room_id' => $fromRoom, 'to_hostel_id' => $toHostel, 'to_room_id' => $toRoom, 'performed_by' => $actor, 'action' => $action, 'reason' => $reason]);
    }

    private function admin(Request $request): void
    {
        abort_unless(strtolower((string) $request->user()?->role) === 'admin', 403, 'Only the school administrator can manage hostels.');
    }
}
