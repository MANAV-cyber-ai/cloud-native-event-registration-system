<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

logoutUser();
setFlash('success', 'You have been logged out successfully.');
redirectTo('index.php');

