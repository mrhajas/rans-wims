const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default [
  {
    id: 'material-concrete-volume',
    category: 'material',
    label: 'Concrete Volume',
    keywords: ['concrete', 'volume', 'cubic', 'wet', 'dry'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'depth', label: 'Thickness / Depth', unit: 'm' },
    ],
    notes: 'Dry volume uses the standard 1.54× factor to account for voids and bulking during dry mixing.',
    compute(v) {
      const wet = num(v.length) * num(v.width) * num(v.depth);
      return [
        { label: 'Wet Volume', value: wet.toFixed(3), unit: 'm³' },
        { label: 'Dry Volume (×1.54)', value: (wet * 1.54).toFixed(3), unit: 'm³' },
      ];
    },
  },
  {
    id: 'material-cement-bags',
    category: 'material',
    label: 'Cement Bags Required',
    keywords: ['cement', 'bags', 'concrete', 'mix'],
    fields: [
      { key: 'volume', label: 'Wet Concrete Volume', unit: 'm³' },
      { key: 'ratioCement', label: 'Mix Ratio - Cement Part', default: 1 },
      { key: 'ratioSand', label: 'Mix Ratio - Sand Part', default: 2 },
      { key: 'ratioAggregate', label: 'Mix Ratio - Aggregate Part', default: 4 },
    ],
    notes: 'Uses 1.54× dry volume factor, 1440 kg/m³ cement density, and 50 kg bags.',
    compute(v) {
      const dry = num(v.volume) * 1.54;
      const sum = num(v.ratioCement) + num(v.ratioSand) + num(v.ratioAggregate) || 1;
      const cementVolume = dry * (num(v.ratioCement) / sum);
      const cementWeight = cementVolume * 1440;
      const bags = cementWeight / 50;
      return [
        { label: 'Cement Volume', value: cementVolume.toFixed(3), unit: 'm³' },
        { label: 'Cement Weight', value: cementWeight.toFixed(1), unit: 'kg' },
        { label: 'Cement Bags (50kg)', value: Math.ceil(bags), unit: 'bags' },
      ];
    },
  },
  {
    id: 'material-sand-required',
    category: 'material',
    label: 'Sand Required',
    keywords: ['sand', 'concrete', 'mix'],
    fields: [
      { key: 'volume', label: 'Wet Concrete Volume', unit: 'm³' },
      { key: 'ratioCement', label: 'Mix Ratio - Cement Part', default: 1 },
      { key: 'ratioSand', label: 'Mix Ratio - Sand Part', default: 2 },
      { key: 'ratioAggregate', label: 'Mix Ratio - Aggregate Part', default: 4 },
    ],
    notes: 'Uses 1.54× dry volume factor and 1600 kg/m³ typical loose sand density.',
    compute(v) {
      const dry = num(v.volume) * 1.54;
      const sum = num(v.ratioCement) + num(v.ratioSand) + num(v.ratioAggregate) || 1;
      const sandVolume = dry * (num(v.ratioSand) / sum);
      return [
        { label: 'Sand Volume', value: sandVolume.toFixed(3), unit: 'm³' },
        { label: 'Sand Weight (approx.)', value: (sandVolume * 1600).toFixed(1), unit: 'kg' },
      ];
    },
  },
  {
    id: 'material-aggregate-required',
    category: 'material',
    label: 'Aggregate Required',
    keywords: ['aggregate', 'gravel', 'stone', 'concrete', 'mix'],
    fields: [
      { key: 'volume', label: 'Wet Concrete Volume', unit: 'm³' },
      { key: 'ratioCement', label: 'Mix Ratio - Cement Part', default: 1 },
      { key: 'ratioSand', label: 'Mix Ratio - Sand Part', default: 2 },
      { key: 'ratioAggregate', label: 'Mix Ratio - Aggregate Part', default: 4 },
    ],
    notes: 'Uses 1.54× dry volume factor and 1550 kg/m³ typical aggregate density.',
    compute(v) {
      const dry = num(v.volume) * 1.54;
      const sum = num(v.ratioCement) + num(v.ratioSand) + num(v.ratioAggregate) || 1;
      const aggVolume = dry * (num(v.ratioAggregate) / sum);
      return [
        { label: 'Aggregate Volume', value: aggVolume.toFixed(3), unit: 'm³' },
        { label: 'Aggregate Weight (approx.)', value: (aggVolume * 1550).toFixed(1), unit: 'kg' },
      ];
    },
  },
  {
    id: 'material-water-requirement',
    category: 'material',
    label: 'Water Requirement',
    keywords: ['water', 'cement', 'ratio', 'concrete'],
    fields: [
      { key: 'cementBags', label: 'Cement Bags (50kg each)' },
      { key: 'wcRatio', label: 'Water-Cement Ratio', default: 0.5 },
    ],
    notes: '1 kg of water ≈ 1 litre.',
    compute(v) {
      const water = num(v.cementBags) * 50 * num(v.wcRatio);
      return [{ label: 'Water Required', value: water.toFixed(1), unit: 'litres' }];
    },
  },
  {
    id: 'material-concrete-weight',
    category: 'material',
    label: 'Concrete Weight',
    keywords: ['concrete', 'weight', 'density'],
    fields: [
      { key: 'volume', label: 'Volume', unit: 'm³' },
      { key: 'density', label: 'Density', unit: 'kg/m³', default: 2400 },
    ],
    notes: 'Default density 2400 kg/m³ for reinforced concrete.',
    compute(v) {
      const weight = num(v.volume) * num(v.density);
      return [
        { label: 'Weight', value: weight.toFixed(1), unit: 'kg' },
        { label: 'Weight', value: (weight / 1000).toFixed(3), unit: 'tons' },
      ];
    },
  },
  {
    id: 'material-block-quantity',
    category: 'material',
    label: 'Concrete Block Quantity',
    keywords: ['block', 'brick', 'wall', 'masonry'],
    fields: [
      { key: 'wallLength', label: 'Wall Length', unit: 'm' },
      { key: 'wallHeight', label: 'Wall Height', unit: 'm' },
      { key: 'openings', label: 'Openings Area (doors/windows)', unit: 'm²', default: 0 },
      { key: 'blockLength', label: 'Block Length', unit: 'mm', default: 400 },
      { key: 'blockHeight', label: 'Block Height', unit: 'mm', default: 200 },
      { key: 'wastage', label: 'Wastage', unit: '%', default: 5 },
    ],
    compute(v) {
      const wallArea = Math.max(num(v.wallLength) * num(v.wallHeight) - num(v.openings), 0);
      const blockArea = (num(v.blockLength) / 1000) * (num(v.blockHeight) / 1000) || 1;
      const blocks = (wallArea / blockArea) * (1 + num(v.wastage) / 100);
      return [
        { label: 'Wall Area', value: wallArea.toFixed(2), unit: 'm²' },
        { label: 'Blocks Required', value: Math.ceil(blocks), unit: 'blocks' },
      ];
    },
  },
  {
    id: 'material-mortar-quantity',
    category: 'material',
    label: 'Mortar Quantity',
    keywords: ['mortar', 'masonry', 'blockwork', 'cement', 'sand'],
    fields: [
      { key: 'wallVolume', label: 'Wall / Blockwork Volume', unit: 'm³' },
      { key: 'mortarPercent', label: 'Mortar Share of Wall Volume', unit: '%', default: 30 },
      { key: 'ratioCement', label: 'Mortar Ratio - Cement Part', default: 1 },
      { key: 'ratioSand', label: 'Mortar Ratio - Sand Part', default: 6 },
    ],
    notes: '30% is a common rule-of-thumb mortar share for standard block/brick joints; adjust as needed. Dry volume uses a 1.33× factor.',
    compute(v) {
      const wetMortar = num(v.wallVolume) * (num(v.mortarPercent) / 100);
      const dryMortar = wetMortar * 1.33;
      const sum = num(v.ratioCement) + num(v.ratioSand) || 1;
      const cementVolume = dryMortar * (num(v.ratioCement) / sum);
      const sandVolume = dryMortar * (num(v.ratioSand) / sum);
      const bags = (cementVolume * 1440) / 50;
      return [
        { label: 'Mortar Volume (wet)', value: wetMortar.toFixed(3), unit: 'm³' },
        { label: 'Cement Bags (50kg)', value: Math.ceil(bags), unit: 'bags' },
        { label: 'Sand Volume', value: sandVolume.toFixed(3), unit: 'm³' },
      ];
    },
  },
];
