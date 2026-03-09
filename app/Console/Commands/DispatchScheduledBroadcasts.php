<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BroadcastNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch';
    protected $description = 'Dispatch scheduled broadcasts to parents/admins/both';

    public function handle(): int
    {
        $broadcasts = DB::table('broadcasts')
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<=', now())
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($broadcasts as $b) {
            DB::table('broadcasts')->where('id', $b->id)->update(['status' => 'processing', 'updated_at' => now()]);

            $sendEmail = in_array($b->channel, ['email', 'both'], true);
            $sendWhatsApp = in_array($b->channel, ['whatsapp', 'both'], true);

            $waParams = [];
            if (!empty($b->whatsapp_params)) {
                $decoded = json_decode($b->whatsapp_params, true);
                if (is_array($decoded)) $waParams = $decoded;
            }

            $notification = new BroadcastNotification(
                subject: $b->subject,
                message: $b->message,
                waTemplate: $b->whatsapp_template_name,
                waLang: $b->whatsapp_lang ?? 'en_US',
                waParams: $waParams,
                sendEmail: $sendEmail,
                sendWhatsApp: $sendWhatsApp
            );

            if ($b->audience === 'parents') {
                $parentIds = DB::table('parent_students')
                    ->when($b->school_id, fn($q) => $q->where('school_id', $b->school_id))
                    ->distinct()
                    ->pluck('parent_id');

                User::whereIn('id', $parentIds)->chunkById(200, function ($users) use ($notification) {
                    foreach ($users as $u) $u->notify($notification);
                });
            } elseif ($b->audience === 'admins') {
                User::query()
                    ->when($b->school_id, fn($q) => $q->where('school_id', $b->school_id))
                    ->where('role', 'Admin')
                    ->chunkById(200, function ($users) use ($notification) {
                        foreach ($users as $u) $u->notify($notification);
                    });
            } else { // both
                // parents
                $parentIds = DB::table('parent_students')
                    ->when($b->school_id, fn($q) => $q->where('school_id', $b->school_id))
                    ->distinct()
                    ->pluck('parent_id');

                User::whereIn('id', $parentIds)->chunkById(200, function ($users) use ($notification) {
                    foreach ($users as $u) $u->notify($notification);
                });

                // admins
                User::query()
                    ->when($b->school_id, fn($q) => $q->where('school_id', $b->school_id))
                    ->where('role', 'Admin')
                    ->chunkById(200, function ($users) use ($notification) {
                        foreach ($users as $u) $u->notify($notification);
                    });
            }

            DB::table('broadcasts')->where('id', $b->id)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return self::SUCCESS;
    }
}