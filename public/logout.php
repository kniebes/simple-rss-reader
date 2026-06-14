<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Util\Auth;

require __DIR__ . '/../vendor/autoload.php';

Kernel::environment();
Auth::logout();

header('Location: /login.php', response_code: 302);
