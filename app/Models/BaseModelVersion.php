<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behaviour for the four *_model_versions tables.
 */
abstract class BaseModelVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'accuracy' => 'decimal:6',
            'macro_f1' => 'decimal:6',
            'supported_languages' => 'array',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Activate this model version.
     *
     * Deactivates another active version with the same model name,
     * then activates this version.
     *
     * The caller is responsible for wrapping this operation in
     * DB::transaction().
     */
    public function activate(): void
    {
        $keyName = $this->getKeyName();
        $keyValue = $this->getKey();

        static::query()
            ->where('name', $this->name)
            ->where('is_active', true)
            ->where(
                $keyName,
                '!=',
                $keyValue
            )
            ->update([
                'is_active' => false,
            ]);

        $this->forceFill([
            'is_active' => true,
            'activated_at' => now(),
        ])->save();
    }
}
