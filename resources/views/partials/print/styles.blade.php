{{--
    Shared print/PDF stylesheet for Payment Vouchers, Staff Records, and
    Supplier Vouchers — one visual language across all three documents:
    Helvetica (DomPDF's built-in corporate sans, no font embedding needed),
    no letter-spacing, rounded-rectangle cards, bolded key figures, and a
    consistent navy/rose accent pair matching the app's own palette.
--}}
<style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; } /* the browser's print keeps the colours, as the PDF does */
    html { color-scheme: light; background: #ffffff; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 24px; background: #ffffff; line-height: 1.45; }

    .watermark {
        position: fixed; top: 260px; left: 90px; width: 420px;
        opacity: 0.06; transform: rotate(-28deg); z-index: -1;
    }

    .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .letterhead .logo-cell { width: 34%; vertical-align: middle; }
    .letterhead .logo-cell img { max-height: 58px; }
    .letterhead .info-cell { width: 66%; vertical-align: middle; text-align: right; }
    .letterhead .company-name { font-size: 16px; font-weight: bold; color: #1c3f66; margin: 0 0 3px; }
    .letterhead .tagline { font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
    .contact-row { display: inline-block; }
    .contact-item { display: inline-block; font-size: 8.7px; color: #475569; margin-left: 10px; white-space: nowrap; }
    .contact-item .ic { display: inline-block; width: 9px; height: 9px; vertical-align: -1px; margin-right: 3px; }

    .band { height: 6px; border-radius: 3px; background: #1c3f66; margin: 10px 0 14px; }

    .title-card {
        border-radius: 10px; background: #1c3f66; padding: 10px 16px; margin-bottom: 16px;
        display: table; width: 100%;
        page-break-inside: avoid; break-inside: avoid;
    }
    .title-card .doc-title { display: table-cell; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }
    .title-card .doc-ref { display: table-cell; text-align: right; vertical-align: middle; font-size: 11px; color: #cbd5e1; }
    .title-card .doc-ref strong { color: #ffffff; font-size: 13px; }

    /* page-break-inside: avoid (dompdf understands this CSS2.1 property,
       unlike the newer Paged Media APIs it skips — see project-certificate
       .blade.php's own docblock) keeps a whole card, including its heading,
       together on one page rather than splitting after the heading and
       starting the actual content fresh on the next page. Real browsers
       honor the modern `break-inside` alias the same way for the on-screen
       Print button's window.print(). Applied here, once, so every report
       built on these shared classes gets it for free. */
    .card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 14px; background: #fafbfc; page-break-inside: avoid; break-inside: avoid; }
    .card-title {
        font-size: 10px; font-weight: bold; color: #1c3f66; text-transform: uppercase; margin: 0 0 8px;
    }

    table.grid { width: 100%; border-collapse: collapse; }
    table.grid tr { page-break-inside: avoid; break-inside: avoid; }
    table.grid td { padding: 4px 0; font-size: 10.8px; vertical-align: top; }
    table.grid .label { color: #6b7280; width: 120px; }
    table.grid .value { font-weight: bold; color: #111827; }

    /* The table itself is NOT set to avoid-inside — a long item list (many
       addendum lines, many certificates) legitimately needs to span pages
       — only each individual ROW is kept intact, so a break only ever
       falls between rows, never through the middle of one. */
    table.items { width: 100%; border-collapse: collapse; border-radius: 8px; overflow: hidden; }
    table.items thead { display: table-header-group; }
    table.items tr { page-break-inside: avoid; break-inside: avoid; }
    table.items th, table.items td { text-align: left; padding: 7px 9px; font-size: 10.3px; }
    table.items th { background: #1c3f66; color: #fff; font-weight: bold; }
    table.items th:first-child { border-top-left-radius: 8px; }
    table.items th:last-child { border-top-right-radius: 8px; }
    table.items td { border-bottom: 1px solid #eef1f6; }
    table.items td.num, table.items th.num { text-align: right; }

    .amount-card {
        border-radius: 10px; background: #f0fdf4; border: 1.5px solid #16a34a; padding: 12px 18px; margin: 14px 0;
        display: table; width: 100%;
        page-break-inside: avoid; break-inside: avoid;
    }
    .amount-card .label { display: table-cell; font-size: 10px; font-weight: bold; color: #166534; text-transform: uppercase; vertical-align: middle; }
    .amount-card .value { display: table-cell; text-align: right; font-size: 20px; font-weight: bold; color: #15803d; vertical-align: middle; }

    .status-pill {
        display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 9.5px; font-weight: bold;
    }
    .status-active, .status-draft-ok { background: #d1fae5; color: #047857; }
    .status-warn { background: #fef3c7; color: #b45309; }
    .status-danger { background: #ffe4e6; color: #be123c; }
    .status-neutral { background: #f1f5f9; color: #475569; }

    .signatures { width: 100%; border-collapse: collapse; margin-top: 34px; page-break-inside: avoid; break-inside: avoid; }
    .signatures td { padding-top: 24px; font-size: 9.5px; color: #374151; vertical-align: top; }
    .signatures .line { border-top: 1.5px solid #1c3f66; margin: 0 10px 5px; padding-top: 4px; font-weight: bold; color: #1f2937; }
    .signatures .role { color: #6b7280; font-style: italic; margin: 0 10px; }
    .signatures .who { margin: 4px 10px 0; color: #111827; font-weight: bold; }
    .signatures .when { margin: 1px 10px 0; color: #9ca3af; font-size: 8.5px; }

    .footer-row { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .footer-row .qr-cell { width: 70px; vertical-align: bottom; }
    .footer-row .foot-cell { vertical-align: bottom; text-align: center; color: #9ca3af; font-size: 8.5px; }
    .footer-row .qr-caption { text-align: center; font-size: 7px; color: #94a3b8; margin-top: 2px; }
</style>
