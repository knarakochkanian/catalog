<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
App\Http\Application::create(new App\Bootstrap())->run();
