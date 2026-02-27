<?php

// Release: v2

$version = 'v2';

while (frankenphp_handle_request(function () use ($version) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'release' => $version,
        'worker_file' => __FILE__,
        'realpath' => realpath(__FILE__),
    ]);
})) {
}
