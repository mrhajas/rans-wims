import material from './calculators/material';
import rebar from './calculators/rebar';
import areaVolume from './calculators/areaVolume';
import excavation from './calculators/excavation';
import electricalPlumbing from './calculators/electricalPlumbing';
import unitConverters from './unitConverters';
import quickReferences from './quickReferences';

export const CATEGORIES = [
  { id: 'basic', label: 'Basic Calculator', icon: 'calculator' },
  { id: 'unit-converter', label: 'Unit Converter', icon: 'refresh' },
  { id: 'material', label: 'Material Calculator', icon: 'cart' },
  { id: 'rebar', label: 'Rebar Calculator', icon: 'link' },
  { id: 'area-volume', label: 'Area & Volume', icon: 'chart-pie' },
  { id: 'excavation', label: 'Excavation', icon: 'truck' },
  { id: 'electrical-plumbing', label: 'Electrical & Plumbing', icon: 'chip' },
  { id: 'quick-reference', label: 'Quick References', icon: 'doc-text' },
  { id: 'favorites', label: 'Favorites', icon: 'heart' },
  { id: 'history', label: 'History', icon: 'clock' },
];

const basicTools = [
  { id: 'basic-standard', category: 'basic', label: 'Standard', keywords: ['standard', 'basic', 'arithmetic'], component: 'StandardCalculator' },
  { id: 'basic-scientific', category: 'basic', label: 'Scientific', keywords: ['scientific', 'trigonometry', 'sqrt', 'power', 'log'], component: 'ScientificCalculator' },
  { id: 'basic-percentage', category: 'basic', label: 'Percentage', keywords: ['percentage', 'percent'], component: 'PercentageCalculator' },
];

const unitConverterTools = unitConverters.map(config => ({
  id: config.id,
  category: config.category,
  label: config.label,
  keywords: config.keywords,
  component: 'UnitConverter',
  config,
}));

const genericTools = [...material, ...rebar, ...areaVolume, ...excavation, ...electricalPlumbing].map(config => ({
  id: config.id,
  category: config.category,
  label: config.label,
  keywords: config.keywords,
  component: 'GenericCalculator',
  config,
}));

const referenceTools = quickReferences.map(config => ({
  id: config.id,
  category: config.category,
  label: config.label,
  keywords: config.keywords,
  component: 'QuickReference',
  config,
}));

export const TOOLS = [...basicTools, ...unitConverterTools, ...genericTools, ...referenceTools];

export function findTool(id) {
  return TOOLS.find(tool => tool.id === id) || null;
}

export function toolsForCategory(categoryId) {
  return TOOLS.filter(tool => tool.category === categoryId);
}

export function searchTools(query) {
  const q = query.trim().toLowerCase();
  if (!q) return TOOLS;
  return TOOLS.filter(tool => {
    const haystack = [tool.label, ...(tool.keywords || [])].join(' ').toLowerCase();
    return haystack.includes(q);
  });
}
