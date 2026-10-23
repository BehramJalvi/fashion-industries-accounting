<?php

namespace App\Observers;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/** Writes created / updated / posted / cancelled / deleted / restored rows to audit_logs. */
class AuditObserver
{
    private const IGNORED = ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at', 'last_login_at'];

    public function created(Model $model): void
    {
        AuditLogger::log('created', $model, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        if (! $changes) {
            return;
        }

        $event = 'updated';
        if (isset($changes['status']) && in_array($changes['status'], ['posted', 'cancelled'], true)) {
            $event = $changes['status'];
        }

        AuditLogger::log($event, $model, array_intersect_key($model->getOriginal(), $changes), $changes);
    }

    public function deleted(Model $model): void
    {
        AuditLogger::log('deleted', $model, $this->clean($model->getOriginal()));
    }

    public function restored(Model $model): void
    {
        AuditLogger::log('restored', $model);
    }

    private function clean(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::IGNORED));
    }
}
