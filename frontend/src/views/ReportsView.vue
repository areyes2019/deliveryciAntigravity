<template>
  <div class="reports-view">
    <div class="page-header">
      <h1>Reports & Analytics</h1>
    </div>
    
    <div class="card mt-6">
      <p>Analyze your business performance over time.</p>
      <div class="coming-soon">
        <span class="icon">📈</span>
        <p>Implementation in progress...</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.coming-soon {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 4rem;
  color: var(--text-muted);
}

.coming-soon .icon {
  font-size: 3rem;
  margin-bottom: 1rem;
}
</style>
