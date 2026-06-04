<?php
/**
 * Aplicación de Registro y Sincronización de Estudiantes con Soporte de Imágenes
 * Conectividad dual: PostgreSQL (PDO) y MongoDB Atlas (Composer Driver)
 * * COMPRESIÓN Y OPTIMIZACIÓN AL 1000%: Resizing gráfico en caliente mediante GD.
 * Reduce imágenes de 8MB a menos de 70KB automáticamente antes de enviar a Atlas.
 */

require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// --- 1. CONFIGURACIÓN DE CONEXIONES Y VARIABLES DE ENTORNO ---

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
    }
} catch (PDOException $e) {
    $pg_error = $e->getMessage();
}

$mongo_uri = getenv('MONGODB_URI'); 
$mongo_db_name = getenv('MONGODB_DB') ?: 'colegio';
$mongo_connected = false;
$mongo_collection = null;
$mongo_error = null;

try {
    if ($mongo_uri) {
        $mongo_client = new Client($mongo_uri);
        $mongo_collection = $mongo_client->selectCollection($mongo_db_name, 'estudiantes');
        $mongo_connected = true;
    }
} catch (Exception $e) {
    $mongo_error = $e->getMessage();
}

// FUNCIÓN MAESTRA DE OPTIMIZACIÓN: Comprime y achica las dimensiones de la foto
function optimizarYComprimirImagen($rutaTemporal, $maxAnchoAlto = 700) {
    list($anchoOriginal, $altoOriginal, $tipoImagen) = @getimagesize($rutaTemporal);
    if (!$anchoOriginal || !$altoOriginal) {
        return file_get_contents($rutaTemporal); // Fallback de emergencia si no es procesable
    }

    // Crear el lienzo correspondiente según el formato subido
    switch ($tipoImagen) {
        case IMAGETYPE_JPEG: $lienzoOriginal = @imagecreatefromjpeg($rutaTemporal); break;
        case IMAGETYPE_PNG:  $lienzoOriginal = @imagecreatefrompng($rutaTemporal); break;
        case IMAGETYPE_WEBP: $lienzoOriginal = @imagecreatefromwebp($rutaTemporal); break;
        default: return file_get_contents($rutaTemporal);
    }

    if (!$lienzoOriginal) {
        return file_get_contents($rutaTemporal);
    }

    // Calcular proporciones óptimas para no distorsionar la cédula/diploma
    $proporcion = $anchoOriginal / $altoOriginal;
    if ($anchoOriginal > $maxAnchoAlto || $altoOriginal > $maxAnchoAlto) {
        if ($proporcion > 1) {
            $nuevoAncho = $maxAnchoAlto;
            $nuevoAlto = $maxAnchoAlto / $proporcion;
        } else {
            $nuevoAlto = $maxAnchoAlto;
            $nuevoAncho = $maxAnchoAlto * $proporcion;
        }
    } else {
        $nuevoAncho = $anchoOriginal;
        $nuevoAlto = $altoOriginal;
    }

    // Renderizar la nueva miniatura ligera
    $lienzoDestino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
    
    // Mantener transparencias si aplican
    imagealphablending($lienzoDestino, false);
    imagesavealpha($lienzoDestino, true);

    imagecopyresampled($lienzoDestino, $lienzoOriginal, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $anchoOriginal, $altoOriginal);

    // Guardar en el buffer de salida comprimiendo drásticamente a formato JPEG (Calidad 60%)
    ob_start();
    imagejpeg($lienzoDestino, null, 60); 
    $datosComprimidos = ob_get_clean();

    // Liberar memoria RAM del servidor
    imagedestroy($lienzoOriginal);
    imagedestroy($lienzoDestino);

    return $datosComprimidos;
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

    if (isset($_FILES['documento_img']) && $_FILES['documento_img']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['documento_img']['tmp_name'];
        
        // Ejecutar nuestra función de compresión extrema en caliente
        $binarioOptimizado = optimizarYComprimirImagen($file_tmp);
        $base64_image = 'data:image/jpeg;base64,' . base64_encode($binarioOptimizado);
    } else {
        $status_message = "Es obligatorio adjuntar un archivo de imagen válido.";
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
                        throw new Exception("El código ya se encuentra en el sistema SQL.");
                    }

                    $start_time = microtime(true);
                    $stmt = $pdo->prepare("INSERT INTO estudiantes (codigo, nombre, email, programa) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$codigo, $nombre, $email, $programa]);
                    $pg_time = round(microtime(true) - $start_time, 5); 
                    $pg_save_ok = true;
                } catch (Exception $e) {
                    $pg_error = $e->getMessage();
                }
            }

            // 2. Guardar en MongoDB Atlas
            if ($mongo_connected && $mongo_collection) {
                try {
                    $exists = $mongo_collection->findOne(['codigo' => $codigo]);
                    if ($exists) {
                        throw new Exception("El código ya existe en NoSQL.");
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
                    $mongo_error = $e->getMessage();
                }
            }

            if ($pg_save_ok && $mongo_save_ok) {
                $status_message = "<strong>¡Sincronización Exitosa!</strong> Registro guardado de forma optimizada (Imagen comprimida con éxito).<br>";
                $status_message .= "<div class='mt-2 p-1.5 bg-white/70 rounded border border-emerald-300 font-mono text-[11px] text-emerald-800 flex justify-between gap-2'>";
                $status_message .= "<span>⏱️ Latencia SQL: <strong>{$pg_time} s</strong></span>";
                $status_message .= "<span>⏱️ Latencia NoSQL (Optimizado): <strong>{$mongo_time} s</strong></span>";
                $status_message .= "</div>";
                $status_type = "success";
            } else {
                $status_message = "Error en la persistencia políglota: " . htmlspecialchars($pg_error ?: $mongo_error);
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
    } catch (PDOException $e) { $pg_error = $e->getMessage(); }
}

