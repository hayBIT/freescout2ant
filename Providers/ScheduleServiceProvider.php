<?php
namespace Modules\AmeiseModule\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AmeiseModule\Events\ScheduleEvent;
use Modules\AmeiseModule\Listeners\ScheduleListener;

class ScheduleServiceProvider extends ServiceProvider
{
    protected $listen = [
        ScheduleEvent::class => [
            ScheduleListener::class,
        ],
    ];

    public function boot()
    {
        parent::boot();
        // Additional logic
    }
}
