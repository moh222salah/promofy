<?php

/*
|--------------------------------------------------------------------------
| Console routes & scheduler
|--------------------------------------------------------------------------
| Custom Artisan closures and scheduled jobs are registered here.
*/

use Illuminate\Support\Facades\Schedule;

Schedule::command('promofy:expire-quotations')->dailyAt('00:10');
