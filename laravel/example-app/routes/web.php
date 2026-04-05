<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

$frontendFiles = [
    'admin-login.html',
    'admin.html',
    'api.js',
    'chat.html',
    'contacts.html',
    'index.html',
    'login.html',
    'signup.html',
    'visitor.html',
];

$serveFrontendFile = function (string $file) use ($frontendFiles) {
    abort_unless(in_array($file, $frontendFiles, true), 404);

    $path = base_path('html/' . $file);
    abort_unless(File::exists($path), 404);

    $contentType = match (pathinfo($path, PATHINFO_EXTENSION)) {
        'js' => 'application/javascript; charset=UTF-8',
        default => 'text/html; charset=UTF-8',
    };

    return response()->file($path, [
        'Content-Type' => $contentType,
    ]);
};

Route::get('/', fn () => $serveFrontendFile('visitor.html'));

Route::get('/{file}', fn (string $file) => $serveFrontendFile($file))
    ->where('file', implode('|', array_map(
        static fn (string $file) => preg_quote($file, '/'),
        $frontendFiles
    )));
