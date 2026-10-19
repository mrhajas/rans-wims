# Material Request: Create Draft Transfers (2026-10-17, not deployed)

Rules, as the owner specified:

* **Only Main Warehouse items.** An item can be moved by transfer only when its procurement procedure is *Main Warehouse* and a warehouse was chosen. Items with any other procedure (Supplier Procure, Supplier Credit, Transfer from Site, Other) are disregarded for now.
* **Only when issued.** The request must be *Partially Issued* or *Issued* (fully). Approved but not yet issued shows nothing and the server refuses.
* **The user says how many.** The Transfers panel on the request page has a quantity box for each item (up to what is still left), even when the request is only partially issued. Empty or zero skips an item. Nothing is prefilled.
* **One draft transfer per source warehouse.** Three items, two from warehouse A and one from warehouse B, make two transfers: one from A, one from B. Each goes to the warehouse that made the request and is saved as a **Draft** (status shown as Draft on the request page, the transfer form and the list).
* **Stock is not checked on a draft.** When a draft is turned into a normal transfer (the "This record is draft" box unticked and saved), the usual stock check runs and says which item is short in that warehouse ("... do not have N in stock, available quantity M"). From a main warehouse the saved transfer then also waits for approval, as before.
* The request can have as many transfers as needed; what a new one may take is asked minus what live transfers already carry. A cancelled or deleted transfer gives its quantity back; editing keeps the same limit.
* Items with variations or serial numbers are listed but disabled (add them to a transfer by hand).
* `transfers.material_request_id` (migration `2026_11_10_100000`); logic in `App\Services\MaterialRequestTransfers`; action `material-requests.create-transfers` (needs `create-transfers`); logged as "Material Request transfers created" and "Transfer Created". Test: `ver/mr_transfer_test.php` (19 checks).

## Added after the owner's second note (2026-10-17)

* **Fully Issued** fills in the quantity still to move for each item; **Partially Issued** leaves the boxes empty and asks how many (what is not moved stays as the balance).
* **An item that already has a transfer is not given another one.** The request page shows each item with the reference(s) of its transfer (chip under the item name, and an "Already on a transfer" list with the balance). To move the balance, change the quantity on that draft transfer (up to what the request asked) or cancel it.
* **Procedure validation:** choosing Main Warehouse for an item checks the stock held there now. No stock at all is refused; less than requested is saved with a note of how much the warehouse holds.
* Printed / downloaded Material Request, Staff record, Archive record, Project Certificate and External Stock record now carry the record's own QR code (scan-to-open token); External Stock is registered in the QR module (`external_stock`). Test `ver/qr_print_test.php`.
