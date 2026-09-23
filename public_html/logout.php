<?php

declare(strict_types=1);

require __DIR__ . '/../private_html/bootstrap.php';

Auth::logout();
header('Location: /login.php');
exit;
