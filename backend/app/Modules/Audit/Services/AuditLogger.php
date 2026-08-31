<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Append-only audit trail service (CLAUDE.md rule 6). Records who did what, to
 * which subject, in which tenant, when. NEVER stores passwords, tokens, or
 * other secrets — metadata is redacted before it is written.
 */
class AuditLogger
{
    /** Metadata keys whose values are always redacted. */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password',
        'token', 'access_token', 'refresh_token', 'remember_token',
        'secret', 'api_key', 'apikey', 'authorization', 'cookie',
        'card', 'card_number', 'cvv', 'bank_account', 'national_id',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{tenant_id?:string|null, actor?:mixed, actor_type?:string,
     *     subject?:Model|null, subject_type?:string, subject_id?:string,
     *     metadata?:array}  $options
     */
    public function log(string $action, array $options = []): AuditLog
    {
        $actor = $options['actor'] ?? null;

        [$actorType, $actorId, $actorLabel] = $this->describeActor($actor, $options['actor_type'] ?? null);

        $subject = $options['subject'] ?? null;
        $subjectType = $options['subject_type'] ?? ($subject ? $subject::class : null);
        $subjectId = $options['subject_id'] ?? ($subject instanceof Model ? (string) $subject->getKey() : null);

        return AuditLog::create([
            'tenant_id' => array_key_exists('tenant_id', $options)
                ? $options['tenant_id']
                : $this->context->tenantId(),
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'actor_label' => $actorLabel,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000) ?: null,
            'metadata' => $this->redact($options['metadata'] ?? []),
        ]);
    }

    /** @return array{0:string,1:?string,2:?string} [type, id, label] */
    private function describeActor(mixed $actor, ?string $forcedType): array
    {
        if ($actor instanceof Model) {
            $type = $forcedType ?? match (true) {
                str_contains($actor::class, 'PlatformAdmin') => 'platform_admin',
                default => 'user',
            };

            return [$type, (string) $actor->getKey(), $actor->getAttribute('email')];
        }

        return [$forcedType ?? 'system', null, null];
    }

    private function redact(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = $this->redact($value);
            }
        }

        return $metadata;
    }
}
