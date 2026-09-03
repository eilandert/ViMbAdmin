<?php

/**
 * Canonical mailbox, alias and domain identity handling.
 *
 * ViMbAdmin's web forms have historically treated identities as
 * case-insensitive and ignored surrounding whitespace. Keep that behaviour in
 * one place so API and form callers cannot drift apart.
 */
final class ViMbAdmin_Identity
{
    public static function canonical(string $value): string
    {
        return strtolower(trim($value));
    }
}
