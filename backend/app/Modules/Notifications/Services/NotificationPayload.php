<?php

namespace App\Modules\Notifications\Services;

/**
 * Sprint 8B Phase 3 hardening — an immutable, WHITELISTED notification payload.
 *
 * Domain producers must NOT hand NotificationService arbitrary arrays,
 * $model->toArray(), or request data. They construct a payload only through
 * NotificationPayloadFactory's named constructors, each of which hardcodes a
 * stable translation key and maps ONLY explicit, locale-neutral, non-sensitive
 * params. This value object is the last line of defence: its constructor rejects
 * non-scalar params and any param key that names a forbidden concept, so a
 * careless future producer cannot leak salary, national ids, bank details,
 * medical data, private reasons, snapshots, or audit internals into a stored
 * notification.
 *
 * Nothing here is a translation string: `key` is a translation key the frontend
 * resolves; `params` are locale-neutral values (names, dates, type labels,
 * result flags) interpolated client-side.
 */
final class NotificationPayload
{
    /**
     * Param keys that must NEVER appear in a notification payload. Matched
     * case-insensitively as whole tokens or substrings so, e.g., "net_minor",
     * "employee_iban", or "rejection_reason" are all rejected. This is defence in
     * depth on top of whitelist-by-construction, not the primary control.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PARAM_TOKENS = [
        'salary', 'gross', 'net', 'deduction', 'allowance_amount', 'amount', 'minor',
        'iban', 'bank', 'account_number', 'national_id', 'nid', 'ssn', 'passport',
        'phone', 'mobile', 'address', 'email', 'medical', 'diagnosis',
        'reason', 'note', 'snapshot', 'fingerprint', 'geo', 'lat', 'lng', 'latitude', 'longitude',
        'password', 'secret', 'token', 'api_key', 'audit',
    ];

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $dedupeKey = null,
    ) {
        $this->assertSafe();
    }

    /** Shape consumed by NotificationService::notify(): the {key, params} data body. */
    public function data(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }

    /** Shape consumed by NotificationService::notify(): the opts (subject + dedupe). */
    public function options(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dedupe_key' => $this->dedupeKey,
        ];
    }

    private function assertSafe(): void
    {
        if ($this->type === '') {
            throw new \InvalidArgumentException('Notification payload requires a non-empty type.');
        }
        if ($this->key === '') {
            throw new \InvalidArgumentException('Notification payload requires a non-empty translation key.');
        }

        foreach ($this->params as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Notification payload param names must be non-empty strings.');
            }
            if (! (is_scalar($value) || $value === null)) {
                throw new \InvalidArgumentException("Notification payload param [{$name}] must be a scalar or null.");
            }

            $haystack = strtolower($name);
            foreach (self::FORBIDDEN_PARAM_TOKENS as $token) {
                if (str_contains($haystack, $token)) {
                    throw new \InvalidArgumentException(
                        "Notification payload param [{$name}] names a forbidden/sensitive concept and cannot be sent."
                    );
                }
            }
        }
    }
}
