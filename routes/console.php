<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
    $schedule->command('logs:collect')
        ->everyFiveSeconds()
        ->runInBackground()
        ->withoutOverlapping();

    $schedule->command('metrics:collect')
        ->everyMinute()
        ->runInBackground()
        ->withoutOverlapping();
});

// Schedule::macro('collectLogs', function (Schedule $schedule) {
//     $schedule->command('logs:collect')
//         ->everyFiveSeconds()
//         ->runInBackground()
//         ->withoutOverlapping();
// });

// Schedule::macro('collectMetrics', function (Schedule $schedule) {
//     $schedule->command('metrics:collect')
//         ->everyMinute()
//         ->runInBackground()
//         ->withoutOverlapping();
// });