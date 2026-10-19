<?php

return [
    'group' => 'stock',
    'title' => 'Units',
    'icon' => 'ruler',
    'order' => 70,
    'summary' => 'The units of measure used to count items (Bags, Pcs, Metre, Kg) and the formulas that convert a smaller or larger unit into the base unit.',
    'where' => 'Sidebar > **Units** > **List All Units** (also **Create New Unit** and **Import Units**).',
    'path' => '/units',
    'permissions' => [
        'read-units' => 'See the unit list and open a unit.',
        'create-units' => 'Create a unit.',
        'update-units' => 'Edit a unit (click its row).',
        'delete-units' => 'Delete, restore and permanently delete a unit.',
    ],
    'overview' => [
        'Every item is counted in one **base unit**: cement in Bags, steel bars in Pcs, cable in Metres. Stock in every project is kept in that unit.',
        'Sometimes a document is easier to fill in with another unit, for example a delivery of 3 **Dozen** helmets or 250 **Centimetres** of trim. A **derived unit** teaches WIMS how to turn such a quantity into the base unit, so stock always stays correct.',
    ],
    'concepts' => [
        ['Base unit', 'A unit with no "Base Unit" set. Items can only be given base units.'],
        ['Derived unit', 'A unit that points to a base unit and carries an operator and a number. Example: `Dozen` is derived from `Pcs` with `Multiply` and `12`.'],
        ['Operator and Operation Value', 'The conversion rule. `Multiply 12` turns 3 Dozen into 36 Pcs. `Divide 100` turns 250 Centimetres into 2.5 Metres. Plus and Minus are also possible but rarely useful.'],
        ['Code', 'A short unique tag such as `pcs` or `dozen`. Excel imports of items and units refer to units by code.'],
    ],
    'functions' => [
        [
            'id' => 'view-units',
            'title' => 'View and search units',
            'icon' => 'list',
            'summary' => 'See all units, which are base units and which are derived, with their conversion formula.',
            'steps' => [
                'Open **Units > List All Units**.',
                'Read the tiles: **Total Units**, **Base Units** and **Derived Units**.',
                'Use **Search** (name or code) and **Filter > Trashed** to find a unit or to show deleted ones.',
                'Read the columns **Name**, **Code**, **Base Unit** and **Formula**. A derived unit shows a formula chip such as `Pcs (pcs) * 12 = Dozen (dozen)`.',
            ],
            'shots' => [['units-list', 'The unit list. Derived units show their formula in the last column.', 'Unit list']],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['The newest unit is listed first. A click on a row opens it for editing if you may update units.']],
        ],
        [
            'id' => 'create-unit',
            'title' => 'Create a unit (base or derived)',
            'icon' => 'plus',
            'summary' => 'Add a new unit of measure, optionally as a conversion of an existing one.',
            'steps' => [
                'Open **Units > Create New Unit**.',
                'Type the **Name** (for example `Dozen`) and a unique **Code** (for example `dozen`).',
                'For a plain unit stop here. For a derived unit choose the **Base Unit** it converts into (for example `Pcs`).',
                'A **Conversion** card appears. Choose the **Operator** (**Multiply**, **Divide**, **Plus**, **Minus**) and type the **Operation Value** (for example `12`).',
                'Check the blue formula line under the fields, then click **Save**.',
            ],
            'fields' => [
                ['Name', 'Required.'],
                ['Code', 'Required. Letters and numbers only, up to 20 characters, different from every other unit.'],
                ['Base Unit', 'Optional. Only base units are offered. The box is hidden until two or more base units exist. Choose it to make this unit a derived unit.'],
                ['Operator', 'Multiply, Divide, Plus or Minus. Stored as `*`, `/`, `+` or `-`.'],
                ['Operation Value', 'A number. With **Multiply** it is how many base units are in one of this unit.'],
            ],
            'shots' => [['units-create-form', 'Creating `Dozen` as 12 Pcs. The formula line confirms the rule.', 'Create unit form']],
            'tips' => [
                'Read the rule as: quantity in the new unit, with the operator and value applied, equals quantity in the base unit. Dozen, Multiply, 12: 1 Dozen = 12 Pcs.',
                'Create the base unit first, then the derived ones.',
            ],
            'warnings' => ['If you pick a Base Unit but leave the operator empty, WIMS does not convert at all and treats the quantity as if it were already in the base unit.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Derived units appear in the **Unit** box of check-in, check-out, transfer and adjustment lines, but only for items whose base unit is the one they derive from.',
                'When such a document is saved, WIMS converts the line quantity to the base unit with this rule before it changes stock. The same conversion is used for the over-selling check and for stock availability on [[material-reallocations]] and issues.',
            ]],
        ],
        [
            'id' => 'edit-unit',
            'title' => 'Edit a unit',
            'icon' => 'pen',
            'summary' => 'Change the name, code or conversion rule of a unit.',
            'steps' => [
                'Click the unit on the list.',
                'Change the fields. The unit you are editing is not offered as its own base unit.',
                'Click **Save**.',
            ],
            'shots' => [['units-edit-form', 'Editing a derived unit.', 'Edit unit form']],
            'warnings' => [
                'Changing the Operation Value only affects documents saved from now on. Quantities already converted into stock stay as they were.',
                'Changing the code affects your Excel sheets, which use unit codes.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['Changes are recorded in the activity log.']],
        ],
        [
            'id' => 'delete-unit',
            'title' => 'Delete, restore and delete permanently',
            'icon' => 'trash',
            'summary' => 'Remove a unit nobody uses, or bring a deleted one back.',
            'steps' => [
                'Open the unit and click **Delete Unit**, then confirm.',
                'To find deleted units use **Filter > Trashed > Only Trashed**. Open one and click **Restore** in the yellow banner, or **Delete Permanently** to erase it.',
            ],
            'warnings' => [
                'A unit **cannot be deleted** while any item uses it or while a derived unit points to it. You see "The record can not be deleted." Change the items to another unit first.',
                'Permanent deletion cannot be undone.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['Permanent deletion is written to the activity log.']],
        ],
        [
            'id' => 'import-export-units',
            'title' => 'Import and export units with Excel',
            'icon' => 'upload',
            'summary' => 'Load many units from one sheet or download the current list.',
            'steps' => [
                'Click **More > Export Units** to download `units.xlsx` (columns `name`, `code`, `base_unit`, `operator`, `operation_value`).',
                'Add rows. For a derived unit write the **code** of the base unit in `base_unit`, the operator (`*`, `/`, `+` or `-`) and the number.',
                'Open **Units > Import Units**, choose the `.xls` / `.xlsx` file and click **Import**.',
            ],
            'shots' => [['units-import', 'The import page with the required columns.', 'Unit import page']],
            'warnings' => [
                '`name` and `code` must be filled on every row or the import stops.',
                'The base unit must already exist (import or create it first).',
                'The **More** menu currently shows only for Super Admin because its permissions (`import-units`, `export-units`) are not in the role screen.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['A code that already exists is updated. The success message tells you how many records were imported or updated.']],
        ],
    ],
    'behind' => [
        ['title' => 'Stock is always held in the base unit', 'text' => 'Whatever unit you choose on a document, WIMS stores the stock and the stock trail in the item\'s base unit after conversion.', 'icon' => 'database'],
        ['title' => 'One level of conversion', 'text' => 'A derived unit converts straight into its base unit. Items and the unit pick-lists work with base units and the units derived from them.', 'icon' => 'sitemap'],
    ],
    'faq' => [
        ['q' => 'Why can I not pick Dozen on the item form?', 'a' => 'Items take only base units. Give the item `Pcs`, then choose `Dozen` on the lines of check-ins, check-outs and transfers.'],
        ['q' => 'What is the difference between a unit and a category?', 'a' => 'A unit says how you count an item (Bags). A category says which family it belongs to (Cement). See [[categories]].'],
    ],
    'related' => ['items', 'categories', 'checkins'],
];
