<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;
use App\Modules\Payroll\Models\PayrollComponent;
use Illuminate\Support\Facades\DB;

/**
 * Tenant compensation component catalog. `type` and `calculation_mode` are
 * immutable once created (changing them would silently reinterpret existing
 * employee assignments and historical payroll). Deactivation blocks NEW
 * assignments but never ends existing effective ones; components are never
 * hard-deleted.
 */
class PayrollComponentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code:string, name:string, type:string, calculation_mode:string, sort_order?:int}  $data
     */
    public function create(User $actor, array $data): PayrollComponent
    {
        return DB::transaction(function () use ($actor, $data) {
            $component = PayrollComponent::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => PayrollComponentType::from($data['type']),
                'calculation_mode' => PayrollComponentMode::from($data['calculation_mode']),
                'active' => true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->audit->log('payroll.component_created', [
                'actor' => $actor, 'subject' => $component,
                'metadata' => ['code' => $component->code, 'type' => $component->type->value, 'mode' => $component->calculation_mode->value],
            ]);

            return $component->fresh();
        });
    }

    /**
     * Update mutable attributes only (name, sort_order, active). type and
     * calculation_mode are locked for the component's life.
     *
     * @param  array{name?:string, sort_order?:int, active?:bool}  $data
     */
    public function update(User $actor, PayrollComponent $component, array $data): PayrollComponent
    {
        return DB::transaction(function () use ($actor, $component, $data) {
            $component = PayrollComponent::query()->lockForUpdate()->findOrFail($component->getKey());
            foreach (['name', 'sort_order', 'active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $component->{$field} = $data[$field];
                }
            }
            $component->save();

            $this->audit->log('payroll.component_updated', [
                'actor' => $actor, 'subject' => $component, 'metadata' => ['fields' => array_keys($data)],
            ]);

            return $component->fresh();
        });
    }
}
