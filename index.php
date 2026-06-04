<?php
/**
 * Aplicación de Registro y Sincronización de Estudiantes con Soporte de Imágenes
 * Conectividad dual: PostgreSQL (PDO) y MongoDB Atlas (Composer Driver)
 * * MODIFICACIÓN ULTRA-ROBUSTA: Acepta cualquier tipo de archivo de imagen,
 * tamaño, nombres sin restricciones ni excepciones.
 */

require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// --- 1. CONFIGURACIÓN DE CONEXIONES Y VARIABLES DE ENTORNO ---

// Configuración PostgreSQL
$pg_url = getenv('DATABASE_URL'); 
$pg_connected = false;
$pdo = null;
$pg_error = null;

try {
    if ($pg_url) {
        $dbopts = parse_url($pg_url);
        $pg_host = $dbopts["host"] ?? '';
        $pg_port = $dbopts["port"] ?? 5432;
        $pg_user = $dbopts["user"] ?? '';
        $pg_pass = $dbopts["pass"] ?? '';
        $pg_db   = ltrim($dbopts["path"] ?? '', '/');
    } else {
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
$mongo_uri = getenv('MONGODB_URI'); 
$mongo_db_name = getenv('MONGODB_DB') ?: 'colegio';
$mongo_connected = false;
$mongo_collection = null;
$mongo_error = null;

try {
    if ($mongo_uri) {
        if (!extension_loaded('mongodb')) {
            throw new Exception("La extensión PHP 'mongodb' no está instalada o cargada.");
        }
        $mongo_client = new Client($mongo_uri);
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
$status_type = null; 
$pg_save_ok = false;
$mongo_save_ok = false;

$pg_time = 0;
$mongo_time = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar'])) {
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $programa = trim($_POST['programa'] ?? '');
    $base64_image = null;

    // LÓGICA DE PROCESAMIENTO TOTAL: Cero filtros restrictivos de extensión o tamaño
    if (isset($_FILES['documento_img']) && $_FILES['documento_img']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['documento_img']['tmp_name'];
        $file_type = $_FILES['documento_img']['type'];
        
        // Si por fallos del navegador no detecta el MIME-type, forzamos uno genérico seguro
        if (empty($file_type)) {
            $file_type = 'image/jpeg';
        }
        
        // Leemos cualquier flujo binario sin importar el peso o nombre original
        $file_data = @file_get_contents($file_tmp);
        if ($file_data !== false) {
            $base64_image = 'data:' . $file_type . ';base64,' . base64_encode($file_data);
        } else {
            $status_message = "Error al procesar la lectura del archivo adjunto.";
            $status_type = "error";
        }
    } else {
        // Clasificación de errores del servidor para guiar al usuario sin bloquearlo
        $upload_error = $_FILES['documento_img']['error'] ?? UPLOAD_ERR_NO_FILE;
        switch ($upload_error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $status_message = "El archivo supera la capacidad máxima del servidor (100MB). Elige una foto más ligera.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $status_message = "Es obligatorio seleccionar un archivo de imagen para el documento.";
                break;
            default:
                $status_message = "No se pudo cargar el archivo correctamente (Código de error PHP: " . $upload_error . ").";
                break;
        }
        $status_type = "error";
    }

    if ($status_type !== 'error') {
        if (empty($codigo) || empty($nombre) || empty($email) || empty($programa)) {
            $status_message = "Todos los campos de texto son de carácter obligatorio.";
            $status_type = "error";
        } else {
            // 1. Guardar en PostgreSQL
            if ($pg_connected && $pdo) {
                try {
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM estudiantes WHERE codigo = ?");
                    $stmt_check->execute([$codigo]);
                    if ($stmt_check->fetchColumn() > 0) {
                        throw new Exception("El código de estudiante ya existe en PostgreSQL.");
                    }

                    $start_time = microtime(true);
                    $stmt = $pdo->prepare("INSERT INTO estudiantes (codigo, nombre, email, programme) VALUES (?, ?, ?, ?)");
                    // NOTA: Ajusta a 'programme' o 'programa' según se llame tu columna exacta de PostgreSQL
                    $stmt = $pdo->prepare("INSERT INTO estudiantes (codigo, nombre, email, programa) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$codigo, $nombre, $email, $programa]);
                    $pg_time = round(microtime(true) - $start_time, 5); 
                    
                    $pg_save_ok = true;
                } catch (Exception $e) {
                    $pg_error = "Error al insertar en PostgreSQL: " . $e->getMessage();
                }
            }

            // 2. Guardar en MongoDB Atlas
            if ($mongo_connected && $mongo_collection) {
                try {
                    $exists = $mongo_collection->findOne(['codigo' => $codigo]);
                    if ($exists) {
                        throw new Exception("El código de estudiante ya existe en MongoDB.");
                    }

                    $start_time = microtime(true);
                    $insertResult = $mongo_collection->insertOne([
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'email' => $email,
                        'programa' => $programa,
                        'documento_base64' => $base64_image, 
                        'fecha_respaldo' => new UTCDateTime()
                    ]);
                    $mongo_time = round(microtime(true) - $start_time, 5); 
                    
                    if ($insertResult->getInsertedCount() > 0) {
                        $mongo_save_ok = true;
                    }
                } catch (Exception $e) {
                    $mongo_error = "Error al insertar en MongoDB Atlas: " . $e->getMessage();
                }
            }

            // Evaluación de Sincronización + Benchmark
            if ($pg_save_ok && $mongo_save_ok) {
                $status_message = "<strong>¡Sincronización Exitosa!</strong> El estudiante fue registrado en PostgreSQL y su respaldo multimedia sin restricciones se guardó en MongoDB Atlas.<br>";
                $status_message .= "<div class='mt-2 p-1.5 bg-white/70 rounded border border-emerald-300 font-mono text-[11px] text-emerald-800 flex justify-between gap-2'>";
                $status_message .= "<span>⏱️ Latencia SQL: <strong>{$pg_time} s</strong></span>";
                $status_message .= "<span>⏱️ Latencia NoSQL (Con Archivo): <strong>{$mongo_time} s</strong></span>";
                $status_message .= "</div>";
                $status_type = "success";
            } elseif ($pg_save_ok && !$mongo_save_ok) {
                $status_message = "Registro parcial: Guardado en PostgreSQL ({$pg_time} s), pero falló el respaldo multimedia en MongoDB Atlas. Detalle: " . htmlspecialchars($mongo_error);
                $status_type = "warning";
            } elseif (!$pg_save_ok && $mongo_save_ok) {
                $status_message = "Registro parcial: Guardado en MongoDB Atlas ({$mongo_time} s), pero falló el relacional en PostgreSQL. Detalle: " . htmlspecialchars($pg_error);
                $status_type = "warning";
            } else {
                $status_message = "Error general: No se pudo registrar en ningún motor de base de datos.";
                $status_type = "error";
            }
        }
    }
}


// --- 3. CONSULTA DE LISTADOS ---

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

// Cálculos para Dashboard
$total_estudiantes = count($estudiantes_pg);
$top_programa = "Ninguno";
if ($total_estudiantes > 0) {
    $programas_array = array_column($estudiantes_pg, 'programa');
    $conteo_programas = array_count_values($programas_array);
    arsort($conteo_programas);
    $top_programa = array_key_first($conteo_programas);
}

$total_documentos_nosql = 0;
foreach ($estudiantes_mongo as $doc) {
    if (!empty($doc['documento_base64'])) {
        $total_documentos_nosql++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizador Avanzado: Arquitectura Híbrida Estudiantil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">

    <header class="bg-gradient-to-r from-slate-900 via-indigo-950 to-blue-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-5 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-white/10 rounded-lg">
                    <svg class="w-8 h-8 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Sincronizador Avanzado Multimotor</h1>
                    <p class="text-xs text-indigo-200">Panel Completo: Tolerancia a Fallos Multimedia y Benchmarking Remoto</p>
                </div>
            </div>
            
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

    <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8">
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Matrícula Total (Postgres)</p>
                    <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo $total_estudiantes; ?> <span class="text-xs font-normal text-slate-500">Filas SQL</span></h3>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
            </div>

            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Programa Mayor Demanda</p>
                    <h3 class="text-sm font-bold text-indigo-950 mt-2 truncate max-w-[200px]" title="<?php echo htmlspecialchars($top_programa); ?>">
                        <?php echo htmlspecialchars($top_programa); ?>
                    </h3>
                </div>
                <div class="p-3 bg-indigo-50 text-indigo-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.232.477-4.5 1.253"/>
                    </svg>
                </div>
            </div>

            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Custodia Multimedia (Atlas)</p>
                    <h3 class="text-2xl font-bold text-emerald-700 mt-1"><?php echo $total_documentos_nosql; ?> <span class="text-xs font-normal text-slate-500">Documentos BSON</span></h3>
                </div>
                <div class="p-3 bg-emerald-50 text-emerald-600 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 sticky top-6">
                    <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        Registrar Estudiante
                    </h2>

                    <?php if ($status_message): ?>
                        <div class="mb-5 p-4 rounded-lg text-sm border <?php 
                            if ($status_type === 'success') echo 'bg-emerald-50 border-emerald-200 text-emerald-800';
                            elseif ($status_type === 'warning') echo 'bg-amber-50 border-amber-200 text-amber-800';
                            else echo 'bg-rose-50 border-rose-200 text-rose-800';
                        ?>">
                            <?php echo $status_message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Código Estudiante *</label>
                            <input type="text" name="codigo" required placeholder="Ej: EST-2026-05" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Nombre Completo *</label>
                            <input type="text" name="nombre" required placeholder="Ej: Brayan Mendoza" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                            <input type="email" name="email" required placeholder="Ej: brayan@universidad.com" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Programa Académico *</label>
                            <input type="text" name="programa" required placeholder="Ej: Ingeniería de Software" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Foto Documento (Cualquier Formato/Tamaño) *</label>
                            <input type="file" name="documento_img" accept="image/*,application/pdf" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-300 rounded-lg p-1 bg-white cursor-pointer">
                            <p class="text-[10px] text-slate-400 mt-1">Soporta fotos masivas e imágenes móviles sin restricciones.</p>
                        </div>

                        <button type="submit" name="registrar" class="w-full bg-gradient-to-r from-blue-700 to-indigo-700 hover:from-blue-800 hover:to-indigo-800 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition-all text-sm flex items-center justify-center gap-2 mt-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Guardar & Sincronizar
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-8">
                
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                            Listado PostgreSQL (SQL Estructurado - Sin Imágenes)
                        </span>
                        <span class="text-xs font-semibold bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full">
                            <?php echo count($estudiantes_pg); ?> registros
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

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>
                            Listado MongoDB Atlas (NoSQL - Con Imagen Base64)
                        </span>
                        <span class="text-xs font-semibold bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full">
                            <?php echo count($estudiantes_mongo); ?> documentos
                        </span>
                    </h2>

                    <?php if (empty($estudiantes_mongo)): ?>
                        <div class="text-center py-8 text-slate-400 text-sm">
                            No hay documentos respaldados en MongoDB Atlas.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm border-collapse">
                                <thead>
                                    <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                                        <th class="py-3 px-2">Código</th>
                                        <th class="py-3 px-2">Nombre</th>
                                        <th class="py-3 px-2">Documento de Respaldo</th>
                                        <th class="py-3 px-2">Metadatos BSON</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <?php foreach ($estudiantes_mongo as $doc): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors">
                                            <td class="py-3 px-2 font-mono text-xs font-bold text-slate-900"><?php echo htmlspecialchars($doc['codigo'] ?? ''); ?></td>
                                            <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($doc['nombre'] ?? ''); ?></td>
                                            
                                            <td class="py-3 px-2">
                                                <?php if (!empty($doc['documento_base64'])): ?>
                                                    <div class="flex items-center gap-3">
                                                        <img src="<?php echo $doc['documento_base64']; ?>" 
                                                             class="w-10 h-10 object-cover rounded-lg border border-slate-200 shadow-sm cursor-pointer hover:ring-2 hover:ring-indigo-500 transition-all"
                                                             onclick="abrirVisor('<?php echo $doc['documento_base64']; ?>', '<?php echo htmlspecialchars($doc['nombre'] ?? ''); ?>')">
                                                        
                                                        <button onclick="abrirVisor('<?php echo $doc['documento_base64']; ?>', '<?php echo htmlspecialchars($doc['nombre'] ?? ''); ?>')"
                                                                class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-semibold bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1.5 rounded-lg transition-all shadow-sm">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                            </svg>
                                                            Ver imagen
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-xs text-slate-400 italic">Sin documento cargado</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td class="py-3 px-2">
                                                <div class="text-[9px] bg-emerald-50 text-emerald-800 p-1.5 rounded-lg font-mono max-w-xs truncate" title="Objeto BSON completo">
                                                    { id: "<?php echo (string)$doc['_id']; ?>", has_image: <?php echo !empty($doc['documento_base64']) ? 'true' : 'false'; ?> }
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
        </div>
    </main>

    <div id="imageModal" class="hidden fixed inset-0 bg-slate-900/85 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl relative animate-in fade-in duration-200">
            <button onclick="cerrarVisor()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <h3 class="text-lg font-bold text-slate-900 mb-1" id="modalTitle">Documento de Estudiante</h3>
            <p class="text-xs text-slate-500 mb-4">Esta imagen se encuentra almacenada de forma estructurada directamente en tu base de datos MongoDB Atlas (Formato BSON - Base64).</p>
            
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-2 flex justify-center items-center overflow-hidden max-h-[450px]">
                <img id="modalImg" src="" alt="Documento oficial del estudiante" class="max-h-[400px] w-auto object-contain rounded-lg shadow-sm">
            </div>
        </div>
    </div>

    <footer class="bg-slate-900 text-slate-400 py-6 mt-12 border-t border-slate-800 text-xs">
        <div class="max-w-7xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div>
                <p>© 2026 - Aplicación de Sincronización Estudiantil Dual.</p>
                <p class="text-slate-600">PostgreSQL (Relacional Estricto - Estructura) & MongoDB Atlas (Documental - Multimedia Base64)</p>
            </div>
            <div class="flex gap-4">
                <span class="hover:text-white transition-colors">PHP 8.2</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">PDO PostgreSQL</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">Base64 en MongoDB</span>
            </div>
        </div>
    </footer>

    <script>
        function abrirVisor(base64Data, nombreEstudiante) {
            document.getElementById('modalImg').src = base64Data;
            document.getElementById('modalTitle').innerHTML = "Documento Oficial de: <span class='text-indigo-600'>" + nombreEstudiante + "</span>";
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function cerrarVisor() {
            document.getElementById('imageModal').classList.add('hidden');
            document.getElementById('modalImg').src = "";
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                cerrarVisor();
            }
        }
    </script>

</body>
</html>
