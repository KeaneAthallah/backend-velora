<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AuthorizationService;
use App\Traits\RequiresPlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    use RequiresPlatformAdmin;

    public function __construct(protected AuthorizationService $authz) {}

    /* ---------------------------------------------------------------- */
    /* Tenant side */
    /* ---------------------------------------------------------------- */

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'support.view', $businessId);

        $query = SupportTicket::with(['creator:id,name', 'assignee:id,name'])
            ->where('business_id', $businessId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $tickets = $query->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25))
            ->through(fn (SupportTicket $ticket) => $this->row($ticket));

        return $this->success($tickets);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'support.manage', $businessId);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(['general', 'billing', 'technical', 'account', 'feature', 'other'])],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array'],
        ]);

        $ticket = SupportTicket::create([
            'business_id' => $businessId,
            'created_by' => $request->user()->id,
            'subject' => $data['subject'],
            'category' => $data['category'] ?? 'general',
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_id' => $request->user()->id,
            'author_type' => 'business',
            'body' => $data['body'],
            'attachments' => $data['attachments'] ?? null,
        ]);

        $this->notifyPlatformAdmins($ticket);

        return $this->success(['ticket' => $this->row($ticket->load('creator:id,name'))], 'Support ticket created', 201);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'support.view', $businessId);
        abort_unless($ticket->business_id === $businessId, 404);

        return $this->success($this->detail($ticket));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'support.manage', $businessId);
        abort_unless($ticket->business_id === $businessId, 404);

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            return $this->error('This ticket is closed. Open a new ticket instead.', 422, [], 'TICKET_CLOSED');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array'],
        ]);

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_id' => $request->user()->id,
            'author_type' => 'business',
            'body' => $data['body'],
            'attachments' => $data['attachments'] ?? null,
        ]);

        $ticket->update(['status' => 'open', 'last_reply_at' => now()]);
        $this->notifyPlatformAdmins($ticket, $data['body']);

        return $this->success(['message' => $this->messageRow($message)], 'Reply sent', 201);
    }

    /* ---------------------------------------------------------------- */
    /* Platform side */
    /* ---------------------------------------------------------------- */

    public function adminIndex(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $query = SupportTicket::with(['business:id,name', 'creator:id,name', 'assignee:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($builder) => $builder->where('subject', 'like', $term)
                ->orWhere('number', 'like', $term));
        }

        $tickets = $query->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25))
            ->through(fn (SupportTicket $ticket) => $this->row($ticket));

        return $this->success([
            'tickets' => $tickets,
            'counts' => [
                'open' => SupportTicket::where('status', 'open')->count(),
                'pending' => SupportTicket::where('status', 'pending')->count(),
                'resolved' => SupportTicket::where('status', 'resolved')->count(),
                'closed' => SupportTicket::where('status', 'closed')->count(),
            ],
        ]);
    }

    public function adminShow(Request $request, SupportTicket $ticket)
    {
        $this->requirePlatformAdmin($request);

        return $this->success($this->detail($ticket));
    }

    public function adminReply(Request $request, SupportTicket $ticket)
    {
        $user = $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'attachments' => ['nullable', 'array'],
        ]);

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_type' => 'platform',
            'body' => $data['body'],
            'attachments' => $data['attachments'] ?? null,
        ]);

        $ticket->update([
            'status' => $data['status'] ?? 'pending',
            'assigned_to' => $ticket->assigned_to ?? $user->id,
            'last_reply_at' => now(),
        ]);

        if ($ticket->created_by) {
            UserNotification::create([
                'business_id' => $ticket->business_id,
                'user_id' => $ticket->created_by,
                'type' => 'support.reply',
                'title' => 'Support replied to '.$ticket->number,
                'body' => $data['body'],
                'data' => ['ticket_id' => (string) $ticket->id],
            ]);
        }

        return $this->success([
            'message' => $this->messageRow($message),
            'ticket' => $this->row($ticket->fresh()->load('creator:id,name')),
        ], 'Reply sent', 201);
    }

    public function adminUpdate(Request $request, SupportTicket $ticket)
    {
        $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if (($data['status'] ?? null) === 'closed') {
            $data['closed_at'] = now();
        } elseif (array_key_exists('status', $data)) {
            $data['closed_at'] = null;
        }

        $ticket->update($data);

        return $this->success(['ticket' => $this->row($ticket->fresh()->load('creator:id,name', 'assignee:id,name'))], 'Ticket updated');
    }

    /* ---------------------------------------------------------------- */
    /* Internals */
    /* ---------------------------------------------------------------- */

    private function detail(SupportTicket $ticket): array
    {
        $ticket->load(['business:id,name', 'creator:id,name,email', 'assignee:id,name', 'messages.author:id,name']);

        return [
            'ticket' => $this->row($ticket),
            'messages' => $ticket->messages->map(fn (SupportTicketMessage $message) => $this->messageRow($message)),
        ];
    }

    private function row(SupportTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'business' => $ticket->relationLoaded('business') ? $ticket->business?->name : null,
            'business_id' => $ticket->business_id ? (string) $ticket->business_id : null,
            'created_by' => $ticket->creator?->name,
            'created_by_email' => $ticket->creator?->email,
            'assigned_to' => $ticket->assignee?->name,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    private function messageRow(SupportTicketMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'author' => $message->author?->name,
            'author_type' => $message->author_type,
            'body' => $message->body,
            'attachments' => $message->attachments,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function notifyPlatformAdmins(SupportTicket $ticket, ?string $body = null): void
    {
        User::where('is_platform_admin', true)->pluck('id')->each(function (int $userId) use ($ticket, $body) {
            UserNotification::create([
                'business_id' => $ticket->business_id,
                'user_id' => $userId,
                'type' => 'support.ticket',
                'title' => 'New support ticket '.$ticket->number,
                'body' => $body ?? $ticket->subject,
                'data' => ['ticket_id' => (string) $ticket->id],
            ]);
        });
    }
}
