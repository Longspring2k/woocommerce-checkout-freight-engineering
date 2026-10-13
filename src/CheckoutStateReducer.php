<?php
declare(strict_types=1);

namespace Showcase;

final class ValidationException extends \InvalidArgumentException
{
}

final class CheckoutStateReducer
{
    private const OWNED_FIELDS = ['delivery_method', 'delivery_note'];
    private const METHODS = ['ship', 'collect'];

    /**
     * Applies a validated partial request to session-like state.
     * Omitted fields are preserved; null explicitly clears delivery_note.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function reduce(array $state, array $patch): array
    {
        self::validatePatch($patch);
        $next = $state;

        if (array_key_exists('delivery_method', $patch)) {
            $next['delivery_method'] = $patch['delivery_method'];
        }

        if (array_key_exists('delivery_note', $patch)) {
            if ($patch['delivery_note'] === null || trim($patch['delivery_note']) === '') {
                unset($next['delivery_note']);
            } else {
                $next['delivery_note'] = trim($patch['delivery_note']);
            }
        }

        if (($next['delivery_method'] ?? null) !== 'ship') {
            unset($next['delivery_note']);
        }

        return $next;
    }

    /** @param array<string, mixed> $patch */
    private static function validatePatch(array $patch): void
    {
        foreach ($patch as $field => $value) {
            if (!is_string($field) || !in_array($field, self::OWNED_FIELDS, true)) {
                throw new ValidationException('Unknown checkout field.');
            }

            if ($field === 'delivery_method') {
                if (!is_string($value) || !in_array($value, self::METHODS, true)) {
                    throw new ValidationException('delivery_method must be ship or collect.');
                }
                continue;
            }

            if ($value !== null && (!is_string($value) || strlen(trim($value)) > 120)) {
                throw new ValidationException('delivery_note must be null or a string of at most 120 bytes.');
            }
        }
    }
}
