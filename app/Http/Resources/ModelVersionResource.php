<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $id
 * @property string $name
 * @property string $version
 * @property string $model_name
 * @property string|null $framework
 * @property string|null $accuracy
 * @property string|null $macro_f1
 * @property array<int, string>|null $supported_languages
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ModelVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'model_name' => $this->model_name,
            'framework' => $this->framework,
            'accuracy' => $this->accuracy !== null
                ? (float) $this->accuracy
                : null,
            'macro_f1' => $this->macro_f1 !== null
                ? (float) $this->macro_f1
                : null,
            'supported_languages' => $this->supported_languages,
            'is_active' => $this->is_active,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}