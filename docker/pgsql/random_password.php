<?php
declare(strict_types=1);

use \ParagonIE\ConstantTime\{
    Base64,
    Base64UrlSafe
};

require \dirname(\dirname(__DIR__)) . '/src/bootstrap.php';

$password = Base64UrlSafe::encode(\random_bytes(33));
$token = Base64::encode(\random_bytes(33));

\Airship\saveJSON(
    ROOT . 'tmp/installing.json',
    [
        'step' => 2,
        'token' => $token,
        'database' => [
            [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'port' => 5432,
                'username' => 'airship',
                'password' => $password,
                'database' => 'airship'
            ]
        ]
    ]
);

echo $password;
exit(0);