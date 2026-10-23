<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        $forget = fn (Setting $s) => Cache::forget('setting:' . $s->key);
        static::saved($forget);
        static::deleted($forget);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever('setting:' . $key, function () use ($key) {
            return static::where('key', $key)->first()?->value;
        }) ?? $default;
    }

    public static function set(string $key, mixed $value, string $group = 'general'): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }
}
