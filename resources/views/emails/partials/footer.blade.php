{{--
    Shared footer for every WIMS email — company details + the standard
    "automated message" disclaimer + copyright. One place to update the
    company's contact details instead of four-plus duplicated copies.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border-top:1px solid #eef1f6; border-radius:0 0 16px 16px;">
    <tr>
        <td align="center" style="padding:20px 24px 8px;">
            <p style="margin:0 0 4px; font-size:12px; font-weight:700; color:#334155;">{{ $companyName ?? 'RANS Company Limited' }}</p>
            <p style="margin:0 0 10px; font-size:11px; color:#94a3b8;">Building &amp; Civil Contractors &middot; Fumba Road, Zanzibar &middot; +255 777 700615</p>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 24px 20px;">
            <p style="margin:0 0 4px; font-size:11px; color:#9ca3af;">{{ __('This is an automated message from RANS WIMS, please do not reply.') }}</p>
            <p style="margin:0; font-size:11px; color:#cbd5e1;">RANS Workflow Integrated Management System &middot; &copy; {{ date('Y') }} Extrion.</p>
        </td>
    </tr>
</table>
