<?php

declare(strict_types=1);

use Kniebes\SimpleRssReader\Kernel;
use Kniebes\SimpleRssReader\Util\Auth;

require __DIR__ . '/../vendor/autoload.php';

Kernel::environment();

$error = '';
$redirect = (string) ($_POST['redirect'] ?? $_GET['redirect'] ?? '/');
if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    // Open-Redirect-Schutz: nur projekt-interne Pfade zulassen.
    $redirect = '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        header('Location: ' . $redirect, response_code: 303);
        exit;
    }
    sleep(1); // simple Brute-Force-Bremse
    $error = 'Falsches Passwort.';
}

require dirname(__DIR__) . '/templates/login.phtml';
