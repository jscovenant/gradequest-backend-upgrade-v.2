<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\SupportTicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketNotifier $notifier)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $platform = $this->isSupportUser($user);
        abort_if($user->isSuperAdminUser() && ! $platform, 403, 'Support access is required.');

        $query = SupportTicket::query()
            ->with(['school:id,name', 'creator:id,firstname,surname,email', 'assignee:id,firstname,surname,email'])
            ->withCount('messages');

        if (! $platform) {
            abort_unless($user->school_id, 403, 'A school account is required.');
            $query->where('school_id', $user->school_id);
        }

        $query
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($platform && $request->filled('assigned_to'), function ($q) use ($request) {
                $request->string('assigned_to')->toString() === 'unassigned'
                    ? $q->whereNull('assigned_to')
                    : $q->where('assigned_to', (int) $request->input('assigned_to'));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%' . trim((string) $request->input('search')) . '%';
                $q->where(fn ($inner) => $inner->where('ticket_number', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhereHas('school', fn ($school) => $school->where('name', 'like', $search)));
            })
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'waiting_for_school' THEN 3 WHEN 'resolved' THEN 4 ELSE 5 END")
            ->orderByDesc('last_reply_at');

        return response()->json([
            'tickets' => $query->paginate(min(max((int) $request->input('per_page', 20), 5), 100)),
            'is_support_user' => $platform,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_if($user->isSuperAdminUser(), 422, 'Tickets must be opened from a school account.');
        abort_unless($user->school_id, 403, 'A school account is required.');

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'category' => ['required', Rule::in(['technical', 'billing', 'results', 'account', 'training', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $ticket = DB::transaction(function () use ($data, $user) {
            $ticket = SupportTicket::create([
                'public_id' => (string) Str::uuid(),
                'ticket_number' => $this->ticketNumber(),
                'school_id' => $user->school_id,
                'created_by' => $user->id,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => 'open',
                'last_reply_at' => now(),
            ]);

            $ticket->messages()->create([
                'user_id' => $user->id,
                'sender_type' => 'school',
                'message' => $data['message'],
            ]);

            return $ticket;
        });

        $ticket->load(['school', 'creator', 'assignee']);
        $this->notifier->ticketCreated($ticket);

        return response()->json(['message' => 'Support ticket created.', 'ticket' => $this->payload($ticket)], 201);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTicket($request->user(), $ticket);
        $ticket->load(['school:id,name', 'creator:id,firstname,surname,email', 'assignee:id,firstname,surname,email', 'messages.user:id,firstname,surname,email,role']);

        return response()->json(['ticket' => $this->payload($ticket, true), 'is_support_user' => $this->isSupportUser($request->user())]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        abort_if($ticket->status === 'closed', 422, 'Reopen this ticket before adding a reply.');

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:10000'],
            'internal_note' => ['nullable', 'boolean'],
        ]);
        $platform = $this->isSupportUser($user);
        abort_if($request->boolean('internal_note') && ! $platform, 403);

        $message = DB::transaction(function () use ($ticket, $user, $data, $platform, $request) {
            $message = $ticket->messages()->create([
                'user_id' => $user->id,
                'sender_type' => $platform ? 'support' : 'school',
                'message' => $data['message'],
                'is_internal_note' => $platform && $request->boolean('internal_note'),
            ]);

            $updates = ['last_reply_at' => now()];
            if (! $request->boolean('internal_note')) {
                $updates['status'] = $platform ? 'waiting_for_school' : 'open';
                $updates['closed_at'] = null;
            }
            $ticket->update($updates);

            return $message;
        });

        $ticket->load(['school', 'creator', 'assignee']);
        if (! $message->is_internal_note) {
            $this->notifier->replied($ticket, $message, $user);
        }

        return response()->json(['message' => 'Reply sent.', 'ticket' => $this->payload($ticket->fresh(), true)]);
    }

    public function update(Request $request, SupportTicket $ticket)
    {
        $this->requireSupport($request->user());
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'waiting_for_school', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        if (($data['status'] ?? null) === 'closed') {
            $data['closed_at'] = now();
        } elseif (isset($data['status'])) {
            $data['closed_at'] = null;
        }
        $ticket->update($data);

        return response()->json(['message' => 'Ticket updated.', 'ticket' => $this->payload($ticket->fresh())]);
    }

    public function assign(Request $request, SupportTicket $ticket)
    {
        $this->requireSupport($request->user());
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        $assignee = isset($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        abort_if($assignee && ! $this->isSupportUser($assignee), 422, 'The assignee must be an active platform support user.');

        $ticket->update([
            'assigned_to' => $assignee?->id,
            'status' => $assignee && $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ]);
        $ticket->load(['school', 'creator', 'assignee']);
        if ($assignee) {
            $this->notifier->assigned($ticket);
        }

        return response()->json(['message' => $assignee ? 'Ticket assigned.' : 'Ticket unassigned.', 'ticket' => $this->payload($ticket)]);
    }

    public function assignees(Request $request)
    {
        $this->requireSupport($request->user());
        $users = User::query()
            ->where('status', 1)
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(role, '-', ''), ' ', ''), '_', '')) in ('superadmin', 'platformstaff')")
            ->orderBy('firstname')->get()
            ->filter(fn (User $user) => $user->hasSuperAdminPermission('support'))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => trim("{$user->firstname} {$user->surname}"), 'email' => $user->email])
            ->values();

        return response()->json(['assignees' => $users]);
    }

    private function authorizeTicket(User $user, SupportTicket $ticket): void
    {
        if ($user->isSuperAdminUser()) {
            $this->requireSupport($user);
            return;
        }
        abort_unless($user->school_id && (int) $user->school_id === (int) $ticket->school_id, 403);
    }

    private function requireSupport(User $user): void
    {
        abort_unless($this->isSupportUser($user), 403, 'Support access is required.');
    }

    private function isSupportUser(User $user): bool
    {
        return $user->isSuperAdminUser() && $user->hasSuperAdminPermission('support') && (int) $user->status === 1;
    }

    private function ticketNumber(): string
    {
        do {
            $number = 'GQ-' . now()->format('Ym') . '-' . Str::upper(Str::random(6));
        } while (SupportTicket::where('ticket_number', $number)->exists());

        return $number;
    }

    private function payload(SupportTicket $ticket, bool $withMessages = false): array
    {
        $ticket->loadMissing(['school:id,name', 'creator:id,firstname,surname,email', 'assignee:id,firstname,surname,email']);
        $data = $ticket->toArray();
        if ($withMessages) {
            $ticket->loadMissing('messages.user:id,firstname,surname,email,role');
            $data['messages'] = $ticket->messages
                ->reject(fn (SupportTicketMessage $message) => $message->is_internal_note && ! request()->user()->isSuperAdminUser())
                ->values()->toArray();
        }
        return $data;
    }
}
