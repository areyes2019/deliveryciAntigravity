Actúa como un desarrollador senior experto en ci4 y vue3. Necesito realizar modificaciones específicas en un módulo de mi sistema siguiendo una arquitectura limpia y desacoplada. 

Por favor, lee atentamente los siguientes componentes antes de generar el código modificado:
En el codigo de muestra aparece un alert para cancelar el pedio por parte del driver con un estilo generico



### 1. RUTA DEL ARCHIVO A MODIFICAR
frontend\src\components\dashboard\ActivityFeed.vue
### 2. ESPECIFICACIONES DE APOYO (SPECS)

### 3. SKILL A SEGUIR
- Sige cuidadosamente este skill
C:\xampp\htdocs\panda_expess\ia\global_criticador.md


### 4. FRAGMENTO DE CÓDIGO DE REFERENCIA
<transition name="panel-slide">
      <div v-if="showDetailPanel && selectedTrip" class="ref-panel">
        <button class="ref-panel__close" @click="closeDetail" aria-label="Cerrar panel">&times;</button>
        <div id="ref-panel-map" class="ref-panel__map"></div>
      </div>
    </transition>

### LO QUE QUIERO 
- Toma estos archivos como base para hacer un sitema de rastreo en vivo
  frontend\src\services\maps\GoogleProvider.js
  frontend\src\services\maps\BaseProvider.js
  frontend\src\services\maps\MapService.js
  frontend\src\components\dashboard\DashboardMap.vue
- Crear un rastreo en tiempo real
 * Despachador hace clic en la tarjeta de viaje
 * Aparece el mapa 
 * En el mapa el se ve la polilyne azul de la ruta del envio seleccionado 
 * El icono de moto va siguiendo el rastreo
El DashboardMap.vue ya tiene algo asi 


### TU TAREA:
1. Analiza el fragmento de código actual y las especificaciones.
2. Aplica los cambios solicitados respetando estrictamente el skill global_criticador.md
3. Devuelve el código completo modificado o las funciones afectadas listas para producción. Incluye comentarios breves donde se aplicaron los cambios.
4. No cambies la logica del codigo. 
5. Solo cambia los estilos css necesrios. 
6. No toque nada del estilo de otros componentes