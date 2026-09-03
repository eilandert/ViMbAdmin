<?php

class OSS_Captcha_Image
{
    private const MAX_FILES = 500;

    private int $dotNoise;
    private int $lineNoise;
    private int $wordLen;
    private int $timeout;

    private static function boundedInt( mixed $value, string $name, int $minimum, int $maximum ): int
    {
        if( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) {
            $normalized = ltrim( $value, '0' );
            $value = filter_var( $normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT );
        }
        if( !is_int( $value ) || $value < $minimum || $value > $maximum )
            throw new ValueError( $name . ' is outside its permitted range' );
        return $value;
    }

    public function __construct(
        mixed $dotNoise = 100,
        mixed $lineNoise = 5,
        mixed $wordLen = 6,
        mixed $timeout = 1800
    )
    {
        $this->dotNoise = self::boundedInt( $dotNoise, 'Captcha dot noise', 0, 10000 );
        $this->lineNoise = self::boundedInt( $lineNoise, 'Captcha line noise', 0, 1000 );
        $this->wordLen = self::boundedInt( $wordLen, 'Captcha word length', 1, 64 );
        $this->timeout = self::boundedInt( $timeout, 'Captcha timeout', 1, 86400 );
    }

    public function generate(): string
    {
        $id = bin2hex(random_bytes(16));
        if ($this->wordLen < 1) {
            throw new ValueError('Captcha word length must be greater than 0');
        }
        $word = OSS_String::randomFromSet('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', $this->wordLen);

        $_SESSION['OSS_Captcha_' . $id] = [
            'word' => $word,
            'expires' => time() + $this->timeout,
        ];

        $dir = OSS_Utils::getTempDir() . '/captchas';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create captcha directory');
            }
        }
        $path = $dir . '/' . $id . '.png';
        try {
            $this->cleanup($dir);
            $this->render($path, $word);
        } catch (Throwable $error) {
            unset($_SESSION['OSS_Captcha_' . $id]);
            @unlink($path);
            throw $error;
        }

        return $id;
    }

    public static function _isValid(mixed $id, mixed $value): bool
    {
        if( !is_string( $id ) )
            return false;
        $key = 'OSS_Captcha_' . $id;
        $captcha = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        $path = self::path((string) $id);
        if ($path !== null) {
            @unlink($path);
        }

        if( !is_string( $value ) )
            return false;

        return is_array($captcha)
            && is_int($captcha['expires'] ?? null)
            && $captcha['expires'] >= time()
            && is_string($captcha['word'] ?? null)
            && hash_equals($captcha['word'], strtoupper(trim($value)));
    }

    public static function path(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = OSS_Utils::getTempDir() . '/captchas/' . $id . '.png';

        return is_readable($path) ? $path : null;
    }

    private function render(string $path, string $word): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required for captcha images');
        }

        $image = imagecreatetruecolor(260, 80);
        $background = imagecolorallocate($image, 248, 248, 248);
        $foreground = imagecolorallocate($image, 35, 35, 35);
        $noise = imagecolorallocate($image, 150, 150, 150);
        if ($background === false || $foreground === false || $noise === false) {
            throw new RuntimeException('Unable to allocate captcha image colors');
        }
        imagefilledrectangle($image, 0, 0, 259, 79, $background);

        for ($i = 0; $i < $this->dotNoise; $i++) {
            imagesetpixel($image, random_int(0, 259), random_int(0, 79), $noise);
        }
        for ($i = 0; $i < $this->lineNoise; $i++) {
            imageline(
                $image,
                random_int(0, 259),
                random_int(0, 79),
                random_int(0, 259),
                random_int(0, 79),
                $noise
            );
        }

        $font = dirname(__FILE__) . '/../../../data/freeserif.ttf';
        if (function_exists('imagettftext') && is_readable($font)) {
            imagettftext($image, 36, random_int(-4, 4), 24, 57, $foreground, $font, $word);
        } else {
            imagestring($image, 5, 70, 31, $word, $foreground);
        }

        try {
            if (!$this->writeImage($image, $path)) {
                throw new RuntimeException('Unable to write captcha image');
            }
        } finally {
            imagedestroy($image);
        }
    }

    protected function writeImage(GdImage $image, string $path): bool
    {
        return imagepng($image, $path);
    }

    private function cleanup(string $dir): void
    {
        $files = glob($dir . '/*.png') ?: [];
        $cutoff = time() - max(1, $this->timeout);

        $mtimes = [];
        foreach ($files as $key => $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime < $cutoff) {
                @unlink($file);
                unset($files[$key]);
                continue;
            }
            $mtimes[$file] = $mtime;
        }

        if (count($files) < self::MAX_FILES) {
            return;
        }

        usort($files, static fn(string $a, string $b): int => $mtimes[$a] <=> $mtimes[$b]);

        foreach (array_slice($files, 0, count($files) - self::MAX_FILES + 1) as $file) {
            @unlink($file);
        }
    }
}
