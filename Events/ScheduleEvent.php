<?php
namespace Modules\AmeiseModule\Events;

use Illuminate\Console\Scheduling\Schedule;

class ScheduleEvent
{
    public $schedule;

    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
    }
}
