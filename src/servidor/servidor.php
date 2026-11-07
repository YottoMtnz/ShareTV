<?php
/* =====================================================================
   ShareTV — servidor.php
   Web pública + API del catálogo + streaming de vídeo (Range) +
   panel de control local. Un solo archivo.
   ===================================================================== */
@ini_set('display_errors', '0');
error_reporting(0);

$BASE   = __DIR__;
$DATOS  = $BASE . '/datos';
if (!is_dir($DATOS)) @mkdir($DATOS, 0777, true);

function leer_json($f, $def = []) { $j = @file_get_contents($f); $d = $j ? json_decode($j, true) : null; return is_array($d) ? $d : $def; }
/* Conteos SIEMPRE reales para el panel: antes solo se actualizaban cuando
   un cliente pedía el catálogo, y en una PC donde nadie había abierto el
   cliente el panel decía «0 películas» con la biblioteca llena. Ahora el
   estado re-escanea si el archivo tiene más de 15 s (o no existe). */
function conteos_actuales($DATOS, $ROOT) {
    $f = $DATOS . '/conteos.json';
    $con = leer_json($f, null);
    $viejo = !is_array($con) || (time() - intval(@filemtime($f) ?: 0) > 15);
    if ($ROOT && $viejo) {
        $cat = escanear($ROOT);
        $ncaps = 0; foreach ($cat['series'] as $s) foreach ($s['temporadas'] as $t) $ncaps += count($t['capitulos']);
        $con = ['peliculas' => count($cat['peliculas']), 'series' => count($cat['series']), 'capitulos' => $ncaps, 'hora' => date('c')];
        guardar_json($f, $con);
    }
    return is_array($con) ? $con : ['peliculas' => 0, 'series' => 0, 'capitulos' => 0];
}
function guardar_json($f, $d) { @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

$CONFIG = leer_json($DATOS . '/config.json', []);
$ROOT   = isset($CONFIG['root']) && $CONFIG['root'] !== '' ? realpath($CONFIG['root']) : false;
$LOCAL  = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

/* ------------------------- utilidades ------------------------- */
function asegurar_carpetas($root) {
    foreach (['peliculas', 'series', 'scrapeo'] as $d) if (!is_dir($root . '/' . $d)) @mkdir($root . '/' . $d, 0777, true);
}
function limpiar_titulo($archivo, &$anio = null) {
    $n = pathinfo($archivo, PATHINFO_FILENAME);
    $n = str_replace(['.', '_'], ' ', $n);
    $anio = null;
    if (preg_match('/\b(19\d{2}|20\d{2})\b/', $n, $m)) { $anio = intval($m[1]); $n = str_replace($m[0], ' ', $n); }
    $n = preg_replace('/\[[^\]]*\]|\([^)]*\)/', ' ', $n);
    $n = preg_replace('/\b(1080p|720p|480p|2160p|480|720|1080|2160|4k|8k|hdrip|bdrip|brrip|bluray|webrip|webdl|web|dvdrip|hd|sd|x264|x265|h264|h265|hevc|av1|aac|ac3|mp3|dual|latino|castellano|espanol|español|sub|subs|subtitulada|completa|extendida|remux|proper|repack|temporada|temp|season)\b/iu', ' ', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return trim($n, " -–—");
}
function num_capitulo($archivo, $respaldo) {
    if (preg_match('/s\d{1,2}[\s._-]*e(\d{1,3})/i', $archivo, $m)) return intval($m[1]);
    if (preg_match('/\b\d{1,2}x(\d{1,3})\b/', $archivo, $m)) return intval($m[1]);
    if (preg_match('/(?:cap[ií]tulo|episodio|ep|cap|e)[\s._-]*(\d{1,3})/i', $archivo, $m)) return intval($m[1]);
    if (preg_match('/^[\s._-]*(\d{1,3})[\s._-]/', $archivo, $m)) return intval($m[1]);
    return $respaldo;
}
function num_temporada($carpeta) {
    if (preg_match('/(?:temp(?:orada)?|season|t)[\s._-]*0*(\d{1,2})/i', $carpeta, $m)) return intval($m[1]);
    if (preg_match('/^0*(\d{1,2})$/', trim($carpeta), $m)) return intval($m[1]);
    return null;
}
function es_mp4($f) { return strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'mp4'; }
function buscar_poster($root, $candidatos) {
    foreach ($candidatos as $rel) {
        foreach (['jpg', 'png', 'webp', 'jpeg'] as $ext) {
            $p = $rel . '.' . $ext;
            if (is_file($root . '/' . $p)) return $p;
        }
    }
    return null;
}

/* ------------------------- escaneo ------------------------- */
function escanear($root) {
    asegurar_carpetas($root);
    $salida = ['peliculas' => [], 'series' => []];
    // Películas: MP4 sueltos en peliculas/
    $dir = $root . '/peliculas';
    $archivos = [];
    foreach (scandir($dir) ?: [] as $f) if ($f[0] !== '.' && is_file($dir . '/' . $f) && es_mp4($f)) $archivos[] = $f;
    natsort($archivos);
    foreach ($archivos as $f) {
        $sin = pathinfo($f, PATHINFO_FILENAME);
        $salida['peliculas'][] = [
            'id'      => 'p-' . substr(md5('peliculas/' . $f), 0, 10),
            'titulo'  => limpiar_titulo($f, $anio),
            'anio'    => $anio,
            'archivo' => $f,
            'poster'  => buscar_poster($root, ['scrapeo/peliculas/' . $sin, 'peliculas/' . $sin]),
        ];
    }
    // Series: series/<Nombre>/<temp N>/<cap>.mp4
    $dirS = $root . '/series';
    $nombres = [];
    foreach (scandir($dirS) ?: [] as $f) if ($f[0] !== '.' && is_dir($dirS . '/' . $f)) $nombres[] = $f;
    natsort($nombres);
    foreach ($nombres as $nombre) {
        $baseS = $dirS . '/' . $nombre;
        $temps = [];   // n => [caps]
        $subs = [];
        foreach (scandir($baseS) ?: [] as $f) if ($f[0] !== '.' && is_dir($baseS . '/' . $f)) $subs[] = $f;
        natsort($subs);
        foreach ($subs as $sub) {
            $n = num_temporada($sub);
            if ($n === null) continue;
            $caps = [];
            $lista = [];
            foreach (scandir($baseS . '/' . $sub) ?: [] as $f) if ($f[0] !== '.' && is_file($baseS . '/' . $sub . '/' . $f) && es_mp4($f)) $lista[] = $f;
            natsort($lista);
            $i = 0;
            foreach ($lista as $f) {
                $i++;
                $caps[] = [
                    'id'      => 'e-' . substr(md5('series/' . $nombre . '/' . $sub . '/' . $f), 0, 10),
                    'n'       => num_capitulo($f, $i),
                    'titulo'  => limpiar_titulo($f),
                    'archivo' => $f,
                    'ruta'    => 'series/' . $nombre . '/' . $sub . '/' . $f,
                ];
            }
            if ($caps) $temps[$n] = $caps;
        }
        // Capítulos sueltos sin carpeta de temporada → Temporada 1
        $sueltos = [];
        foreach (scandir($baseS) ?: [] as $f) if ($f[0] !== '.' && is_file($baseS . '/' . $f) && es_mp4($f)) $sueltos[] = $f;
        if ($sueltos) {
            natsort($sueltos); $i = 0; $caps = [];
            foreach ($sueltos as $f) { $i++; $caps[] = ['id' => 'e-' . substr(md5('series/' . $nombre . '/' . $f), 0, 10), 'n' => num_capitulo($f, $i), 'titulo' => limpiar_titulo($f), 'archivo' => $f, 'ruta' => 'series/' . $nombre . '/' . $f]; }
            if (!isset($temps[1])) $temps[1] = $caps;
        }
        if (!$temps) continue;
        ksort($temps);
        $listaT = [];
        foreach ($temps as $n => $caps) $listaT[] = ['n' => $n, 'capitulos' => $caps];
        $salida['series'][] = [
            'id'         => 's-' . substr(md5('series/' . $nombre), 0, 10),
            'titulo'     => $nombre,
            'poster'     => buscar_poster($root, ['scrapeo/series/' . $nombre, 'series/' . $nombre . '/poster', 'series/' . $nombre . '/folder']),
            'temporadas' => $listaT,
        ];
    }
    return $salida;
}
function mapa_ids($cat) {
    $mapa = [];
    global $ROOT;
    foreach ($cat['peliculas'] as $p) $mapa[$p['id']] = $ROOT . '/peliculas/' . $p['archivo'];
    foreach ($cat['series'] as $s) foreach ($s['temporadas'] as $t) foreach ($t['capitulos'] as $c) $mapa[$c['id']] = $ROOT . '/' . $c['ruta'];
    return $mapa;
}

/* ------------------------- streaming con Range ------------------------- */
function servir_video($path) {
    if (!is_file($path)) { http_response_code(404); echo 'No encontrado'; return; }
    $size = filesize($path);
    $fp = fopen($path, 'rb');
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    $start = 0; $end = $size - 1;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') $start = max(0, $size - intval($m[2]));
        else { $start = intval($m[1]); if ($m[2] !== '') $end = min(intval($m[2]), $size - 1); }
        if ($start > $end) { header('HTTP/1.1 416 Requested Range Not Satisfiable'); header("Content-Range: bytes */$size"); fclose($fp); return; }
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (ob_get_level()) ob_end_clean();
    fseek($fp, $start);
    $queda = $end - $start + 1;
    while ($queda > 0 && !feof($fp)) {
        $trozo = fread($fp, min(1048576, $queda));
        if ($trozo === false) break;
        echo $trozo; flush();
        if (connection_aborted()) break;
        $queda -= strlen($trozo);
    }
    fclose($fp);
}

/* =========================== SCRAPEO (sin claves) ===========================
   Series → TVMaze (póster, sinopsis, títulos y fotos reales de capítulos).
   Películas → sugerencias de IMDb (póster) + Wikipedia EN (sinopsis).
   Todo se guarda en <biblioteca>/scrapeo/: imágenes + datos.json.
   Lo ejecuta el motor como proceso aparte:  php servidor.php --scrapear
   ========================================================================= */
function slog($m) { echo '[' . date('H:i:s') . '] ' . $m . PHP_EOL; @ob_flush(); flush(); }
function http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'ShareTV/1.0 (uso personal)']);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($r !== false && $code < 400) ? $r : null;
    }
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: ShareTV/1.0\r\n", 'timeout' => 25]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}
function http_json($url) { $t = http_get($url); if ($t === null) return null; $d = json_decode($t, true); return is_array($d) ? $d : null; }
/* ¿Hay internet? Una sola sonda rápida por pasada: si el transporte falla,
   el escrapeo ESPERA sin marcar nada como «sin resultados» (si lo marcara,
   no reintentaría en una semana aunque volviera la conexión). */
