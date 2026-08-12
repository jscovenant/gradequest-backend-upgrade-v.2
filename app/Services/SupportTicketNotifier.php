<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportTicketNotifier
{
    public function ticketCreated(SupportTicket $ticket): void
    {
        $recipients = $this->supportUsers()->pluck('email')->filter()->unique()->all();
        $this->send($recipients, "New support ticket {$ticket->ticket_number}: {$ticket->subject}", $ticket,
            'A school has opened a new support ticket.', null, true);
    }

    public function replied(SupportTicket $ticket, SupportTicketMessage $message, User $sender): void
    {
        if ($sender->isSuperAdminUser()) {
            $recipient = $ticket->creator?->email;
            $intro = 'GradeQuest Support has replied to your ticket.';
        } else {
            $recipients = $ticket->assignee?->email
                ? [$ticket->assignee->email]
                : $this->supportUsers()->pluck('email')->filter()->unique()->all();
            $this->send($recipients, "School replied to {$ticket->ticket_number}: {$ticket->subject}", $ticket,
                'The school has added a reply to this support ticket.', $message->message, true);
            return;
        }

        $this->send(array_filter([$recipient]), "Update on {$ticket->ticket_number}: {$ticket->subject}", $ticket,
            $intro, $message->message);
    }

    public function assigned(SupportTicket $ticket): void
    {
        if (! $ticket->assignee?->email) {
            return;
        }

        $this->send([$ticket->assignee->email], "Ticket assigned: {$ticket->ticket_number}", $ticket,
            'This support ticket has been assigned to you.', null, true);
    }

    private function send(array $recipients, string $subject, SupportTicket $ticket, string $intro, ?string $message = null, bool $platformUrl = false): void
    {
        if ($recipients === []) {
            return;
        }

        $path = $platformUrl ? '/superadmin/support' : '/support';
        $url = config('support.frontend_url') . $path . '?ticket=' . $ticket->public_id;
        $schoolName = $ticket->school?->name ?: 'School';
        $safeIntro = e($intro);
        $safeMessage = $message ? nl2br(e($message)) : '';
        $html = "<div style=\"font-family:Arial,sans-serif;color:#172033;line-height:1.6\">"
            . "<h2 style=\"color:#4f46e5\">GradeQuest Support</h2><p>{$safeIntro}</p>"
            . "<p><strong>Ticket:</strong> " . e($ticket->ticket_number) . "<br>"
            . "<strong>School:</strong> " . e($schoolName) . "<br>"
            . "<strong>Subject:</strong> " . e($ticket->subject) . "</p>"
            . ($safeMessage ? "<div style=\"background:#f8fafc;border-left:4px solid #4f46e5;padding:12px\">{$safeMessage}</div>" : '')
            . "<p><a href=\"" . e($url) . "\" style=\"display:inline-block;background:#4f46e5;color:#fff;padding:10px 16px;text-decoration:none;border-radius:6px\">Open ticket</a></p>"
            . '<p style="color:#64748b;font-size:12px">Reply inside GradeQuest so the full conversation remains attached to the ticket.</p></div>';

        try {
            Mail::html($html, function ($mail) use ($recipients, $subject) {
                $mail->to($recipients)
                    ->from(config('support.mail_from_address'), config('support.mail_from_name'))
                    ->replyTo(config('support.mail_from_address'), config('support.mail_from_name'))
                    ->subject($subject);
            });
        } catch (\Throwable $exception) {
            Log::error('Support ticket email failed.', [
                'recipients' => $recipients,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function supportUsers()
    {
        return User::query()
            ->where('status', 1)
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(role, '-', ''), ' ', ''), '_', '')) in ('superadmin', 'platformstaff')")
            ->get()
            ->filter(fn (User $user) => $user->hasSuperAdminPermission('support'));
    }
}
