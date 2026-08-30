<?php

require __DIR__ . '/../library/OSS/Exception.php';
require __DIR__ . '/../library/OSS/Crypt/Exception.php';
require __DIR__ . '/../library/OSS/String.php';
require __DIR__ . '/../library/OSS/Crypt/Bcrypt.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};

$rejectsCost = static function (mixed $cost): bool {
    try {
        $bcryptClass = new ReflectionClass(OSS_Crypt_Bcrypt::class);
        $bcryptClass->newInstance($cost);
    } catch (OSS_Crypt_Exception) {
        return true;
    }

    return false;
};

echo "== OSS bcrypt ==\n";

$bcrypt = new OSS_Crypt_Bcrypt(4);
$hash = $bcrypt->hash('correct horse battery staple');
$check('hashing succeeds', is_string($hash));
$check('hash preserves the $2a$ format and configured cost', is_string($hash) && preg_match('/^\$2a\$04\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1);
$check('the generated hash verifies', is_string($hash) && $bcrypt->verify('correct horse battery staple', $hash));
$check('a wrong password is rejected', is_string($hash) && !$bcrypt->verify('wrong password', $hash));
$check('a malformed hash is rejected', !$bcrypt->verify('correct horse battery staple', 'not-a-bcrypt-hash'));

$knownHash = crypt('password', '$2a$04$usesomesillystringfore');
$check('a compatible existing $2a$ hash verifies', $bcrypt->verify('password', $knownHash));

$firstSalt = OSS_Crypt_Bcrypt::generateSalt();
$secondSalt = OSS_Crypt_Bcrypt::generateSalt();
$check('salt preserves the $2a$ format and configured cost', preg_match('/^\$2a\$04\$[A-Za-z0-9]{22}$/D', $firstSalt) === 1);
$check('successive salts use fresh randomness', $firstSalt !== $secondSalt);

new OSS_Crypt_Bcrypt('04');
$check('numeric configuration strings remain compatible', str_starts_with(OSS_Crypt_Bcrypt::generateSalt(), '$2a$04$'));
new OSS_Crypt_Bcrypt(31);
$check('the maximum valid cost is retained', str_starts_with(OSS_Crypt_Bcrypt::generateSalt(), '$2a$31$'));

$check('cost below the bcrypt minimum is rejected', $rejectsCost(3));
$check('cost above the bcrypt maximum is rejected', $rejectsCost(32));
$check('non-integer cost text is rejected', $rejectsCost('12x'));
$check('fractional costs are rejected', $rejectsCost(12.5));

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Crypt_Bcrypt assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
