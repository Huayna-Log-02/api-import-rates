-- Ejecutar en produccion antes de importar tarifas con formulas que terminan en "+igv".
-- Este indicador permite que el calculo aplique el IGV configurado en el sistema
-- despues de resolver las operaciones encadenadas de la tarifa.

BEGIN;

ALTER TABLE tarifa_proveedores_operaciones
ADD COLUMN IF NOT EXISTS aplica_igv boolean NOT NULL DEFAULT false;

ALTER TABLE tarifa_agentes_operaciones
ADD COLUMN IF NOT EXISTS aplica_igv boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN tarifa_proveedores_operaciones.aplica_igv
IS 'Indica si al resultado de las operaciones de la tarifa se le debe adicionar IGV usando el porcentaje configurado en el sistema.';

COMMENT ON COLUMN tarifa_agentes_operaciones.aplica_igv
IS 'Indica si al resultado de las operaciones de la tarifa se le debe adicionar IGV usando el porcentaje configurado en el sistema.';

COMMIT;

-- Verificacion:
SELECT table_name, column_name, data_type, column_default, is_nullable
FROM information_schema.columns
WHERE table_name IN ('tarifa_proveedores_operaciones', 'tarifa_agentes_operaciones')
  AND column_name = 'aplica_igv'
ORDER BY table_name;
