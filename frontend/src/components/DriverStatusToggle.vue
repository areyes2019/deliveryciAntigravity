<script setup>
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  loading:    { type: Boolean, default: false }
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
  if (props.loading) return
  emit('update:modelValue', !props.modelValue)
}
</script>

<template>
  <button
    @click="toggle"
    :disabled="loading"
    class="relative flex items-center rounded-full p-[3px] select-none
           focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed
           active:scale-95 transition-transform duration-100"
    style="
      width: 160px;
      height: 40px;
      background: rgba(255,255,255,0.08);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.14);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 4px 16px rgba(0,0,0,0.3);
    "
  >
    <!-- Sliding pill -->
    <span
      class="absolute top-[3px] bottom-[3px] rounded-full
             transition-all duration-300 ease-[cubic-bezier(0.34,1.15,0.64,1)]"
      :class="modelValue ? 'bg-emerald-500' : 'bg-gray-600'"
      :style="{
        left:  modelValue ? '3px' : 'calc(50%)',
        width: 'calc(50% - 3px)',
        boxShadow: modelValue
          ? '0 2px 14px rgba(16,185,129,0.55), inset 0 1px 0 rgba(255,255,255,0.2)'
          : '0 2px 8px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.08)',
      }"
    ></span>

    <!-- En línea -->
    <span
      class="relative z-10 flex-1 flex items-center justify-center gap-1
             font-bold tracking-wide transition-colors duration-200"
      :class="modelValue ? 'text-white text-[11px]' : 'text-white/35 text-[11px]'"
    >
      <span
        class="w-1.5 h-1.5 rounded-full transition-all duration-300 flex-shrink-0"
        :class="modelValue ? 'bg-white animate-pulse' : 'bg-white/30'"
      ></span>
      En línea
    </span>

    <!-- Offline -->
    <span
      class="relative z-10 flex-1 flex items-center justify-center gap-1
             font-bold tracking-wide transition-colors duration-200"
      :class="!modelValue ? 'text-white text-[11px]' : 'text-white/35 text-[11px]'"
    >
      <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
        <path d="M20 18.54L5.46 4 4 5.46l4 4H4l4 4h2.54l4 4H10l1.73 1.73A2 2 0 0014 21h5.54L21 22.46 22.46 21 20 18.54zM4 8l4 4h1.17L4.83 7.66A9.95 9.95 0 004 8zm6-4.93A10 10 0 0120 12a9.9 9.9 0 01-1.29 4.88l1.45 1.45A11.9 11.9 0 0022 12C22 6.48 17.52 2 12 2c-1.63 0-3.18.37-4.57 1L8.9 4.47C9.88 4.17 10.92 4 12 4z"/>
      </svg>
      Offline
    </span>

    <!-- Spinner overlay -->
    <span
      v-if="loading"
      class="absolute inset-0 flex items-center justify-center z-20 rounded-full"
      style="background: rgba(0,0,0,0.35)"
    >
      <svg class="w-4 h-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
    </span>
  </button>
</template>
