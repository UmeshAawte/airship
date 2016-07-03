<?php
declare(strict_types=1);

require \dirname(\dirname(__DIR__)) . '/src/bootstrap.php';

$config = \Airship\loadJSON(ROOT . '/tmp/installing.json');

echo $config['token'];
exit(0);