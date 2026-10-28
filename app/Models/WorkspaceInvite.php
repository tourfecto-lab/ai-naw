<?php

/**
 * Tourfecto - Workspace Invite Model
 * @version 1.0.0
 */
class WorkspaceInvite extends Model
{
    protected $table = 'workspace_invites';

    protected $fillable = [
        'owner_user_id',
        'invited_by',
        'email',
        'role',
        'token',
        'status',
        'accepted_at',
        'expires_at',
    ];

    public static function createFor(int $ownerUserId, int $invitedBy, string $email, string $role): array
    {
        $token = bin2hex(random_bytes(24));

        $invite = new self([
            'owner_user_id' => $ownerUserId,
            'invited_by' => $invitedBy,
            'email' => $email,
            'role' => $role,
            'token' => $token,
            'status' => 'pending',
        ]);
        // expires_at مالوش default في fillable لأنه معتمد على NOW() -
        // بنحطه يدويًا هنا قبل الحفظ.
        $invite->setAttribute('expires_at', date('Y-m-d H:i:s', strtotime('+7 days')));
        $invite->save();

        return ['model' => $invite, 'token' => $token];
    }

    public function isExpired(): bool
    {
        return strtotime((string) $this->getAttribute('expires_at')) < time();
    }

    public function revoke(): bool
    {
        $this->setAttribute('status', 'revoked');
        return (bool) $this->save();
    }

    public function markAccepted(): bool
    {
        $this->setAttribute('status', 'accepted');
        $this->setAttribute('accepted_at', date('Y-m-d H:i:s'));
        return (bool) $this->save();
    }

    public function toSafeArray(): array
    {
        return [
            'id' => (int) $this->getAttribute('id'),
            'email' => $this->getAttribute('email'),
            'role' => $this->getAttribute('role'),
            'status' => $this->isExpired() && $this->getAttribute('status') === 'pending' ? 'expired' : $this->getAttribute('status'),
            'created_at' => $this->getAttribute('created_at'),
            'expires_at' => $this->getAttribute('expires_at'),
        ];
    }
}
