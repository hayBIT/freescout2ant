<?php
namespace Modules\AmeiseModule\Listeners;

use Modules\AmeiseModule\Events\ScheduleEvent;

class ScheduleListener
{
    public function handle(ScheduleEvent $event)
    {
        $event->schedule->command('ameise:archive-threads')->everyFiveMinutes(); // every 5 mintues
    }
}

