const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default [
  {
    id: 'rebar-weight',
    category: 'rebar',
    label: 'Rebar Weight',
    keywords: ['rebar', 'steel', 'weight', 'bar'],
    fields: [
      { key: 'diameter', label: 'Bar Diameter', unit: 'mm' },
      { key: 'length', label: 'Length per Bar', unit: 'm' },
      { key: 'quantity', label: 'Number of Bars', default: 1 },
    ],
    notes: 'Unit weight formula: d² / 162.2 (kg/m), the standard approximation for steel rebar.',
    compute(v) {
      const unitWeight = (num(v.diameter) * num(v.diameter)) / 162.2;
      const total = unitWeight * num(v.length) * num(v.quantity);
      return [
        { label: 'Unit Weight', value: unitWeight.toFixed(3), unit: 'kg/m' },
        { label: 'Total Weight', value: total.toFixed(2), unit: 'kg' },
      ];
    },
  },
  {
    id: 'rebar-length',
    category: 'rebar',
    label: 'Rebar Length',
    keywords: ['rebar', 'length', 'lap'],
    fields: [
      { key: 'bars', label: 'Number of Bars' },
      { key: 'lengthPerBar', label: 'Length per Bar', unit: 'm' },
      { key: 'laps', label: 'Number of Laps', default: 0 },
      { key: 'lapLength', label: 'Lap Length per Lap', unit: 'mm', default: 0 },
    ],
    compute(v) {
      const base = num(v.bars) * num(v.lengthPerBar);
      const lapTotal = (num(v.laps) * num(v.lapLength)) / 1000;
      return [{ label: 'Total Length', value: (base + lapTotal).toFixed(2), unit: 'm' }];
    },
  },
  {
    id: 'rebar-bar-quantity',
    category: 'rebar',
    label: 'Bar Quantity',
    keywords: ['rebar', 'quantity', 'spacing', 'bars'],
    fields: [
      { key: 'span', label: 'Span / Member Length', unit: 'm' },
      { key: 'spacing', label: 'Spacing (c/c)', unit: 'mm' },
    ],
    compute(v) {
      const spacing = num(v.spacing) || 1;
      const quantity = Math.floor((num(v.span) * 1000) / spacing) + 1;
      return [{ label: 'Bars Required', value: quantity, unit: 'bars' }];
    },
  },
  {
    id: 'rebar-binding-wire',
    category: 'rebar',
    label: 'Binding Wire Quantity',
    keywords: ['binding', 'wire', 'rebar', 'steel'],
    fields: [
      { key: 'steelWeight', label: 'Total Rebar Weight', unit: 'kg' },
      { key: 'wirePercent', label: 'Binding Wire Share', unit: '%', default: 1 },
    ],
    notes: 'Rule of thumb: binding wire ≈ 0.8–1% of total steel weight (about 8–10 kg per ton).',
    compute(v) {
      const wire = num(v.steelWeight) * (num(v.wirePercent) / 100);
      return [{ label: 'Binding Wire', value: wire.toFixed(2), unit: 'kg' }];
    },
  },
];
