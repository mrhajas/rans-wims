<template>
  <th class="sc-hd" :class="['hd-' + tone, { 'ac-num': num, 'is-sorted': active }]" :aria-sort="active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'">
    <button v-if="col" type="button" class="sc-hbtn" :title="$t('Click to sort')" @click="$emit('sort', col)">
      <span v-if="icon" class="sc-hico"><icons :name="icon" class="w-4 h-4" /></span><span class="sc-hlabel"><slot /></span>
      <span class="sc-harrows" :class="active ? 'is-' + dir : ''" aria-hidden="true"><i class="up"></i><i class="down"></i></span>
    </button>
    <span v-else class="sc-hbtn is-plain"><span v-if="icon" class="sc-hico"><icons :name="icon" class="w-4 h-4" /></span><span class="sc-hlabel"><slot /></span></span>
  </th>
</template>

<script>
// A table heading that sorts when clicked. `tone` colours it by the kind of column (key, text, money, num, date, state, end); `col` is the sort key (none = plain heading).
export default {
  props: { col: { type: String, default: '' }, tone: { type: String, default: 'text' }, num: Boolean, icon: { type: String, default: '' }, sort: { type: String, default: '' }, dir: { type: String, default: 'asc' } },
  emits: ['sort'],
  computed: { active() { return !!this.col && this.sort === this.col; } },
};
</script>
