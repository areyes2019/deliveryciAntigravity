<script setup>

const props = defineProps({
  modelValue: {
    type: String,
    default: 'live'
  }
})

const emit = defineEmits(['update:modelValue'])

const options = [
  {
    value: 'live',
    label: 'Live',
    icon: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z'
  },
  {
    value: 'simulator',
    label: 'Sim',
    icon: 'M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z'
  }
]

const isActive = (val) => props.modelValue === val

const select = (val) => {
  if (props.modelValue === val) return
  emit('update:modelValue', val)
}
</script>

<template>
  <div
    class="relative flex items-center rounded-full p-[3px] select-none"
    style="
      width: 168px;
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
      :class="modelValue === 'live'
        ? 'bg-emerald-500'
        : 'bg-amber-500'"
      :style="{
        left: modelValue === 'live' ? '3px' : 'calc(50%)',
        width: 'calc(50% - 3px)',
        boxShadow: modelValue === 'live'
          ? '0 2px 14px rgba(16,185,129,0.55), inset 0 1px 0 rgba(255,255,255,0.2)'
          : '0 2px 14px rgba(245,158,11,0.55), inset 0 1px 0 rgba(255,255,255,0.2)',
      }"
    ></span>

    <!-- Buttons -->
    <button
      v-for="opt in options"
      :key="opt.value"
      @click="select(opt.value)"
      class="relative z-10 flex-1 flex items-center justify-center gap-1.5
             h-full rounded-full font-bold tracking-wide
             transition-all duration-200 active:scale-95 focus:outline-none"
      :class="isActive(opt.value)
        ? 'text-white text-[11px]'
        : 'text-white/40 text-[11px] hover:text-white/65'"
    >
      <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
        <path :d="opt.icon"/>
      </svg>
      <span>{{ opt.label }}</span>
    </button>

  </div>
</template>
