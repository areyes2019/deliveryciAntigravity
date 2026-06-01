necesito que ejecutes una tarea específica de refactorización basándote en las instrucciones detalladas que he guardado en estos archivo: 

ai\promps\refactoring\6-1.md

Inmeidatamente despues ejecuta este skill: ai\skills\global\global_criticador.md

Por favor, sigue estos pasos:
1. Lee el contenido del archivo indicado.
2. Analiza el código actual en `DashboardView.vue` (o los archivos relevantes) para entender qué debes extraer o modificar.
3. Ejecuta los cambios exactamente como se solicitan en el documento, respetando las convenciones de Vue 3 (Composition API).
4. Cuando termines, haz un resumen breve de los archivos creados o modificados.

No modifiques funcionalidades que no estén explícitamente mencionadas en el documento. Comienza a leer el archivo ahora.


INSERT INTO orders (
    uuid,
    client_id,
    pickup_lat,
    pickup_lng,
    pickup_address,
    drop_lat,
    drop_lng,
    drop_address,
    receiver_name,
    receiver_phone,
    scheduled_at,
    status,
    payment_type,
    cost,
    distance_km,
    total_to_collect,
    created_at,
    updated_at
)

SELECT
    UUID() AS uuid,
    1 AS client_id,

    o.pickup_lat,
    o.pickup_lng,
    o.pickup_address,

    o.drop_lat,
    o.drop_lng,
    o.drop_address,

    o.receiver_name,
    o.receiver_phone,

    NOW() AS scheduled_at,

    'publicado' AS status,
    'prepaid' AS payment_type,

    o.cost,
    o.distance_km,

    o.total_to_collect,

    NOW(),
    NOW()

FROM orders o

WHERE
    o.pickup_lat IS NOT NULL
    AND o.drop_lat IS NOT NULL
    AND o.pickup_address IS NOT NULL
    AND o.drop_address IS NOT NULL

ORDER BY RAND()

LIMIT 10;

Open Ai apiKey
<!-- REMOVED: do not commit API keys here -->