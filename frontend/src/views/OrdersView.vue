<template>
  <div class="orders-view">
    <div class="page-header">
      <h1>Orders Tracking</h1>
    </div>
    
    <div class="card mt-6">
      <p>View and manage all active delivery orders.</p>
      <div class="coming-soon">
        <span class="icon">📦</span>
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
