const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default [
  {
    id: 'excavation-volume',
    category: 'excavation',
    label: 'Excavation Volume',
    keywords: ['excavation', 'volume', 'dig', 'bulking'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'depth', label: 'Depth', unit: 'm' },
      { key: 'bulking', label: 'Bulking Factor', default: 1.25 },
    ],
    notes: 'Bulking factor estimates the loose (disturbed) soil volume for disposal/haulage.',
    compute(v) {
      const bank = num(v.length) * num(v.width) * num(v.depth);
      return [
        { label: 'Bank Volume', value: bank.toFixed(3), unit: 'm³' },
        { label: 'Loose Volume (bulked)', value: (bank * num(v.bulking)).toFixed(3), unit: 'm³' },
      ];
    },
  },
  {
    id: 'excavation-backfill',
    category: 'excavation',
    label: 'Backfilling Quantity',
    keywords: ['backfill', 'excavation', 'volume'],
    fields: [
      { key: 'excavationVolume', label: 'Excavation Volume', unit: 'm³' },
      { key: 'structureVolume', label: 'Structure / Foundation Volume', unit: 'm³' },
    ],
    compute(v) {
      const backfill = Math.max(num(v.excavationVolume) - num(v.structureVolume), 0);
      return [{ label: 'Backfill Volume', value: backfill.toFixed(3), unit: 'm³' }];
    },
  },
  {
    id: 'excavation-cut-fill',
    category: 'excavation',
    label: 'Cut & Fill',
    keywords: ['cut', 'fill', 'earthwork', 'balance'],
    fields: [
      { key: 'cutVolume', label: 'Cut Volume', unit: 'm³' },
      { key: 'fillVolume', label: 'Fill Volume', unit: 'm³' },
    ],
    compute(v) {
      const net = num(v.cutVolume) - num(v.fillVolume);
      return [
        { label: net >= 0 ? 'Surplus (to remove)' : 'Shortfall (to import)', value: Math.abs(net).toFixed(3), unit: 'm³' },
      ];
    },
  },
];
