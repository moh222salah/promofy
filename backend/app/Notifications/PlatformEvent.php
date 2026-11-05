<?php

namespace App\Notifications;

use App\Services\Notifications\EventCatalog;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformEvent extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> seconds between retries */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** @param  array<string, scalar|null>  $data */
    public function __construct(public string $event, public array $data = []) {}

    public function via(object $notifiable): array
    {
        $channels = (array) app(SettingsService::class)->get('notifications', 'channels', ['database' => true, 'mail' => true]);
        $via = [];
        if ($channels['database'] ?? true) {
            $via[] = 'database';
        }
        if (($channels['mail'] ?? true) && ! empty($notifiable->email)) {
            $via[] = 'mail';
        }

        return $via;
    }

    /** @return array{subject_ar: string, subject_en: string, body_ar: string, body_en: string} */
    public function texts(): array
    {
        $default = EventCatalog::all()[$this->event];
        $custom = (array) (((array) app(SettingsService::class)->get('notifications', 'templates', []))[$this->event] ?? []);
        $out = [];
        foreach (['subject_ar', 'subject_en', 'body_ar', 'body_en'] as $key) {
            $text = (string) (($custom[$key] ?? '') !== '' ? $custom[$key] : $default[$key]);
            foreach ($this->data as $name => $value) {
                $text = str_replace(':'.$name, (string) $value, $text);
            }
            $out[$key] = $text;
        }

        return $out;
    }

    public function toDatabase(object $notifiable): array
    {
        return ['event' => $this->event, 'data' => $this->data] + $this->texts();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->texts();

        return (new MailMessage)->subject($t['subject_en'].' · '.$t['subject_ar'])->line($t['body_en'])->line($t['body_ar']);
    }
}
