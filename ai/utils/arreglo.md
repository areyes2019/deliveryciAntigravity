Actúa como un desarrollador senior experto. Necesito realizar modificaciones específicas en un módulo de mi sistema siguiendo una arquitectura limpia y desacoplada. 

Por favor, lee atentamente los siguientes componentes antes de generar el código modificado:
En el codigo de muestra aparece un alert para cancelar el pedio por parte del driver con un estilo generico



### 1. RUTA DEL ARCHIVO A MODIFICA
src\views\Dashboard.vue

### 2. ESPECIFICACIONES DE APOYO (SPECS)
- Sigue las especificaciones de este spec 
ia\views\SPEC_AS-IS_Dashboard.spec.md

### 3. SKILL A SEGUIR
- Sige cuidadosamente este skill
C:\xampp\htdocs\panda_expess\ia\global_criticador.md

### 4. FRAGMENTO DE CÓDIGO DE REFERENCIA
<div v-else class="orders-list">
        <AvailableOrderCard
          v-for="order in availableOrders"
          :key="order.id"
          :order="order"
          :can-accept-trips="driverStore.canAcceptTrips"
          @accept="tripMgmt.acceptOrder(order)"
        />
      </div>


### LO QUE QUIERO 
Cuando no hay un viaje y el usuario hace scroll, crea una lellenda algo asi como "No tienes viajes" y un icono arriba de la leyenda. Todo Centrado



### TU TAREA:
1. Analiza el fragmento de código actual y las especificaciones.
2. Aplica los cambios solicitados respetando estrictamente el skill global_criticador.md
3. Devuelve el código completo modificado o las funciones afectadas listas para producción. Incluye comentarios breves donde se aplicaron los cambios.
4. No cambies la logica del codigo. 
5. Solo cambia los estilos css necesrios. 
6. No toque nada del estilo de otros componentes