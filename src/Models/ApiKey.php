<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Multiple API keys with scoped permissions.
 *
 * Permissions follow resource.action format:
 *   campaigns.read, campaigns.write, campaigns.send
 *   contacts.read, contacts.write
 *   analytics.read
 *   workflows.read, workflows.write
 *   privacy.*
 *   * (super — all permissions)
 */
class ApiKey extends Model
{
    protected $table = 'ck_api_keys';

    protected $fillable = [
        'name',
        'key',
        'permissions',  // JSON array of permission strings
        'expires_at',
        'last_used_at',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'expires_at'  => 'datetime',
        'last_used_at'=> 'datetime',
        'is_active'   => 'boolean',
    ];

    protected $hidden = ['key'];

    // ── Factory ───────────────────────────────────────────────────────────

    public static function generate(
        string $name,
        array  $permissions = ['*'],
        int    $expiresInDays = 365,
        ?int   $createdBy = null,
    ): static {
        return static::create([
            'name'        => $name,
            'key'         => 'rh_live_' . bin2hex(random_bytes(32)),
            'permissions' => $permissions,
            'expires_at'  => now()->addDays($expiresInDays),
            'created_by'  => $createdBy,
            'is_active'   => true,
        ]);
    }

    // ── Auth helpers ──────────────────────────────────────────────────────

    public static function findByToken(string $token): ?static
    {
        return static::where('key', $token)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?? [];

        if (in_array('*', $perms)) {
            return true;
        }

        if (in_array($permission, $perms)) {
            return true;
        }

        // Wildcard namespace: 'campaigns.*' grants any campaigns.X
        [$resource] = explode('.', $permission);
        return in_array("{$resource}.*", $perms);
    }

    public function touch(): bool
    {
        return $this->forceFill(['last_used_at' => now()])->save();
    }

    // ── Revoke ────────────────────────────────────────────────────────────

    public function revoke(): void
    {
        $this->update(['is_active' => false]);
    }
}
