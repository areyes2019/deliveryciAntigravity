import { ref, computed } from 'vue'

const drivers = ref([])

export function useDrivers() {
  const activeDrivers = computed(() => {
    return drivers.value.filter(d => d.is_active != 0)
  })

  return {
    drivers,
    activeDrivers
  }
}
