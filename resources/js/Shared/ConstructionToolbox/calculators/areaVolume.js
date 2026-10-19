const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default [
  {
    id: 'geometry-rectangle',
    category: 'area-volume',
    label: 'Rectangle',
    keywords: ['rectangle', 'area', 'perimeter'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
    ],
    compute(v) {
      return [
        { label: 'Area', value: (num(v.length) * num(v.width)).toFixed(3), unit: 'm²' },
        { label: 'Perimeter', value: (2 * (num(v.length) + num(v.width))).toFixed(3), unit: 'm' },
      ];
    },
  },
  {
    id: 'geometry-triangle',
    category: 'area-volume',
    label: 'Triangle',
    keywords: ['triangle', 'area'],
    fields: [
      { key: 'base', label: 'Base', unit: 'm' },
      { key: 'height', label: 'Height', unit: 'm' },
    ],
    compute(v) {
      return [{ label: 'Area', value: ((num(v.base) * num(v.height)) / 2).toFixed(3), unit: 'm²' }];
    },
  },
  {
    id: 'geometry-circle',
    category: 'area-volume',
    label: 'Circle',
    keywords: ['circle', 'area', 'circumference', 'radius'],
    fields: [{ key: 'radius', label: 'Radius', unit: 'm' }],
    compute(v) {
      const r = num(v.radius);
      return [
        { label: 'Area', value: (Math.PI * r * r).toFixed(3), unit: 'm²' },
        { label: 'Circumference', value: (2 * Math.PI * r).toFixed(3), unit: 'm' },
      ];
    },
  },
  {
    id: 'geometry-slab',
    category: 'area-volume',
    label: 'Slab',
    keywords: ['slab', 'volume', 'floor', 'roof'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'thickness', label: 'Thickness', unit: 'm' },
    ],
    compute(v) {
      return [{ label: 'Volume', value: (num(v.length) * num(v.width) * num(v.thickness)).toFixed(3), unit: 'm³' }];
    },
  },
  {
    id: 'geometry-column',
    category: 'area-volume',
    label: 'Column (Rectangular)',
    keywords: ['column', 'volume', 'rectangular'],
    fields: [
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'depth', label: 'Depth', unit: 'm' },
      { key: 'height', label: 'Height', unit: 'm' },
    ],
    notes: 'For circular columns, use the Cylinder calculator instead.',
    compute(v) {
      return [{ label: 'Volume', value: (num(v.width) * num(v.depth) * num(v.height)).toFixed(3), unit: 'm³' }];
    },
  },
  {
    id: 'geometry-beam',
    category: 'area-volume',
    label: 'Beam',
    keywords: ['beam', 'volume'],
    fields: [
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'depth', label: 'Depth', unit: 'm' },
      { key: 'length', label: 'Length', unit: 'm' },
    ],
    compute(v) {
      return [{ label: 'Volume', value: (num(v.width) * num(v.depth) * num(v.length)).toFixed(3), unit: 'm³' }];
    },
  },
  {
    id: 'geometry-footing',
    category: 'area-volume',
    label: 'Footing',
    keywords: ['footing', 'foundation', 'volume'],
    fields: [
      { key: 'length', label: 'Length', unit: 'm' },
      { key: 'width', label: 'Width', unit: 'm' },
      { key: 'depth', label: 'Depth', unit: 'm' },
    ],
    compute(v) {
      return [{ label: 'Volume', value: (num(v.length) * num(v.width) * num(v.depth)).toFixed(3), unit: 'm³' }];
    },
  },
  {
    id: 'geometry-cylinder',
    category: 'area-volume',
    label: 'Cylinder',
    keywords: ['cylinder', 'volume', 'column', 'tank'],
    fields: [
      { key: 'radius', label: 'Radius', unit: 'm' },
      { key: 'height', label: 'Height', unit: 'm' },
    ],
    compute(v) {
      const r = num(v.radius);
      const h = num(v.height);
      return [
        { label: 'Volume', value: (Math.PI * r * r * h).toFixed(3), unit: 'm³' },
        { label: 'Surface Area', value: (2 * Math.PI * r * (r + h)).toFixed(3), unit: 'm²' },
      ];
    },
  },
];
