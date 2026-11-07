<?php
/* =====================================================================
   ShareTV — motor.php (supervisor, se ejecuta con php.exe en consola)

   ShareTV es 100% RED LOCAL: sirve tu cine dentro de tu WiFi, SIN
   internet, sin túnel, sin Telegram y sin cuentas. Para verlo desde
   fuera de casa ya está CineBox; esto es otra cosa.

   El motor mantiene vivos tres procesos hijos:
     · web     — el servidor de siempre (servidor.php en el puerto 8899)
     · faro    — anuncia el servidor por la WiFi (UDP 8898) para que la
                 app del TV lo encuentre sola, sin escribir direcciones
     · scrapeo — pósters y datos; es lo ÚNICO que usa internet, y si no
                 hay, simplemente espera y reintenta (servidor.php)

   La salida de los hijos va a ARCHIVOS de registro (nunca a tuberías:
   en Windows las tuberías de proc_open pueden bloquear al supervisor).
   ===================================================================== */
@ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

$BASE   = __DIR__;
$DATOS  = $BASE . '/datos';
if (!is_dir($DATOS)) mkdir($DATOS, 0777, true);
$PUERTO = 8899;
$WIN    = PHP_OS_FAMILY === 'Windows';
$LOGWEB = $DATOS . '/web.log';

function leer_json($f, $def = []) { $j = @file_get_contents($f); $d = $j ? json_decode($j, true) : null; return is_array($d) ? $d : $def; }
function guardar_json($f, $d) { @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function decir($m) { echo '[' . date('H:i:s') . '] ' . $m . PHP_EOL; @ob_flush(); flush(); }

/* ================= FARO DE RED LOCAL (php motor.php --faro) =================
   Escucha preguntas UDP «SHARETV?» en la WiFi y responde «SHARETV!<puerto>».
   El cliente aprende la IP del propio paquete de respuesta, así que da
   igual que la IP de esta PC cambie: el faro no necesita saberla. */
if (($argv[1] ?? '') === '--faro') {
    $s = function_exists('socket_create') ? @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP) : false;
    if (!$s) { decir('Faro: este PHP no trae sockets; el cliente usará la dirección guardada.'); exit(1); }
    @socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
    if (!@socket_bind($s, '0.0.0.0', 8898)) { decir('Faro: el puerto UDP 8898 está ocupado.'); exit(1); }
    @socket_set_nonblock($s);
    decir('Faro de red local activo: anunciando ShareTV en tu WiFi (UDP 8898).');
    $resp = 'SHARETV!' . $PUERTO;
    while (true) {
        $ctlF = leer_json($DATOS . '/control.json', []);
        if (!empty($ctlF['salir'])) break;
        $lec = [$s]; $esc = null; $exc = null;
        if (@socket_select($lec, $esc, $exc, 1) > 0) {
            $buf = ''; $ip = ''; $pt = 0;
            $n = @socket_recvfrom($s, $buf, 64, 0, $ip, $pt);
            if ($n !== false && strpos((string)$buf, 'SHARETV?') === 0) @socket_sendto($s, $resp, strlen($resp), 0, $ip, $pt);
        }
    }
    @socket_close($s);
    exit(0);
}

/* ---------- limpiar restos de una corrida anterior (solo los nuestros) ---------- */
$pidsViejos = leer_json($DATOS . '/pids.json', []);
foreach (['web', 'scrapeo', 'faro'] as $k) {
    $pid = intval($pidsViejos[$k] ?? 0);
    if ($pid > 0) {
        if ($WIN) @shell_exec('taskkill /PID ' . $pid . ' /F 2>nul');
        else @posix_kill($pid, 9);
    }
}
@unlink($DATOS . '/pids.json');

/* ---------- la orden de apagar es de UN SOLO USO ----------
   Si la corrida anterior terminó con «Apagar todo», el salir=true quedó
   grabado en control.json. Sin esto, el motor nuevo lo lee al nacer y se
   apaga en el mismo segundo. Se descarta al arrancar. */
$ctl0 = leer_json($DATOS . '/control.json', ['salir' => false]);
if (!empty($ctl0['salir'])) {
    $ctl0['salir'] = false;
    guardar_json($DATOS . '/control.json', $ctl0);
    decir('Había una orden de apagado de la vez anterior; la descarto y arranco normal.');
}

/* ---------- estado ---------- */
$ESTADO = ['aviso' => null, 'desde' => date('c')];
function publicar_estado() { global $ESTADO, $DATOS; $ESTADO['vivo'] = date('c'); guardar_json($DATOS . '/estado.json', $ESTADO); }
publicar_estado();

