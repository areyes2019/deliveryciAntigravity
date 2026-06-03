<template>
  <div class="map-area map-area--main">
    <div id="map-root" class="map-root-frame"></div>
  </div>
</template>

<script setup>
</script>

<style scoped>
.map-area--main {
  flex: 1;
  position: relative;
  height: 100%;
  min-width: 0;
}

.map-root-frame {
  width: 100%;
  height: 100%;
  border-radius: 0;
}

#map-root {
  width: 100%;
  height: 100%;
  background: #e2e8f0;
}

@media (max-width: 900px) {
  .map-area--main { min-height: 400px; }
}

</style>
