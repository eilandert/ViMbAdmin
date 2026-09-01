<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Doctrine;

final class ResultValidator
{
    /**
     * @template T of object
     * @param class-string<T> $expectedClass
     * @return array<int,T>
     */
    public static function entityList(mixed $result, string $expectedClass, string $context): array
    {
        if (!is_array($result)) {
            throw new \UnexpectedValueException($context . ' must return an entity array.');
        }

        $entities = [];
        foreach ($result as $key => $entity) {
            if (!is_int($key) || !$entity instanceof $expectedClass) {
                throw new \UnexpectedValueException($context . ' returned an invalid entity.');
            }
            $entities[$key] = $entity;
        }
        return $entities;
    }

    /**
     * @template T of object
     * @param class-string<T> $expectedClass
     * @return T|null
     */
    public static function nullableEntity(mixed $result, string $expectedClass, string $context): ?object
    {
        if ($result === null) {
            return null;
        }
        if (!$result instanceof $expectedClass) {
            throw new \UnexpectedValueException($context . ' returned an invalid entity.');
        }
        return $result;
    }

    public static function affectedRows(mixed $result, string $context): int
    {
        if (!is_int($result)) {
            throw new \UnexpectedValueException($context . ' must return an integer row count.');
        }
        return $result;
    }
}
