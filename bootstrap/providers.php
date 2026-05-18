<?php

use App\Providers\AggregatorServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ScraperServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AggregatorServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    ScraperServiceProvider::class,
    TenancyServiceProvider::class,
];
