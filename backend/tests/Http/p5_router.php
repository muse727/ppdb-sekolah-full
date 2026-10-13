<?php
$app = require __DIR__.'/p5_bootstrap.php';
$request = Illuminate\Http\Request::capture();
$response = $app->handle($request);
$response->send();
$app->terminate($request, $response);