function hay_internet() {
    if (!function_exists('curl_init')) return http_get('https://api.tvmaze.com') !== null;
    $ch = curl_init('https://api.tvmaze.com');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'ShareTV/1.0']);
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    return $ok !== false && $errno === 0;
}
function esc_estado($txt) { global $DATOS; guardar_json($DATOS . '/escrapeo_estado.json', ['txt' => $txt, 'en' => time()]); }
function guardar_imagen($url, $destino) {
    $bin = http_get($url);
    if ($bin === null || strlen($bin) < 2500) return false;
    $cab = substr($bin, 0, 4);
    if ($cab[0] !== "\xFF" || $cab[1] !== "\xD8") { if (substr($cab, 0, 4) !== "\x89PNG" && substr($bin, 0, 4) !== 'RIFF') return false; }
    if (!is_dir(dirname($destino))) @mkdir(dirname($destino), 0777, true);
    return @file_put_contents($destino, $bin) !== false;
}
function norm_txt($s) { $s = mb_strtolower($s ?? '', 'UTF-8'); $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']); return trim(preg_replace('/[^a-z0-9]+/', ' ', $s)); }
function recortar($s, $max = 420) { $s = trim(preg_replace('/\s+/', ' ', (string)$s)); if (mb_strlen($s) <= $max) return $s; $c = mb_substr($s, 0, $max); $p = max(mb_strrpos($c, '. '), mb_strrpos($c, ' ')); return mb_substr($c, 0, $p > 60 ? $p + 1 : $max) . '…'; }

/* Títulos reales y fotos de los capítulos que SÍ están en la biblioteca. */
function caps_buscar($idShow, $serieCat, $baseSerie) {
    $tiene = [];
    foreach ($serieCat['temporadas'] as $t) foreach ($t['capitulos'] as $c) $tiene['S' . $t['n'] . 'E' . $c['n']] = true;
    $eps = http_json('https://api.tvmaze.com/shows/' . intval($idShow) . '/episodes');
    $caps = [];
    if ($eps) foreach ($eps as $e) {
        $k = 'S' . intval($e['season'] ?? 0) . 'E' . intval($e['number'] ?? 0);
        if (!isset($tiene[$k])) continue;
        if (!empty($e['name'])) $caps[$k] = $e['name'];
        $still = $e['image']['medium'] ?? null;
        $sf = $baseSerie . '/caps/' . $k . '.jpg';
        if ($still && !is_file($sf)) guardar_imagen($still, $sf);
    }
    return $caps;
}

function scrapeo_pasada() {
    global $DATOS, $ROOT;
    $cfg = leer_json($DATOS . '/config.json', []);
    $root = !empty($cfg['root']) ? realpath($cfg['root']) : false;
    if (!$root) { slog('Scrapeo: sin carpeta configurada.'); return; }
    $dirS = $root . '/scrapeo';
    asegurar_carpetas($root);
    $metaF = $dirS . '/datos.json';
    $meta = leer_json($metaF, ['peliculas' => [], 'series' => []]);
    if (!isset($meta['peliculas']) || !is_array($meta['peliculas'])) $meta['peliculas'] = [];
    if (!isset($meta['series']) || !is_array($meta['series'])) $meta['series'] = [];
    $cat = escanear($root);
    $cambios = false;

    /* Sin internet: el escrapeo queda A LA ESPERA, sin tocar nada. El
       servidor y el cliente siguen funcionando en la red local. */
    if (!hay_internet()) {
        esc_estado('Sin internet — el escrapeo espera y reintenta solo.');
        slog('Scrapeo: sin internet; la pasada se pospone (nada queda marcado).');
        return;
    }
    esc_estado('Escrapeando…');

    /* ---------- SERIES: TVMaze ---------- */
    foreach ($cat['series'] as $s) {
        $nombre = $s['titulo'];
        $m = $meta['series'][$nombre] ?? null;
        $posterF = $dirS . '/series/' . $nombre . '.jpg';
        if ($m && !empty($m['ok'])) {
            if (!is_file($posterF) && !empty($m['poster_url'])) { if (guardar_imagen($m['poster_url'], $posterF)) { slog("Póster recuperado: $nombre"); $cambios = true; } }
            if (!empty($m['tvmaze'])) {
                $faltan = false;
                foreach ($s['temporadas'] as $t) foreach ($t['capitulos'] as $c) { $k = 'S' . $t['n'] . 'E' . $c['n']; if (empty($m['caps'][$k]) || !is_file($dirS . '/series/' . $nombre . '/caps/' . $k . '.jpg')) { $faltan = true; break 2; } }
                if ($faltan) { $nuevos = caps_buscar(intval($m['tvmaze']), $s, $dirS . '/series/' . $nombre); if ($nuevos) { $m['caps'] = array_merge($m['caps'] ?? [], $nuevos); $meta['series'][$nombre] = $m; $cambios = true; slog("Capítulos completados: $nombre"); } }
            }
            continue;
        }
        if ($m && !empty($m['sin']) && (time() - intval($m['sin'])) < 7 * 86400) continue;
        slog("Buscando serie: $nombre");
        $res = http_json('https://api.tvmaze.com/search/shows?q=' . urlencode($nombre));
        if ($res === null) { slog('  TVMaze no respondió (¿se cortó internet?); queda pendiente, sin marcar.'); continue; }
        $show = null;
        if ($res && !empty($res[0]['show']) && floatval($res[0]['score'] ?? 0) >= 0.4) $show = $res[0]['show'];
        if (!$show) { $meta['series'][$nombre] = ['sin' => time()]; $cambios = true; slog("  Sin resultados para: $nombre"); continue; }
        $m = ['ok' => true, 'titulo_real' => $show['name'] ?? $nombre, 'tvmaze' => $show['id'] ?? null];
        if (!empty($show['premiered'])) $m['anio'] = intval(substr($show['premiered'], 0, 4));
        if (!empty($show['summary'])) $m['sinopsis'] = recortar(strip_tags(html_entity_decode($show['summary'], ENT_QUOTES, 'UTF-8')));
        if (!empty($show['genres'])) $m['generos'] = implode(' · ', $show['genres']);
        $img = $show['image']['original'] ?? ($show['image']['medium'] ?? null);
        if ($img) { $m['poster_url'] = $img; if (guardar_imagen($img, $posterF)) slog("  Póster ✓ $nombre"); }
        // Capítulos que SÍ tiene Fraudy: título real + foto
        $m['caps'] = caps_buscar(intval($show['id']), $s, $dirS . '/series/' . $nombre);
        slog('  Capítulos con título real: ' . count($m['caps']));
        $meta['series'][$nombre] = $m; $cambios = true;
        usleep(200000);
    }

    /* ---------- PELÍCULAS: IMDb (póster) + Wikipedia (sinopsis) ---------- */
    foreach ($cat['peliculas'] as $p) {
        $arch = $p['archivo'];
        $m = $meta['peliculas'][$arch] ?? null;
        $sin = pathinfo($arch, PATHINFO_FILENAME);
        $posterF = $dirS . '/peliculas/' . $sin . '.jpg';
        if ($m && !empty($m['ok'])) {
            if (!is_file($posterF) && !empty($m['poster_url'])) { if (guardar_imagen($m['poster_url'], $posterF)) { slog("Póster recuperado: $arch"); $cambios = true; } }
            continue;
        }
        if ($m && !empty($m['sin']) && (time() - intval($m['sin'])) < 7 * 86400) continue;
        $titulo = $p['titulo']; $anio = $p['anio'];
        slog("Buscando película: $titulo" . ($anio ? " ($anio)" : ''));
        $letra = mb_strtolower(mb_substr($titulo, 0, 1, 'UTF-8'), 'UTF-8');
        $res = http_json('https://v2.sg.media-imdb.com/suggestion/' . rawurlencode($letra) . '/' . rawurlencode($titulo) . '.json');
        if ($res === null) { slog('  IMDb no respondió (¿se cortó internet?); queda pendiente, sin marcar.'); continue; }
        $cand = [];
        foreach (($res['d'] ?? []) as $d) { if (($d['qid'] ?? '') === 'movie' && !empty($d['i']['imageUrl'])) $cand[] = $d; }
        $elegido = null;
        if ($cand) {
            if ($anio) foreach ($cand as $d) if (intval($d['y'] ?? 0) === intval($anio)) { $elegido = $d; break; }
            if (!$elegido) { usort($cand, function ($a, $b) { return intval($a['rank'] ?? 999999) <=> intval($b['rank'] ?? 999999); }); $elegido = $cand[0]; }
        }
        if (!$elegido) { $meta['peliculas'][$arch] = ['sin' => time()]; $cambios = true; slog("  Sin resultados para: $titulo"); continue; }
        $m = ['ok' => true, 'titulo_real' => $elegido['l'] ?? $titulo, 'imdb' => $elegido['id'] ?? null];
        if (!empty($elegido['y'])) $m['anio'] = intval($elegido['y']);
        if (!empty($elegido['s'])) $m['reparto'] = $elegido['s'];
        $imgOrig = $elegido['i']['imageUrl'];
        $img900 = preg_replace('/\._V1_.*\.jpg$/', '._V1_UY900_.jpg', $imgOrig);
        $m['poster_url'] = $imgOrig;
        if (guardar_imagen($img900, $posterF) || guardar_imagen($imgOrig, $posterF)) slog("  Póster ✓ $titulo");
        // Sinopsis desde Wikipedia EN (mejor desambiguada en cine)
        foreach (array_filter([$m['titulo_real'] . ' (' . ($m['anio'] ?? $anio) . ' film)', $m['titulo_real'] . ' (film)']) as $tit) {
            $w = http_json('https://en.wikipedia.org/w/api.php?action=query&format=json&prop=extracts&exintro=1&explaintext=1&redirects=1&titles=' . urlencode($tit));
            $pages = $w['query']['pages'] ?? [];
            $pg = $pages ? reset($pages) : null;
            if ($pg && !empty($pg['extract']) && strlen($pg['extract']) > 100) { $m['sinopsis'] = recortar($pg['extract']); break; }
        }
        $meta['peliculas'][$arch] = $m; $cambios = true;
        usleep(200000);
    }

    if ($cambios) {
        $tmp = $metaF . '.tmp';
        @file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $metaF);
        slog('Scrapeo: datos actualizados.');
    } else slog('Scrapeo: nada pendiente.');
    esc_estado('Escrapeo al día ✓');
}

