<template>
  <admin-layout :title="$t('Certificate') + ' #' + certificate.certificate_number">
    <div class="lg-page px-4 md:px-0 pc-form pc-show">
      <page-header
        icon="doc-text"
        :title="certificate.certificate_type + ' No. ' + certificate.certificate_number"
        :subtitle="[certificate.reference, certificate.warehouse_name, $t('Version') + ' ' + certificate.version].filter(Boolean).join(' · ')"
      >
        <span class="lg-chip" :class="statusChip(certificate.status)">{{ certificate.status }}</span>
        <span
          class="lg-chip"
          :class="[payChip(certificate.payment_status), certificate.payment_status_override ? 'lg-tip' : '']"
          :data-tip="certificate.payment_status_override ? $t('Manually overridden') + ': ' + certificate.status_override_reason : null"
        >
          {{ paymentLabel(certificate.payment_status) }}
          <icons v-if="certificate.payment_status_override" name="edit" class="w-3 h-3" />
        </span>
        <spr-confirm-password
          v-if="can.recordPayment"
          :title="$t('Override Payment Status')"
          :content="$t('Overriding forces the status shown on this certificate instead of the normally calculated one. Please confirm your password to continue.')"
          @confirmed="openOverrideModal"
        >
          <button
            type="button"
            class="lg-btn lg-btn-soft pc-sm lg-tip"
            :data-tip="$t('Forces a fixed status instead of the live calculation, used for exceptions (disputes, holds). Clear it once resolved so the status goes back to auto.')"
          >
            <icons name="exclamation" class="w-3.5 h-3.5" /> {{ $t('Override Status') }}
          </button>
        </spr-confirm-password>
        <Link :href="route('project-certificates.project.index', certificate.warehouse_id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ certificate.warehouse_name }}
        </Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <div v-if="certificate.status === 'issued' && isForeignCertificate && certificate.amount_in_tzs !== null" class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <span><b>{{ $t('Net Payable') }}:</b> ≈ TZS {{ formatNumber(certificate.amount_in_tzs) }}</span>
      </div>
      <div v-else-if="certificate.status === 'issued' && isForeignCertificate" class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('Exchange rate not set') }}</span>
      </div>

      <div v-if="hasActions" class="pc-actions">
        <button v-if="can.update && certificate.status === 'draft'" type="button" class="lg-btn lg-btn-soft" @click="openEditDetailsModal">
          <icons name="edit" class="w-4 h-4" /> {{ $t('Edit Details') }}
        </button>
        <button v-if="can.submit" type="button" class="lg-btn lg-btn-soft" @click="act('submit')">
          <icons name="send" class="w-4 h-4" /> {{ $t('Submit for Review') }}
        </button>
        <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="act('approve')">
          <icons name="tick" class="w-4 h-4" /> {{ $t('Approve') }}
        </button>
        <button v-if="can.issue" type="button" class="lg-btn lg-btn-primary" @click="act('issue')">
          <icons name="tick" class="w-4 h-4" /> {{ $t('Issue Certificate') }}
        </button>
        <button v-if="can.recordPayment && certificate.status === 'issued'" type="button" class="lg-btn lg-btn-green" @click="openPaymentModal">
          <icons name="cash" class="w-4 h-4" /> {{ $t('Record Payment') }}
        </button>
        <a v-if="can.viewFinancials" :href="route('project-certificates.certificate.print', certificate.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a v-if="can.viewFinancials" :href="route('project-certificates.certificate.download', certificate.id)" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download PDF') }}
        </a>
        <button v-if="can.revise" type="button" class="lg-btn lg-btn-soft" @click="reviseModalOpen = true">
          <icons name="refresh" class="w-4 h-4" /> {{ $t('Revise') }}
        </button>
        <spr-confirm-password
          v-if="can.cancel"
          :title="$t('Cancel Certificate')"
          :content="$t('This permanently cancels this certificate. It can\'t be reactivated afterwards. Please confirm your password to continue.')"
          @confirmed="act('cancel')"
        >
          <button type="button" class="lg-btn lg-btn-red">
            <icons name="x-circle" class="w-4 h-4" /> {{ $t('Cancel') }}
          </button>
        </spr-confirm-password>
      </div>

      <div class="pc-split" :class="{ 'has-side': hasSide }">
        <section class="lg-card pc-main-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Calculation') }}</h2>
              <p class="lg-card-sub">{{ certificate.currency }}</p>
            </div>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-10">#</th>
                  <th>{{ $t('Item') }}</th>
                  <th class="num">{{ $t('Value') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in certificate.lines" :key="line.id" :class="{ 'pc-emph': ['total', 'subtotal'].includes(line.type) }">
                  <td class="pc-faint" data-label="#">{{ i + 1 }}</td>
                  <td class="pc-wrap" :class="{ 'lg-main': ['total', 'subtotal'].includes(line.type) }" :data-label="$t('Item')">{{ line.label }}</td>
                  <td class="num" :class="{ 'lg-main': ['total', 'subtotal'].includes(line.type) }" :data-label="$t('Value')">{{ money(line.value) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <div v-if="hasSide" class="pc-side">
          <section v-if="(certificate.attachments || []).length" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              </div>
            </header>
            <div class="flex flex-wrap gap-2">
              <a v-for="a in certificate.attachments" :key="a.id" :href="a.download_url" class="pc-file">
                <icons name="download" class="w-3.5 h-3.5 flex-none" /> <span>{{ a.name }}</span>
              </a>
            </div>
          </section>

          <section v-if="certificate.revisions.length" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Revision History') }}</h2>
                <p class="lg-card-sub">{{ $t('Click a revision to see exactly what changed.') }}</p>
              </div>
            </header>
            <ul class="pc-timeline">
              <li v-for="r in certificate.revisions" :key="r.version">
                <span class="pc-tl-dot"></span>
                <button type="button" class="pc-rev-row" @click="openRevisionModal(r)">
                  <span class="lg-chip lg-chip-indigo flex-none">{{ $t('Version') }} {{ r.version }}</span>
                  <span class="pc-rev-body">
                    <span class="pc-rev-reason">{{ r.reason }}</span>
                    <span class="pc-rev-meta">{{ r.changed_by_name }} &middot; {{ r.created_at }}</span>
                  </span>
                  <icons name="chevron-down" class="w-4 h-4 pc-rev-chev transform -rotate-90" />
                </button>
              </li>
            </ul>
          </section>
        </div>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Payment History') }}</h2>
            <p class="lg-card-sub">{{ certificate.payments.length }} &middot; {{ money(certificate.total_paid) }}</p>
          </div>
        </header>

        <div v-if="certificate.payments.length">
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Date') }}</th>
                  <th>{{ $t('Reference') }}</th>
                  <th>{{ $t('Method') }}</th>
                  <th>{{ $t('Paid By') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                  <th>{{ $t('Recorded By') }}</th>
                  <th class="num"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in certificate.payments" :key="p.id">
                  <td :data-label="$t('Date')">{{ p.payment_date }}</td>
                  <td class="pc-wrap" :data-label="$t('Reference')">
                    <span class="lg-main">{{ p.reference || '—' }}</span>
                    <div v-if="(p.attachments || []).length" class="flex flex-wrap gap-1.5 mt-1 pc-end">
                      <a v-for="a in p.attachments" :key="a.id" :href="a.download_url" class="lg-icon-btn lg-tip pc-mini" :data-tip="a.name" :aria-label="a.name">
                        <icons name="clip" class="w-3 h-3" />
                      </a>
                    </div>
                  </td>
                  <td :data-label="$t('Method')">
                    <span v-if="p.method" class="lg-chip lg-chip-slate" style="text-transform: none">{{ p.method }}</span>
                    <template v-else>—</template>
                  </td>
                  <td :data-label="$t('Paid By')">{{ p.paid_by || '—' }}</td>
                  <td class="num" :data-label="$t('Amount')">
                    <div class="pc-amt">
                      <span class="lg-main">{{ money(p.amount) }}</span>
                      <span v-if="p.paid_currency" class="lg-chip lg-chip-indigo pc-cur">{{ p.paid_currency }}</span>
                    </div>
                    <div v-if="p.paid_currency" class="lg-subtext pc-wrap">
                      {{ $t('Received') }} {{ p.paid_currency }} {{ formatNumber(p.paid_amount) }}
                      &middot; {{ $t('Rate') }} 1 {{ p.paid_currency }} = {{ formatNumber(p.exchange_rate) }} {{ certificate.currency }}
                    </div>
                  </td>
                  <td class="pc-faint" :data-label="$t('Recorded By')">{{ p.recorded_by_name }}</td>
                  <td class="num" data-label="">
                    <spr-confirm-password
                      v-if="can.editPayment"
                      :title="$t('Edit Payment')"
                      :content="$t('Editing a recorded payment changes this certificate\'s paid/outstanding totals · please confirm your password to continue.')"
                      @confirmed="openEditPayment(p)"
                    >
                      <button type="button" class="lg-icon-btn" :aria-label="$t('Edit Payment')"><icons name="edit" class="w-3.5 h-3.5" /></button>
                    </spr-confirm-password>
                    <span
                      v-else
                      class="lg-icon-btn pc-disabled"
                      tabindex="0"
                      @mouseenter="showDisabledEditTip($event)"
                      @mouseleave="hideDisabledEditTip"
                      @focus="showDisabledEditTip($event)"
                      @blur="hideDisabledEditTip"
                    >
                      <icons name="edit" class="w-3.5 h-3.5" />
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pc-total">
            <div class="min-w-0">
              <div class="pc-total-label">{{ $t('Total') }}</div>
              <div v-if="Object.keys(paymentTotalsByOriginalCurrency).length > 1" class="lg-subtext">
                {{ $t('Received') }}:
                <template v-for="(sum, cur, idx) in paymentTotalsByOriginalCurrency" :key="cur">
                  <template v-if="idx > 0"> + </template>{{ cur }} {{ formatNumber(sum) }}
                </template>
              </div>
            </div>
            <div class="pc-total-val">{{ money(certificate.total_paid) }}</div>
          </div>
        </div>
        <div v-else class="lg-empty pc-empty-box">{{ $t('No payments recorded yet.') }}</div>

        <!-- Teleported to <body> so this tooltip escapes the table's
             overflow-x wrapper (overflow-x alone forces overflow-y to auto too,
             which would clip an upward bubble). -->
        <Teleport to="body">
          <div v-if="disabledEditTip.visible" class="pc-tip-bubble-fixed" :style="{ left: disabledEditTip.left + 'px', top: disabledEditTip.top + 'px' }">
            <template v-if="certificate.status !== 'issued'">
              {{ $t("This certificate isn't issued (it's currently") }} {{ certificate.status }}{{ $t("), so its recorded payments can't be edited right now · only an issued certificate's payments can be corrected.") }}
            </template>
            <template v-else>{{ $t('Only a Super Admin can edit a recorded payment.') }}</template>
          </div>
        </Teleport>
      </section>
    </div>

    <!-- Revision diff modal -->
    <Teleport to="body">
      <div v-if="revisionModalOpen" class="pc-overlay lg-page pc-form" @click.self="revisionModalOpen = false">
        <div class="pc-dialog pc-dialog-wide" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('What Changed') }} &middot; {{ $t('Version') }} {{ activeRevision?.version }} → {{ $t('Version') }} {{ (activeRevision?.version || 0) + 1 }}</h2>
              <p class="lg-card-sub">{{ activeRevision?.reason }}</p>
              <p class="lg-subtext">{{ activeRevision?.changed_by_name }} &middot; {{ activeRevision?.created_at }}</p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="revisionModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="pc-dialog-body pc-stack">
            <div v-if="revisionDiff.certificate.length">
              <div class="pc-sec-title">{{ $t('Certificate Details') }}</div>
              <div class="lg-table-wrap">
                <table class="lg-table pc-diff-table">
                  <tbody>
                    <tr v-for="d in revisionDiff.certificate" :key="d.field">
                      <td class="pc-diff-label">{{ d.label }}</td>
                      <td class="pc-diff-old">{{ d.oldValue }}</td>
                      <td class="pc-diff-arrow">→</td>
                      <td class="pc-diff-new">{{ d.newValue }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div v-if="revisionDiff.lines.length">
              <div class="pc-sec-title">{{ $t('Calculation Lines') }}</div>
              <div class="lg-table-wrap">
                <table class="lg-table pc-diff-table">
                  <tbody>
                    <tr v-for="d in revisionDiff.lines" :key="d.field">
                      <td class="pc-diff-label">
                        {{ d.label }}
                        <span v-if="d.status !== 'changed'" class="lg-chip" :class="d.status === 'added' ? 'lg-chip-green' : 'lg-chip-red'">{{ d.status }}</span>
                      </td>
                      <td class="pc-diff-old">{{ d.oldValue }}</td>
                      <td class="pc-diff-arrow">→</td>
                      <td class="pc-diff-new">{{ d.newValue }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <p v-if="!revisionDiff.certificate.length && !revisionDiff.lines.length" class="lg-empty">
              {{ $t('No differences found between these two versions.') }}
            </p>
          </div>

          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="revisionModalOpen = false"><icons name="cross" class="w-4 h-4" /> {{ $t('Close') }}</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Record payment modal -->
    <Teleport to="body">
      <div v-if="paymentModalOpen" class="pc-overlay lg-page pc-form" @click.self="paymentModalOpen = false">
        <div class="pc-dialog pc-dialog-wide" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Record Payment') }}</h2>
              <p class="lg-card-sub">{{ $t('Outstanding') }}: <strong class="pc-warn">{{ money(certificate.outstanding) }}</strong></p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="paymentModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="pc-dialog-body pc-stack">
            <div class="pc-sec">
              <div class="pc-sec-title"><icons name="cash" class="w-3.5 h-3.5" /> {{ $t('Amount') }}</div>
              <div class="lg-fields">
                <div>
                  <label class="pc-label">{{ $t('Amount Received') }}</label>
                  <input v-model.number="paymentForm.paid_amount" type="number" step="0.01" min="0" class="pc-input" />
                </div>
                <div>
                  <label class="pc-label">{{ $t('Currency Received') }}</label>
                  <auto-complete v-model="paymentForm.paid_currency" :suggestions="currencyOptions" placeholder="TZS" />
                </div>
                <div v-if="!isSameCurrency" class="lg-span-all">
                  <label class="pc-label">{{ $t('Exchange Rate') }} (1 {{ paymentForm.paid_currency }} = ? {{ certificate.currency }})</label>
                  <input v-model.number="paymentForm.exchange_rate" type="number" step="0.000001" min="0" class="pc-input" />
                  <p class="pc-hint">{{ $t('Converts to') }}: <strong>{{ money(convertedAmount) }}</strong></p>
                </div>
              </div>
            </div>

            <div class="pc-sec">
              <div class="pc-sec-title"><icons name="calendar" class="w-3.5 h-3.5" /> {{ $t('Details') }}</div>
              <div class="lg-fields">
                <div>
                  <label class="pc-label">{{ $t('Payment Date') }}</label>
                  <date-picker v-model="paymentForm.payment_date" />
                </div>
                <div>
                  <label class="pc-label">{{ $t('Method') }}</label>
                  <auto-complete v-model="paymentForm.method" :suggestions="paymentMethods" freeform placeholder="Bank Transfer, Cash, Cheque…" />
                </div>
                <div class="lg-span-all">
                  <label class="pc-label">{{ $t('Reference Number') }}</label>
                  <input v-model="paymentForm.reference_number" type="text" class="pc-input" :placeholder="$t('Bank slip, cheque, or transaction number…')" />
                </div>
                <div class="lg-span-all">
                  <label class="pc-label">{{ $t('Paid By') }}</label>
                  <auto-complete v-model="paymentForm.paid_by" :suggestions="paidByOptions" freeform :placeholder="$t('Who made this payment…')" />
                </div>
              </div>
            </div>

            <div class="pc-sec">
              <div class="pc-sec-title"><icons name="clip" class="w-3.5 h-3.5" /> {{ $t('Notes & Proof of Payment') }}</div>
              <label class="pc-label">{{ $t('Notes') }}</label>
              <textarea v-model="paymentForm.notes" class="pc-input" rows="2" :placeholder="$t('Any extra context for this payment…')"></textarea>
              <label class="pc-label mt-3">{{ $t('Attachments') }}</label>
              <attachment-picker v-model="paymentForm.attachments" :max-files="5" :help-text="$t('Receipts, bank slips, or proof of payment · up to 5 files.')" />
            </div>

            <div v-if="paymentError" class="lg-note lg-note-red">
              <icons name="exclamation" class="w-4 h-4" />
              <span>{{ paymentError }}
                <button type="button" class="pc-linkbtn ml-1" @click="paymentForm.confirmed = true; submitPayment();">{{ $t('Confirm anyway') }}</button>
              </span>
            </div>
          </div>

          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="paymentModalOpen = false"><icons name="cross" class="w-4 h-4" /> {{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="paying" @click="submitPayment">
              <icons name="tick" class="w-4 h-4" /> {{ paying ? $t('Saving…') : $t('Save Payment') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Edit payment modal — only reachable after SprConfirmPassword's inline
         re-confirmation succeeds (see the payment row's Edit action above) -->
    <Teleport to="body">
      <div v-if="editingPayment" class="pc-overlay lg-page pc-form" @click.self="editingPayment = null">
        <div class="pc-dialog" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="edit" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Edit Payment') }}</h2>
              <p class="lg-card-sub">{{ editingPayment.reference }}</p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="editingPayment = null"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="pc-dialog-body">
            <div class="lg-fields">
              <div>
                <label class="pc-label">{{ $t('Amount Received') }}</label>
                <input v-model.number="editForm.paid_amount" type="number" step="0.01" min="0" class="pc-input" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Currency Received') }}</label>
                <auto-complete v-model="editForm.paid_currency" :suggestions="currencyOptions" placeholder="TZS" />
              </div>
              <div v-if="!isEditSameCurrency" class="lg-span-all">
                <label class="pc-label">{{ $t('Exchange Rate') }} (1 {{ editForm.paid_currency }} = ? {{ certificate.currency }})</label>
                <input v-model.number="editForm.exchange_rate" type="number" step="0.000001" min="0" class="pc-input" />
                <p class="pc-hint">{{ $t('Converts to') }}: <strong>{{ money(editConvertedAmount) }}</strong></p>
              </div>
              <div>
                <label class="pc-label">{{ $t('Payment Date') }}</label>
                <date-picker v-model="editForm.payment_date" flush />
              </div>
              <div>
                <label class="pc-label">{{ $t('Method') }}</label>
                <auto-complete v-model="editForm.method" :suggestions="paymentMethods" freeform placeholder="Bank Transfer, Cash, Cheque…" />
              </div>
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Reference Number') }}</label>
                <input v-model="editForm.reference_number" type="text" class="pc-input" :placeholder="$t('Bank slip, cheque, or transaction number…')" />
              </div>
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Paid By') }}</label>
                <auto-complete v-model="editForm.paid_by" :suggestions="paidByOptions" freeform :placeholder="$t('Who made this payment…')" />
              </div>
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Notes') }}</label>
                <textarea v-model="editForm.notes" class="pc-input" rows="2" :placeholder="$t('Any extra context for this payment…')"></textarea>
              </div>
            </div>
            <div v-if="editError" class="lg-note lg-note-red mt-3">
              <icons name="exclamation" class="w-4 h-4" />
              <span>{{ editError }}
                <button type="button" class="pc-linkbtn ml-1" @click="editForm.confirmed = true; submitEditPayment();">{{ $t('Confirm anyway') }}</button>
              </span>
            </div>
          </div>

          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="editingPayment = null"><icons name="cross" class="w-4 h-4" /> {{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="savingEdit" @click="submitEditPayment">
              <icons name="tick" class="w-4 h-4" /> {{ savingEdit ? $t('Saving…') : $t('Save Changes') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Zero-outstanding confirm — our own styled dialog, not window.confirm() -->
    <Teleport to="body">
      <div v-if="zeroOutstandingConfirmOpen" class="pc-overlay lg-page pc-form" @click.self="zeroOutstandingConfirmOpen = false">
        <div class="pc-dialog pc-dialog-sm pc-dialog-center" role="dialog" aria-modal="true">
          <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
          <h2 class="lg-card-title mt-2">{{ $t('No Outstanding Balance') }}</h2>
          <p class="lg-card-sub">{{ $t('This certificate has no outstanding balance. Record a payment anyway?') }}</p>
          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="zeroOutstandingConfirmOpen = false">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" @click="confirmZeroOutstandingPayment">{{ $t('Record Anyway') }}</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Revise modal -->
    <Teleport to="body">
      <div v-if="reviseModalOpen" class="pc-overlay lg-page pc-form" @click.self="reviseModalOpen = false">
        <div class="pc-dialog" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Revise Certificate') }}</h2>
              <p class="lg-card-sub">{{ $t('This preserves version') }} {{ certificate.version }} {{ $t('as history and reopens the certificate as a draft for editing.') }}</p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="reviseModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>
          <div class="pc-dialog-body">
            <label class="pc-label">{{ $t('Reason for revision') }}</label>
            <textarea v-model="reviseReason" class="pc-input" rows="3"></textarea>
          </div>
          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="reviseModalOpen = false">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="!reviseReason || revising" @click="submitRevise">{{ revising ? $t('Saving…') : $t('Revise') }}</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Edit Details modal — Draft-only, metadata fields only (type, dates,
         period, currency, description/notes). The calculation lines
         themselves aren't editable here — cancel and re-create via the
         Builder if the figures themselves are wrong. -->
    <Teleport to="body">
      <div v-if="editDetailsModalOpen" class="pc-overlay lg-page pc-form" @click.self="editDetailsModalOpen = false">
        <div class="pc-dialog pc-dialog-wide" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="edit" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Edit Certificate Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Metadata only: the calculation lines can\'t be changed here. Cancel and re-create this certificate if the figures themselves are wrong.') }}</p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="editDetailsModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="pc-dialog-body">
            <div class="lg-fields">
              <div>
                <label class="pc-label">{{ $t('Certificate Type') }}</label>
                <auto-complete v-model="editDetailsForm.certificate_type" :suggestions="certificateTypes" freeform />
              </div>
              <div>
                <label class="pc-label">{{ $t('Period Covered') }}</label>
                <input v-model="editDetailsForm.period_covered" type="text" class="pc-input" :placeholder="$t('e.g. Jun – Jul 2026')" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Valuation Date') }}</label>
                <date-picker v-model="editDetailsForm.valuation_date" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Certificate Date') }}</label>
                <date-picker v-model="editDetailsForm.certificate_date" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Payment Due Date') }}</label>
                <date-picker v-model="editDetailsForm.payment_due_date" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Currency') }}</label>
                <auto-complete v-model="editDetailsForm.currency" :suggestions="currencyOptions" />
              </div>
              <div v-if="(editDetailsForm.currency || 'TZS').toUpperCase() !== 'TZS'">
                <label class="pc-label">{{ $t('Exchange Rate') }}</label>
                <input v-model.number="editDetailsForm.exchange_rate" type="number" step="0.000001" min="0" class="pc-input" :placeholder="$t('Rate for the day')" />
              </div>
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Description') }}</label>
                <textarea v-model="editDetailsForm.description" class="pc-input" rows="2"></textarea>
              </div>
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Notes') }}</label>
                <textarea v-model="editDetailsForm.notes" class="pc-input" rows="2"></textarea>
              </div>
            </div>
            <div v-if="editDetailsError" class="lg-note lg-note-red mt-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ editDetailsError }}</span></div>
          </div>

          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="editDetailsModalOpen = false">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="savingDetails" @click="submitEditDetails">
              <icons name="tick" class="w-4 h-4" /> {{ savingDetails ? $t('Saving…') : $t('Save Changes') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Override payment status modal — forces certificate.payment_status to
         a fixed value (e.g. "Disputed", "On Hold") instead of the normal
         live-computed one, for exceptions the auto calculation can't
         represent. Clearing the dropdown back to "Auto" removes the
         override and resumes normal computation. -->
    <Teleport to="body">
      <div v-if="overrideModalOpen" class="pc-overlay lg-page pc-form" @click.self="overrideModalOpen = false">
        <div class="pc-dialog" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Override Payment Status') }}</h2>
              <p class="lg-card-sub">{{ $t('Forces the payment status shown above instead of the normally calculated one. Use only for exceptions. Clear it once resolved.') }}</p>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="overrideModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>
          <div class="pc-dialog-body">
            <label class="pc-label">{{ $t('Payment Status') }}</label>
            <auto-complete v-model="overrideForm.payment_status_override" :suggestions="overrideStatusOptions" />
            <div v-if="overrideForm.payment_status_override !== 'auto'" class="mt-3">
              <label class="pc-label">{{ $t('Reason') }}</label>
              <textarea v-model="overrideForm.reason" class="pc-input" rows="3" :placeholder="$t('Why this certificate needs a manual status override…')"></textarea>
            </div>
            <div v-if="overrideError" class="lg-note lg-note-red mt-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ overrideError }}</span></div>
          </div>
          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="overrideModalOpen = false">{{ $t('Cancel') }}</button>
            <button
              type="button"
              class="lg-btn lg-btn-primary"
              :disabled="overriding || (overrideForm.payment_status_override !== 'auto' && !overrideForm.reason)"
              @click="submitOverride"
            >
              {{ overriding ? $t('Saving…') : $t('Save') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SprConfirmPassword from '@/Shared/SprConfirmPassword.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, SprConfirmPassword, AttachmentPicker, DatePicker, PageHeader, StatTiles },

  props: {
    certificate: Object,
    can: Object,
    currencies: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
  },

  data() {
    return {
      paymentModalOpen: false,
      zeroOutstandingConfirmOpen: false,
      reviseModalOpen: false,
      revisionModalOpen: false,
      overrideModalOpen: false,
      editDetailsModalOpen: false,
      activeRevision: null,
      paying: false,
      revising: false,
      overriding: false,
      savingDetails: false,
      overrideError: '',
      editDetailsError: '',
      overrideForm: { payment_status_override: 'auto', reason: '' },
      editDetailsForm: {
        certificate_type: '', period_covered: '', valuation_date: '', certificate_date: '',
        payment_due_date: '', currency: 'TZS', exchange_rate: null, description: '', notes: '',
      },
      certificateTypes: [
        'Interim Payment Certificate',
        'Advance Payment Certificate',
        'Final Payment Certificate',
        'Retention Release Certificate',
        'Practical Completion Certificate',
        'Making Good Defects Certificate',
        'Variation Certificate',
      ],
      paymentError: '',
      reviseReason: '',
      paymentForm: {
        paid_amount: null, paid_currency: this.certificate.currency, exchange_rate: null,
        payment_date: new Date().toISOString().slice(0, 10), method: '', paid_by: '', reference_number: '', notes: '', attachments: [], confirmed: false,
      },
      editingPayment: null,
      savingEdit: false,
      editError: '',
      editForm: {
        paid_amount: null, paid_currency: this.certificate.currency, exchange_rate: null,
        payment_date: '', method: '', paid_by: '', reference_number: '', notes: '', confirmed: false,
      },
      disabledEditTip: { visible: false, top: 0, left: 0 },
    };
  },

  computed: {
    tiles() {
      const c = this.certificate;
      const issued = c.status === 'issued';

      return [
        { label: this.$t('Certified'), value: issued ? this.money(c.cumulative_certified_amount) : this.$t('Not yet issued'), icon: 'doc-text', tone: 'navy' },
        { label: this.$t('Net Payable'), value: issued ? this.money(c.net_payment_amount) : this.$t('Not yet issued'), icon: 'calculator', tone: 'indigo' },
        { label: this.$t('Paid'), value: this.money(c.total_paid), icon: 'tick', tone: 'emerald', valueClass: 'is-good' },
        { label: this.$t('Outstanding'), value: this.money(c.outstanding), icon: 'clock', tone: 'amber', valueClass: Number(c.outstanding) > 0 ? 'is-bad' : 'is-good' },
      ];
    },
    hasSide() {
      return (this.certificate.attachments || []).length > 0 || (this.certificate.revisions || []).length > 0;
    },
    hasActions() {
      const c = this.can;

      return !!((c.update && this.certificate.status === 'draft') || c.submit || c.approve || c.issue || c.viewFinancials
        || (c.recordPayment && this.certificate.status === 'issued') || c.revise || c.cancel);
    },
    // "TZS Tanzanian Shilling" — code and name, no dash — matches
    // Builder.vue's own currency picker rather than Currencies::all()'s
    // shared "CODE — Name" label (still used elsewhere in the app).
    currencyOptions() {
      return this.currencies.map(c => ({ id: c.code, label: `${c.code} ${c.name}` }));
    },
    isSameCurrency() {
      return (this.paymentForm.paid_currency || '').toUpperCase() === (this.certificate.currency || '').toUpperCase();
    },
    // This project's own funding clients (contract.clients — see
    // ProjectCertificateController::show()), with the primary contract
    // client listed first. Scoped to the project rather than every client
    // in the system, since a payment should come from one of the parties
    // actually funding it.
    paidByOptions() {
      const ownClient = this.certificate.contract?.client_name;
      const all = ownClient ? [ownClient, ...this.clients.filter(c => c !== ownClient)] : this.clients;

      return all;
    },
    // Rounds paid_amount to 2dp BEFORE multiplying — matches
    // ProjectCertificatePaymentController::store()'s exact two-step
    // rounding (round(paid_amount,2) then round(×rate,2)), so this preview
    // never shows a figure a cent off from what actually gets saved.
    convertedAmount() {
      if (this.isSameCurrency) return this.paymentForm.paid_amount || 0;

      const paidAmount = Math.round((Number(this.paymentForm.paid_amount) || 0) * 100) / 100;

      return Math.round(paidAmount * (Number(this.paymentForm.exchange_rate) || 0) * 100) / 100;
    },
    isEditSameCurrency() {
      return (this.editForm.paid_currency || '').toUpperCase() === (this.certificate.currency || '').toUpperCase();
    },
    editConvertedAmount() {
      if (this.isEditSameCurrency) return this.editForm.paid_amount || 0;

      const paidAmount = Math.round((Number(this.editForm.paid_amount) || 0) * 100) / 100;

      return Math.round(paidAmount * (Number(this.editForm.exchange_rate) || 0) * 100) / 100;
    },
    isForeignCertificate() {
      return !!(this.certificate.cert_currency && this.certificate.cert_currency.toUpperCase() !== 'TZS');
    },
    currencyOptions() {
      return (this.currencies || []).map(c => ({ id: c.code, label: `${c.code} ${c.name}` }));
    },
    overrideStatusOptions() {
      return [
        { id: 'auto', label: this.$t('Auto (recalculate normally)') },
        { id: 'unpaid', label: this.paymentLabel('unpaid') },
        { id: 'partially_paid', label: this.paymentLabel('partially_paid') },
        { id: 'paid_in_full', label: this.paymentLabel('paid_in_full') },
        { id: 'overdue', label: this.paymentLabel('overdue') },
        { id: 'disputed', label: this.paymentLabel('disputed') },
        { id: 'on_hold', label: this.paymentLabel('on_hold') },
        { id: 'cancelled', label: this.paymentLabel('cancelled') },
      ];
    },
    // Payments as ORIGINALLY received, grouped by whatever currency each one
    // actually came in — not certificate.total_paid, which is every payment
    // already converted to the certificate's own currency. A certificate can
    // collect a mix (e.g. one payment straight in TZS, another wired in USD
    // and converted), and "Total TZS 100,000,000" alone hides that split.
    paymentTotalsByOriginalCurrency() {
      const totals = {};
      (this.certificate.payments || []).forEach(p => {
        const currency = p.paid_currency || this.certificate.currency;
        const amount = p.paid_currency ? Number(p.paid_amount || 0) : Number(p.amount || 0);
        totals[currency] = (totals[currency] || 0) + amount;
      });

      return totals;
    },
    // Diffs the open revision's snapshot against whatever state came right
    // after it — the NEXT-more-recent revision's own snapshot if one
    // exists, or the live certificate when this is the most recent
    // revision. certificate.revisions is already ordered newest-first, so
    // "one index earlier" is "one version later".
    revisionDiff() {
      if (!this.activeRevision) return { certificate: [], lines: [] };

      const revisions = this.certificate.revisions;
      const idx = revisions.findIndex(r => r.version === this.activeRevision.version);
      const nextSnapshot = idx > 0 ? revisions[idx - 1].snapshot : null;

      const oldCert = this.activeRevision.snapshot.certificate || {};
      const oldLines = this.activeRevision.snapshot.lines || [];
      const newCert = nextSnapshot ? (nextSnapshot.certificate || {}) : this.certificate;
      const newLines = nextSnapshot ? (nextSnapshot.lines || []) : this.certificate.lines;

      const certFieldLabels = {
        certificate_type: this.$t('Certificate Type'),
        status: this.$t('Status'),
        currency: this.$t('Currency'),
        exchange_rate: this.$t('Exchange Rate'),
        valuation_date: this.$t('Valuation Date'),
        certificate_date: this.$t('Certificate Date'),
        payment_due_date: this.$t('Payment Due Date'),
        period_covered: this.$t('Period Covered'),
        description: this.$t('Description'),
        notes: this.$t('Notes'),
        cumulative_certified_amount: this.$t('Certified'),
        net_payment_amount: this.$t('Net Payment'),
      };
      // The snapshot stores raw model values (a decimal cast serializes as
      // "212000000.00", a date cast as a full ISO datetime string); the
      // live certificate prop arrives already normalized (a JS number, a
      // plain "2026-08-13" string). Comparing those two representations as
      // plain strings flags every date/money field as "changed" even when
      // nothing actually moved — each field type needs its own equality
      // check, not String(a) !== String(b).
      const dateFields = ['valuation_date', 'certificate_date', 'payment_due_date'];
      const moneyFields = ['cumulative_certified_amount', 'net_payment_amount'];
      const numericFields = ['exchange_rate'];
      const normalizeForCompare = (field, value) => {
        if (value === null || value === undefined || value === '') return null;
        if (dateFields.includes(field)) return String(value).slice(0, 10);
        if (moneyFields.includes(field) || numericFields.includes(field)) return Number(value);

        return String(value).trim();
      };

      const certificateDiff = [];
      Object.keys(certFieldLabels).forEach(field => {
        const oldValue = oldCert[field];
        const newValue = newCert[field];
        if (normalizeForCompare(field, oldValue) !== normalizeForCompare(field, newValue)) {
          certificateDiff.push({
            field, label: certFieldLabels[field],
            oldValue: this.formatDiffValue(field, oldValue),
            newValue: this.formatDiffValue(field, newValue),
          });
        }
      });

      const oldByKey = {};
      oldLines.forEach(l => { oldByKey[l.line_key] = l; });
      const newByKey = {};
      newLines.forEach(l => { newByKey[l.line_key] = l; });

      const lineDiff = [];
      [...new Set([...Object.keys(oldByKey), ...Object.keys(newByKey)])].forEach(key => {
        const oldLine = oldByKey[key];
        const newLine = newByKey[key];
        if (oldLine && !newLine) {
          lineDiff.push({ field: key, label: oldLine.label, status: 'removed', oldValue: this.money(oldLine.value), newValue: '—' });
        } else if (!oldLine && newLine) {
          lineDiff.push({ field: key, label: newLine.label, status: 'added', oldValue: '—', newValue: this.money(newLine.value) });
        } else if (oldLine && newLine && (Number(oldLine.value) !== Number(newLine.value) || oldLine.label !== newLine.label)) {
          lineDiff.push({ field: key, label: newLine.label, status: 'changed', oldValue: this.money(oldLine.value), newValue: this.money(newLine.value) });
        }
      });

      return { certificate: certificateDiff, lines: lineDiff };
    },
  },

  methods: {
    statusChip(status) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', approved: 'lg-chip-blue', issued: 'lg-chip-green', revised: 'lg-chip-amber', cancelled: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    payChip(status) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', paid_in_full: 'lg-chip-green', overdue: 'lg-chip-red', disputed: 'lg-chip-violet', on_hold: 'lg-chip-slate', cancelled: 'lg-chip-slate' }[status] || 'lg-chip-slate';
    },
    // Anchored via getBoundingClientRect + position:fixed (see the
    // Teleport above) rather than the usual CSS :hover bubble, since this
    // one sits inside a horizontally-scrollable table — see the comment
    // by the Teleport for why a plain CSS bubble gets clipped there.
    showDisabledEditTip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.disabledEditTip = { visible: true, top: rect.top - 8, left: rect.right };
    },
    hideDisabledEditTip() {
      this.disabledEditTip.visible = false;
    },
    openRevisionModal(revision) {
      this.activeRevision = revision;
      this.revisionModalOpen = true;
    },
    formatDiffValue(field, value) {
      if (['cumulative_certified_amount', 'net_payment_amount'].includes(field)) {
        return this.money(value);
      }
      if (field === 'exchange_rate') {
        return value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
      }
      if (['valuation_date', 'certificate_date', 'payment_due_date'].includes(field)) {
        return value ? String(value).slice(0, 10) : '—';
      }

      return value === null || value === undefined || value === '' ? '—' : value;
    },
    formatNumber(v) {
      return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
    },
    money(v) {
      return (this.certificate.currency || 'TZS') + '\u00A0' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    paymentLabel(status) {
      return { unpaid: 'Unpaid', partially_paid: 'Partially Paid', paid_in_full: 'Paid in Full', overdue: 'Overdue', disputed: 'Disputed', on_hold: 'On Hold', cancelled: 'Cancelled' }[status] || status;
    },
    act(action) {
      this.$inertia.post(this.route('project-certificates.' + action, this.certificate.id), {}, { preserveScroll: true });
    },
    // Outstanding already at (or below) zero means this certificate is
    // fully settled — recording another payment against it is unusual
    // enough to check first, rather than only catching it after the user
    // has filled the whole form in (the backend's own overspend check,
    // further down, still applies once they're in the modal). Uses our own
    // pc-modal confirm rather than window.confirm() — a plain browser
    // dialog can't carry this app's styling.
    openPaymentModal() {
      if (this.certificate.outstanding <= 0) {
        this.zeroOutstandingConfirmOpen = true;
        return;
      }
      this.paymentModalOpen = true;
    },
    confirmZeroOutstandingPayment() {
      this.zeroOutstandingConfirmOpen = false;
      this.paymentModalOpen = true;
    },
    submitPayment() {
      this.paying = true;
      this.paymentError = '';
      this.$inertia.post(this.route('project-certificates.payments.store', this.certificate.id), this.paymentForm, {
        preserveScroll: true,
        onError: (errors) => { this.paymentError = errors.paid_amount || errors.exchange_rate || Object.values(errors)[0]; },
        onSuccess: () => {
          this.paymentModalOpen = false;
          this.paymentForm = {
            paid_amount: null, paid_currency: this.certificate.currency, exchange_rate: null,
            payment_date: new Date().toISOString().slice(0, 10), method: '', paid_by: '', reference_number: '', notes: '', attachments: [], confirmed: false,
          };
        },
        onFinish: () => { this.paying = false; },
      });
    },
    openEditPayment(payment) {
      this.editError = '';
      this.editingPayment = payment;
      this.editForm = {
        paid_amount: payment.paid_currency ? payment.paid_amount : payment.amount,
        paid_currency: payment.paid_currency || this.certificate.currency,
        exchange_rate: payment.exchange_rate,
        payment_date: payment.payment_date,
        method: payment.method || '',
        paid_by: payment.paid_by || '',
        reference_number: payment.reference_number || '',
        notes: payment.notes || '',
        confirmed: false,
      };
    },
    submitEditPayment() {
      this.savingEdit = true;
      this.editError = '';
      this.$inertia.put(this.route('project-certificates.payments.update', [this.certificate.id, this.editingPayment.id]), this.editForm, {
        preserveScroll: true,
        onError: (errors) => { this.editError = errors.paid_amount || errors.exchange_rate || Object.values(errors)[0]; },
        onSuccess: () => { this.editingPayment = null; },
        onFinish: () => { this.savingEdit = false; },
      });
    },
    submitRevise() {
      this.revising = true;
      this.$inertia.post(this.route('project-certificates.revise', this.certificate.id), { reason: this.reviseReason }, {
        preserveScroll: true,
        onSuccess: () => { this.reviseModalOpen = false; },
        onFinish: () => { this.revising = false; },
      });
    },
    openEditDetailsModal() {
      this.editDetailsForm = {
        certificate_type: this.certificate.certificate_type,
        period_covered: this.certificate.period_covered || '',
        valuation_date: this.certificate.valuation_date,
        certificate_date: this.certificate.certificate_date,
        payment_due_date: this.certificate.payment_due_date || '',
        currency: this.certificate.currency,
        exchange_rate: this.certificate.exchange_rate,
        description: this.certificate.description || '',
        notes: this.certificate.notes || '',
      };
      this.editDetailsError = '';
      this.editDetailsModalOpen = true;
    },
    submitEditDetails() {
      this.savingDetails = true;
      this.$inertia.put(this.route('project-certificates.update', this.certificate.id), this.editDetailsForm, {
        preserveScroll: true,
        onError: (errors) => { this.editDetailsError = Object.values(errors)[0]; },
        onSuccess: () => { this.editDetailsModalOpen = false; },
        onFinish: () => { this.savingDetails = false; },
      });
    },
    openOverrideModal() {
      this.overrideForm = {
        payment_status_override: this.certificate.payment_status_override || 'auto',
        reason: this.certificate.status_override_reason || '',
      };
      this.overrideError = '';
      this.overrideModalOpen = true;
    },
    submitOverride() {
      this.overriding = true;
      const payload = {
        payment_status_override: this.overrideForm.payment_status_override === 'auto' ? null : this.overrideForm.payment_status_override,
        reason: this.overrideForm.reason,
      };
      this.$inertia.put(this.route('project-certificates.payments.status-override', this.certificate.id), payload, {
        preserveScroll: true,
        onError: (errors) => { this.overrideError = errors.reason || errors.payment_status_override || Object.values(errors)[0]; },
        onSuccess: () => { this.overrideModalOpen = false; },
        onFinish: () => { this.overriding = false; },
      });
    },
  },
};
</script>

<style scoped>
/* ---------- shared Project Certificates chrome (pc- prefix) ---------- */
.pc-label {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 1.1rem;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--lg-mute);
  margin-bottom: 0.3rem;
}
.pc-req { color: #be123c; }
.pc-input {
  display: block;
  width: 100%;
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
  padding: 0.55rem 0.8rem;
  font-size: 0.85rem;
  color: var(--lg-ink);
  background: var(--lg-card);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-input::placeholder { color: var(--lg-faint); }
.pc-input:hover:not(:disabled) { border-color: rgba(47, 111, 179, 0.55); }
.pc-input:focus { outline: none; border-color: #2f6fb3; box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18); }
.pc-input:disabled, .pc-input.is-readonly { background: var(--lg-soft); color: var(--lg-mute); cursor: not-allowed; }
.pc-input.is-readonly { font-weight: 800; }
textarea.pc-input { resize: vertical; }

.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.pc-form :deep(select),
.pc-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.pc-form :deep(select:focus),
.pc-form :deep(textarea:focus) {
  border-color: #2f6fb3 !important;
  box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18) !important;
}
html.dark .pc-form :deep(input),
html.dark .pc-form :deep(select),
html.dark .pc-form :deep(textarea) { color-scheme: dark; }

.pc-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; accent-color: #2f6fb3; flex: none; }

.pc-info { display: inline-flex; align-items: center; color: var(--lg-faint); cursor: help; text-transform: none; letter-spacing: 0; }
.pc-info:hover { color: var(--lg-navy); }
html.dark .pc-info:hover { color: #93c5fd; }
.pc-info.lg-tip::after { right: auto; left: 50%; width: 240px; margin-left: -120px; z-index: 300; font-weight: 600; }
@media (max-width: 640px) { .pc-info.lg-tip::after { margin-left: -60px; width: 220px; } }

.pc-linkbtn { display: inline-flex; align-items: center; gap: 4px; padding: 0 !important; min-height: 0 !important; font-size: 0.74rem; font-weight: 800; color: var(--lg-navy); }
.pc-linkbtn:hover { text-decoration: underline; }
html.dark .pc-linkbtn { color: #93c5fd; }

.pc-sec { padding: 0.9rem 1rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.pc-sec-title { display: flex; align-items: center; gap: 7px; margin-bottom: 0.75rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-navy); }
html.dark .pc-sec-title { color: #93c5fd; }

.pc-sm { padding: 6px 12px; font-size: 0.72rem; }

.pc-file { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 6px 12px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: background 0.15s ease, transform 0.15s ease; }
.pc-file:hover { transform: translateY(-1px); background: rgba(47, 111, 179, 0.12); }
.pc-file > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
html.dark .pc-file { color: #bfdbfe; }

.pc-filelist { border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.pc-filelist li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; font-size: 0.82rem; color: var(--lg-ink); border-bottom: 1px solid var(--lg-line); }
.pc-filelist li:last-child { border-bottom: 0; }
.pc-filelist li > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---------- dialogs (teleported to body) ---------- */
.pc-overlay { --pc-solid: #ffffff; position: fixed; inset: 0; z-index: 200; display: flex; justify-content: center; padding: 1rem; overflow-y: auto; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); }
html.dark .pc-overlay { --pc-solid: #0f172a; }
.pc-dialog { display: flex; flex-direction: column; width: 100%; max-width: 30rem; margin: auto; border-radius: 22px; background: var(--pc-solid); border: 1px solid var(--lg-line); box-shadow: 0 30px 70px -20px rgba(15, 23, 42, 0.55); }
.pc-dialog-wide { max-width: 38rem; }
.pc-dialog-sm { max-width: 24rem; }
.pc-dialog-head { display: flex; align-items: center; gap: 12px; padding: 1.05rem 1.3rem; border-bottom: 1px solid var(--lg-line); }
.pc-dialog-body { padding: 1.1rem 1.3rem 1.2rem; }
.pc-dialog-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; padding: 0.85rem 1.3rem; border-top: 1px solid var(--lg-line); background: var(--lg-soft); border-radius: 0 0 22px 22px; }
.pc-dialog-center { align-items: center; text-align: center; padding: 1.6rem 1.4rem 1.3rem; gap: 4px; }
.pc-dialog-center .pc-dialog-foot { align-self: stretch; margin: 1rem -1.4rem -1.3rem; justify-content: center; }
.pc-stack > * + * { margin-top: 0.85rem; }


/* ---------- Show ---------- */
.pc-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 1rem; }
.pc-split { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0 1rem; align-items: start; }
@media (min-width: 1024px) { .pc-split.has-side { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); } }
.pc-side { min-width: 0; }
.pc-main-card { min-width: 0; }

.pc-faint { color: var(--lg-faint); }
.pc-wrap { white-space: normal; }
.pc-end { justify-content: flex-start; }
.pc-mini { width: 24px; height: 24px; border-radius: 8px; }
.pc-emph td { font-weight: 800; background: var(--lg-soft); color: var(--lg-navy); }
html.dark .pc-emph td { color: #bfdbfe; }
.pc-warn { color: #b45309; }
html.dark .pc-warn { color: #fcd34d; }
.pc-hint { margin-top: 0.35rem; font-size: 0.74rem; color: var(--lg-mute); }
.pc-amt { display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap; }
.pc-cur { padding: 0 8px; font-size: 0.66rem; }
.pc-empty-box { border: 1px dashed var(--lg-line); border-radius: 16px; }
.pc-disabled { color: var(--lg-faint); background: var(--lg-soft); cursor: not-allowed; }
.pc-disabled:hover { transform: none; background: var(--lg-soft); }

.pc-total { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 0.75rem; padding: 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.pc-total-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }
.pc-total-val { font-size: 1.15rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #047857; text-align: right; }
html.dark .pc-total-val { color: #6ee7b7; }

/* Revision history timeline */
.pc-timeline { position: relative; margin: 0; padding: 0 0 0 18px; list-style: none; }
.pc-timeline::before { content: ''; position: absolute; left: 5px; top: 8px; bottom: 8px; width: 2px; border-radius: 2px; background: var(--lg-line); }
.pc-timeline li { position: relative; }
.pc-tl-dot { position: absolute; left: -18px; top: 50%; width: 12px; height: 12px; margin-top: -6px; border-radius: 9999px; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 0 0 3px var(--lg-card); }
.pc-rev-row { width: 100%; display: flex; align-items: center; gap: 10px; text-align: left; padding: 0.6rem 0.65rem; margin-bottom: 4px; border-radius: 14px; border: 1px solid transparent; transition: background 0.15s ease, border-color 0.15s ease; }
.pc-rev-row:hover { background: var(--lg-soft); border-color: var(--lg-line); }
.pc-rev-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.pc-rev-reason { font-size: 0.83rem; font-weight: 600; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pc-rev-meta { margin-top: 1px; font-size: 0.7rem; color: var(--lg-faint); }
.pc-rev-chev { flex: none; color: var(--lg-faint); }

/* Revision diff */
.pc-diff-table { white-space: normal; }
.pc-diff-table td { vertical-align: top; }
.pc-diff-label { font-size: 0.78rem; color: var(--lg-mute); }
.pc-diff-old { text-align: right; color: #be123c; text-decoration: line-through; text-decoration-color: rgba(190, 18, 60, 0.4); word-break: break-word; }
.pc-diff-new { text-align: right; color: #047857; font-weight: 800; word-break: break-word; }
.pc-diff-arrow { text-align: center; padding-left: 4px !important; padding-right: 4px !important; color: var(--lg-faint); }
html.dark .pc-diff-old { color: #fda4af; }
html.dark .pc-diff-new { color: #6ee7b7; }

/* Teleported tooltip for the disabled "edit payment" icon */
.pc-tip-bubble-fixed {
  position: fixed; transform: translate(-100%, -100%);
  width: min(280px, calc(100vw - 2rem)); white-space: normal; text-align: left;
  padding: 0.6rem 0.75rem; border-radius: 0.75rem; font-size: 0.72rem; font-weight: 600; line-height: 1.4;
  background: #0f172a; color: #f1f5f9; box-shadow: 0 12px 28px -8px rgba(15, 23, 42, 0.55);
  z-index: 300; pointer-events: none;
}
html.dark .pc-tip-bubble-fixed { background: #1e293b; }

@media (prefers-reduced-motion: reduce) { .pc-rev-row { transition: none; } }
</style>