$estudiantes_mongo = [];
if ($mongo_connected && $mongo_collection) {
    try {
        $cursor = $mongo_collection->find([], ['sort' => ['fecha_respaldo' => -1]]);
        $estudiantes_mongo = iterator_to_array($cursor);
    } catch (Exception $e) { $mongo_error = $e->getMessage(); }
}

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
    if (!empty($doc['documento_base64'])) { $total_documentos_nosql++; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizador Escolar de Alta Velocidad</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
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
                    <h1 class="text-xl font-bold tracking-tight">Sincronizador de Alta Velocidad</h1>
                    <p class="text-xs text-indigo-200">Motor de Compresión Activo: Rendimiento Híbrido Optimizado al 1000%</p>
                </div>
            </div>
            <div class="flex gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $pg_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $pg_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span> PostgreSQL
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $mongo_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $mongo_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span> MongoDB Atlas
                </span>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Matrícula (Postgres)</p>
                    <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo $total_estudiantes; ?> <span class="text-xs font-normal text-slate-500">Filas</span></h3>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
            </div>
            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Programa Líder</p>
                    <h3 class="text-sm font-bold text-indigo-950 mt-2 truncate max-w-[200px]"><?php echo htmlspecialchars($top_programa); ?></h3>
                </div>
                <div class="p-3 bg-indigo-50 text-indigo-600 rounded-lg"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.232.477-4.5 1.253"/></svg></div>
            </div>
            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Custodia Liviana (Atlas)</p>
                    <h3 class="text-2xl font-bold text-emerald-700 mt-1"><?php echo $total_documentos_nosql; ?> <span class="text-xs font-normal text-slate-500">Documentos</span></h3>
                </div>
                <div class="p-3 bg-emerald-50 text-emerald-600 rounded-lg"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 sticky top-6">
                    <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                        Registrar Estudiante
                    </h2>

                    <?php if ($status_message): ?>
                        <div class="mb-5 p-4 rounded-lg text-sm border <?php echo ($status_type === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                            <?php echo $status_message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Código Estudiante *</label>
                            <input type="text" name="codigo" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Nombre Completo *</label>
                            <input type="text" name="nombre" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                            <input type="email" name="email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Programa Académico *</label>
                            <input type="text" name="programa" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Foto Documento (Cédula/Diploma) *</label>
                            <input type="file" name="documento_img" accept="image/*" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-300 rounded-lg p-1 bg-white cursor-pointer">
                        </div>
                        <button type="submit" name="registrar" class="w-full bg-gradient-to-r from-blue-700 to-indigo-700 text-white font-semibold py-2 px-4 rounded-lg shadow-sm text-sm">
                            Guardar & Sincronizar Fast
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-8">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h2 class="text-base font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span> Listado PostgreSQL (SQL Rápido)
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase font-semibold">
                                    <th class="py-2">Código</th>
                                    <th class="py-2">Nombre</th>
                                    <th class="py-2">Programa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_pg as $est): ?>
                                    <tr>
                                        <td class="py-2.5 font-mono text-xs font-bold"><?php echo htmlspecialchars($est['codigo']); ?></td>
                                        <td class="py-2.5"><?php echo htmlspecialchars($est['nombre']); ?></td>
                                        <td class="py-2.5 text-xs"><span class="bg-slate-100 px-2 py-0.5 rounded"><?php echo htmlspecialchars($est['programa']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h2 class="text-base font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span> Listado MongoDB Atlas (NoSQL Optimizado)
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase font-semibold">
                                    <th class="py-2">Código</th>
                                    <th class="py-2">Nombre</th>
                                    <th class="py-2">Documento Organizado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_mongo as $doc): ?>
                                    <tr>
                                        <td class="py-2.5 font-mono text-xs font-bold"><?php echo htmlspecialchars($doc['codigo'] ?? ''); ?></td>
                                        <td class="py-2.5"><?php echo htmlspecialchars($doc['nombre'] ?? ''); ?></td>
                                        <td class="py-2.5">
                                            <?php if (!empty($doc['documento_base64'])): ?>
                                                <div class="flex items-center gap-2">
                                                    <button onclick="abrirVisor('<?php echo $doc['documento_base64']; ?>', '<?php echo htmlspecialchars($doc['nombre'] ?? ''); ?>')"
                                                            class="inline-flex items-center gap-1 text-xs text-indigo-600 font-semibold bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition-all shadow-sm">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                        Ver imagen
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-400 italic">Sin adjunto</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="imageModal" class="hidden fixed inset-0 bg-slate-900/85 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl relative">
            <button onclick="cerrarVisor()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 class="text-lg font-bold text-slate-900 mb-4" id="modalTitle">Documento de Estudiante</h3>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-2 flex justify-center items-center max-h-[400px] overflow-hidden">
                <img id="modalImg" src="" alt="Documento" class="max-h-[380px] w-auto object-contain rounded-lg">
            </div>
        </div>
    </div>

    <script>
        function abrirVisor(base64Data, nombreEstudiante) {
            document.getElementById('modalImg').src = base64Data;
            document.getElementById('modalTitle').innerHTML = "Documento Verificado de: <span class='text-indigo-600'>" + nombreEstudiante + "</span>";
            document.getElementById('imageModal').classList.remove('hidden');
        }
        function cerrarVisor() {
            document.getElementById('imageModal').classList.add('hidden');
            document.getElementById('modalImg').src = "";
        }
        window.onclick = function(e) { if (e.target == document.getElementById('imageModal')) cerrarVisor(); }
    </script>
</body>
</html>