if (PHP_SAPI === 'cli') {
    if (in_array('--scrapear', $argv ?? [], true)) scrapeo_pasada();
    return;
}

/* ------------------------- rutas ------------------------- */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode($uri);

if ($uri === '/ping') { header('Content-Type: text/plain'); echo 'ShareTV OK'; return true; }

if ($uri === '/api/catalogo') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$ROOT) { echo json_encode(['ok' => false, 'error' => 'Sin carpeta configurada. Abre el panel.']); return true; }
    $cat = escanear($ROOT);
    // Mezclar lo scrapeado (pósters ya los encuentra buscar_poster en scrapeo/)
    $meta = leer_json($ROOT . '/scrapeo/datos.json', ['peliculas' => [], 'series' => []]);
    foreach ($cat['peliculas'] as &$p) {
        $m = $meta['peliculas'][$p['archivo']] ?? null;
        if ($m) {
            if (!empty($m['anio'])) $p['anio'] = $m['anio'];
            if (!empty($m['sinopsis'])) $p['sinopsis'] = $m['sinopsis'];
            if (!empty($m['reparto'])) $p['reparto'] = $m['reparto'];
        }
    }
    unset($p);
    foreach ($cat['series'] as &$s) {
        $m = $meta['series'][$s['titulo']] ?? null;
        if ($m) {
            if (!empty($m['sinopsis'])) $s['sinopsis'] = $m['sinopsis'];
            if (!empty($m['anio'])) $s['anio'] = $m['anio'];
            if (!empty($m['generos'])) $s['generos'] = $m['generos'];
            foreach ($s['temporadas'] as &$t) foreach ($t['capitulos'] as &$c) {
                $k = 'S' . $t['n'] . 'E' . $c['n'];
                if (!empty($m['caps'][$k])) $c['titulo_real'] = $m['caps'][$k];
                $foto = 'scrapeo/series/' . $s['titulo'] . '/caps/' . $k . '.jpg';
                if (is_file($ROOT . '/' . $foto)) $c['foto'] = $foto;
            }
            unset($t, $c);
        }
    }
    unset($s);
    $ncaps = 0; foreach ($cat['series'] as $s) foreach ($s['temporadas'] as $t) $ncaps += count($t['capitulos']);
    guardar_json($DATOS . '/conteos.json', ['peliculas' => count($cat['peliculas']), 'series' => count($cat['series']), 'capitulos' => $ncaps, 'hora' => date('c')]);
    echo json_encode(['ok' => true, 'peliculas' => $cat['peliculas'], 'series' => $cat['series']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

if ($uri === '/ver') {
    if (!$ROOT) { http_response_code(503); echo 'Sin carpeta'; return true; }
    $id = $_GET['id'] ?? '';
    $mapa = mapa_ids(escanear($ROOT));
    if (!isset($mapa[$id])) { http_response_code(404); echo 'No encontrado'; return true; }
    servir_video($mapa[$id]);
    return true;
}

if ($uri === '/img') {
    if (!$ROOT) { http_response_code(503); return true; }
    $rel = str_replace('\\', '/', $_GET['ruta'] ?? '');
    $abs = realpath($ROOT . '/' . $rel);
    $ext = strtolower(pathinfo($abs ?: '', PATHINFO_EXTENSION));
    if (!$abs || strpos($abs, $ROOT) !== 0 || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { http_response_code(404); return true; }
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    return true;
}

/* -------- panel y control: SOLO desde esta PC -------- */
if ($uri === '/api/estado' || $uri === '/api/config' || $uri === '/api/control' || $uri === '/panel') {
    if (!$LOCAL) { http_response_code(404); echo 'No encontrado'; return true; }
    if ($uri === '/api/estado') {
        header('Content-Type: application/json; charset=utf-8');
        $est = leer_json($DATOS . '/estado.json', ['tunel' => false, 'enlace' => null]);
        $con = conteos_actuales($DATOS, $ROOT);
        $esc = leer_json($DATOS . '/escrapeo_estado.json', []);
        echo json_encode([
            'ok' => true,
            'configurado' => $ROOT !== false,
            'root'  => $CONFIG['root'] ?? '',
            'root_ok' => $ROOT !== false,
            'direccion_local' => 'http://' . gethostbyname(gethostname()) . ':8899',
            'escrapeo' => $esc['txt'] ?? null,
            'estado' => $est, 'conteos' => $con,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return true;
    }
    if ($uri === '/api/config') {
        $in = $_REQUEST;
        if (empty($in)) { $j = json_decode((string)@file_get_contents('php://input'), true); if (is_array($j)) $in = $j; }
        $nuevo = $CONFIG;
        if (!empty($in['root'])) $nuevo['root'] = trim((string)$in['root']);
        guardar_json($DATOS . '/config.json', $nuevo);
        $rp = $nuevo['root'] ?? '';
        if ($rp !== '' && !is_dir($rp)) @mkdir($rp, 0777, true); // si la carpeta no existe, la crea
        $r = $rp !== '' ? realpath($rp) : false;
        if ($r) asegurar_carpetas($r);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'root_ok' => $r !== false]);
        return true;
    }
    if ($uri === '/api/control') {
        $in = $_REQUEST;
        if (empty($in)) { $j = json_decode((string)@file_get_contents('php://input'), true); if (is_array($j)) $in = $j; }
        $ctl = leer_json($DATOS . '/control.json', ['salir' => false]);
        if (($in['accion'] ?? '') === 'apagar')   { $ctl['salir'] = true; }
        guardar_json($DATOS . '/control.json', $ctl);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        return true;
    }
    // /panel
    header('Content-Type: text/html; charset=utf-8');
    echo pagina_panel();
    return true;
}

if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    echo pagina_publica();
    return true;
}

return false;

/* =========================== PÁGINAS =========================== */
function pagina_publica() { return <<<'HTML'
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ShareTV</title><style>
*{box-sizing:border-box;margin:0}
html,body{height:100%;overflow:hidden;overscroll-behavior:none}
body{background:#070912;color:#fff;font-family:system-ui,'Segoe UI',Roboto,sans-serif;touch-action:pan-x}
/* ---------- FONDO (sigue al foco) ---------- */
#fondoImg,#fondoGen{position:fixed;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 12%;transition:opacity .7s}
#fondoImg{opacity:0}#fondoImg.on{opacity:.95}
#fondoGen{opacity:0}#fondoGen.on{opacity:1}
.scrim{position:fixed;inset:0;pointer-events:none}
.scrimA{background:linear-gradient(90deg,rgba(7,9,18,.94) 0%,rgba(7,9,18,.62) 46%,rgba(7,9,18,.08) 78%)}
.scrimB{background:linear-gradient(0deg,rgba(7,9,18,.97) 6%,rgba(7,9,18,.45) 48%,transparent 74%),linear-gradient(180deg,rgba(7,9,18,.55),transparent 22%)}
/* ---------- CABECERA ---------- */
#escenario{position:fixed;left:50%;top:50%;width:1600px;height:900px;transform-origin:center center;z-index:6}
header{position:fixed;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;gap:1rem;padding:.85rem 51px}
.logo{font-size:1.55rem;font-weight:800}.logo span{color:#2dd4bf}
#conteo{opacity:.65;font-size:.9rem}
#buscar{margin-left:auto;background:rgba(10,14,28,.7);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:999px;padding:.45rem .95rem;font-size:.98rem;width:190px;outline:none}
#buscar:focus{border-color:#2dd4bf;box-shadow:0 0 0 3px rgba(45,212,191,.3);width:250px}
/* ---------- SELECTOR PELÍCULAS / SERIES ---------- */
#tabs{display:flex;background:rgba(10,14,28,.72);border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:3px;gap:2px}
.tab{border:none;background:transparent;color:rgba(255,255,255,.75);font-size:1rem;font-weight:750;padding:.42rem 1.25rem;border-radius:999px;cursor:pointer;outline:none;transition:.2s}
.tab.on{background:#2dd4bf;color:#03211c}
.tab:focus{box-shadow:0 0 0 3px rgba(45,212,191,.5)}
.tab:hover{color:#fff}.tab.on:hover{color:#03211c}
/* ---------- INFO DEL ELEMENTO ENFOCADO ---------- */
#info{position:fixed;left:51px;top:150px;width:700px;z-index:10;pointer-events:none}
.tag{display:inline-block;background:#2dd4bf;color:#03211c;font-weight:800;font-size:.74rem;letter-spacing:.13em;padding:.26rem .65rem;border-radius:6px;text-transform:uppercase}
#infoTit{font-size:3.6rem;font-weight:900;line-height:1.05;margin:.65rem 0 .45rem;text-shadow:0 4px 26px rgba(0,0,0,.65)}
#infoMeta{opacity:.9;font-size:1.02rem;text-shadow:0 2px 10px rgba(0,0,0,.8)}
#infoSin{margin-top:.6rem;opacity:.82;font-size:.99rem;line-height:1.45;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;text-shadow:0 2px 10px rgba(0,0,0,.85)}
#infoHint{margin-top:.9rem;display:inline-block;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);padding:.42rem .9rem;border-radius:999px;font-size:.92rem;font-weight:650;backdrop-filter:blur(4px)}
/* ---------- FILAS (solo se mueven de lado) ---------- */
#filasWrap{position:fixed;left:0;right:0;bottom:0;z-index:12;overflow:hidden;max-height:660px}
#filas{transition:transform .45s cubic-bezier(.2,.8,.25,1);will-change:transform;padding-bottom:1.1rem}
.fila{margin-top:.4rem}
.fila h2{margin:0 51px .6rem;font-size:1.3rem;font-weight:700;text-shadow:0 2px 10px rgba(0,0,0,.7)}
.rail{display:flex;gap:1rem;overflow-x:auto;padding:.55rem 51px .9rem;scrollbar-width:none}
.rail::-webkit-scrollbar{display:none}
.card{flex:none;width:196px;background:none;border:none;color:#fff;text-align:left;padding:0;cursor:pointer;border-radius:14px;outline:none}
.cover{position:relative;display:block;width:196px;height:294px;border-radius:14px;overflow:hidden;background:#131830;transition:transform .2s,box-shadow .2s;box-shadow:0 8px 20px rgba(0,0,0,.5)}
.cover img{width:100%;height:100%;object-fit:cover;display:block}
.gen{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:.85rem;text-align:center;background:linear-gradient(155deg,hsl(var(--h) 62% 34%),hsl(calc(var(--h) + 45) 68% 15%))}
.gen b{font-size:1.3rem;font-weight:900;line-height:1.2;text-transform:uppercase;text-shadow:0 3px 14px rgba(0,0,0,.55)}
.card:focus .cover,.card:hover .cover{transform:scale(1.1) translateY(-4px);box-shadow:0 18px 36px rgba(0,0,0,.7),0 0 0 3px #2dd4bf}
.prog{position:absolute;left:0;right:0;bottom:0;height:5px;background:rgba(255,255,255,.28);z-index:2}
.prog i{display:block;height:100%;background:#2dd4bf}
.ct{display:block;margin-top:.5rem;font-weight:650;font-size:1.02rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cm{display:block;opacity:.6;font-size:.83rem}
.vacio{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem;font-size:1.15rem;line-height:1.7;opacity:.9;z-index:5}
/* ---------- VISTA DE SERIE (capítulos en fila horizontal) ---------- */
#serie{position:fixed;inset:0;z-index:15;display:none;flex-direction:column;justify-content:flex-end;padding:0 0 2.2rem;background:linear-gradient(0deg,rgba(7,9,18,.96) 30%,rgba(7,9,18,.25) 75%)}
#serieTop{position:absolute;top:110px;left:51px;right:51px}
#volver{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:999px;padding:.5rem 1.1rem;font-size:.98rem;font-weight:700;cursor:pointer;outline:none}
#volver:focus,#volver:hover{background:#2dd4bf;color:#03211c}
#serieTit{font-size:3.2rem;font-weight:900;margin-top:.8rem;text-shadow:0 4px 22px rgba(0,0,0,.7)}
#serieMeta{opacity:.85;margin-top:.25rem}
#serieSin{max-width:640px;opacity:.8;line-height:1.45;margin-top:.55rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.chips{display:flex;gap:.5rem;flex-wrap:wrap;margin:0 51px .8rem}
.chip{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:999px;padding:.45rem 1.05rem;cursor:pointer;font-size:.97rem;font-weight:650;outline:none}
.chip.on{background:#2dd4bf;color:#03211c;border-color:#2dd4bf}
.chip:focus{box-shadow:0 0 0 3px rgba(45,212,191,.45)}
#capsRail{display:flex;gap:.85rem;overflow-x:auto;padding:.5rem 51px .6rem;scrollbar-width:none}
#capsRail::-webkit-scrollbar{display:none}
.epcard{flex:none;width:300px;background:none;border:none;color:#fff;text-align:left;padding:0;cursor:pointer;border-radius:12px;outline:none}
.epimg{position:relative;display:block;width:300px;height:169px;border-radius:12px;overflow:hidden;background:#131830;transition:transform .2s,box-shadow .2s;box-shadow:0 8px 20px rgba(0,0,0,.5)}
.epimg img{width:100%;height:100%;object-fit:cover;display:block}
.epimg .gen b{font-size:2.6rem}
.epnum{position:absolute;left:.6rem;bottom:.45rem;background:rgba(0,0,0,.55);border-radius:8px;padding:.1rem .55rem;font-weight:900;font-size:1.25rem}
.epcard:focus .epimg,.epcard:hover .epimg{transform:scale(1.05) translateY(-3px);box-shadow:0 16px 32px rgba(0,0,0,.7),0 0 0 3px #2dd4bf}
.eptit{display:block;margin-top:.45rem;font-weight:650;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.epcm{display:block;opacity:.6;font-size:.83rem}
/* ---------- REPRODUCTOR ---------- */
#velo{position:fixed;inset:0;background:#000;display:none;z-index:50;flex-direction:column}
#velo video{flex:1;width:100%;min-height:0;background:#000}
#velo{cursor:none}
/* Capa táctil del reproductor: solo aparece al tocar la pantalla (móvil) */
#vtouch{position:absolute;inset:0;z-index:3;display:none}
#vtouch.ver{display:block}
#vplay{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:112px;height:112px;border-radius:50%;border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.55);color:#fff;font-size:42px;cursor:pointer;outline:none}
#vprev{position:absolute;top:26px;left:34px;background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.35);color:#fff;font-weight:700;border-radius:999px;padding:.75rem 1.35rem;font-size:1.05rem;cursor:pointer;outline:none}
#vnext{position:absolute;top:26px;right:34px;background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.35);color:#fff;font-weight:700;border-radius:999px;padding:.75rem 1.35rem;font-size:1.05rem;cursor:pointer;outline:none}
#vseekrow{position:absolute;left:0;right:0;bottom:0;display:flex;align-items:center;gap:1rem;padding:2.4rem 3.2vw 1.3rem;background:linear-gradient(0deg,rgba(0,0,0,.88),transparent)}
#vseekrow span{font-size:1rem;min-width:3.4rem;text-align:center;text-shadow:0 1px 6px #000}
#vseek{flex:1;accent-color:#2dd4bf;height:36px;cursor:pointer;background:transparent}
#vestado{font-weight:800}
#sig{margin-left:auto;background:#2dd4bf;color:#03211c;border:none}
</style></head><body>
<img id="fondoImg" alt=""><div id="fondoGen"></div><div class="scrim scrimA"></div><div class="scrim scrimB"></div>
<div id="escenario"><header><div class="logo">Share<span>TV</span></div><nav id="tabs" style="display:none"></nav><input id="buscar" type="search" placeholder="🔎 Buscar…" autocomplete="off"></header>
<div id="info"><span class="tag" id="infoTag">ShareTV</span><h1 id="infoTit">Tu cine en casa</h1><div id="infoMeta">Cargando tu biblioteca…</div><div id="infoSin"></div></div>
<div id="filasWrap"><div id="filas"></div></div>
<section id="serie"><div id="serieTop"><button id="volver">← Volver</button><h1 id="serieTit"></h1><div id="serieMeta"></div><div id="serieSin"></div></div>
<div class="chips" id="chips"></div><div id="capsRail"></div></section></div>
<div id="velo"><video id="repro" playsinline></video><div id="vtouch"><button id="vprev" tabindex="-1">⏮ Anterior</button><button id="vnext" tabindex="-1">Siguiente ⏭</button><button id="vplay" tabindex="-1">⏸</button><div id="vseekrow"><span id="vtcur">0:00</span><input id="vseek" type="range" min="0" max="1000" value="0" tabindex="-1"><span id="vtdur">0:00</span></div></div></div>
<script>
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
/* Escenario fijo de 1600x900: la interfaz ENTERA se escala como una foto
   según la pantalla. Misma información y mismas proporciones en la TV,
   en un monitor o en un móvil; nada se corta ni se monta sobre nada. */
const ESC=document.getElementById('escenario');
function encajar(){const s=Math.min(innerWidth/1600,innerHeight/900);ESC.style.transform='translate(-50%,-50%) scale('+s+')';document.documentElement.style.setProperty('--esc',s)}
addEventListener('resize',encajar);addEventListener('orientationchange',encajar);encajar();
const LS='sharetv_progreso_v1';
let D=null, vista='filas', serieIdx=null, tempSel=null, actual=null, origen='filas', tabActiva=null;
const prog=()=>{try{return JSON.parse(localStorage.getItem(LS))||{}}catch(e){return{}}};
const guardaProg=o=>{try{localStorage.setItem(LS,JSON.stringify(o))}catch(e){}};
function tono(txt){let h=0;for(const c of (txt||''))h=(h*31+c.charCodeAt(0))%360;return h}
function portada(tit,poster){if(poster)return `<img src="/img?ruta=${encodeURIComponent(poster)}" alt="" loading="lazy">`;
 return `<span class="gen" style="--h:${tono(tit)}"><b>${tit}</b></span>`}
function barra(id){const p=prog()[id];if(!p||!p.d)return'';const pc=Math.min(100,Math.round(p.p/p.d*100));return (pc>2&&pc<97)?`<span class="prog"><i style="width:${pc}%"></i></span>`:''}
function fondo(tit,poster){const img=$('#fondoImg'),gen=$('#fondoGen');
 if(poster){gen.classList.remove('on');img.src='/img?ruta='+encodeURIComponent(poster);img.classList.add('on')}
 else{img.classList.remove('on');img.removeAttribute('src');gen.style.background=`linear-gradient(155deg,hsl(${tono(tit)} 60% 30%),hsl(${(tono(tit)+50)%360} 65% 11%))`;gen.classList.add('on')}}
function setInfo(o){fondo(o.titulo,o.poster);$('#infoTag').textContent=o.tag;$('#infoTit').textContent=o.titulo;$('#infoMeta').textContent=o.meta||'';$('#infoSin').textContent=o.sinopsis||''}
function metaSerie(s){const n=s.temporadas.reduce((a,t)=>a+t.capitulos.length,0);return (s.anio?s.anio+' · ':'')+n+' capítulos · '+s.temporadas.length+' temporada'+(s.temporadas.length===1?'':'s')+(s.generos?' · '+s.generos:'')}
/* ---------- FILAS ---------- */
function render(){if(!D)return;const o=prog();
 const ids=new Set([...D.peliculas.map(p=>p.id),...D.series.flatMap(s=>s.temporadas.flatMap(t=>t.capitulos.map(c=>c.id)))]);
 const seg=Object.entries(o).filter(([id,x])=>ids.has(id)&&x.d&&x.p>10&&x.p<x.d*0.95).sort((a,b)=>b[1].t-a[1].t).slice(0,12);
 const cardP=(p)=>`<button class="card" data-tipo="peli" data-id="${p.id}"><span class="cover">${portada(p.titulo,p.poster)}${barra(p.id)}</span><span class="ct">${p.titulo}</span><span class="cm">${p.anio||'Película'}</span></button>`;
 const filaSeg=seg.length?`<section class="fila"><h2>Seguir viendo</h2><div class="rail">${seg.map(([id,x])=>`<button class="card" data-tipo="seguir" data-id="${id}"><span class="cover">${portada(x.titulo||'Vídeo',x.poster)}${barra(id)}</span><span class="ct">${x.titulo||'Vídeo'}</span><span class="cm">A medias</span></button>`).join('')}</div></section>`:'';
 const filaP=D.peliculas.length?`<section class="fila"><h2>Películas</h2><div class="rail">${D.peliculas.map(cardP).join('')}</div></section>`:'';
 const filaS=D.series.length?`<section class="fila"><h2>Series</h2><div class="rail">${D.series.map((s,i)=>`<button class="card" data-tipo="serie" data-i="${i}"><span class="cover">${portada(s.titulo,s.poster)}</span><span class="ct">${s.titulo}</span><span class="cm">${s.temporadas.reduce((a,t)=>a+t.capitulos.length,0)} capítulos</span></button>`).join('')}</div></section>`:'';
 const grupos=[{id:'seguir',label:'Seguir viendo',html:filaSeg},{id:'pelis',label:'Películas',html:filaP},{id:'series',label:'Series',html:filaS}].filter(g=>g.html);
 if(!grupos.length){$('#filas').innerHTML='';$('#tabs').style.display='none';$('#infoMeta').textContent='Todavía no hay vídeos: pon MP4 en peliculas y series, y ShareTV los presenta aquí solos.';return}
 if(!grupos.some(g=>g.id===tabActiva))tabActiva=grupos.some(g=>g.id==='pelis')?'pelis':grupos[0].id;
 $('#filas').innerHTML=grupos.map(g=>`<div class="grupo" data-grupo="${g.id}"${g.id===tabActiva?'':' style="display:none"'}>${g.html}</div>`).join('');
 const nav=$('#tabs');
 if(grupos.length>1){nav.style.display='';nav.innerHTML=grupos.map(g=>`<button class="tab${g.id===tabActiva?' on':''}" data-tab="${g.id}">${g.label}</button>`).join('')}else nav.style.display='none';
 const primera=$(`#filas .grupo[data-grupo="${tabActiva}"] .card`);if(primera){infoDeCard(primera);primera.focus({preventScroll:true})}ajustarFilas()}
function syncTabs(){$$('#tabs .tab').forEach(b=>b.classList.toggle('on',b.dataset.tab===tabActiva));$$('#filas .grupo').forEach(g=>g.style.display=g.dataset.grupo===tabActiva?'':'none')}
function mostrarTab(t,bajar){if(t===tabActiva&&!bajar)return;tabActiva=t;syncTabs();const primera=$(`#filas .grupo[data-grupo="${t}"] .card`);if(primera){infoDeCard(primera);if(bajar)primera.focus({preventScroll:true})}ajustarFilas()}
function infoDeCard(card){const o=prog();
 if(card.dataset.tipo==='peli'){const p=D.peliculas.find(y=>y.id===card.dataset.id);if(p)setInfo({tag:'Película',titulo:p.titulo,meta:(p.anio?p.anio+'':'')+(p.reparto?' · '+p.reparto:''),sinopsis:p.sinopsis,poster:p.poster,hint:'Pulsa OK para ver la película'})}
 else if(card.dataset.tipo==='serie'){const s=D.series[+card.dataset.i];if(s)setInfo({tag:'Serie',titulo:s.titulo,meta:metaSerie(s),sinopsis:s.sinopsis,poster:s.poster,hint:'Pulsa OK para ver los capítulos'})}
 else{const x=o[card.dataset.id];if(x)setInfo({tag:'Seguir viendo',titulo:x.titulo||'Vídeo',meta:'Lo dejaste a medias',poster:x.poster,hint:'Pulsa OK para seguir donde ibas'})}}
/* ---------- SERIE: capítulos en fila horizontal ---------- */
function abrirSerie(i){const s=D.series[i];if(!s)return;vista='serie';serieIdx=i;tempSel=s.temporadas[0].n;tabActiva='series';syncTabs();
 $('#serieTit').textContent=s.titulo;$('#serieMeta').textContent=metaSerie(s);$('#serieSin').textContent=s.sinopsis||'';
 $('#filasWrap').style.display='none';$('#info').style.display='none';$('#serie').style.display='flex';
 setInfo({tag:'Serie',titulo:s.titulo,meta:metaSerie(s),sinopsis:s.sinopsis,poster:s.poster,hint:''});fondo(s.titulo,s.poster);
 pintarCaps();const v=$('#volver');if(v)v.focus({preventScroll:true})}
function pintarCaps(){const s=D.series[serieIdx];const tt=s.temporadas.find(x=>x.n===tempSel)||s.temporadas[0];tempSel=tt.n;
 $('#chips').innerHTML=s.temporadas.length>1?s.temporadas.map(x=>`<button class="chip ${x.n===tt.n?'on':''}" data-temp="${x.n}">Temporada ${x.n}</button>`).join(''):'';
 $('#capsRail').innerHTML=tt.capitulos.map((c,ci)=>`<button class="epcard" data-id="${c.id}" data-ci="${ci}"><span class="epimg">${c.foto?`<img src="/img?ruta=${encodeURIComponent(c.foto)}" alt="" loading="lazy">`:`<span class="gen" style="--h:${tono(s.titulo)}"><b>${c.n}</b></span>`}<span class="epnum">${c.n}</span>${barra(c.id)}</span><span class="eptit">${c.titulo_real||c.titulo||('Capítulo '+c.n)}</span><span class="epcm">${c.titulo_real?'Capítulo '+c.n:'Capítulo '+c.n}</span></button>`).join('');
 const prim=$('#capsRail .epcard');if(prim)prim.focus({preventScroll:true})}
function cerrarSerie(){vista='filas';$('#serie').style.display='none';$('#filasWrap').style.display='';$('#info').style.display='';
 const card=$(`#filas .card[data-tipo="serie"][data-i="${serieIdx}"]`);if(card){infoDeCard(card);card.focus({preventScroll:true});card.scrollIntoView({inline:'center',block:'nearest'})}}
$('#volver').onclick=cerrarSerie;
/* ---------- REPRODUCTOR ---------- */
function ver(id,titulo,ctx,poster){actual={id,titulo,ctx:ctx||null,poster:poster||null};if(vista!=='player')origen=vista;
 const v=$('#repro');$('#velo').style.display='flex';vista='player';ocultarTouch();
 const p=prog()[id];v.src='/ver?id='+id;
 v.onloadedmetadata=()=>{if(p&&p.p>10&&v.duration&&p.p<v.duration*0.95){try{v.currentTime=p.p}catch(e){}}};
 v.play().catch(()=>{});
 if(document.documentElement.requestFullscreen)document.documentElement.requestFullscreen().catch(()=>{})}
function siguiente(){if(!actual||!actual.ctx)return null;const s=D.series[actual.ctx.serie];if(!s)return null;
 const tt=s.temporadas.find(x=>x.n===actual.ctx.temp);if(!tt)return null;
 const mk=(c,temp,ci)=>({id:c.id,titulo:s.titulo+' · '+(c.titulo_real||('Capítulo '+c.n)),ctx:{serie:actual.ctx.serie,temp,ci},poster:s.poster});
 if(actual.ctx.ci+1<tt.capitulos.length)return mk(tt.capitulos[actual.ctx.ci+1],tt.n,actual.ctx.ci+1);
 const i2=s.temporadas.indexOf(tt);if(i2+1<s.temporadas.length&&s.temporadas[i2+1].capitulos.length)return mk(s.temporadas[i2+1].capitulos[0],s.temporadas[i2+1].n,0);
 return null}
function anterior(){if(!actual||!actual.ctx)return null;const s=D.series[actual.ctx.serie];if(!s)return null;
 const tt=s.temporadas.find(x=>x.n===actual.ctx.temp);if(!tt)return null;
 const mk=(c,temp,ci)=>({id:c.id,titulo:s.titulo+' · '+(c.titulo_real||('Capítulo '+c.n)),ctx:{serie:actual.ctx.serie,temp,ci},poster:s.poster});
 if(actual.ctx.ci-1>=0)return mk(tt.capitulos[actual.ctx.ci-1],tt.n,actual.ctx.ci-1);
 const i2=s.temporadas.indexOf(tt);if(i2-1>=0&&s.temporadas[i2-1].capitulos.length){const pt=s.temporadas[i2-1];return mk(pt.capitulos[pt.capitulos.length-1],pt.n,pt.capitulos.length-1)}
 return null}
function cerrar(){ocultarTouch();const v=$('#repro');v.pause();v.removeAttribute('src');v.load();$('#velo').style.display='none';
 if(document.fullscreenElement)document.exitFullscreen().catch(()=>{});
 const volvia=origen;actual=null;vista=volvia==='serie'?'serie':'filas';
 if(vista==='serie'){pintarCaps()}else{render()}}
const v=$('#repro');let ultG=0;
/* La interfaz del reproductor vive oculta: aparece con Atrás o con OK y se va sola */
v.addEventListener('timeupdate',()=>{const a=Date.now();if(a-ultG<4000||!actual)return;ultG=a;
 if(v.duration>0){const o=prog();o[actual.id]={p:v.currentTime,d:v.duration,t:a,titulo:actual.titulo,ctx:actual.ctx,poster:actual.poster};guardaProg(o)}});
v.addEventListener('ended',()=>{if(actual){const o=prog();delete o[actual.id];guardaProg(o)}const nx=siguiente();if(nx)ver(nx.id,nx.titulo,nx.ctx,nx.poster);else cerrar()});
/* ---------- Capa TÁCTIL del reproductor (el móvil) ----------
   En el TV el reproductor sigue sin interfaz: esta capa SOLO aparece al
   tocar la pantalla, sus botones no aceptan foco (tabindex -1) y el mando
   nunca la ve. Trae pausa grande, la barra para correr el vídeo con el
   dedo y el botón Siguiente en las series. */
const vt=$('#vtouch'),vseek=$('#vseek'),vplayB=$('#vplay'),vnextB=$('#vnext'),vprevB=$('#vprev');
let arrastrando=false,tocTimer=null;
function fmtT2(s){s=Math.max(0,Math.floor(s||0));const h=Math.floor(s/3600),m=Math.floor(s%3600/60),ss=s%60;return (h?h+':':'')+String(m).padStart(h?2:1,'0')+':'+String(ss).padStart(2,'0')}
function pintarTouch(){vplayB.textContent=v.paused?'▶':'⏸';const nx=actual&&actual.ctx?siguiente():null;vnextB.style.display=nx?'block':'none';const pv=actual&&actual.ctx?anterior():null;vprevB.style.display=pv?'block':'none';if(v.duration>0){if(!arrastrando)vseek.value=Math.round(v.currentTime/v.duration*1000);$('#vtcur').textContent=fmtT2(v.currentTime);$('#vtdur').textContent=fmtT2(v.duration)}}
function mostrarTouch(){if(vista!=='player')return;vt.classList.add('ver');pintarTouch();clearTimeout(tocTimer);if(!v.paused)tocTimer=setTimeout(ocultarTouch,3500)}
function ocultarTouch(){if(vt)vt.classList.remove('ver');clearTimeout(tocTimer)}
v.addEventListener('pointerdown',e=>{e.preventDefault();vt.classList.contains('ver')?ocultarTouch():mostrarTouch()});
vt.addEventListener('pointerdown',e=>{if(e.target===vt)ocultarTouch()});
vplayB.addEventListener('click',e=>{e.stopPropagation();v.paused?v.play():v.pause();pintarTouch();clearTimeout(tocTimer);if(!v.paused)tocTimer=setTimeout(ocultarTouch,3500)});
vnextB.addEventListener('click',e=>{e.stopPropagation();const nx=siguiente();if(nx)ver(nx.id,nx.titulo,nx.ctx,nx.poster);ocultarTouch()});
vprevB.addEventListener('click',e=>{e.stopPropagation();const pv=anterior();if(pv)ver(pv.id,pv.titulo,pv.ctx,pv.poster);ocultarTouch()});
vseek.addEventListener('pointerdown',e=>{e.stopPropagation();arrastrando=true;clearTimeout(tocTimer)});
vseek.addEventListener('input',()=>{if(v.duration>0){v.currentTime=vseek.value/1000*v.duration;$('#vtcur').textContent=fmtT2(v.currentTime)}});
vseek.addEventListener('pointerup',()=>{arrastrando=false;clearTimeout(tocTimer);tocTimer=setTimeout(ocultarTouch,2500)});
v.addEventListener('timeupdate',()=>{if(vt.classList.contains('ver')&&!arrastrando&&v.duration>0){vseek.value=Math.round(v.currentTime/v.duration*1000);$('#vtcur').textContent=fmtT2(v.currentTime);$('#vtdur').textContent=fmtT2(v.duration)}});
v.addEventListener('pause',()=>{if(vt.classList.contains('ver')){pintarTouch();clearTimeout(tocTimer)}});
v.addEventListener('play',()=>{if(vt.classList.contains('ver')){pintarTouch();clearTimeout(tocTimer);tocTimer=setTimeout(ocultarTouch,3500)}});
/* ---------- CLICS Y FOCO ---------- */
document.addEventListener('click',e=>{
 const tab=e.target.closest('.tab');if(tab){mostrarTab(tab.dataset.tab,true);return}
 const chip=e.target.closest('.chip');if(chip){tempSel=+chip.dataset.temp;pintarCaps();return}
 const ep=e.target.closest('.epcard');if(ep){const s=D.series[serieIdx];const tt=s.temporadas.find(x=>x.n===tempSel);const c=tt.capitulos[+ep.dataset.ci];ver(ep.dataset.id,s.titulo+' · '+(c.titulo_real||('Capítulo '+c.n)),{serie:serieIdx,temp:tempSel,ci:+ep.dataset.ci},s.poster);return}
 const card=e.target.closest('.card');if(!card)return;
 if(card.dataset.tipo==='peli'){const p=D.peliculas.find(y=>y.id===card.dataset.id);ver(card.dataset.id,p?p.titulo:'Película',null,p?p.poster:null)}
 else if(card.dataset.tipo==='serie'){abrirSerie(+card.dataset.i)}
 else{const x=prog()[card.dataset.id];if(x)ver(card.dataset.id,x.titulo||'Vídeo',x.ctx||null,x.poster||null)}});
document.addEventListener('focusin',e=>{const tb=e.target.closest('.tab');if(tb&&vista==='filas'&&tb.dataset.tab!==tabActiva)mostrarTab(tb.dataset.tab,false);
 const card=e.target.closest('.card');if(card&&vista==='filas')infoDeCard(card);
 const ae=document.activeElement;if(ae&&(ae.classList.contains('card')||ae.classList.contains('epcard')))ae.scrollIntoView({inline:'center',block:'nearest'});
 if(vista==='filas')ajustarFilas()});
function ajustarFilas(){const wrap=$('#filasWrap'),fil=$('#filas');const max=fil.scrollHeight-wrap.clientHeight;
 if(max<=0){fil.style.transform='';return}
 const ae=document.activeElement;const row=ae?ae.closest('.fila'):null;if(!row)return;
 let shift=Math.min(max,Math.max(0,row.offsetTop+row.offsetHeight-wrap.clientHeight+14));
 fil.style.transform=`translateY(${-shift}px)`}
/* ---------- BUSCADOR ---------- */
$('#buscar').addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();
 $$('#filas .card').forEach(c=>{const t=(c.querySelector('.ct')||{}).textContent||'';c.style.display=(!q||t.toLowerCase().includes(q))?'':'none'});
 $$('#filas .fila').forEach(f=>{const vis=[...f.querySelectorAll('.card')].some(c=>c.style.display!=='none');f.style.display=vis?'':'none'});ajustarFilas()});
/* ---------- MANDO: flechas y atrás. La página NUNCA se desplaza en vertical ---------- */
document.addEventListener('keydown',e=>{
 const esAtras=e.key==='Escape'||e.key==='Backspace'||e.key==='GoBack'||e.keyCode===461||e.keyCode===10009;
 /* --- Reproductor SIN interfaz: no hay botones ni focos dentro, solo el vídeo.
        OK pausa/reanuda · ◀ ▶ corren 10 s · ▲▼ cambian de capítulo (series) · Atrás sale --- */
 if(vista==='player'){
   if(esAtras){e.preventDefault();cerrar();return}
   if(e.key==='Enter'||e.key===' '){e.preventDefault();v.paused?v.play():v.pause();return}
   if(e.key==='ArrowLeft'){e.preventDefault();v.currentTime=Math.max(0,v.currentTime-10);return}
   if(e.key==='ArrowRight'){e.preventDefault();v.currentTime=Math.min(v.duration||0,v.currentTime+10);return}
   if(e.key==='ArrowUp'){e.preventDefault();const nx=siguiente();if(nx)ver(nx.id,nx.titulo,nx.ctx,nx.poster);return}
   if(e.key==='ArrowDown'){e.preventDefault();const pv=anterior();if(pv)ver(pv.id,pv.titulo,pv.ctx,pv.poster);return}
   return}
 /* Escribiendo en el buscador, las teclas son suyas (salvo ↓ para bajar a las tarjetas) */
 if(vista==='filas'&&document.activeElement===$('#buscar')&&e.key!=='ArrowDown')return;
 if(esAtras){if(vista==='serie'){e.preventDefault();cerrarSerie()}return}
 if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
 e.preventDefault();
 const scope=vista==='serie'?'#serie button, #serie .epcard':'#tabs .tab, #filas .card, #buscar';
 const els=$$(scope).filter(el=>el.offsetParent!==null&&el.style.display!=='none');
 if(!els.length)return;const ae=document.activeElement;const i=els.indexOf(ae);
 if(i<0){els[0].focus();return}
 const r=ae.getBoundingClientRect();let mejor=null,mejorD=1e12;
 for(const el of els){if(el===ae)continue;const q=el.getBoundingClientRect();const dx=q.left+q.width/2-(r.left+r.width/2),dy=q.top+q.height/2-(r.top+r.height/2);
  const ok=e.key==='ArrowRight'?dx>10&&Math.abs(dy)<r.height*.9:e.key==='ArrowLeft'?dx<-10&&Math.abs(dy)<r.height*.9:e.key==='ArrowDown'?dy>10&&Math.abs(dx)<r.width*1.4:dy<-10&&Math.abs(dx)<r.width*1.4;
  if(!ok)continue;const dist=dx*dx+dy*dy;if(dist<mejorD){mejorD=dist;mejor=el}}
 if(mejor){mejor.focus();return}
 /* Atajos seguros: pestaña + ↓ entra a las tarjetas; tarjeta + ↑ sube a su pestaña */
 if(e.key==='ArrowDown'&&ae.classList.contains('tab')){const p=$(`#filas .grupo[data-grupo="${tabActiva}"] .card`);if(p)p.focus()}
 else if(e.key==='ArrowUp'&&ae.classList.contains('card')){const tb=$(`#tabs .tab[data-tab="${tabActiva}"]`);if(tb)tb.focus()}});
async function cargar(){try{const d=await(await fetch('/api/catalogo')).json();
 if(!d.ok){$('#infoMeta').textContent=d.error||'Error al cargar. Revisa el panel de ShareTV en tu PC.';return}
 D=d;render()}catch(e){$('#infoMeta').textContent='Sin conexión con ShareTV. ¿Está encendido en tu PC?'}}
cargar();
</script></body></html>
HTML;
}

function pagina_panel() { return <<<'HTML'
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ShareTV — Panel</title><style>
*{box-sizing:border-box;margin:0}body{background:#0b0e1a;color:#fff;font-family:system-ui,'Segoe UI',sans-serif;padding:1.4rem;max-width:640px;margin:0 auto}
.logo{font-size:1.8rem;font-weight:800}.logo span{color:#2dd4bf}.sub{opacity:.75;margin:.2rem 0 1.4rem}
label{display:block;margin:1rem 0 .35rem;font-weight:600}input{width:100%;padding:.7rem .9rem;border-radius:10px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:#fff;font-size:1rem}
.ayuda{opacity:.65;font-size:.86rem;margin-top:.25rem}
button{background:#2dd4bf;color:#04241f;border:none;font-weight:700;border-radius:999px;padding:.7rem 1.4rem;cursor:pointer;font-size:1rem;margin:.4rem .3rem 0 0}
button.rojo{background:#e5484d;color:#fff}
#caja{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:1rem 1.2rem;margin-top:1.4rem;line-height:1.55}
#msg{min-height:1.3em;margin-top:.8rem;color:#ffd166}
</style></head><body>
<div class="logo">Share<span>TV</span></div><div class="sub">Panel de control — solo visible en esta PC.</div>
<div class="ayuda" style="font-size:.95rem;opacity:.85">📶 ShareTV funciona en tu <b>red local, sin internet</b>: abre la app en el TV y se conecta sola por tu WiFi. Lo único que usa internet es el escrapeo (pósters y datos), y si no hay, espera y reintenta solo.</div>
<label>Carpeta de tu biblioteca</label><input id="root" placeholder="D:\MisPeliculas">
<div class="ayuda">Dentro de esa carpeta ShareTV crea solo: <b>peliculas</b>, <b>series</b> y <b>scrapeo</b>. Copia ahí tus MP4 y aparecen en el TV.</div>
<button id="guardar">💾 Guardar</button>
<div id="caja">
<div>Estado: <b id="est">…</b></div>
<div>Dirección en tu red: <b id="dir">—</b></div>
<div id="conteos"></div>
<div id="escr"></div>
<div id="aviso"></div>
<div style="margin-top:.6rem"><button id="apagar" class="rojo">⏻ Apagar todo</button></div>
</div>
<div id="msg"></div>
<script>
const $=s=>document.querySelector(s);
let _relleno=false;
async function estado(){try{const d=await(await fetch('/api/estado')).json();
 if(!_relleno){$('#root').value=d.root||'';_relleno=true}
 $('#est').textContent=d.root_ok?'🟢 Red local activa — tu cine está servido en tu WiFi':'🔴 Falta la carpeta de la biblioteca';
 $('#dir').textContent=d.direccion_local||'—';
 $('#conteos').textContent='Biblioteca: '+d.conteos.peliculas+' películas · '+d.conteos.series+' series · '+d.conteos.capitulos+' capítulos';
 $('#escr').textContent=d.escrapeo?('🖼 '+d.escrapeo):'';
 const e=d.estado||{};
 $('#aviso').textContent=e.aviso?('⚠ '+e.aviso):'';
}catch(_){$('#est').textContent='⚠ Sin respuesta del servidor local'}}
$('#guardar').onclick=async()=>{$('#msg').textContent='Guardando…';
 try{const q=new URLSearchParams({root:$('#root').value});
 const r=await(await fetch('/api/config?'+q)).json();
 $('#msg').textContent=r.ok?(r.root_ok?'✓ Guardado. Abre la app en el TV y se conecta sola.':'⚠ Guardado, pero esa carpeta no se pudo usar. Revisa la ruta (ej.: D:\\Biblioteca).'):'Error al guardar';
 }catch(e){$('#msg').textContent='⚠ El servidor local no respondió al guardar. Cierra ShareTV y vuelve a abrirlo.'}
 _relleno=false;estado()};
async function control(a){try{await fetch('/api/control?accion='+a);$('#msg').textContent='✓ Orden enviada: '+a}catch(e){$('#msg').textContent='⚠ El servidor local no respondió.'}setTimeout(estado,600)}
$('#apagar').onclick=()=>{if(confirm('¿Apagar ShareTV por completo?'))control('apagar')};
estado();setInterval(estado,2500);
</script></body></html>
HTML;
}
