export default [
  {
    id: 'unit-length',
    category: 'unit-converter',
    label: 'Length',
    keywords: ['length', 'distance', 'mm', 'cm', 'm', 'km', 'inch', 'foot', 'yard', 'mile'],
    units: [
      { key: 'mm', label: 'Millimetres (mm)', factor: 0.001 },
      { key: 'cm', label: 'Centimetres (cm)', factor: 0.01 },
      { key: 'm', label: 'Metres (m)', factor: 1 },
      { key: 'km', label: 'Kilometres (km)', factor: 1000 },
      { key: 'inch', label: 'Inches (in)', factor: 0.0254 },
      { key: 'foot', label: 'Feet (ft)', factor: 0.3048 },
      { key: 'yard', label: 'Yards (yd)', factor: 0.9144 },
      { key: 'mile', label: 'Miles (mi)', factor: 1609.344 },
    ],
  },
  {
    id: 'unit-area',
    category: 'unit-converter',
    label: 'Area',
    keywords: ['area', 'mm2', 'cm2', 'm2', 'hectares', 'acres', 'ft2'],
    units: [
      { key: 'mm2', label: 'Square Millimetres (mm²)', factor: 0.000001 },
      { key: 'cm2', label: 'Square Centimetres (cm²)', factor: 0.0001 },
      { key: 'm2', label: 'Square Metres (m²)', factor: 1 },
      { key: 'hectare', label: 'Hectares (ha)', factor: 10000 },
      { key: 'acre', label: 'Acres', factor: 4046.8564224 },
      { key: 'ft2', label: 'Square Feet (ft²)', factor: 0.09290304 },
    ],
  },
  {
    id: 'unit-volume',
    category: 'unit-converter',
    label: 'Volume',
    keywords: ['volume', 'm3', 'ft3', 'litres', 'gallons'],
    units: [
      { key: 'm3', label: 'Cubic Metres (m³)', factor: 1 },
      { key: 'ft3', label: 'Cubic Feet (ft³)', factor: 0.0283168 },
      { key: 'litre', label: 'Litres (L)', factor: 0.001 },
      { key: 'gallon', label: 'Gallons (US)', factor: 0.00378541 },
    ],
  },
  {
    id: 'unit-weight',
    category: 'unit-converter',
    label: 'Weight',
    keywords: ['weight', 'mass', 'mg', 'g', 'kg', 'ton', 'lb'],
    units: [
      { key: 'mg', label: 'Milligrams (mg)', factor: 0.000001 },
      { key: 'g', label: 'Grams (g)', factor: 0.001 },
      { key: 'kg', label: 'Kilograms (kg)', factor: 1 },
      { key: 'ton', label: 'Metric Tons (t)', factor: 1000 },
      { key: 'lb', label: 'Pounds (lb)', factor: 0.45359237 },
    ],
  },
  {
    id: 'unit-temperature',
    category: 'unit-converter',
    label: 'Temperature',
    keywords: ['temperature', 'celsius', 'fahrenheit', 'kelvin'],
    units: [
      { key: 'celsius', label: 'Celsius (°C)' },
      { key: 'fahrenheit', label: 'Fahrenheit (°F)' },
      { key: 'kelvin', label: 'Kelvin (K)' },
    ],
    toBase(value, unit) {
      if (unit === 'fahrenheit') return ((value - 32) * 5) / 9;
      if (unit === 'kelvin') return value - 273.15;
      return value;
    },
    fromBase(celsius, unit) {
      if (unit === 'fahrenheit') return (celsius * 9) / 5 + 32;
      if (unit === 'kelvin') return celsius + 273.15;
      return celsius;
    },
  },
  {
    id: 'unit-pressure',
    category: 'unit-converter',
    label: 'Pressure',
    keywords: ['pressure', 'kpa', 'mpa', 'bar', 'psi'],
    units: [
      { key: 'kpa', label: 'Kilopascals (kPa)', factor: 1 },
      { key: 'mpa', label: 'Megapascals (MPa)', factor: 1000 },
      { key: 'bar', label: 'Bar', factor: 100 },
      { key: 'psi', label: 'PSI', factor: 6.89476 },
    ],
  },
];
