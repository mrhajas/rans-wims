<template>
  <admin-layout :title="$t('Terms & Conditions')">
    <div class="px-4 md:px-0 tc-shell max-w-3xl mx-auto">
      <div class="tc-bg-decor" aria-hidden="true">
        <span class="tc-blob tc-blob-1"></span>
        <span class="tc-blob tc-blob-2"></span>
      </div>

      <div class="tc-hero rounded-2xl p-5 md:p-6 mb-6 relative z-[1]">
        <div class="flex items-center gap-4 flex-wrap">
          <div class="tc-hero-icon flex items-center justify-center h-14 w-14 rounded-2xl flex-none">
            <icons name="lock-closed" class="w-7 h-7 text-white" />
          </div>
          <div class="min-w-0 flex-1">
            <div class="text-[0.68rem] font-bold uppercase tracking-wide text-indigo-600/80">{{ $t('WIMS Legal') }}</div>
            <h1 class="tc-hero-title">{{ $t('Terms & Conditions') }}</h1>
            <div class="tc-hero-meta">
              {{ $t('Version') }} {{ version }}
              <span v-if="acceptedAt">&middot; {{ $t('You read this on') }} {{ $datetime(acceptedAt) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <a :href="route('terms.print')" target="_blank" class="tc-liquid-btn tc-liquid-btn-blue">
              <span class="tc-liquid-btn-icon"><icons name="printer" class="w-4 h-4" /></span>
              {{ $t('Print') }}
            </a>
            <a :href="route('terms.pdf')" class="tc-liquid-btn tc-liquid-btn-rose">
              <span class="tc-liquid-btn-icon"><icons name="download" class="w-4 h-4" /></span>
              {{ $t('Download') }}
            </a>
          </div>
        </div>
      </div>

      <div class="space-y-3 relative z-[1]">
        <div v-for="(section, i) in sections" :key="i" class="tc-card rounded-2xl p-5">
          <div class="flex items-center gap-3 mb-2.5">
            <div class="tc-card-icon flex items-center justify-center h-9 w-9 rounded-xl flex-none">
              <icons :name="sectionIcon(section.title)" class="w-4 h-4 text-white" />
            </div>
            <h2 class="tc-card-title">{{ section.title }}</h2>
          </div>
          <div class="tc-card-body" v-html="formatBody(section.body)"></div>
        </div>
      </div>

      <div class="tc-sign rounded-2xl p-5 mt-6 mb-8 relative z-[1]">
        <div class="tc-sign-name">{{ $t('WIMS Administration') }}</div>
        <div class="tc-sign-sub">{{ $t('RANS: Workflow Integrated Management System') }}</div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
  components: { AdminLayout },
  props: {
    content: { type: String, default: '' },
    version: { type: Number, required: true },
    acceptedAt: { type: String, default: null },
  },
  computed: {
    sections() {
      return this.content
        .split(/\n(?=## )/)
        .map(block => block.trim())
        .filter(Boolean)
        .map(block => {
          const withoutMarker = block.replace(/^##\s+/, '');
          const [title, ...rest] = withoutMarker.split('\n');
          return { title: title.trim(), body: rest.join('\n').trim() };
        });
    },
  },
  methods: {
    formatBody(body) {
      const escaped = body
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
      return escaped.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>').replace(/\n/g, '<br>');
    },
    sectionIcon(title) {
      const t = title.toLowerCase();
      const rules = [
        [/what is|about wims/, 'building'],
        [/information we collect|collect/, 'collection'],
        [/monitor|audit/, 'eye-flat'],
        [/responsib|credential/, 'lock-closed'],
        [/business records|records/, 'doc-text'],
        [/module|attendance/, 'clock'],
        [/cookie|storage|offline/, 'chip'],
        [/access control|permission/, 'key'],
        [/administrator/, 'users'],
        [/prohibited|security/, 'exclamation'],
        [/suspension|suspend/, 'lock-closed'],
        [/availability/, 'info'],
        [/agreement|acceptance/, 'tick'],
      ];
      const match = rules.find(([re]) => re.test(t));
      return match ? match[1] : 'doc';
    },
  },
};
</script>

<style scoped>
.tc-shell { position: relative; }
.tc-bg-decor { position: absolute; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; border-radius: inherit; }
.tc-blob { position: absolute; border-radius: 9999px; filter: blur(70px); opacity: 0.18; }
.tc-blob-1 { top: -60px; left: -40px; height: 220px; width: 220px; background: radial-gradient(circle, #818cf8, transparent 70%); }
.tc-blob-2 { top: 40px; right: -60px; height: 240px; width: 240px; background: radial-gradient(circle, #38bdf8, transparent 70%); }

.tc-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(224, 231, 255, 0.55), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(79, 70, 229, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.tc-hero-icon { background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 8px 20px -6px rgba(67, 56, 202, 0.5); }
.tc-hero-title { font-size: 1.3rem; font-weight: 800; color: #0f172a; line-height: 1.2; margin-top: 0.1rem; }
.tc-hero-meta { font-size: 0.75rem; color: #64748b; margin-top: 0.3rem; font-weight: 600; }

.tc-card { background: #fff; border: 1px solid #eef1f6; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 8px 20px rgba(15, 23, 42, 0.04); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.tc-card:hover { transform: translateY(-1px); box-shadow: 0 10px 22px -8px rgba(79, 70, 229, 0.12); }
.tc-card-icon { background: linear-gradient(145deg, #a5b4fc, #4f46e5); box-shadow: 0 4px 10px -3px rgba(79, 70, 229, 0.4); }
.tc-card-title { font-size: 0.95rem; font-weight: 800; color: #1e293b; }
.tc-card-body { font-size: 0.85rem; line-height: 1.65; color: #475569; }
.tc-card-body :deep(strong) { color: #1e293b; font-weight: 700; }

.tc-sign { background: linear-gradient(140deg, rgba(224, 231, 255, 0.4), rgba(255, 255, 255, 0.6)); border: 1px dashed #c7d2fe; text-align: center; }
.tc-sign-name { font-size: 0.9rem; font-weight: 800; color: #4338ca; }
.tc-sign-sub { font-size: 0.75rem; color: #64748b; margin-top: 0.15rem; }

.tc-liquid-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
  padding: 0.6rem 1rem; border-radius: 0.9rem;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.75), rgba(255, 255, 255, 0.4));
  border: 1px solid rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(10px) saturate(160%);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 1px 2px rgba(15, 23, 42, 0.04), 0 8px 18px -10px rgba(15, 23, 42, 0.15);
  color: #334155; font-weight: 700; font-size: 0.8rem;
  transition: transform 0.15s ease, box-shadow 0.2s ease;
}
.tc-liquid-btn:hover { transform: translateY(-2px); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8), 0 14px 26px -12px var(--liquid-glow, rgba(15, 23, 42, 0.25)); }
.tc-liquid-btn:active { transform: translateY(0); }
.tc-liquid-btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 0.55rem; color: #fff; flex-shrink: 0; }
.tc-liquid-btn-blue { --liquid-glow: rgba(37, 99, 235, 0.35); }
.tc-liquid-btn-blue .tc-liquid-btn-icon { background: linear-gradient(145deg, #60a5fa, #2563eb); }
.tc-liquid-btn-rose { --liquid-glow: rgba(225, 29, 72, 0.35); }
.tc-liquid-btn-rose .tc-liquid-btn-icon { background: linear-gradient(145deg, #fb7185, #e11d48); }

html.dark .tc-hero { background: linear-gradient(140deg, rgba(30, 27, 75, 0.5), rgba(15, 23, 42, 0.5)); border-color: rgba(255, 255, 255, 0.08); }
html.dark .tc-hero-title { color: #f1f5f9; }
html.dark .tc-card { background: #0f172a; border-color: rgba(255, 255, 255, 0.08); }
html.dark .tc-card-title { color: #f1f5f9; }
html.dark .tc-card-body { color: #cbd5e1; }
html.dark .tc-card-body :deep(strong) { color: #f1f5f9; }
html.dark .tc-sign { background: rgba(30, 27, 75, 0.3); border-color: rgba(129, 140, 248, 0.3); }
html.dark .tc-liquid-btn { background: linear-gradient(150deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.4)); border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }

@media (max-width: 480px) {
  .tc-hero .flex.items-center.gap-4 { flex-direction: column; align-items: flex-start; }
  .tc-liquid-btn { flex: 1; }
}
</style>
