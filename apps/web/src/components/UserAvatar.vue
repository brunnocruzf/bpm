<script setup lang="ts">
import { computed } from 'vue'
import { initialsOf } from '@/composables/format'

const props = withDefaults(defineProps<{ name?: string | null; size?: number; src?: string | null }>(), { name: '', size: 34, src: null })
const initials = computed(() => initialsOf(props.name))
const style = computed(() => ({ width: `${props.size}px`, height: `${props.size}px`, fontSize: `${Math.max(10, Math.round(props.size * 0.36))}px` }))
</script>

<template>
  <span class="user-avatar" :style="style" aria-hidden="true">
    <img v-if="src" :src="src" alt="" />
    <template v-else>{{ initials }}</template>
  </span>
</template>

<style scoped>
.user-avatar { flex: none; display: inline-grid; place-items: center; overflow: hidden; border-radius: 50%; color: var(--brand-ink); background: var(--brand-soft); border: 1px solid var(--brand-line); font-weight: 750; line-height: 1; }
.user-avatar img { width: 100%; height: 100%; object-fit: cover; }
</style>
