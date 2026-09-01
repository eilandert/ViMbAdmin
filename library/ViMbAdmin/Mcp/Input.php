<?php

/**
 * Runtime shape validation for untrusted MCP request and configuration values.
 */
final class ViMbAdmin_Mcp_Input
{
    /** @return array<string,mixed> */
    public static function map(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new ViMbAdmin_Mcp_Exception("{$name} must be an object");
        }
        foreach ($value as $key => $_) {
            if (!is_string($key)) {
                throw new ViMbAdmin_Mcp_Exception("{$name} must use string keys");
            }
        }

        return $value;
    }

    public static function string(mixed $value, string $name, bool $required = false): string
    {
        if (!is_string($value)) {
            throw new ViMbAdmin_Mcp_Exception("{$name} must be a string");
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new ViMbAdmin_Mcp_Exception("{$name} required");
        }

        return $value;
    }

    public static function exactString(mixed $value, string $name, bool $required = false): string
    {
        if (!is_string($value)) {
            throw new ViMbAdmin_Mcp_Exception("{$name} must be a string");
        }
        if ($required && $value === '') {
            throw new ViMbAdmin_Mcp_Exception("{$name} required");
        }

        return $value;
    }

    public static function exactNullableString(mixed $value, string $name): ?string
    {
        return $value === null ? null : self::exactString($value, $name);
    }

    public static function boolean(mixed $value, string $name): bool
    {
        if (!is_bool($value)) {
            throw new ViMbAdmin_Mcp_Exception("{$name} must be a boolean");
        }

        return $value;
    }

    public static function nonNegativeInteger(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new ViMbAdmin_Mcp_Exception("{$name} must be a non-negative integer");
    }

    /** @param array<string,mixed> $values */
    public static function optionalBoolean(array $values, string $key, bool $default): bool
    {
        return array_key_exists($key, $values)
            ? self::boolean($values[$key], "param \"{$key}\"")
            : $default;
    }

    /** @param array<string,mixed> $values */
    public static function optionalInteger(array $values, string $key, int $default): int
    {
        return array_key_exists($key, $values)
            ? self::nonNegativeInteger($values[$key], "param \"{$key}\"")
            : $default;
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
            $walked[] = $key;
            if (!is_array($value)) {
                throw new ViMbAdmin_Mcp_Exception(
                    'configuration ' . implode('.', array_slice($walked, 0, -1)) . ' must be an object'
                );
            }
            $value = self::map(
                $value,
                'configuration ' . (count($walked) === 1 ? 'root' : implode('.', array_slice($walked, 0, -1)))
            );
            if (!array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }

    /** @param array<string,mixed> $options */
    public static function optionString(array $options, string $default, string ...$path): string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found
            ? self::string($value, 'configuration ' . implode('.', $path))
            : $default;
    }

    /** @param array<string,mixed> $options */
    public static function optionInteger(array $options, int $default, string ...$path): int
    {
        [$found, $value] = self::option($options, ...$path);
        return $found
            ? self::nonNegativeInteger($value, 'configuration ' . implode('.', $path))
            : $default;
    }

    /** @param array<string,mixed> $options */
    public static function optionBoolean(array $options, bool $default, string ...$path): bool
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        throw new ViMbAdmin_Mcp_Exception(
            'configuration ' . implode('.', $path) . ' must be boolean'
        );
    }

    /**
     * @return array{mode?:'auto'|'off'|'on',proxies?:list<string>|string}
     */
    public static function trustedProxy(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $options = self::map($value, 'configuration trustedproxy');
        $result = [];
        if (array_key_exists('mode', $options)) {
            $mode = strtolower(self::string($options['mode'], 'configuration trustedproxy.mode', true));
            if (!in_array($mode, ['auto', 'off', 'on'], true)) {
                throw new ViMbAdmin_Mcp_Exception('configuration trustedproxy.mode must be auto, off, or on');
            }
            $result['mode'] = $mode;
        }
        if (array_key_exists('proxies', $options)) {
            $proxies = $options['proxies'];
            if (is_string($proxies)) {
                $result['proxies'] = $proxies;
            } elseif (is_array($proxies) && array_is_list($proxies)) {
                $list = [];
                foreach ($proxies as $proxy) {
                    if (!is_string($proxy)) {
                        throw new ViMbAdmin_Mcp_Exception('configuration trustedproxy.proxies must contain strings');
                    }
                    $list[] = $proxy;
                }
                $result['proxies'] = $list;
            } else {
                throw new ViMbAdmin_Mcp_Exception('configuration trustedproxy.proxies must be a string or list of strings');
            }
        }

        return $result;
    }

    /**
     * @return array{username:string,local_part:string,name:?string,password:string,quota:int,active:bool}
     */
    public static function mailboxSnapshot(mixed $value): array
    {
        $snapshot = self::map($value, 'archive mailbox snapshot');
        foreach (['username', 'local_part', 'name', 'password', 'quota', 'active'] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new ViMbAdmin_Mcp_Exception("archive mailbox snapshot missing {$key}");
            }
        }

        return [
            'username' => self::exactString($snapshot['username'], 'archive mailbox snapshot username', true),
            'local_part' => self::exactString($snapshot['local_part'], 'archive mailbox snapshot local_part', true),
            'name' => self::exactNullableString($snapshot['name'], 'archive mailbox snapshot name'),
            'password' => self::exactString($snapshot['password'], 'archive mailbox snapshot password', true),
            'quota' => self::nonNegativeInteger($snapshot['quota'], 'archive mailbox snapshot quota'),
            'active' => self::boolean($snapshot['active'], 'archive mailbox snapshot active'),
        ];
    }
}
