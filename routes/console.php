<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('test', function () {
    $this->info('LRC Handicapping artisan is working.');
})->purpose('Test that artisan boots correctly');
