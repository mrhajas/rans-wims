<?php

namespace App\Http\Controllers\SupplierRecords\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Every Supplier Records controller shares one route-name prefix
 * ("supplier-records.") so App\Traits\Authorizable's default
 * route-name-derived ability string (`{action}-supplier-records`) can't
 * tell a Purchase Order apart from a Payment. This overrides that
 * derivation to use each controller's own declared module slug instead —
 * see App\Support\PermissionRegistry for the matching permission entries
 * (purchase_orders, goods_receipts, payments, etc.).
 */
trait HasOwnModulePermission
{
    /**
     * The PermissionRegistry module slug this controller's CRUD actions
     * (index/show/create/store/edit/update/destroy) should check against —
     * e.g. 'purchase-orders' for `read-purchase-orders`,
     * `create-purchase-orders`, etc.
     */
    abstract protected function modulePermissionSlug(): string;

    public function getAbility($method)
    {
        $action = Arr::get($this->getAbilitiesMap(), Str::kebab($method));

        return $action ? Str::kebab($action . '-' . $this->modulePermissionSlug()) : null;
    }

    /**
     * Mirrors Authorizable's private $abilities default — duplicated here
     * (read-only, method-name access) since a trait can't read a private
     * property declared on the class that pulls in a different trait.
     */
    private function getAbilitiesMap(): array
    {
        return [
            'index' => 'read',
            'show' => 'read',
            'edit' => 'update',
            'update' => 'update',
            'create' => 'create',
            'store' => 'create',
            'destroy' => 'delete',
            'delete' => 'delete',
            'delete-attachment' => 'delete',
        ];
    }
}
