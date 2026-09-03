<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Input;

final class Reader
{
    public static function requiredString(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new \LogicException("{$name} must be a string");
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function stringKeyedArray(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \LogicException("{$name} must be an array");
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new \LogicException("{$name} must use string keys");
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{bool,mixed}
     */
    public static function option(array $options, string ...$path): array
    {
        $value = $options;
        $walked = [];
        foreach ($path as $key) {
            $value = self::stringKeyedArray(
                $value,
                'Configuration ' . ($walked === [] ? 'root' : implode('.', $walked)),
            );
            $walked[] = $key;
            if (!array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }
}
