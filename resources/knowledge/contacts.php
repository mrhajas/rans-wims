<?php

return [
    'group' => 'people',
    'title' => 'Contacts',
    'icon' => 'handshake',
    'order' => 20,
    'summary' => 'The address book for everyone you work with outside the login system: suppliers, clients, drivers, foremen, sub-contractors and more.',
    'where' => 'Sidebar > **Contacts** > **List All Contacts** / **Create New Contact**.',
    'path' => '/contacts',
    'permissions' => [
        'read-contacts' => 'Show **Contacts** in the menu. (The list itself, and the contact pickers inside other forms, work for every signed-in user.)',
        'create-contacts' => 'Add contacts, including from the quick **Add New Contact** box inside other forms.',
        'update-contacts' => 'Open and change a contact, and restore a deleted one.',
        'delete-contacts' => 'Delete a contact, and delete it permanently.',
        'print-contacts' => 'Use **Print** and **Download** on the list.',
        'import-contacts / export-contacts' => 'Show the **More** menu with **Import Contacts** and **Export Contacts**. These two names are not on the Roles permission screen, so in practice only Super Admins see the menu.',
    ],
    'overview' => [
        'A **contact** is a person or company that does not need to sign in to WIMS. Suppliers who deliver materials, clients you build for, drivers and movers, foremen, sub-contractors, consultants and security guards all live here. A contact has a name, a category, an email, a phone number and a free note.',
        'Contacts are used all over WIMS. You choose a supplier when you receive stock (see [[checkins]]), a recipient when you issue stock (see [[checkouts]]), a payee on a payment voucher (see [[payment-vouchers]]), a driver on a transport trip (see [[transport-management]]), workers on a labour list (see [[labour]]) and the party on records such as assets and certificates. Keep the list tidy and those pickers stay tidy too.',
        'A contact in the **Suppliers** category is more than a name. It is the supplier in the whole **Supplier Records** area (quotations, purchase orders, invoices, payments and the supplier ledger, see [[supplier-records]]). Its bank and tax details are kept there.',
    ],
    'concepts' => [
        ['Category', 'A label such as Suppliers, Drivers or Clients. WIMS comes with a standard list and you can type a new one. WIMS tidies the spelling and re-uses an existing category if only the capital letters differ.'],
        ['Suppliers category', 'Marks the contact as a supplier. Clicking such a contact on the list opens its **Supplier Records** profile instead of the plain edit form.'],
        ['Loading & UnLoading', 'A special category. Contacts in it are the workers on the Loading & Unloading attendance screens of Transport.'],
    ],
    'functions' => [
        [
            'id' => 'browse-contacts',
            'title' => 'Browse, search and filter contacts',
            'icon' => 'list',
            'summary' => 'Find a supplier or driver quickly and see how many contacts you have in each category.',
            'steps' => [
                'Open **Contacts > List All Contacts**.',
                'The tiles at the top show **All Contacts** and your five biggest categories. Click a tile to filter by that category; click **All Contacts** to clear it.',
                'Type in **Search** to find by name, email or phone number.',
                'Click **Filter** to pick a **Category** and to show **Not Trashed**, **With Trashed** or **Only Trashed** contacts. Click **Reset** to clear.',
                'Hover a name to see a small preview card. Click a row to open it.',
            ],
            'fields' => [
                ['No', 'Row number across all pages.'],
                ['Name', 'Initials badge and name. A small bin icon marks a deleted contact.'],
                ['Category', 'Shown as a chip.'],
                ['Email / Phone / Details', 'As saved. Details is the free note.'],
            ],
            'shots' => [
                ['contacts-list', 'The contacts list with category tiles, search and Print / Download.', 'Contacts list'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Clicking a **Suppliers** contact opens its profile in Supplier Records. Every other contact opens the normal edit form (needs `update-contacts`).',
                'Search looks at name, email and phone together.',
            ]],
        ],
        [
            'id' => 'create-contact',
            'title' => 'Create a contact',
            'icon' => 'plus',
            'summary' => 'Add a supplier, client, driver or any other outside party.',
            'steps' => [
                'Open **Contacts > Create New Contact**.',
                'Type the **Name**.',
                'Choose or type a **Category**. Pick from the list or type your own and it is remembered.',
                'Enter an **Email** and/or a **Phone**. At least one of them is needed.',
                'In **Details** write anything useful: company, address, what they supply.',
                'Click **Save**.',
            ],
            'fields' => [
                ['Name', 'Required, up to 50 characters and must be different from every other contact (even trashed ones).'],
                ['Category', 'Optional, up to 50 characters. Standard choices: Engineers, Technicians, Suppliers, Foreman, SubContractors, Workers, General, Drivers, Dispatchers, Movers, Logistics, Consultants, Architects, Surveyors, Security, Equipment Rental, Clients, Loading & UnLoading.'],
                ['Email', 'Optional, but must look like an email address if filled.'],
                ['Phone', 'Required when there is no email.'],
                ['Details', 'Optional free text.'],
            ],
            'shots' => [
                ['contacts-create-form', 'The contact form. When the category is Suppliers a note explains that financial details come after the first save.', 'Create contact form'],
            ],
            'tips' => [
                'Use the same spelling for a supplier every time, for example "Zanzibar Steel Works Ltd". Names must be unique, so a second spelling means a second supplier.',
                'The **Add Personnel** buttons in Labour open this form (or a small box) with the category already filled in.',
            ],
            'warnings' => ['A contact called "ABC Hardware" cannot be created again even if the first one is deleted. Restore the old one instead.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The category is tidied before saving: extra spaces are removed and, if it matches an existing category apart from capitals, the existing spelling is used. So "drivers" becomes "Drivers".',
                'New categories are not stored in a separate list. They appear in the category choices as soon as at least one contact uses them.',
                'The contact is saved to your company account and the action is written to the activity log.',
            ]],
        ],
        [
            'id' => 'edit-contact',
            'title' => 'Edit a contact',
            'icon' => 'pen',
            'summary' => 'Correct a phone number, change the category or add notes.',
            'steps' => [
                'Click the contact on the list.',
                'Change the fields and click **Save**. A "Saved." note appears.',
                'For a supplier, use **Manage** in the **Supplier Financial Details** card to set tax ID, payment terms, currency, credit limit and bank details (see [[supplier-records]]).',
                'Use **View** in the **Payment Vouchers** card to open every voucher made out to this contact.',
            ],
            'shots' => [
                ['contacts-edit-form', 'Editing a supplier: extra cards link to the supplier financial details and to payment vouchers.', 'Edit contact form'],
            ],
            'warnings' => ['Renaming a contact renames it everywhere it is used, because records point to the contact, not to a typed name.'],
        ],
        [
            'id' => 'delete-contact',
            'title' => 'Delete, restore and permanently delete a contact',
            'icon' => 'trash',
            'summary' => 'Remove contacts you no longer need, without breaking old records.',
            'steps' => [
                'Open the contact and click **Delete Contact**, then confirm.',
                'To see deleted contacts choose **Filter > Trashed > Only Trashed**.',
                'Open a trashed contact. The bar at the top offers to restore it. Use **Delete Permanently** to erase it for good.',
            ],
            'warnings' => [
                'A contact used on any checkin or checkout cannot be deleted. WIMS shows "The record can not be deleted."',
                'A supplier with quotations, purchase orders, supplier invoices, payment requests, payments, ledger entries or reconciliations cannot be deleted either.',
                'Permanent deletion cannot be undone.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'A normal delete only hides the contact; it disappears from pickers but old records still show its name. Permanent deletion writes an activity log entry first.',
            ]],
        ],
        [
            'id' => 'print-download-contacts',
            'title' => 'Print or download the list',
            'icon' => 'printer',
            'summary' => 'Get a paper or PDF copy of exactly the contacts you see.',
            'steps' => [
                'Set the search, category and trashed filter you want.',
                'Click **Print** (opens a print page) or **Download** (a PDF named `contacts-date-time.pdf`).',
                'The report starts with a summary: number of contacts, with phone, with email and number of categories, then a breakdown by category and the table.',
            ],
            'shots' => [
                ['contacts-print', 'The printable list. The filters you chose are shown at the top so the paper explains itself.', 'Contacts print preview'],
            ],
            'warnings' => ['Needs `print-contacts`. Both versions include at most 5000 contacts.'],
        ],
        [
            'id' => 'import-contacts',
            'title' => 'Import contacts from Excel',
            'icon' => 'upload',
            'summary' => 'Add or update many contacts at once from a spreadsheet.',
            'steps' => [
                'On the contacts list click **More > Import Contacts**.',
                'Click **Download sample file** to see the column headings. Keep the headings in the first row: `name`, `phone`, `email`, `details`.',
                'Fill your sheet and save it as an Excel file (`.xls` or `.xlsx`). The downloaded sample is a CSV, so re-save as Excel.',
                'Click **Select file** (or drag the file in) and click **Import**.',
            ],
            'shots' => [
                ['contacts-import', 'The import page lists the required columns and the two rules.', 'Contacts import page'],
            ],
            'fields' => [
                ['name', 'Required.'],
                ['phone / email', 'At least one of them is required for every row.'],
                ['details', 'Optional note.'],
            ],
            'warnings' => [
                'A contact with the same **name** is updated, not duplicated: its email, phone and details are overwritten.',
                'The import does not read a category column. Imported contacts have no category (existing contacts keep theirs).',
                'If one row is missing its name or both phone and email, WIMS stops with the message "name along email or phone are required." Rows above it were already saved, so fix the file and import again.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['On success you are returned to the list with "Contacts imported" and the count.']],
        ],
        [
            'id' => 'export-contacts',
            'title' => 'Export contacts to Excel',
            'icon' => 'download',
            'summary' => 'Download every contact as a spreadsheet.',
            'steps' => ['Click **More > Export Contacts**. A file `contacts.xlsx` downloads with the columns name, email, phone and details.'],
            'warnings' => ['The export always contains all contacts, ignoring the filters on screen, and does not include the category. Use **Download** (PDF) when you need a filtered copy.'],
        ],
        [
            'id' => 'quick-add-contact',
            'title' => 'Quick-add a contact from another form',
            'icon' => 'users',
            'summary' => 'Create a missing payee without leaving the payment voucher you are filling. (Labour has its own **Add Personnel** box that saves a contact the same way.)',
            'steps' => [
                'On a payment voucher (single or batch), click the small **+** button (tooltip **Add New Contact**) next to the payee picker. The **Add New Contact** box opens.',
                'Type the **Name**, choose an optional **Category (optional)**, and enter **Phone (optional)** and **Email (optional)**.',
                'Click **Save Contact**. The new contact is selected for you in the form.',
            ],
            'shots' => [
                ['contacts-quick-add', 'The quick-add box. It only asks for what you need right now.', 'Add New Contact dialog'],
            ],
            'warnings' => [
                'You need `create-contacts` or the **+** button is hidden. A duplicate name shows an error inside the box.',
                'The category list in this box is the standard list only; to use a custom category open the full form.',
                'Here phone or email is **not** forced, but the full form requires one of them. Complete the contact later from the contacts list.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['The box saves the contact through the same checks as the full form (unique name, valid email) and hands the new contact back to the page, so nothing on the voucher is lost.']],
        ],
    ],
    'behind' => [
        ['title' => 'Contacts are shared by many modules', 'text' => 'Stock receipts and issues, payment vouchers, transport trips, labour lists, assets, compliance records, material requests, gate passes and the archive all point to a contact. This is why a used contact cannot be deleted and why renaming it changes every record.', 'icon' => 'link'],
        ['title' => 'Supplier protection', 'text' => 'A supplier is protected by all seven Supplier Records tables (quotations, purchase orders, invoices, payment requests, payments, ledger and reconciliations). Deleting is refused with a friendly message instead of breaking those pages.', 'icon' => 'shield-check'],
        ['title' => 'Change history', 'text' => 'Creating, changing and permanently deleting a contact is recorded in the activity log, see [[activity-logs]].', 'icon' => 'activity'],
    ],
    'faq' => [
        ['q' => 'The contact I need does not appear in a picker.', 'a' => 'Check it is not deleted (**Filter > Trashed**), and that the picker does not filter by category (for example drivers only).'],
        ['q' => 'Can two contacts share a name?', 'a' => 'No. Names must be unique. Add something to tell them apart, for example the town.'],
        ['q' => 'Where do I enter a supplier\'s bank details?', 'a' => 'Open the supplier, click **Manage** in **Supplier Financial Details** (or open it from Supplier Records). See [[supplier-records]].'],
        ['q' => 'What is the difference between a contact and a user?', 'a' => 'A user can sign in to WIMS. A contact cannot. Staff of your own company who need payroll are in [[staffs]].'],
    ],
    'related' => ['staffs', 'users', 'supplier-records', 'payment-vouchers', 'labour', 'checkins'],
];
