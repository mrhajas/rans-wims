const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default [
  {
    id: 'electrical-cable-length',
    category: 'electrical-plumbing',
    label: 'Cable Length',
    keywords: ['cable', 'electrical', 'wire', 'length'],
    fields: [
      { key: 'distance', label: 'One-Way Distance', unit: 'm' },
      { key: 'runs', label: 'Number of Runs', default: 1 },
      { key: 'wastage', label: 'Wastage', unit: '%', default: 10 },
    ],
    compute(v) {
      const total = num(v.distance) * num(v.runs) * (1 + num(v.wastage) / 100);
      return [{ label: 'Total Cable Length', value: total.toFixed(2), unit: 'm' }];
    },
  },
  {
    id: 'plumbing-pipe-length',
    category: 'electrical-plumbing',
    label: 'Pipe Length',
    keywords: ['pipe', 'plumbing', 'length'],
    fields: [
      { key: 'distance', label: 'One-Way Distance', unit: 'm' },
      { key: 'runs', label: 'Number of Runs', default: 1 },
      { key: 'wastage', label: 'Wastage', unit: '%', default: 5 },
    ],
    compute(v) {
      const total = num(v.distance) * num(v.runs) * (1 + num(v.wastage) / 100);
      return [{ label: 'Total Pipe Length', value: total.toFixed(2), unit: 'm' }];
    },
  },
  {
    id: 'plumbing-pipe-volume',
    category: 'electrical-plumbing',
    label: 'Pipe Volume',
    keywords: ['pipe', 'volume', 'water', 'litres'],
    fields: [
      { key: 'diameter', label: 'Internal Diameter', unit: 'mm' },
      { key: 'length', label: 'Length', unit: 'm' },
    ],
    compute(v) {
      const radiusM = num(v.diameter) / 2000;
      const volume = Math.PI * radiusM * radiusM * num(v.length);
      return [
        { label: 'Volume', value: volume.toFixed(4), unit: 'm³' },
        { label: 'Volume', value: (volume * 1000).toFixed(2), unit: 'litres' },
      ];
    },
  },
  {
    id: 'plumbing-water-tank-capacity',
    category: 'electrical-plumbing',
    label: 'Water Tank Capacity',
    keywords: ['water', 'tank', 'capacity', 'litres'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'height', label: 'Height', unit: 'm' },
    ],
    compute(v) {
      const volume = num(v.length) * num(v.width) * num(v.height);
      return [
        { label: 'Volume', value: volume.toFixed(3), unit: 'm³' },
        { label: 'Capacity', value: (volume * 1000).toFixed(0), unit: 'litres' },
      ];
    },
  },
];
