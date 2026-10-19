{{-- Loading & Unloading cards for the Transport reports (print / PDF). Needs $loading. --}}
<table class="stat-grid">
    <tr>
        <td class="accent-blue">
            <span class="stat-label">Loading &amp; Unloading Total</span>
            <span class="stat-value">TZS {{ number_format($loading['totals']['earned'], 2) }}</span>
        </td>
        <td>
            <span class="stat-label">Worker-days</span>
            <span class="stat-value">{{ number_format($loading['totals']['days']) }}</span>
        </td>
        <td class="accent-green">
            <span class="stat-label">Loading &amp; Unloading Paid</span>
            <span class="stat-value">TZS {{ number_format($loading['totals']['paid'], 2) }}</span>
        </td>
        <td class="accent-amber">
            <span class="stat-label">Loading &amp; Unloading Balance</span>
            <span class="stat-value">TZS {{ number_format($loading['totals']['balance'], 2) }}</span>
        </td>
    </tr>
</table>
