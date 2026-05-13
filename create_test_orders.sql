-- ============================================================
-- 6 viajes de prueba con ubicaciones reales en Celaya, Gto.
-- 3 INMEDIATOS + 3 PROGRAMADOS PARA MAÑANA
-- ============================================================

-- ============================================================
-- 🚀 VIAJES INMEDIATOS (status = publicado, sin scheduled_at)
-- Aparecen en "Viajes en Cola" del dashboard
-- ============================================================

-- -------------------------------------------
-- INMEDIATO 1: prepaid - Farmacia a Centro
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.522200, -100.812500, 'Farmacia Guadalajara, Av. Benito Juárez 310, Zona Centro, 38000 Celaya, Gto.',
    20.517800, -100.820300, 'Calle Ignacio Allende 150, Colonia Centro, 38000 Celaya, Gto.',
    'Rosa Martínez', '4617890123',
    'Medicamentos recetados - Antibióticos',
    'publicado', 'prepaid',
    35.00, 1.8, NULL, 0.00, 0,
    NOW(), NOW()
);

-- -------------------------------------------
-- INMEDIATO 2: cash_on_delivery - McDonald's a Residencial
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.537200, -100.809000, 'McDonald''s Celaya, Av. Tecnológico 400, Cd Industrial, 38010 Celaya, Gto.',
    20.525100, -100.835000, 'Residencial Los Pinos 78, 38030 Celaya, Gto.',
    'Carlos Sánchez', '4612345678',
    'Orden de 4 hamburguesas + papas - Comida a domicilio',
    'publicado', 'cash_on_delivery',
    42.00, 2.8, NULL, 42.00, 0,
    NOW(), NOW()
);

-- -------------------------------------------
-- INMEDIATO 3: cash_full - Aurrera a Las Flores
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.529556, -100.846635, 'Bodega Aurrera, Prolongación Irrigación 200, Zona Agropecuaria, 38010 Celaya, Gto.',
    20.508900, -100.830100, 'Colonia Las Flores 215, 38040 Celaya, Gto.',
    'Laura Torres', '4618901234',
    'Entrega de licuadora Oster + batidora - Valor producto $899',
    'publicado', 'cash_full',
    50.00, 3.2, 899.00, 949.00, 0,
    NOW(), NOW()
);

-- ============================================================
-- 📅 VIAJES PROGRAMADOS PARA MAÑANA (status = pendiente)
-- Aparecen en "Viajes Pendientes" del dashboard
-- ============================================================

-- -------------------------------------------
-- PROGRAMADO 1: mañana 8:00 AM - prepaid
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    scheduled_at, created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.529556, -100.846635, 'Bodega Aurrera, Prolongación Irrigación 200, Zona Agropecuaria, 38010 Celaya, Gto.',
    20.511900, -100.817500, 'Parque Xochipilli, Av. Benito Juárez 100, Zona Centro, 38000 Celaya, Gto.',
    'María García', '4611234567',
    'Entrega de despensa familiar - 2 bolsas grandes',
    'pendiente', 'prepaid',
    47.50, 3.5, NULL, 0.00, 0,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 8 HOUR, NOW(), NOW()
);

-- -------------------------------------------
-- PROGRAMADO 2: mañana 2:00 PM - cash_on_delivery
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    scheduled_at, created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.517500, -100.824500, 'Walmart Celaya, Av. Paseo de la Constitución 500, Las Insurgentes, 38080 Celaya, Gto.',
    20.527415, -100.843945, 'Real del Seminario 124, Fraccionamiento Real del Seminario, 38020 Celaya, Gto.',
    'Juan Hernández', '4619876543',
    'Envío de documento - Sobre tamaño carta',
    'pendiente', 'cash_on_delivery',
    45.00, 3.0, NULL, 45.00, 0,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 14 HOUR, NOW(), NOW()
);

-- -------------------------------------------
-- PROGRAMADO 3: mañana 7:00 PM - cash_full
-- -------------------------------------------
INSERT INTO orders (
    uuid, client_id, driver_id,
    pickup_lat, pickup_lng, pickup_address,
    drop_lat, drop_lng, drop_address,
    receiver_name, receiver_phone,
    description,
    status, payment_type,
    cost, distance_km, product_amount, total_to_collect, paid,
    scheduled_at, created_at, updated_at
) VALUES (
    UUID(),
    1, NULL,
    20.528800, -100.815900, 'Soriana Hidalgo, Av. Hidalgo 200, Zona Centro, 38000 Celaya, Gto.',
    20.535800, -100.827100, 'Colonia Los Olivos 345, 38030 Celaya, Gto.',
    'Ana López', '4615554321',
    'Entrega de pastel de cumpleaños + 12 refrescos - Valor producto $120',
    'pendiente', 'cash_full',
    45.00, 2.5, 120.00, 165.00, 0,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 19 HOUR, NOW(), NOW()
);
