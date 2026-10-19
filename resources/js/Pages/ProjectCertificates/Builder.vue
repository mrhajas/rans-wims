<template>
  <admin-layout :title="$t('Create Certificate')">
    <div class="lg-page px-4 md:px-0 pc-page">
      <div class="mb-3 print:hidden">
        <Link :href="route('project-certificates.project.index', warehouse.id)" class="pc-back-link">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ warehouse.name }}
        </Link>
      </div>

      <page-header icon="doc-text" :title="$t('New Project Certificate')" :subtitle="warehouse.name + ' · ' + $t('Certification No.') + ' ' + form.certificate_number">
        <span class="lg-chip lg-chip-blue pc-draft-chip">
          <icons name="edit" class="w-4 h-4" />
          {{ $t('Draft') }}
          <span class="pc-tip">
            <icons name="info" class="w-3.5 h-3.5" />
            <span class="pc-tip-bubble pc-tip-bubble-end">{{ $t("This certificate hasn't been submitted yet · only people with access to this project can see or edit it.") }}</span>
          </span>
        </span>
      </page-header>

      <!-- Contract reference card -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('Contract') }}</div>
        </div>
        <div class="lg-fields pc-contract-grid text-sm">
          <div><div class="pc-mini-label">{{ $t('Client') }}</div><div class="font-semibold">{{ contract.client?.name || '—' }}</div></div>
          <div><div class="pc-mini-label">{{ $t('Currency') }}</div><div class="font-semibold">{{ contract.currency }}</div></div>
          <div><div class="pc-mini-label">{{ $t('Original Sum') }}</div><div class="font-semibold">{{ money(contract.original_contract_sum) }}</div></div>
          <div><div class="pc-mini-label">{{ $t('Revised Sum (VAT Exclusive)') }}</div><div class="font-semibold">{{ money(contract.revised_contract_sum) }}</div></div>
          <div>
            <div class="pc-mini-label">
              {{ $t('Approved Addendums') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('Total of every approved Contract Addendum · draft/pending ones are proposals, not yet a binding variation.') }}</span>
              </span>
            </div>
            <div class="font-semibold">{{ money(addendumStats.total_addendum_sum) }}</div>
          </div>
          <div>
            <div class="pc-mini-label">{{ $t('Total Project Amount') }}</div>
            <div class="font-semibold text-[#7f1d1d]">{{ money(addendumStats.total_project_amount) }}</div>
          </div>
        </div>
      </section>

      <!-- Certificate details -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="edit" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('Certificate Details') }}</div>
        </div>
        <div class="lg-fields">
          <div>
            <label class="pc-label">
              {{ $t('Certification No.') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t("Assigned automatically in order for this project · it can't be changed here.") }}</span>
              </span>
            </label>
            <input :value="form.certificate_number" type="text" class="pc-input pc-input-readonly" readonly disabled />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Certificate Type') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('What kind of certificate this is. Most monthly valuations use Interim Payment Certificate.') }}</span>
              </span>
            </label>
            <auto-complete v-model="form.certificate_type" :suggestions="certificateTypes" freeform />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Period Covered') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('The billing period this certificate covers, e.g. "June – July 2026".') }}</span>
              </span>
            </label>
            <input v-model="form.period_covered" type="text" class="pc-input" :placeholder="$t('e.g. Jun – Jul 2026')" />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Valuation Date') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('The date the works on site were measured and assessed.') }}</span>
              </span>
            </label>
            <date-picker v-model="form.valuation_date" />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Certificate Date') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('The official date printed on this certificate.') }}</span>
              </span>
            </label>
            <date-picker v-model="form.certificate_date" />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Payment Due Date') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('The date payment against this certificate is expected by.') }}</span>
              </span>
            </label>
            <date-picker v-model="form.payment_due_date" />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Currency') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t("The currency this certificate's figures are entered in.") }}</span>
              </span>
            </label>
            <auto-complete v-model="form.currency" :suggestions="currencyOptions" />
          </div>
          <div v-if="!isTzs">
            <label class="pc-label">
              {{ $t('Exchange Rate') }} (1 {{ form.currency }} = ? TZS)
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t("Today's rate, used to convert this certificate's total into TZS for reporting.") }}</span>
              </span>
            </label>
            <input v-model.number="form.exchange_rate" type="number" step="0.000001" min="0" class="pc-input" :placeholder="$t('Rate for the day')" />
          </div>
          <div class="lg-span-all">
            <label class="pc-label">
              {{ $t('Description') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('A short note about what this certificate covers (optional).') }}</span>
              </span>
            </label>
            <textarea v-model="form.description" class="pc-input" rows="2"></textarea>
          </div>
          <div class="lg-span-all">
            <label class="pc-label">
              {{ $t('Attachments') }}
              <span class="pc-tip">
                <icons name="info" class="w-3 h-3" />
                <span class="pc-tip-bubble">{{ $t('Measurement sheets, site photos, or any supporting documents for this certificate (optional).') }}</span>
              </span>
            </label>
            <attachment-picker v-model="form.attachments" :max-files="10" :help-text="$t('PDF, DOC, DOCX, JPG, JPEG, or PNG · up to 10 files.')" />
          </div>
        </div>
      </section>

      <!-- Calculation: Works & Inputs -->
      <section class="lg-card">
        <div class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('Works & Inputs') }}</div>
          <button type="button" class="pc-quick-btn pc-head-end" @click="addInputLine">
            <icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Add Input Line') }}
          </button>
        </div>

        <p v-if="prefilledFromCertificateNumber" class="lg-note lg-note-blue pc-prefill-note">
          <icons name="info" class="w-3.5 h-3.5" />
          {{ $t('Structure copied from Certificate No.') }} {{ prefilledFromCertificateNumber }}. {{ $t("Amount fields below start at 0 · this period's figures still need entering.") }}
        </p>

        <div v-for="(line, idx) in inputLines" :key="line.line_key" class="pc-line-row" :class="{ 'pc-line-row-error': lineHasError(inputAbsIndex(idx)) }">
          <div class="pc-line-num">
            {{ idx + 1 }}
            <icons v-if="lineHasError(inputAbsIndex(idx))" name="exclamation" className="w-3.5 h-3.5 pc-line-error-flag" />
          </div>
          <div class="pc-line-fields">
            <input v-model="line.label" type="text" class="pc-input pc-line-label" :class="{ 'pc-input-error': lineFieldError(inputAbsIndex(idx), 'label') }" :placeholder="$t('Line description')" />

            <template v-if="!hasChildren(line)">
              <input v-model.number="line.value" type="number" step="0.01" class="pc-input pc-line-value" :class="{ 'pc-input-error': lineFieldError(inputAbsIndex(idx), 'value') }" />
              <button type="button" class="pc-add-term" @click="addChild(line)">+ {{ $t('Break') }}</button>
            </template>
            <div v-else class="pc-line-value pc-line-value-computed">{{ moneyCert(inputLineValue(line)) }}</div>

            <p v-if="lineHasError(inputAbsIndex(idx))" class="pc-line-error-msg">
              {{ lineFieldError(inputAbsIndex(idx), 'label') || lineFieldError(inputAbsIndex(idx), 'value') || $t('This line has an error · check its fields.') }}
            </p>

            <div v-if="hasChildren(line)" class="pc-children">
              <div v-for="(child, ci) in line.operands.children" :key="ci" class="pc-child-row">
                <span class="pc-child-num">{{ idx + 1 }}{{ letter(ci) }}</span>
                <input v-model="child.label" type="text" class="pc-input pc-child-label" :placeholder="$t('Sub-item description')" />
                <input v-model.number="child.value" type="number" step="0.01" class="pc-input pc-child-value" />
                <button type="button" class="pc-term-remove" @click="removeChild(line, ci)">&times;</button>
              </div>
              <button type="button" class="pc-add-term" @click="addChild(line)">+ {{ $t('sub-item') }}</button>
            </div>
          </div>
          <div class="pc-line-computed">{{ moneyCert(inputLineValue(line)) }}</div>
          <button type="button" class="pc-line-remove" @click="removeInputLine(idx)"><icons name="trash" class="w-3.5 h-3.5" /></button>
        </div>

        <div class="pc-line-row pc-line-emphasis">
          <div class="pc-line-num"></div>
          <div class="pc-line-fields"><span class="font-bold">{{ $t('Subtotal') }}</span></div>
          <div class="pc-line-computed">{{ moneyCert(computed['auto_subtotal'] ?? 0) }}</div>
          <span style="width:15.5px" class="flex-none"></span>
        </div>
      </section>

      <!-- Calculation: Additions, Deductions & References -->
      <section class="lg-card">
        <div class="lg-card-head flex-wrap">
          <span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('Additions, Deductions & References') }}</div>
          <div class="flex items-center gap-2 flex-wrap pc-head-end">
            <button type="button" class="pc-quick-btn pc-quick-btn-addition" @click="addAdjustmentLine('addition')"><icons name="up" class="w-3.5 h-3.5" /> {{ $t('Addition') }} (+)</button>
            <button type="button" class="pc-quick-btn pc-quick-btn-deduction" @click="addAdjustmentLine('deduction')"><icons name="down" class="w-3.5 h-3.5" /> {{ $t('Deduction') }} (&minus;)</button>
            <button type="button" class="pc-quick-btn pc-quick-btn-reference" @click="addAdjustmentLine('reference')"><icons name="link" class="w-3.5 h-3.5" /> {{ $t('Reference') }} (&plusmn;)</button>
          </div>
        </div>

        <p v-if="!adjustmentLines.length" class="lg-empty pc-empty">{{ $t('No additions, deductions, or references yet.') }}</p>

        <div v-for="(line, idx) in adjustmentLines" :key="line.line_key" class="pc-line-row" :class="{ 'pc-line-row-error': lineHasError(adjustmentAbsIndex(idx)) }">
          <div class="pc-line-num">
            {{ inputLines.length + idx + 1 }}
            <icons v-if="lineHasError(adjustmentAbsIndex(idx))" name="exclamation" className="w-3.5 h-3.5 pc-line-error-flag" />
          </div>
          <div class="pc-line-fields">
            <input v-model="line.label" type="text" class="pc-input pc-line-label" :class="{ 'pc-input-error': lineFieldError(adjustmentAbsIndex(idx), 'label') }" :placeholder="$t('Line description')" />
            <span class="pc-adjustment-type-badge" :class="'pc-adj-' + line.type">{{ typeLabel(line.type) }}</span>

            <template v-if="line.type === 'addition' || line.type === 'deduction'">
              <div style="width:200px">
                <auto-complete v-model="line.operands.terms[0].ref" :suggestions="mirrorOptions(line)" :placeholder="$t('Which label?')" :error="lineFieldError(adjustmentAbsIndex(idx), 'operands')" />
              </div>
            </template>

            <template v-else>
              <div style="width:190px">
                <auto-complete :model-value="line.operands.source" :suggestions="referenceSourceOptions" @update:model-value="v => setReferenceSource(line, v)" :error="lineFieldError(adjustmentAbsIndex(idx), 'operands.source')" />
              </div>
              <template v-if="line.operands.source === 'other'">
                <input v-model="line.operands.reference_number" type="text" class="pc-input" style="width:140px" :placeholder="$t('Reference No.')" />
                <input v-model.number="line.value" type="number" step="0.01" class="pc-input pc-line-value" :placeholder="$t('Amount')" />
              </template>
              <input
                v-else type="text" class="pc-input pc-input-readonly pc-line-value" readonly disabled
                :value="line.operands.source ? moneyCert(referenceSourceValue(line.operands.source)) : $t('Select a source…')"
              />
              <button
                type="button" class="pc-sign-toggle" :class="line.operands.sign === '+' ? 'pc-sign-plus' : 'pc-sign-minus'"
                @click="line.operands.sign = line.operands.sign === '+' ? '-' : '+'"
              >
                {{ line.operands.sign === '+' ? ('+ ' + $t('Add')) : ('− ' + $t('Deduct')) }}
              </button>
            </template>

            <p v-if="lineHasError(adjustmentAbsIndex(idx))" class="pc-line-error-msg">
              {{ lineFieldError(adjustmentAbsIndex(idx), 'label') || lineFieldError(adjustmentAbsIndex(idx), 'operands') || lineFieldError(adjustmentAbsIndex(idx), 'operands.source') || lineFieldError(adjustmentAbsIndex(idx), 'value') || $t('This line has an error · check its fields.') }}
            </p>
          </div>
          <div class="pc-line-computed">{{ moneyCert(computed[line.line_key] ?? 0) }}</div>
          <button type="button" class="pc-line-remove" @click="removeAdjustmentLine(idx)"><icons name="trash" class="w-3.5 h-3.5" /></button>
        </div>

        <div class="pc-line-row pc-line-emphasis">
          <div class="pc-line-num"></div>
          <div class="pc-line-fields"><span class="font-bold">{{ $t('Total / Net Payment') }}</span></div>
          <div class="pc-line-computed pc-total-computed">{{ moneyCert(computed['auto_total'] ?? 0) }}</div>
          <span style="width:15.5px" class="flex-none"></span>
        </div>

        <p v-if="engineError" class="lg-note lg-note-red pc-error-note">{{ engineError }}</p>
      </section>

      <!-- Sticky summary -->
      <div class="lg-actionbar pc-summary-bar">
        <div class="flex items-end gap-6 flex-wrap">
          <div>
            <div class="pc-mini-label">{{ $t('Net Payment (last line)') }}</div>
            <div class="pc-net-payment-value">{{ moneyCert(lastLineValue) }}</div>
          </div>
          <div v-if="!isTzs">
            <div class="pc-mini-label">{{ $t('Amount in TZS') }}</div>
            <div class="text-lg font-bold pc-tzs">{{ moneyIn(lastLineValue * (Number(form.exchange_rate) || 0), 'TZS') }}</div>
          </div>
        </div>
        <div class="lg-actionbar-btns">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="saving" @click="save(false)">
            <icons name="doc-text" class="w-4 h-4" /> {{ $t('Save Draft') }}
          </button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="saving" @click="save(true)">
            <icons name="tick" class="w-4 h-4" /> {{ saving ? $t('Saving…') : $t('Save & Continue') }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, AttachmentPicker, DatePicker, PageHeader },

  props: {
    warehouse: Object,
    contract: Object,
    addendumStats: { type: Object, default: () => ({ total_contract_sum: 0, total_addendum_sum: 0, total_project_amount: 0 }) },
    nextCertificateNumber: Number,
    defaultLines: Array,
    prefilledFromCertificateNumber: Number,
    previousCertificateCumulative: { type: Number, default: 0 },
    previousCertificateNetPaid: { type: Number, default: 0 },
    lineTypes: Array,
    currencies: Array,
  },

  data() {
    return {
      saving: false,
      engineError: '',
      form: {
        certificate_number: this.nextCertificateNumber,
        certificate_type: 'Interim Payment Certificate',
        period_covered: '',
        valuation_date: this.todayIso(),
        certificate_date: this.todayIso(),
        payment_due_date: this.todayIso(),
        description: '',
        notes: '',
        currency: this.contract.currency || 'TZS',
        exchange_rate: null,
        attachments: [],
      },
      // Only 4 QS-facing types now — Input, Addition, Deduction, Reference.
      // Subtotal/Total are never stored in either array; they're derived
      // (autoSubtotalLine/autoTotalLine below) and injected only at submit
      // time, so the QS never has to build or maintain a formula by hand.
      inputLines: JSON.parse(JSON.stringify(this.defaultLines)).filter(l => l.type === 'input'),
      adjustmentLines: JSON.parse(JSON.stringify(this.defaultLines)).filter(l => l.type !== 'input').map(l => ({ ...l, operands: l.operands ?? {} })),
      // At least five real certificate types beyond the default, matching
      // how RANS actually names these documents — freeform so a QS can
      // still type something else if a project genuinely needs it.
      certificateTypes: [
        'Interim Payment Certificate',
        'Advance Payment Certificate',
        'Final Payment Certificate',
        'Retention Release Certificate',
        'Practical Completion Certificate',
        'Making Good Defects Certificate',
        'Variation Certificate',
      ],
      // Deliberately trimmed to the 4 sources a QS actually needs — see
      // referenceContext() in CertificateCalculationEngine for the full
      // set this still resolves against server-side. "Other" is the
      // fully-manual escape hatch: a free-text reference number paired
      // with a hand-typed amount, for anything not covered by the 3
      // automatic sources.
      referenceSourceOptions: [
        { id: 'contract_revised_sum', label: this.$t('Contract Sum') },
        { id: 'previous_certificate_cumulative', label: this.$t('Previous Certificate') },
        { id: 'contract_variation_amount', label: this.$t('Contract Variation') },
        { id: 'other', label: this.$t('Other (Enter Manually)') },
      ],
    };
  },

  computed: {
    // A Subtotal line is never picked by the QS — it's derived from every
    // Input line above it and injected at submit time (and into the live
    // preview below), so the running total always stays in sync with
    // whatever inputs currently exist.
    autoSubtotalLine() {
      return {
        line_key: 'auto_subtotal',
        label: this.$t('Subtotal'),
        type: 'subtotal',
        operands: { terms: this.inputLines.map(l => ({ ref: l.line_key, operator: '+' })) },
        value: 0,
      };
    },

    // Likewise, the final Total/Net Payment line is derived — Subtotal,
    // plus every Addition (mirrors its picked label, +), minus every
    // Deduction (mirrors its picked label, −), plus/minus every Reference
    // at whatever sign the QS chose for it.
    autoTotalLine() {
      const terms = [{ ref: 'auto_subtotal', operator: '+' }];
      this.adjustmentLines.forEach((l) => {
        if (l.type === 'addition' || l.type === 'deduction') {
          terms.push({ ref: l.line_key, operator: l.type === 'deduction' ? '-' : '+' });
        } else {
          terms.push({ ref: l.line_key, operator: l.operands?.sign === '+' ? '+' : '-' });
        }
      });

      return { line_key: 'auto_total', label: this.$t('Total / Net Payment'), type: 'total', operands: { terms }, value: 0 };
    },

    // The full graph actually sent to the server — inputs (rolled up if
    // they have sub-items), the auto Subtotal, every addition/deduction/
    // reference, then the auto Total. Order here IS the sort_order/lines.N
    // index used everywhere else on this page (save(), lineFieldError()).
    allLines() {
      return [
        ...this.inputLines.map(l => ({ ...l, value: this.inputLineValue(l) })),
        this.autoSubtotalLine,
        ...this.adjustmentLines,
        this.autoTotalLine,
      ];
    },

    // Client-side preview only — the authoritative bcmath evaluation always
    // happens server-side on save (CertificateCalculationEngine). This just
    // gives the QS live feedback while building the structure.
    computed() {
      const result = {};
      const byKey = Object.fromEntries(this.allLines.map(l => [l.line_key, l]));
      const visited = new Set();

      const resolve = (key) => {
        if (key in result) return result[key];
        if (visited.has(key)) return 0; // cycle — leave for server-side validation to reject
        visited.add(key);
        const line = byKey[key];
        if (!line) return 0;

        let value = 0;
        if (line.type === 'input') {
          value = Number(line.value) || 0;
        } else if (line.type === 'reference') {
          // The 3 automatic sources resolve from data already on this page
          // (this.contract / previousCertificateCumulative) — kept in sync
          // with CertificateCalculationEngine::referenceContext(), the
          // authoritative server-side resolution used on save. "other" is
          // the manually-typed escape hatch.
          value = this.referenceSourceValue(line.operands?.source, line.value);
        } else {
          value = (line.operands?.terms || []).reduce((sum, term) => {
            const termValue = term.ref ? resolve(term.ref) : (Number(term.value) || 0);

            return term.operator === '-' ? sum - termValue : sum + termValue;
          }, 0);
        }

        result[key] = Math.round(value * 100) / 100;

        return result[key];
      };

      this.allLines.forEach(l => resolve(l.line_key));

      return result;
    },

    lastLineValue() {
      return this.computed.auto_total ?? 0;
    },

    // "TZS Tanzanian Shilling" — no dash — per the currency picker on this
    // page specifically; Currencies::all()'s own `label` (used elsewhere in
    // the app) keeps its "CODE — Name" format, so it's built fresh here
    // rather than changed at the source.
    currencyOptions() {
      return this.currencies.map(c => ({ id: c.code, label: `${c.code} ${c.name}` }));
    },

    isTzs() {
      return (this.form.currency || 'TZS').toUpperCase() === 'TZS';
    },
  },

  methods: {
    todayIso() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    },

    money(v) {
      return (this.contract.currency || 'TZS') + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    // Calculation-line values and the running total are entered in the
    // certificate's own currency (form.currency), which can differ from
    // the contract's fixed currency shown on the Contract card above.
    moneyCert(v) {
      return (this.form.currency || 'TZS') + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    moneyIn(v, currency) {
      return currency + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    typeLabel(t) {
      return { input: 'Input', addition: 'Addition', deduction: 'Deduction', reference: 'Reference' }[t] || t;
    },

    // A unique-enough key for a brand-new line — not persisted anywhere
    // meaningful until save, just needs to not collide with anything else
    // already on this certificate.
    newLineKey() {
      return 'line_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    },

    addInputLine() {
      this.inputLines.push({ line_key: this.newLineKey(), label: '', type: 'input', operands: null, value: 0 });
    },

    removeInputLine(idx) {
      this.inputLines.splice(idx, 1);
    },

    hasChildren(line) {
      return !!(line.operands && line.operands.children && line.operands.children.length);
    },

    // A line with sub-items has no directly-typed value of its own — it's
    // always the sum of its children, so the two can never drift apart.
    inputLineValue(line) {
      if (! this.hasChildren(line)) {
        return Number(line.value) || 0;
      }

      return line.operands.children.reduce((sum, c) => sum + (Number(c.value) || 0), 0);
    },

    addChild(line) {
      if (! this.hasChildren(line)) {
        line.operands = { children: [] };
      }
      line.operands.children.push({ label: '', value: 0 });
    },

    removeChild(line, idx) {
      line.operands.children.splice(idx, 1);
      if (! line.operands.children.length) {
        line.operands = null;
      }
    },

    // Sub-item numbering — line 2's children are 2A, 2B, 2C…
    letter(i) {
      return String.fromCharCode(65 + i);
    },

    // "Which label" candidates for an Addition/Deduction line — any other
    // Input/Addition/Deduction line, but never a Reference. A Reference
    // already feeds the Total directly at its own sign (see
    // autoTotalLine); letting an Addition/Deduction also mirror one would
    // double-count it.
    mirrorOptions(line) {
      return [...this.inputLines, ...this.adjustmentLines]
        .filter(l => l.line_key !== line.line_key && l.type !== 'reference')
        .map(l => ({ id: l.line_key, label: l.label || l.line_key }));
    },

    addAdjustmentLine(type) {
      const line_key = this.newLineKey();
      if (type === 'reference') {
        this.adjustmentLines.push({ line_key, label: '', type, operands: { source: 'previous_certificate_cumulative', sign: '-' }, value: 0 });
      } else {
        // The term's own operator always mirrors the picked label as-is
        // (+) — Addition/Deduction's sign is applied exactly once, by
        // autoTotalLine below, when this line rolls into the Total.
        // Baking the sign in here too would double-apply it (a Deduction
        // would net out as an addition once summed into the Total).
        this.adjustmentLines.push({ line_key, label: '', type, operands: { terms: [{ ref: null, value: 0, operator: '+' }] }, value: 0 });
      }
    },

    removeAdjustmentLine(idx) {
      this.adjustmentLines.splice(idx, 1);
    },

    // Switching source away from "other" drops any manually-typed amount —
    // the server computes it instead, so a stale manual figure left behind
    // would be misleading (even though it's never actually submitted as
    // the value the engine uses for a source-based reference).
    setReferenceSource(line, source) {
      line.operands.source = source;
      if (source === 'other') {
        line.operands.reference_number = line.operands.reference_number || '';
      } else {
        line.value = 0;
      }
    },

    // The live-preview value for a Reference line's chosen source — must
    // agree with CertificateCalculationEngine::referenceContext(), the
    // authoritative resolution the server re-runs on save.
    referenceSourceValue(source, manualValue) {
      switch (source) {
        // Addendum-inclusive — must agree with CertificateCalculationEngine
        // ::referenceContext()'s own "current total, not just the bare
        // revised sum" rule for this exact key.
        case 'contract_revised_sum': return Number(this.addendumStats?.total_project_amount) || 0;
        case 'contract_variation_amount': return Number(this.contract.contract_variation_amount) || 0;
        case 'contract_original_sum': return Number(this.contract.original_contract_sum) || 0;
        case 'previous_certificate_cumulative': return Number(this.previousCertificateCumulative) || 0;
        // Not offered in referenceSourceOptions below (the QS-facing
        // dropdown is deliberately trimmed to 4 choices), but a certificate
        // carried forward via linesFromPrevious() from an older certificate
        // could still have a line stuck on one of these two — resolving them
        // here too keeps that line's preview correct instead of silently 0.
        case 'previous_certificate_net_paid': return Number(this.previousCertificateNetPaid) || 0;
        case 'other': return Number(manualValue) || 0;
        default: return 0;
      }
    },

    save(andEvaluate) {
      this.engineError = '';

      if (!this.isTzs && !this.form.exchange_rate) {
        this.engineError = this.$t('Enter the day’s exchange rate to convert this certificate to TZS.');
        return;
      }

      this.saving = true;

      const payload = {
        ...this.form,
        lines: this.allLines.map((l, i) => ({
          line_key: l.line_key, label: l.label, type: l.type, sort_order: i + 1, operands: l.operands ?? null, value: l.value ?? 0,
        })),
      };

      this.$inertia.post(this.route('project-certificates.store', this.warehouse.id), payload, {
        onError: (errors) => {
          // Field-level errors (lines.N.*) render inline next to each row/
          // field below via lineFieldError() — this banner is only for a
          // top-level summary, or a genuinely non-line error (e.g.
          // certificate_date) that has nowhere else to attach.
          const lineErrorKeys = Object.keys(errors).filter(k => k.startsWith('lines.'));
          if (lineErrorKeys.length) {
            this.engineError = this.$t('Some calculation lines need fixing · see the highlighted fields below.');
          } else {
            this.engineError = errors.lines || Object.values(errors)[0] || this.$t('Please check the form for errors.');
          }
        },
        onFinish: () => { this.saving = false; },
      });
    },

    // allLines() lays the submitted array out as [...inputs, autoSubtotal,
    // ...adjustments, autoTotal] — these translate a row's position within
    // its own section back into that absolute lines.N index, so error
    // lookups line up with what the server actually validated.
    inputAbsIndex(idx) {
      return idx;
    },
    adjustmentAbsIndex(idx) {
      return this.inputLines.length + 1 + idx;
    },

    // Looks up a field-level validation error for one calculation line —
    // e.g. lineFieldError(2, 'operands.rate') reads $page.props.errors['lines.2.operands.rate'].
    // Reads $page.props.errors directly (not a local copy) so it stays
    // reactive across repeated failed saves without any extra bookkeeping.
    lineFieldError(idx, field) {
      return this.$page.props.errors?.[`lines.${idx}.${field}`] || '';
    },
    // A line-level error can land on a few different specific field keys
    // depending on which check caught it (line_key, operands, operands.source,
    // operands.rate) — this checks all of them so the row itself can be
    // outlined red regardless of which one actually fired.
    lineHasError(idx) {
      const errors = this.$page.props.errors || {};

      return Object.keys(errors).some(k => k.startsWith(`lines.${idx}.`));
    },
  },
};
</script>

<style scoped>
.pc-back-link { display: inline-flex; align-items: center; gap: 4px; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.pc-back-link:hover { color: var(--lg-navy); }
html.dark .pc-back-link:hover { color: #bfdbfe; }

.pc-mini-label { font-size: 0.65rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.15rem; }
.pc-contract-grid { grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); }
.pc-head-end { margin-left: auto; }
.pc-empty { padding: 18px 0 !important; text-align: center; font-size: 0.85rem; }
.pc-draft-chip { padding: 5px 14px; font-size: 0.82rem; }
.pc-tzs { color: var(--lg-mute); }

.pc-label { display: flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 700; color: var(--lg-mute); margin-bottom: 0.3rem; }
.pc-input { border: 1px solid var(--lg-line); border-radius: 0.75rem; padding: 0.55rem 0.8rem; font-size: 0.83rem; background: var(--lg-card); color: var(--lg-ink); }
.pc-input:focus { outline: none; border-color: #1c3f66; box-shadow: 0 0 0 3px rgba(28, 63, 102, 0.12); }
.pc-input:where(textarea, select, input):not([class*="pc-line"]):not([class*="pc-term"]):not([class*="pc-child"]) { width: 100%; }
.pc-input-readonly { background: #f8fafc; color: #6b7280; cursor: not-allowed; font-weight: 700; }

/* Small CSS-only info tooltip used throughout this page's labels */
.pc-tip { position: relative; display: inline-flex; color: #cbd5e1; cursor: help; }
.pc-tip:hover { color: #1c3f66; }
.pc-tip-bubble {
  position: absolute; left: 50%; bottom: calc(100% + 8px); transform: translateX(-50%) translateY(4px);
  width: min(220px, calc(100vw - 2rem)); white-space: normal; text-align: left;
  padding: 0.55rem 0.7rem; border-radius: 0.55rem; font-size: 0.7rem; font-weight: 500; line-height: 1.4;
  background: #0f172a; color: #f1f5f9; box-shadow: 0 12px 28px -8px rgba(15, 23, 42, 0.45);
  opacity: 0; visibility: hidden; pointer-events: none; z-index: 30;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.pc-tip:hover .pc-tip-bubble { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }

.pc-quick-btn { display: inline-flex; align-items: center; gap: 6px; padding: 0.45rem 0.9rem; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; color: #1c3f66; background: #eef2ff; }
.pc-quick-btn:hover { background: #e0e7ff; }
/* Addition/Deduction/Reference quick-add buttons — same colors as their
   line's own type badge (.pc-adj-*), so the button that creates a line
   already previews what that line will look like. */
.pc-quick-btn-addition { color: #1c3f66; background: #eef2ff; }
.pc-quick-btn-addition:hover { background: #e0e7ff; }
.pc-quick-btn-deduction { color: #b91c1c; background: #fef2f2; }
.pc-quick-btn-deduction:hover { background: #fee2e2; }
.pc-quick-btn-reference { color: #92400e; background: #fef3c7; }
.pc-quick-btn-reference:hover { background: #fde68a; }

.pc-line-row { display: flex; align-items: flex-start; gap: 10px; flex-wrap: wrap; padding: 0.6rem 0; border-bottom: 1px solid var(--lg-line); }
.pc-line-emphasis { background: var(--lg-soft); margin: 0 -0.5rem; padding: 0.6rem 0.5rem; border-radius: 0.5rem; }
.pc-line-row-error { background: #fef2f2; margin: 0 -0.5rem; padding: 0.6rem 0.5rem; border-radius: 0.5rem; border: 1px solid #fecaca; }
.pc-line-num { width: 22px; flex: none; color: #94a3b8; font-size: 0.75rem; padding-top: 0.55rem; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.pc-line-error-flag { color: #dc2626; }
.pc-line-error-msg { flex-basis: 100%; font-size: 0.72rem; font-weight: 600; color: #b91c1c; margin-top: 2px; }
.pc-input-error { border-color: #fca5a5 !important; background: #fef2f2; }
.pc-line-fields { flex: 1; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.pc-line-label { flex: 1; min-width: 140px; }
.pc-line-type { width: 160px; flex: none; }
.pc-line-value { flex: 1 1 120px; min-width: 120px; max-width: 190px; }
.pc-line-computed { flex: 1 1 130px; min-width: 130px; max-width: 220px; text-align: right; font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; padding-top: 0.55rem; white-space: nowrap; }
.pc-total-computed { font-size: 1.05rem; font-weight: 800; color: #7f1d1d; }
.pc-line-remove { flex: none; color: #cbd5e1; padding-top: 0.5rem; }
.pc-line-remove:hover { color: #b91c1c; }

.pc-terms { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.pc-term { display: inline-flex; align-items: center; gap: 4px; background: #f8fafc; border-radius: 0.5rem; padding: 3px 4px; }
.pc-term-op { border: none; background: transparent; font-weight: 800; color: #1c3f66; width: 26px; }
.pc-term-remove { color: #cbd5e1; font-size: 1rem; line-height: 1; padding: 0 4px; }
.pc-term-remove:hover { color: #b91c1c; }
.pc-add-term { flex: none; font-size: 0.72rem; font-weight: 700; color: #1c3f66; white-space: nowrap; }

.pc-line-value-computed { width: 190px; flex: none; padding: 0.5rem 0.7rem; font-size: 0.83rem; font-weight: 700; color: #6b7280; }

.pc-adjustment-type-badge {
  flex: none; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em;
  padding: 0.25rem 0.55rem; border-radius: 9999px; color: #1c3f66; background: #eef2ff;
}
.pc-adj-deduction { color: #b91c1c; background: #fef2f2; }
.pc-adj-reference { color: #92400e; background: #fef3c7; }

.pc-sign-toggle { flex: none; font-size: 0.72rem; font-weight: 800; padding: 0.4rem 0.7rem; border-radius: 0.5rem; }
.pc-sign-plus { color: #15803d; background: #dcfce7; }
.pc-sign-minus { color: #b91c1c; background: #fef2f2; }

.pc-children { flex-basis: 100%; display: flex; flex-direction: column; gap: 6px; margin-top: 4px; padding-left: 1.1rem; border-left: 2px solid #eef1f6; }
.pc-child-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pc-child-num { flex: none; width: 26px; font-size: 0.7rem; font-weight: 700; color: #94a3b8; }
.pc-child-label { flex: 1; min-width: 130px; }
.pc-child-value { flex: 1 1 100px; min-width: 100px; max-width: 160px; }

.pc-error-note { margin-top: 0.75rem; font-size: 0.8rem; font-weight: 600; }
.pc-prefill-note { align-items: center; margin-bottom: 0.85rem; font-size: 0.76rem; }
.pc-prefill-note svg { margin-top: 0; }

.pc-summary-bar { margin-top: 0.5rem; }
.pc-net-payment-value { font-size: 1.6rem; font-weight: 800; color: #7f1d1d; line-height: 1.15; font-variant-numeric: tabular-nums; }
html.dark .pc-net-payment-value { color: #fca5a5; }
.pc-tip-bubble-end { left: auto; right: -8px; transform: translateY(4px); }
.pc-tip:hover .pc-tip-bubble-end { transform: translateY(0); }

html.dark .pc-input { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .pc-input-readonly { background: #0f172a; color: #94a3b8; }
html.dark .pc-line-emphasis, html.dark .pc-term { background: #1e293b; }
html.dark .pc-line-row-error { background: rgba(127, 29, 29, 0.15); border-color: rgba(220, 38, 38, 0.4); }
html.dark .pc-input-error { background: rgba(127, 29, 29, 0.15); }
html.dark .pc-line-value-computed { color: #94a3b8; }
html.dark .pc-adjustment-type-badge { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; }
html.dark .pc-adj-deduction { background: rgba(127, 29, 29, 0.2); color: #fca5a5; }
html.dark .pc-adj-reference { background: rgba(146, 64, 14, 0.2); color: #fcd34d; }
html.dark .pc-quick-btn-addition { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; }
html.dark .pc-quick-btn-deduction { background: rgba(127, 29, 29, 0.2); color: #fca5a5; }
html.dark .pc-quick-btn-reference { background: rgba(146, 64, 14, 0.2); color: #fcd34d; }
html.dark .pc-sign-plus { background: rgba(21, 128, 61, 0.2); color: #4ade80; }
html.dark .pc-sign-minus { background: rgba(127, 29, 29, 0.2); color: #fca5a5; }
html.dark .pc-children { border-color: rgba(255, 255, 255, 0.08); }
</style>
