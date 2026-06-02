<?php
/**
 * Aplicación de Registro y Sincronización de Estudiantes
 * Conectividad dual: PostgreSQL (PDO) y MongoDB Atlas (Composer Driver)
 */

require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// --- 1. CONFIGURACIÓN DE CONEXIONES Y VARIABLES DE ENTORNO ---

// Configuración PostgreSQL
$pg_url = getenv('DATABASE_URL'); // Render proporciona esto de forma nativa
$pg_connected = false;
$pdo = null;
$pg_error = null;

try {
    if ($pg_url) {
        // Parsear la URL de conexión que provee Render de forma nativa
        $dbopts = parse_url($pg_url);
        $pg_host = $dbopts["host"] ?? '';
        $pg_port = $dbopts["port"] ?? 5432;
        $pg_user = $dbopts["user"] ?? '';
        $pg_pass = $dbopts["pass"] ?? '';
        $pg_db   = ltrim($dbopts["path"] ?? '', '/');
    } else {
        // Fallbacks locales o individuales
        $pg_host = getenv('PGHOST') ?: 'localhost';
        $pg_port = getenv('PGPORT') ?: '5432';
        $pg_user = getenv('PGUSER') ?: 'postgres';
        $pg_pass = getenv('PGPASSWORD') ?: '';
        $pg_db   = getenv('PGDATABASE') ?: 'estudiantes';
    }

    if (!empty($pg_host)) {
        $dsn = "pgsql:host=$pg_host;port=$pg_port;dbname=$pg_db;sslmode=require";
        $pdo = new PDO($dsn, $pg_user, $pg_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
        $pg_connected = true;
    } else {
        $pg_error = "La variable de entorno de PostgreSQL no está configurada.";
    }
} catch (PDOException $e) {
    $pg_error = "Error al conectar con PostgreSQL: " . $e->getMessage();
}

// Configuración MongoDB
$mongo_uri = getenv('MONGODB_URI'); // Cadena de conexión de MongoDB Atlas
$mongo_db_name = getenv('MONGODB_DB') ?: 'colegio';
$mongo_connected = false;
$mongo_collection = null;
$mongo_error = null;

try {
    if ($mongo_uri) {
        // Verificar que la extensión nativa mongodb esté cargada
        if (!extension_loaded('mongodb')) {
            throw new Exception("La extensión PHP 'mongodb' no está instalada o cargada.");
        }
        $mongo_client = new Client($mongo_uri);
        // Intentar una operación mínima para verificar conectividad
        $mongo_client->listDatabases();
        
        $mongo_collection = $mongo_client->selectCollection($mongo_db_name, 'estudiantes');
        $mongo_connected = true;
    } else {
        $mongo_error = "La variable de entorno MONGODB_URI no está configurada.";
    }
} catch (Exception $e) {
    $mongo_error = "Error al conectar con MongoDB: " . $e->getMessage();
}


// --- 2. PROCESAMIENTO DEL FORMULARIO DE REGISTRO (POST) ---

$status_message = null;
$status_type = null; // 'success', 'warning', 'error'
$pg_save_ok = false;
$mongo_save_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar'])) {
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $programa = trim($_POST['programa'] ?? '');

    if (empty($codigo) || empty($nombre) || empty($email) || empty($programa)) {
        $status_message = "Todos los campos del formulario son obligatorios.";
        $status_type = "error";
    } else {
        // Intento de guardado en PostgreSQL
        if ($pg_connected && $pdo) {
            try {
                // Verificar si ya existe el código de estudiante para evitar duplicados en PG
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM estudiantes WHERE codigo = ?");
                $stmt_check->execute([$codigo]);
                if ($stmt_check->fetchColumn() > 0) {
                    throw new Exception("El código de estudiante ya se encuentra registrado en PostgreSQL.");
                }

                $stmt = $pdo->prepare("INSERT INTO estudiantes (codigo, nombre, email, programa) VALUES (?, ?, ?, ?)");
                $stmt->execute([$codigo, $nombre, $email, $programa]);
                $pg_save_ok = true;
            } catch (Exception $e) {
                $pg_error = "Error al insertar en PostgreSQL: " . $e->getMessage();
            }
        }

        // Intento de guardado en MongoDB Atlas
        if ($mongo_connected && $mongo_collection) {
            try {
                // Verificar si ya existe en MongoDB
                $exists = $mongo_collection->findOne(['codigo' => $codigo]);
                if ($exists) {
                    throw new Exception("El código de estudiante ya se encuentra registrado en MongoDB.");
                }

                $insertResult = $mongo_collection->insertOne([
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'email' => $email,
                    'programa' => $programa,
                    'fecha_respaldo' => new UTCDateTime()
                ]);
                
                if ($insertResult->getInsertedCount() > 0) {
                    $mongo_save_ok = true;
                }
            } catch (Exception $e) {
                $mongo_error = "Error al insertar en MongoDB Atlas: " . $e->getMessage();
            }
        }

        // Evaluación de los resultados de la sincronización
        if ($pg_save_ok && $mongo_save_ok) {
            $status_message = "¡Sincronización Exitosa! El estudiante '<strong>" . htmlspecialchars($nombre) . "</strong>' fue almacenado correctamente tanto en PostgreSQL como en MongoDB Atlas.";
            $status_type = "success";
        } elseif ($pg_save_ok && !$mongo_save_ok) {
            $status_message = "Registro parcial: El estudiante fue guardado en <strong>PostgreSQL</strong>, pero falló el respaldo en <strong>MongoDB Atlas</strong>. Detalle: " . htmlspecialchars($mongo_error);
            $status_type = "warning";
        } elseif (!$pg_save_ok && $mongo_save_ok) {
            $status_message = "Registro parcial: El estudiante fue guardado en <strong>MongoDB Atlas</strong>, pero falló la inserción en <strong>PostgreSQL</strong>. Detalle: " . htmlspecialchars($pg_error);
            $status_type = "warning";
        } else {
            $status_message = "Error crítico: No se pudo almacenar la información en ningún soporte de datos. Verifique los registros de conexión.";
            $status_type = "error";
        }
    }
}


