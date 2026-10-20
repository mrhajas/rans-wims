# Super Adjustment: Import by Category (2026-10-21)

Next to **Import from Warehouse** (unchanged: replaces the list, positive and negative stock only, skips zero) the form has **Import by Category**.
- A popup lists the categories that have at least one item with a stock record in the chosen Project / Warehouse, with the item count (a parent counts its sub-categories too, labels "Parent > Child"). Routes `super-adjustments.warehouse-categories` and `super-adjustments.category-stock`.
- Importing ADDS the items of the chosen category and its sub-categories that have a stock record in the warehouse, whatever the quantity: positive, negative and zero. Items already in the list are skipped; a negative system quantity starts the count at 0 (as the other import). The message says how many were added, how many had zero or negative stock and how many were already there.
- Both imports show a loading overlay (moving bar, shimmering rows, spinning cog) while they run; the buttons are disabled meanwhile.
- Nothing about saving or approving a count changed (`persist`, approvals, sessions). Same permission (`create-super-adjustments`) and warehouse access as the other import. Test: `ver/sa_import_category.php` (20 checks).
