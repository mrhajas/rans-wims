<?php

namespace App\Services\Signatories;

use App\Models\SigningMapping;
use App\Models\SigningRole;
use Illuminate\Support\Facades\DB;

/** Puts each document adapter's starting setup in place for an account. Never overwrites what an administrator has already set for a document. */
class MappingSeeder
{
    /** @return int how many setup rows were added */
    public function seed(int $account, ?string $onlyType = null, bool $replace = false): int
    {
        $added = 0;
        $roles = SigningRole::query()->where('account_id', $account)->pluck('id', 'key');
        foreach (SignatureResolver::adapters() as $type => $class) {
            if ($onlyType && $onlyType !== $type) {
                continue;
            }
            $exists = SigningMapping::query()->where('account_id', $account)->where('document_type', $type)->exists();
            if ($exists && ! $replace) {
                continue;
            }
            if ($replace) {
                SigningMapping::query()->where('account_id', $account)->where('document_type', $type)->delete();
            }
            foreach ($class::defaultMappings() as $m) {
                $roleId = $roles[$m['role']] ?? null;
                if (! $roleId || ! isset($class::actions()[$m['action']])) {
                    continue;
                }
                SigningMapping::query()->updateOrCreate(
                    ['account_id' => $account, 'document_type' => $type, 'signing_role_id' => $roleId, 'workflow_action' => $m['action']],
                    ['slot' => $m['slot'] ?? 1, 'is_required' => (bool) ($m['required'] ?? false), 'show_when' => $m['show_when'] ?? 'always', 'blocks_final' => false, 'missing_behaviour' => 'blank', 'is_active' => true],
                );
                $added++;
            }
        }

        return $added;
    }

    public function seedAll(?string $onlyType = null): int
    {
        $n = 0;
        foreach (DB::table('accounts')->pluck('id') as $account) {
            $n += $this->seed((int) $account, $onlyType);
        }

        return $n;
    }
}
