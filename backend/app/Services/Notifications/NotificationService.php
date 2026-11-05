<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\PlatformEvent;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * Notify staff holding the event's permission (or only the given users), plus any extra e-mail
     * recipients configured by an administrator. Never throws: a notification problem must not break the business action.
     *
     * @param  array<string, scalar|null>  $data
     * @param  list<int>|null  $onlyUserIds
     */
    public static function send(string $event, array $data = [], ?array $onlyUserIds = null): void
    {
        try {
            if (! EventCatalog::exists($event)) {
                return;
            }
            $permission = EventCatalog::all()[$event]['permission'];
            $users = $onlyUserIds !== null
                ? User::whereIn('id', $onlyUserIds)->where('is_active', true)->get()
                : User::where('type', User::TYPE_STAFF)->where('is_active', true)->get()->filter(fn (User $u) => $u->hasPermission($permission))->values();

            $notification = new PlatformEvent($event, $data);
            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }

            $extra = (array) (((array) app(SettingsService::class)->get('notifications', 'recipients', []))[$event] ?? []);
            foreach (array_filter($extra, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)) as $email) {
                Notification::route('mail', $email)->notify(new PlatformEvent($event, $data));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
