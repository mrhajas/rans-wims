<template>
  <admin-layout :title="$t('My ID')">
    <div class="lg-page sc-ui px-4 md:px-0 myid">
      <page-header icon="user" :title="$t('My ID')" :subtitle="$t('Your RANS Staff ID card, front and back.')" />

      <section v-if="!card" class="lg-card myid-empty">
        <icons name="user" class="w-10 h-10" />
        <h2>{{ linked ? $t('No RANS ID has been issued to you yet') : $t('Your login is not linked to a staff record') }}</h2>
        <p>{{ linked ? $t('When the office issues your Staff ID it appears here.') : $t('Ask the office to link your login to your staff record, then your ID appears here.') }}</p>
      </section>

      <template v-else>
        <div class="myid-top">
          <category-chip large :name="card.category" :color="card.color" :ink="card.ink" :icon="card.icon" />
          <span class="lg-chip" :class="statusChip(card.status)">{{ $t(card.status) }}</span>
        </div>
        <section v-if="card.status_raw !== 'Active'" class="lg-note lg-note-amber" style="margin-bottom: 14px"><icons :name="statusIcon(card.status)" class="w-4 h-4" /><span>{{ $t('This ID is not active (:s). Please see the office.', { s: $t(card.status).toLowerCase() }) }}</span></section>

        <section class="lg-card myid-card">
          <div class="myid-flip" :class="{ 'is-back': side === 'back' }" @click="side = side === 'front' ? 'back' : 'front'" role="button" tabindex="0" :aria-label="$t('Turn the card over')" @keydown.enter="side = side === 'front' ? 'back' : 'front'">
            <div class="myid-side myid-front"><card-face :html="front" :css="css" /></div>
            <div class="myid-side myid-backside"><card-face :html="back" :css="css" /></div>
          </div>
          <div class="myid-tabs">
            <button type="button" :class="{ on: side === 'front' }" @click="side = 'front'">{{ $t('Front') }}</button>
            <button type="button" :class="{ on: side === 'back' }" @click="side = 'back'">{{ $t('Back') }}</button>
          </div>
          <p class="myid-hint">{{ $t('Tap the card to turn it over.') }}</p>
        </section>

        <section class="lg-card myid-info">
          <dl>
            <div><dt>{{ $t('ID number') }}</dt><dd>{{ card.number }}</dd></div>
            <div><dt>{{ $t('Name') }}</dt><dd>{{ card.name }}</dd></div>
            <div v-if="card.title"><dt>{{ $t('Title') }}</dt><dd>{{ card.title }}</dd></div>
            <div><dt>{{ $t('Issued') }}</dt><dd>{{ card.issued_at ? $date(card.issued_at) : '-' }}</dd></div>
            <div><dt>{{ $t('Valid until') }}</dt><dd>{{ card.validity_text }}</dd></div>
          </dl>
          <p class="myid-note">{{ $t('The QR code on your ID is checked by security. If your ID is lost or broken, tell the office so it can be blocked and replaced.') }}</p>
        </section>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CardFace from './Parts/CardFace.vue';
import CategoryChip from './Parts/CategoryChip.vue';
import { statusChip, statusIcon } from './Parts/status';

export default {
  components: { AdminLayout, PageHeader, CardFace, CategoryChip },
  props: { card: Object, linked: Boolean, front: String, back: String, css: String },
  data() { return { side: 'front' }; },
  methods: { statusChip, statusIcon },
};
</script>

<style scoped>
.myid { max-width: 760px; margin: 0 auto; }
.myid-top { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.myid-empty { display: grid; gap: 8px; justify-items: center; text-align: center; padding: 40px 20px; color: var(--lg-mute, #64748b); }
.myid-empty h2 { margin: 0; font-size: 1.1rem; color: var(--lg-ink, #0f2540); }
.myid-card { display: grid; justify-items: center; gap: 14px; padding: 26px 16px 20px; background: radial-gradient(120% 90% at 50% 0%, rgba(21, 101, 192, 0.1), transparent 60%), var(--lg-card, #fff); }
.myid-flip { position: relative; width: min(260px, 70vw); aspect-ratio: 55 / 85; cursor: pointer; perspective: 1200px; }
.myid-side { position: absolute; inset: 0; backface-visibility: hidden; transition: transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1); }
.myid-backside { transform: rotateY(180deg); }
.myid-flip.is-back .myid-front { transform: rotateY(-180deg); }
.myid-flip.is-back .myid-backside { transform: rotateY(0); }
.myid-tabs { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); }
.myid-tabs button { border: 0; padding: 7px 18px; border-radius: 999px; background: transparent; font-weight: 700; font-size: 0.82rem; color: var(--lg-mute, #64748b); cursor: pointer; }
.myid-tabs button.on { background: #fff; color: #1e4467; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.myid-hint { margin: 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.myid-info { margin-top: 14px; padding: 18px 20px; }
.myid-info dl { margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px 20px; }
.myid-info dt { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.myid-info dd { margin: 2px 0 0; font-weight: 700; color: var(--lg-ink, #0f2540); }
.myid-note { margin: 16px 0 0; font-size: 0.82rem; color: var(--lg-mute, #64748b); }
@media (prefers-reduced-motion: reduce) { .myid-side { transition: none; } }
</style>
