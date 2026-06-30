<?php
// sms-side/app/Observers/QuestionObserver.php

namespace App\Observers;

use App\Models\Question;
use App\Services\CbtConnectorService;

/**
 * Automatically queues question sync to CBT whenever a question
 * is created, updated, or deleted in the SMS.
 * This is the "offline-first" trigger — syncs immediately if online,
 * queues for later if not.
 */
class QuestionObserver
{
    public function __construct(private CbtConnectorService $cbt) {}

    public function saved(Question $question): void
    {
        // Only sync if the exam is linked to CBT (has been sent to CBT)
        if (!$question->exam?->cbt_exam_id) return;

        $this->cbt->queueQuestion($question, 'upsert');
    }

    public function deleted(Question $question): void
    {
        if (!$question->exam?->cbt_exam_id) return;

        $this->cbt->queueQuestion($question, 'delete');
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// sms-side/app/Observers/ExamObserver.php
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Observers;

use App\Models\Exam;
use App\Services\CbtConnectorService;

class ExamObserver
{
    public function __construct(private CbtConnectorService $cbt) {}

    /**
     * When an exam is published in SMS, queue all its questions for sync.
     */
    public function updated(Exam $exam): void
    {
        if ($exam->wasChanged('status') && $exam->status === 'published') {
            $this->cbt->queueExamQuestions($exam);
        }
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// sms-side/app/Notifications/CbtSyncFailureNotification.php
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Notifications;

use App\Models\CbtSyncQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

/**
 * Notifies the school admin when sync items are permanently abandoned.
 * Sent via email + stored in the notifications table for in-app display.
 */
class CbtSyncFailureNotification extends Notification
{
    use Queueable;

    public function __construct(
        private CbtSyncQueue $item,
        private string       $context = ''
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('CBT Sync Failed — Action Required')
            ->greeting("Hello {$notifiable->name},")
            ->line("A {$this->item->entity_type} failed to sync to the CBT platform after multiple attempts.")
            ->line("**Error:** {$this->item->last_error}")
            ->line("**Entity:** {$this->item->entity_type} #{$this->item->entity_id}")
            ->action('View Sync Dashboard', url('/admin/cbt/sync-status'))
            ->line('You can retry failed items from the CBT Sync dashboard.')
            ->salutation('— GradeQuest');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'        => 'cbt_sync_failure',
            'entity_type' => $this->item->entity_type,
            'entity_id'   => $this->item->entity_id,
            'error'       => $this->item->last_error,
            'sync_item_id'=> $this->item->id,
            'action_url'  => '/admin/cbt/sync-status',
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// sms-side/app/Notifications/CbtResultReceivedNotification.php
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Notifications;

use App\Models\CbtResultsInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

/**
 * In-app notification: results have been received and posted from CBT.
 */
class CbtResultReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(private array $summary) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'       => 'cbt_results_received',
            'exam_id'    => $this->summary['cbt_exam_id'],
            'count'      => $this->summary['count'],
            'term'       => $this->summary['term'],
            'session'    => $this->summary['academic_session'],
            'action_url' => '/admin/results',
            'message'    => "{$this->summary['count']} CBT results have been posted to student records.",
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// sms-side/app/Providers/AppServiceProvider.php additions
// ─────────────────────────────────────────────────────────────────────────────

/*
Add to the boot() method of AppServiceProvider:

use App\Models\{Question, Exam};
use App\Observers\{QuestionObserver, ExamObserver};

Question::observe(QuestionObserver::class);
Exam::observe(ExamObserver::class);
*/
