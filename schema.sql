-- Creación de la Tabla Estudiantes en PostgreSQL
-- Ejecuta este script desde la consola de Render o mediante DBeaver

DROP TABLE IF EXISTS estudiantes;

CREATE TABLE estudiantes (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    programa VARCHAR(150) NOT NULL,
    fecha_registro TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Inserción inicial de prueba (Opcional)
INSERT INTO estudiantes (codigo, nombre, email, programa) 
VALUES ('EST-TEST-01', 'Estudiante de Prueba', 'prueba@render.com', 'Ingeniería de Software');
