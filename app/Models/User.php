<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use HasApiTokens;
    use HasRoles;

    protected $guard_name = 'web';
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'first_name',
        'last_name',
        'email',
        'password',
        'profile_photo',
        'status',
        'doc_tipo',
        'doc_numero',
        'celular',
        'pais',
        'religion',
        'fecha_nacimiento',
        // por seguridad NO metemos failed_login_attempts ni login_blocked_until en fillable
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'password'           => 'hashed',
            'fecha_nacimiento'   => 'date',
            'login_blocked_until'=> 'datetime',
        ];
    }

    public function expedientesAcademicos()
    {
        return $this->hasMany(ExpedienteAcademico::class, 'user_id');
    }

    /**
     * Mutator: hashea automáticamente la contraseña si viene en claro.
     */
    public function setPasswordAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['password'] = $value;
            return;
        }

        // Evita re-hashear si ya parece un bcrypt ($2y$)
        if (Str::startsWith($value, '$2y$')) {
            $this->attributes['password'] = $value;
        } else {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    /**
     * Accesor útil: nombre completo.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /* ===============================
     *  SEGURIDAD: INTENTOS DE LOGIN
     * =============================== */

    /**
     * ¿Está bloqueado actualmente por demasiados intentos?
     */
    public function isLoginBlocked(): bool
    {
        if (!$this->login_blocked_until) {
            return false;
        }

        return now()->lessThan($this->login_blocked_until);
    }

    /**
     * Segundos restantes de bloqueo.
     */
    public function secondsUntilLoginUnblocked(): int
    {
        if (!$this->login_blocked_until) {
            return 0;
        }

        return max(0, $this->login_blocked_until->diffInSeconds(now()));
    }

    /**
     * Registrar un intento fallido y recalcular bloqueo.
     */
    public function registerFailedLoginAttempt(): void
    {
        $this->failed_login_attempts = ($this->failed_login_attempts ?? 0) + 1;

        $seconds = $this->lockoutSecondsForAttempts($this->failed_login_attempts);

        if ($seconds > 0) {
            $this->login_blocked_until = now()->addSeconds($seconds);
        }

        $this->save();
    }

    /**
     * Resetear contador al loguear correctamente.
     */
    public function resetLoginAttempts(): void
    {
        $this->failed_login_attempts = 0;
        $this->login_blocked_until   = null;
        $this->save();
    }

    /**
     * Lógica de escalamiento:
     * 3 intentos → 1 min
     * 6 intentos → 5 min
     * 9 intentos o más → 10 min
     */
    protected function lockoutSecondsForAttempts(int $attempts): int
    {
        if ($attempts >= 9) {
            return 10 * 60; // 10 min
        }

        if ($attempts >= 6) {
            return 5 * 60; // 5 min
        }

        if ($attempts >= 3) {
            return 60; // 1 min
        }

        return 0;
    }
}