/* ---------- procesos hijos (salida a archivos, sin tuberías) ---------- */
$hijos = ['web' => null, 'scrapeo' => null, 'faro' => null];
function lanzar($cual, $cmd, $log) {
    global $hijos, $BASE, $DATOS;
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $proc = @proc_open($cmd, $desc, $pipes, $BASE);
    if (!is_resource($proc)) { decir("No pude lanzar $cual"); return false; }
    fclose($pipes[0]);
    $hijos[$cual] = $proc;
    $st = proc_get_status($proc);
    $pids = leer_json($DATOS . '/pids.json', []);
    $pids[$cual] = $st['pid'];
    guardar_json($DATOS . '/pids.json', $pids);
    decir(ucfirst($cual) . ' lanzado (PID ' . $st['pid'] . ')');
    return true;
}
function vivo($cual) {
    global $hijos;
    if (!$hijos[$cual]) return false;
    $st = proc_get_status($hijos[$cual]);
    return $st['running'];
}
function matar($cual) {
    global $hijos, $WIN;
    if (!$hijos[$cual]) return;
    $st = proc_get_status($hijos[$cual]);
    if ($st['running']) {
        if ($WIN) @shell_exec('taskkill /PID ' . $st['pid'] . ' /T /F 2>nul');
        else { @posix_kill($st['pid'], 15); }
        @proc_terminate($hijos[$cual], 9);
    }
    @proc_close($hijos[$cual]);
    $hijos[$cual] = null;
    decir(ucfirst($cual) . ' detenido');
}
register_shutdown_function(function () { matar('web'); matar('scrapeo'); matar('faro'); @unlink(__DIR__ . '/datos/pids.json'); });

/* ---------- arranque ---------- */
decir('ShareTV — tu cine en tu red local, sin internet.');
decir('Panel: http://localhost:' . $PUERTO . '/panel');
decir('En tu WiFi estoy en http://' . gethostbyname(gethostname()) . ':' . $PUERTO . ' — la app del TV me encuentra sola, no hay que escribir nada.');
@file_put_contents($LOGWEB, '');
lanzar('web', [PHP_BINARY, '-S', '0.0.0.0:' . $PUERTO, '-t', $BASE, $BASE . '/servidor.php'], $LOGWEB);
lanzar('faro', [PHP_BINARY, $BASE . '/motor.php', '--faro'], $DATOS . '/faro.log');

$ultimoLatido = 0;
$ultimoScrapeo = time() - 585; // la primera pasada de scrapeo sale a los ~15 s
$ultimoFaro = time();          // último (re)lanzamiento del faro
$avisoActual = null;           // último aviso publicado

while (true) {
    $cfg = leer_json($DATOS . '/config.json', []);
    $ctl = leer_json($DATOS . '/control.json', ['salir' => false]);

    if (!empty($ctl['salir'])) { $ctl['salir'] = false; guardar_json($DATOS . '/control.json', $ctl); decir('Orden de apagar recibida. Cerrando…'); break; }

    // Servidor web siempre vivo
    if (!vivo('web')) { decir('El servidor web se detuvo; relanzando…'); lanzar('web', [PHP_BINARY, '-S', '0.0.0.0:' . $PUERTO, '-t', $BASE, $BASE . '/servidor.php'], $LOGWEB); }

    // Faro siempre vivo (con respiro entre relanzamientos)
    if (!vivo('faro') && time() - $ultimoFaro >= 10) {
        $ultimoFaro = time();
        lanzar('faro', [PHP_BINARY, $BASE . '/motor.php', '--faro'], $DATOS . '/faro.log');
    }

    // Scrapeo en segundo plano: una pasada cada 10 min si la anterior ya terminó.
    // Si no hay internet, la pasada se pospone sola sin marcar nada (servidor.php).
    if (!vivo('scrapeo') && time() - $ultimoScrapeo >= 600) {
        $ultimoScrapeo = time();
        lanzar('scrapeo', [PHP_BINARY, $BASE . '/servidor.php', '--scrapear'], $DATOS . '/scrapeo.log');
    }

    // El único aviso posible: falta la carpeta de la biblioteca
    $rootOk = !empty($cfg['root']) && realpath($cfg['root']) !== false;
    $aviso = $rootOk ? null : 'Elige la carpeta de tu biblioteca en el panel y pulsa Guardar.';
    if ($aviso !== $avisoActual) { $avisoActual = $aviso; $ESTADO['aviso'] = $aviso; publicar_estado(); if ($aviso) decir($aviso); }

    if (time() - $ultimoLatido >= 10) { $ultimoLatido = time(); publicar_estado(); }
    usleep(400000);
}

matar('web'); matar('scrapeo'); matar('faro');
publicar_estado();
decir('ShareTV apagado. Hasta luego.');
