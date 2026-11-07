# ShareTV 🎬

**Tu cine personal en tu red local. Sin internet, sin cuentas, sin nube.**

El servidor vive en tu PC de Windows y el cliente en tu Android TV (o móvil). Se encuentran solos por tu red: abres la app y a ver cine.

**Página del proyecto:** https://yottomtnz.github.io/ShareTV/

## Descargas

| Pieza | Archivo |
|---|---|
| Servidor (Windows) | [ShareTV-Windows.zip](descargas/ShareTV-Windows.zip) — un `.exe` y una carpeta `Data` |
| Cliente (Android TV / móvil) | [ShareTV.apk](descargas/ShareTV.apk) — 33 KB |

## Cómo se usa

1. Descomprime el servidor y abre **ShareTV.exe**. Elige tu carpeta de cine una sola vez.
2. Copia tus vídeos: películas en `peliculas/`, series en `series/<Nombre>/` (temporadas en subcarpetas `temp 1`, `temp 2`… o sueltas).
3. Instala la APK en el TV y ábrela: **encuentra tu PC sola** (faro en la red + escaneo por el puerto 8899). Sin escribir direcciones IP.

Con internet, el servidor busca solo los pósters, sinopsis y las fotos y títulos reales de los capítulos, y los guarda. Sin internet, el cine sigue funcionando igual: el escrapeo simplemente espera.

## El mando (en el reproductor)

- **OK** — pausa / sigue
- **◀ ▶** — saltan 10 segundos
- **▲ ▼** — capítulo siguiente / anterior (en series)
- **Atrás** — sale, de una vez

La app recuerda dónde ibas en cada película y capítulo («Seguir viendo»).

## Código

- `src/servidor/` — el motor y el panel (PHP): supervisa el servidor web, el faro de descubrimiento y el escrapeo.
- `src/cliente-android/` — la app (Java, un solo archivo): WebView a pantalla completa + descubrimiento por faro y escaneo.
- `src/lanzador/` — el lanzador de Windows (Go) que abre el panel y arranca el motor.

---

*Hecho por Fraudy Martinez Madruga.*
