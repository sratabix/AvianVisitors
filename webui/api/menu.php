<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

echo
    json_encode([
        'items' => [
            ['label' => 'settings', 'href' => '/#admin=settings', 'native' => true],
            ['label' => 'system', 'href' => '/#admin=system', 'native' => true],
            ['label' => 'logs', 'href' => '/#admin=logs', 'native' => true],
            ['label' => 'tools', 'href' => '/#admin=tools', 'native' => true],
        ],
    ])
;