// --- 3. CONSULTA DE LISTADOS EN AMBAS BASES DE DATOS ---

$estudiantes_pg = [];
if ($pg_connected && $pdo) {
    try {
        $stmt_list = $pdo->query("SELECT * FROM estudiantes ORDER BY fecha_registro DESC");
        $estudiantes_pg = $stmt_list->fetchAll();
    } catch (PDOException $e) {
        $pg_error = "Error al consultar PostgreSQL: " . $e->getMessage();
    }
}

$estudiantes_mongo = [];
if ($mongo_connected && $mongo_collection) {
    try {
        $cursor = $mongo_collection->find([], ['sort' => ['fecha_respaldo' => -1]]);
        $estudiantes_mongo = iterator_to_array($cursor);
    } catch (Exception $e) {
        $mongo_error = "Error al consultar MongoDB: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizador Escolar: PostgreSQL & MongoDB</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">

    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-700 to-indigo-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-5 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-white/10 rounded-lg">
                    <svg class="w-8 h-8 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Sincronizador Multimotor de Estudiantes</h1>
                    <p class="text-xs text-blue-100">Desplegado en Render • Replicación Relacional a NoSQL</p>
                </div>
            </div>
            
            <!-- Indicadores de estado de Base de Datos -->
            <div class="flex gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $pg_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $pg_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span>
                    PostgreSQL
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $mongo_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $mongo_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span>
                    MongoDB Atlas
                </span>
            </div>
        </div>
    </header>

    <!-- Contenido Principal -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Formulario de Registro (Columna Izquierda) -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 sticky top-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    Registrar Estudiante
                </h2>

                <!-- Tarjetas de estado / Mensajes de respuesta -->
                <?php if ($status_message): ?>
                    <div class="mb-5 p-4 rounded-lg text-sm border <?php 
                        if ($status_type === 'success') echo 'bg-emerald-50 border-emerald-200 text-emerald-800';
                        elseif ($status_type === 'warning') echo 'bg-amber-50 border-amber-200 text-amber-800';
                        else echo 'bg-rose-50 border-rose-200 text-rose-800';
                    ?>">
                        <?php echo $status_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($pg_error && !$pg_connected): ?>
                    <div class="mb-4 p-3 bg-rose-50 border border-rose-100 text-rose-700 rounded-lg text-xs">
                        <strong>Error Postgres:</strong> <?php echo htmlspecialchars($pg_error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($mongo_error && !$mongo_connected): ?>
                    <div class="mb-4 p-3 bg-rose-50 border border-rose-100 text-rose-700 rounded-lg text-xs">
                        <strong>Error MongoDB:</strong> <?php echo htmlspecialchars($mongo_error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Código Estudiante *</label>
                        <input type="text" name="codigo" required placeholder="Ej: EST-2026-01" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Nombre Completo *</label>
                        <input type="text" name="nombre" required placeholder="Ej: Juan Pérez" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                        <input type="email" name="email" required placeholder="Ej: juan.perez@universidad.com" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Programa Académico *</label>
                        <input type="text" name="programa" required placeholder="Ej: Ingeniería de Sistemas" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>

                    <button type="submit" name="registrar" class="w-full bg-gradient-to-r from-blue-700 to-indigo-700 hover:from-blue-800 hover:to-indigo-800 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition-all text-sm flex items-center justify-center gap-2 mt-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                        </svg>
                        Guardar & Sincronizar
                    </button>
                </form>
            </div>
        </div>

        <!-- Vista de Datos en Paralelo (Columna Derecha / Centro) -->
        <div class="lg:col-span-2 space-y-8">
            
            <!-- Listado PostgreSQL -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <span class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                        Listado PostgreSQL (SQL)
                    </span>
                    <span class="text-xs font-semibold bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full">
                        <?php echo count($estudiantes_pg); ?> estudiantes
                    </span>
                </h2>

                <?php if (empty($estudiantes_pg)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">
                        No hay estudiantes registrados en PostgreSQL.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-2">Código</th>
                                    <th class="py-3 px-2">Nombre</th>
                                    <th class="py-3 px-2">Email</th>
                                    <th class="py-3 px-2">Programa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_pg as $est): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="py-3 px-2 font-mono text-xs font-bold text-slate-900"><?php echo htmlspecialchars($est['codigo']); ?></td>
                                        <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($est['nombre']); ?></td>
                                        <td class="py-3 px-2 text-slate-500"><?php echo htmlspecialchars($est['email']); ?></td>
                                        <td class="py-3 px-2 text-xs"><span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded"><?php echo htmlspecialchars($est['programa']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Listado MongoDB Atlas -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <span class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>
                        Listado MongoDB Atlas (NoSQL - BSON)
                    </span>
                    <span class="text-xs font-semibold bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full">
                        <?php echo count($estudiantes_mongo); ?> documentos
                    </span>
                </h2>

                <?php if (empty($estudiantes_mongo)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">
                        No hay documentos de estudiantes respaldados en MongoDB Atlas.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-2">_id (Mongo Object)</th>
                                    <th class="py-3 px-2">Código</th>
                                    <th class="py-3 px-2">Nombre</th>
                                    <th class="py-3 px-2">Propiedades NoSQL</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_mongo as $doc): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="py-3 px-2 font-mono text-[10px] text-slate-400" title="<?php echo (string)$doc['_id']; ?>">
                                            <?php echo substr((string)$doc['_id'], -8); ?>... [ObjectID]
                                        </td>
                                        <td class="py-3 px-2 font-mono text-xs font-bold text-slate-900"><?php echo htmlspecialchars($doc['codigo'] ?? ''); ?></td>
                                        <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($doc['nombre'] ?? ''); ?></td>
                                        <td class="py-3 px-2">
                                            <div class="text-[10px] bg-emerald-50 text-emerald-800 p-1 rounded font-mono">
                                                { email: "<?php echo htmlspecialchars($doc['email'] ?? ''); ?>", prog: "<?php echo htmlspecialchars($doc['programa'] ?? ''); ?>" }
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-6 mt-12 border-t border-slate-800 text-xs">
        <div class="max-w-7xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div>
                <p>© 2026 - Aplicación de Sincronización Estudiantil Dual.</p>
                <p class="text-slate-600">PostgreSQL (Modelo Relacional Estricto) & MongoDB Atlas (Modelo Documental Dinámico)</p>
            </div>
            <div class="flex gap-4">
                <span class="hover:text-white transition-colors">PHP 8.1+</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">PDO pgsql</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">Composer MongoDB</span>
            </div>
        </div>
    </footer>

</body>
</html>