<?php

declare(strict_types=1);

/** @return list<string> */
function staticLocationHeaders(string $config): array
{
    $marker = 'location ~* \.(?:css|js|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot|map)$ {';
    $start = strpos($config, $marker);
    if ($start === false) {
        return [];
    }
    $end = strpos($config, "\n    }", $start);
    if ($end === false) {
        return [];
    }
    preg_match_all('/^\s*add_header\s+(.+);\s*$/m', substr($config, $start, $end - $start), $matches);
    return $matches[1];
}

$config = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
if (!is_string($config)) {
    throw new RuntimeException('Unable to read shipped Angie configuration');
}

$required = [
    'X-Frame-Options            "DENY"                              always',
    'X-Content-Type-Options     "nosniff"                           always',
    'X-XSS-Protection           "0"                                 always',
    'Referrer-Policy            "strict-origin-when-cross-origin"   always',
    'Permissions-Policy         "accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()" always',
    'Cross-Origin-Opener-Policy "same-origin"                       always',
    'Strict-Transport-Security  "max-age=31536000; includeSubDomains" always',
    'Content-Security-Policy "default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; font-src \'self\' data:; connect-src \'self\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'self\'; object-src \'none\'" always',
    'Cache-Control "public, max-age=604800, immutable"',
];

$headers = staticLocationHeaders($config);
if ($headers !== $required) {
    fwrite(STDERR, "Static responses do not retain the complete ordered security-header contract.\n");
    exit(1);
}

$mutant = str_replace("        add_header Strict-Transport-Security  \"max-age=31536000; includeSubDomains\" always;\n", '', $config);
if (staticLocationHeaders($mutant) === $required) {
    fwrite(STDERR, "Missing static-response security headers are not detected.\n");
    exit(1);
}

echo "Angie static-response security headers passed\n";
