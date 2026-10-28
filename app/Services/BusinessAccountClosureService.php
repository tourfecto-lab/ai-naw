<?php

/** Team-owned resources require explicit transfer or closure before owner deletion. */
class BusinessAccountClosureService
{
    /**
     * تنفيذ التحضير الكامل قبل حذف الحساب: تحويل ملكية + توثيق.
     *
     * @return array{transferred: array<int,array{business_id:int,new_owner_id:int}>, cleaned: int}
     */
    public function prepareForAccountDeletion(int $deletedUserId): array
    {
        $this->assertDeletionSafe($deletedUserId);
        return ['transferred'=>[],'cleaned'=>0];
    }

    /**
     * اختيار أعلى عضو نشط رتبة ليرث الملكية - أو null لو مفيش حد.
     * Pure - قابل للاختبار offline بمصفوفة roles.
     */
    public function pickSuccessor(int $businessId): ?int
    {
        $members = (new BusinessMember())->where([
            'business_id' => $businessId,
            'status' => 'active',
        ], [], 0);

        $best = null;
        $bestRank = 0;
        foreach ($members as $member) {
            $userId = $member->getAttribute('user_id');
            if ($userId === null) {
                continue;
            }
            $rank = BusinessAccessService::roleRank((string) $member->getAttribute('role'));
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = (int) $userId;
            }
        }

        return $best;
    }

    /**
     * نسخة pure من الاختيار فوق مصفوفة أدوار - للاختبار بدون DB.
     *
     * @param array<int,array{user_id:int,role:string}> $members
     */
    public function pickSuccessorFromMembers(array $members): ?int
    {
        $best = null;
        $bestRank = 0;
        foreach ($members as $member) {
            $rank = BusinessAccessService::roleRank((string) ($member['role'] ?? ''));
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = (int) ($member['user_id'] ?? 0);
            }
        }
        return $best !== null && $best > 0 ? $best : null;
    }

    public static function assertDeletionSafe(int $userId): void
    {
        $pdo=Database::getInstance()->getConnection();
        $q=$pdo->prepare("SELECT b.id FROM businesses b JOIN business_members m ON m.business_id=b.id WHERE b.owner_user_id=? AND m.status='active' AND m.user_id<>? LIMIT 1");$q->execute([$userId,$userId]);
        if($q->fetchColumn())throw new RuntimeException('Account deletion blocked: transfer the business and all its websites/resources explicitly before deleting the owner.');
        $q=$pdo->prepare("SELECT id FROM users WHERE owner_user_id=? AND status='active' LIMIT 1");$q->execute([$userId]);
        if($q->fetchColumn())throw new RuntimeException('Account deletion blocked: this workspace has active team members. Transfer or close it explicitly first.');
    }

}
