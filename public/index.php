<?php
declare(strict_types=1);

use Tds\CoreFrontendApi\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

Bootstrap::createApp(dirname(__DIR__))->run();
