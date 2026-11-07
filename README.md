# ShareTV 🎬

**Tu cine personal en tu red local. Sin internet, sin cuentas, sin nube.**

El servidor vive en tu PC de Windows y el cliente en tu Android TV (o móvil). Se encuentran solos por tu red: abres la app y a ver cine.

**Página del proyecto:** https://yottomtnz.github.io/ShareTV/

## Descargas

| Pieza | Archivo |
|---|---|
| Servidor (Windows) | [ShareTV-Windows.zip](descargas/ShareTV-Windows.zip) |
| Cliente (Android TV / móvil) | [ShareTV.apk](descargas/ShareTV.apk) |

## Cómo se usa

1. Descomprime el servidor, ábrelo y elige tu carpeta de cine una sola vez.
2. Copia tus vídeos: películas en `peliculas/`, series en `series/<Nombre>/` (temporadas en subcarpetas `temp 1`, `temp 2`… o sueltas).
3. Instala la APK en el TV y ábrela: **encuentra tu PC sola**. Sin escribir direcciones IP.

Con internet, el servidor busca solo los pósters, sinopsis y las fotos y títulos reales de los capítulos, y los guarda. Sin internet, el cine sigue funcionando igual: el escrapeo simplemente espera.

## El mando (en el reproductor)

- **OK** — pausa / sigue
- **◀ ▶** — saltan 10 segundos
- **▲ ▼** — capítulo siguiente / anterior (en series)
- **Atrás** — sale, de una vez

La app recuerda dónde ibas en cada película y capítulo («Seguir viendo»).

**En el móvil** todo es táctil: las filas se deslizan con el dedo, y en el reproductor, al tocar la pantalla aparecen la barra para correr el vídeo manualmente, el botón de pausa y el de **Siguiente** capítulo. Atrás es el gesto normal del teléfono. La misma APK sirve para el TV y para el móvil: en el TV manda el mando y en el móvil manda el dedo.

## Código

El código fuente del servidor y del cliente está en la carpeta `src/`.

---

*Hecho por Fraudy Martinez Madruga.*
