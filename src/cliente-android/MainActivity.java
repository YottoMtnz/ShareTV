package com.madruga.sharetv;

import android.app.Activity;
import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.webkit.ConsoleMessage;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.net.wifi.WifiManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.DatagramPacket;
import java.net.DatagramSocket;
import java.net.InetAddress;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * ShareTV — cliente Android TV LIVIANO: una carcasa mínima con el mismo
 * WebView simple de CineBox. La interfaz es la página del servidor (la que
 * ya funciona en la PC); esta app solo hace tres cosas:
 * ShareTV es 100% RED LOCAL, sin internet: la app descubre el servidor
 * preguntando por la WiFi (el «faro» del motor responde con su puerto;
 * la IP se aprende del propio paquete, así que da igual que cambie).
 * Lo carga en el WebView y se cura sola si la dirección cambia.
 * Sin librerías: APK diminuto, pensado para cajitas lentas.
 */
public class MainActivity extends Activity {

    private static final int FONDO = Color.rgb(7, 9, 18);
    private static final int TEAL = Color.rgb(45, 212, 191);
    private static final int TEXTO = Color.rgb(240, 244, 248);
    private static final int TENUE = Color.argb(170, 240, 244, 248);
    private static final Pattern PAT_URL = Pattern.compile("https?://[^\\s\"'<>]+");

    private SharedPreferences prefs;
    private final Handler ui = new Handler(Looper.getMainLooper());
    private final ExecutorService hilo = Executors.newSingleThreadExecutor();

    private FrameLayout raiz;
    private WebView web;
    private View vistaVideoFs; // vista de vídeo a pantalla completa (WebChromeClient)

    private LinearLayout vistaEstado;
    private TextView txtEstado;
    private LinearLayout filaBotonesEstado;
    private Button btnReintentar;
    private Button btnCfgEstado;

    private LinearLayout vistaSetup;
    private Button btnLan;
    private EditText edDir;
    private Button btnGuardar;
    private TextView txtCfg;

    private enum Modo { WEB, ESTADO, SETUP }
    private Modo modo = Modo.ESTADO;
    private String base = "";

    private int dp(int v) { return (int) (v * getResources().getDisplayMetrics().density); }

    /* ---------------- Ciclo de vida ---------------- */

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Trampa: si algo cierra la app, el motivo queda guardado y a la
        // próxima apertura se muestra en pantalla (para poder verlo en foto).
        Thread.setDefaultUncaughtExceptionHandler((h, error) -> {
            try {
                java.io.StringWriter sw = new java.io.StringWriter();
                error.printStackTrace(new java.io.PrintWriter(sw));
                getSharedPreferences("sharetv", MODE_PRIVATE).edit()
                        .putString("ultimo_error", sw.toString()).commit();
            } catch (Throwable ignored) {}
            android.os.Process.killProcess(android.os.Process.myPid());
        });
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN
                | WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        // Pantalla completa DE VERDAD: dibujar también bajo el agujero de la
        // cámara en los móviles (si no, Android deja una franja muerta al lado
        // de la cámara). En las cajitas de TV no hay cámara y no afecta.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            WindowManager.LayoutParams lp = getWindow().getAttributes();
            lp.layoutInDisplayCutoutMode =
                    WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
            getWindow().setAttributes(lp);
        }
        prefs = getSharedPreferences("sharetv", MODE_PRIVATE);
        base = prefs.getString("base", "");

        raiz = new FrameLayout(this);
        raiz.setBackgroundColor(FONDO);
        construirWeb();
        construirEstado();
        construirSetup();
        setContentView(raiz);
        aplicarInmersivo();

        String errorGuardado = prefs.getString("ultimo_error", "");
        if (!errorGuardado.isEmpty()) {
            prefs.edit().remove("ultimo_error").apply();
            String corto = errorGuardado.length() > 700 ? errorGuardado.substring(0, 700) : errorGuardado;
            mostrarEstado("La vez anterior la app se cerró por un error. Haz una foto de esto:\n\n" + corto, true);
            vigilar();
            return;
        }
        // SIEMPRE a buscar solo al abrir, esté configurado o recién instalado:
        // faro y, si hace falta, escaneo de la red. Antes una instalación
        // limpia caía directa en la pantalla de configuración sin ni siquiera
        // intentarlo, y la única puerta era el botón de buscar. La config
        // manual queda como último recurso (botón Configuración del estado).
        descubrir();
        vigilar();
    }

    private void aplicarInmersivo() {
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                        | View.SYSTEM_UI_FLAG_FULLSCREEN
                        | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                        | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                        | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                        | View.SYSTEM_UI_FLAG_LAYOUT_STABLE);
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) aplicarInmersivo();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (web != null) web.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (web != null) web.onResume();
    }

    @Override
    protected void onDestroy() {
        if (web != null) { web.destroy(); web = null; }
        hilo.shutdownNow();
        super.onDestroy();
    }

    /* ---------------- Construcción ---------------- */

    private void construirWeb() {
        web = new WebView(this);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);           // la página guarda «Seguir viendo» aquí
        s.setDatabaseEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setMediaPlaybackRequiresUserGesture(false); // el siguiente capítulo entra solo
        s.setLoadWithOverviewMode(false);
        s.setUseWideViewPort(false);
        web.setBackgroundColor(FONDO);
        web.setFocusable(true);
        web.setFocusableInTouchMode(false);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return false; // todo se queda dentro
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                if (modo == Modo.ESTADO) mostrarWeb();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) descubrir();
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onConsoleMessage(ConsoleMessage m) { return true; }

            // El reproductor de la página pide pantalla completa de verdad
            @Override
            public void onShowCustomView(View view, CustomViewCallback callback) {
                vistaVideoFs = view;
                raiz.addView(view, new FrameLayout.LayoutParams(-1, -1));
                web.setVisibility(View.INVISIBLE);
            }

            @Override
            public void onHideCustomView() {
                if (vistaVideoFs != null) { raiz.removeView(vistaVideoFs); vistaVideoFs = null; }
                web.setVisibility(View.VISIBLE);
                web.requestFocus();
            }
        });
        raiz.addView(web, new FrameLayout.LayoutParams(-1, -1));
    }

    private TextView texto(String t, float sp, int color, boolean bold) {
        TextView tv = new TextView(this);
        tv.setText(t);
        tv.setTextSize(sp);
        tv.setTextColor(color);
        if (bold) tv.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
        return tv;
    }

    private GradientDrawable pastilla(int fondo, Integer borde, int radioDp) {
        GradientDrawable g = new GradientDrawable();
        g.setShape(GradientDrawable.RECTANGLE);
        g.setCornerRadius(radioDp >= 999 ? 999f : dp(radioDp));
        g.setColor(fondo);
        if (borde != null) g.setStroke(dp(2), borde);
        return g;
    }

    private Button boton(String t, View.OnClickListener accion) {
        Button b = new Button(this);
        b.setText(t);
        b.setTextSize(16f);
        b.setAllCaps(false);
        b.setTextColor(TEXTO);
        b.setBackground(pastilla(Color.argb(50, 255, 255, 255), Color.argb(90, 255, 255, 255), 999));
        b.setPadding(dp(22), dp(10), dp(22), dp(10));
        b.setOnFocusChangeListener((v, f) -> {
            b.setBackground(pastilla(f ? TEAL : Color.argb(50, 255, 255, 255),
                    f ? TEAL : Color.argb(90, 255, 255, 255), 999));
            b.setTextColor(f ? Color.rgb(3, 33, 28) : TEXTO);
        });
        b.setOnClickListener(accion);
        return b;
    }

    private void construirEstado() {
        vistaEstado = new LinearLayout(this);
        vistaEstado.setOrientation(LinearLayout.VERTICAL);
        vistaEstado.setGravity(Gravity.CENTER);
        vistaEstado.setBackgroundColor(FONDO);
        LinearLayout logo = new LinearLayout(this);
        logo.setGravity(Gravity.CENTER);
        logo.addView(texto("Share", 40f, TEXTO, true));
        logo.addView(texto("TV", 40f, TEAL, true));
        vistaEstado.addView(logo);
        txtEstado = texto("Buscando tu cine…", 17f, TENUE, false);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-2, -2);
        lp.topMargin = dp(16);
        txtEstado.setLayoutParams(lp);
        txtEstado.setGravity(Gravity.CENTER);
        vistaEstado.addView(txtEstado);
        filaBotonesEstado = new LinearLayout(this);
        filaBotonesEstado.setOrientation(LinearLayout.HORIZONTAL);
        filaBotonesEstado.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams lf = new LinearLayout.LayoutParams(-2, -2);
        lf.topMargin = dp(26);
        filaBotonesEstado.setLayoutParams(lf);
        btnReintentar = boton("Reintentar", v -> descubrir());
        btnCfgEstado = boton("Configuración", v -> mostrarSetup());
        filaBotonesEstado.addView(btnReintentar);
        View sep = new View(this);
        sep.setLayoutParams(new LinearLayout.LayoutParams(dp(14), 1));
        filaBotonesEstado.addView(sep);
        filaBotonesEstado.addView(btnCfgEstado);
        filaBotonesEstado.setVisibility(View.GONE);
        vistaEstado.addView(filaBotonesEstado);
        raiz.addView(vistaEstado, new FrameLayout.LayoutParams(-1, -1));
    }

    private EditText campo(String hint) {
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setTextSize(17f);
        e.setTextColor(TEXTO);
        e.setHintTextColor(Color.argb(110, 240, 244, 248));
        e.setBackground(pastilla(Color.argb(40, 255, 255, 255), Color.argb(90, 255, 255, 255), 12));
        e.setPadding(dp(16), dp(12), dp(16), dp(12));
        e.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        e.setSingleLine(true);
        return e;
    }

    private void construirSetup() {
        vistaSetup = new LinearLayout(this);
        vistaSetup.setOrientation(LinearLayout.VERTICAL);
        vistaSetup.setGravity(Gravity.CENTER_HORIZONTAL);
        vistaSetup.setBackgroundColor(FONDO);
        vistaSetup.setPadding(dp(60), dp(70), dp(60), 0);
        vistaSetup.setVisibility(View.GONE);
        vistaSetup.addView(texto("Configura ShareTV una sola vez · v1.7", 26f, TEXTO, true));
        TextView ayuda = texto("ShareTV vive en tu red local: pulsa «Buscar en mi red» y la app encuentra tu PC sola por la WiFi. Sin internet, sin cuentas, sin escribir direcciones.", 15f, TENUE, false);
        LinearLayout.LayoutParams la = new LinearLayout.LayoutParams(dp(620), -2);
        la.topMargin = dp(10);
        ayuda.setLayoutParams(la);
        ayuda.setGravity(Gravity.CENTER);
        vistaSetup.addView(ayuda);
        btnLan = boton("📶 Buscar en mi red (sin internet)", v -> {
            // Un solo camino para todo: el mismo descubrimiento del arranque.
            // (Antes este botón tenía su propio ritual en otro hilo y al
            // terminar tocaba la pantalla desde ahí: la app se cerraba.)
            txtCfg.setText("Buscando ShareTV en tu red…");
            descubrir();
        });
        LinearLayout.LayoutParams ll = new LinearLayout.LayoutParams(-2, -2);
        ll.topMargin = dp(24);
        vistaSetup.addView(btnLan, ll);
        edDir = campo("O escribe la dirección: http://192.168.1.50:8899");
        LinearLayout.LayoutParams ld = new LinearLayout.LayoutParams(dp(560), -2);
        ld.topMargin = dp(22);
        vistaSetup.addView(edDir, ld);
        btnGuardar = boton("Guardar y buscar mi cine", v -> {
            String dir = edDir.getText().toString().trim();
            if (!dir.isEmpty() && !dir.startsWith("http")) dir = "http://" + dir;
            if (dir.endsWith("/")) dir = dir.substring(0, dir.length() - 1);
            prefs.edit().putString("manual", dir).apply();
            descubrir();
        });
        LinearLayout.LayoutParams lb = new LinearLayout.LayoutParams(-2, -2);
        lb.topMargin = dp(22);
        vistaSetup.addView(btnGuardar, lb);
        txtCfg = texto("", 14f, TENUE, false);
        LinearLayout.LayoutParams lx = new LinearLayout.LayoutParams(dp(620), -2);
        lx.topMargin = dp(14);
        txtCfg.setLayoutParams(lx);
        txtCfg.setGravity(Gravity.CENTER);
        vistaSetup.addView(txtCfg);
        // Cadena de foco explícita: en TV el foco nunca se adivina
        btnLan.setId(View.generateViewId());
        edDir.setId(View.generateViewId());
        btnGuardar.setId(View.generateViewId());
        btnLan.setNextFocusDownId(edDir.getId());
        edDir.setNextFocusDownId(btnGuardar.getId());
        edDir.setNextFocusUpId(btnLan.getId());
        btnGuardar.setNextFocusUpId(edDir.getId());
        raiz.addView(vistaSetup, new FrameLayout.LayoutParams(-1, -1));
    }

    /* ---------------- Modos ---------------- */

    private void mostrarWeb() {
        modo = Modo.WEB;
        vistaEstado.setVisibility(View.GONE);
        vistaSetup.setVisibility(View.GONE);
        web.setVisibility(View.VISIBLE);
        web.requestFocus();
    }

    private void mostrarEstado(String msg, boolean conBotones) {
        modo = Modo.ESTADO;
        txtEstado.setText(msg);
        filaBotonesEstado.setVisibility(conBotones ? View.VISIBLE : View.GONE);
        vistaSetup.setVisibility(View.GONE);
        vistaEstado.setVisibility(View.VISIBLE);
        vistaEstado.bringToFront();
        if (conBotones) ui.post(() -> btnReintentar.requestFocus());
    }

    private void mostrarSetup() {
        modo = Modo.SETUP;
        edDir.setText(prefs.getString("manual", ""));
        txtCfg.setText("");
        vistaEstado.setVisibility(View.GONE);
        vistaSetup.setVisibility(View.VISIBLE);
        vistaSetup.bringToFront();
        ui.post(() -> btnLan.requestFocus());
    }

    /* ------------- Descubrimiento en la red local (sin internet) ------------- */

    private volatile boolean descubriendo = false;

    private void descubrir() {
        if (descubriendo) return; // una búsqueda a la vez (en cajitas lentas se agradece)
        descubriendo = true;
        // OJO: descubrir() se llama también desde hilos de fondo (el botón de
        // buscar en la red). Tocar la pantalla desde ahí MATA la app en
        // Android (CalledFromWrongThreadException): todo lo visual va por ui.post.
        ui.post(() -> mostrarEstado("Buscando tu cine…", false));
        hilo.execute(() -> {
            try {
                // 1) La dirección que ya funcionó
                String guardada = prefs.getString("base", "");
                if (!guardada.isEmpty() && ping(guardada)) {
                    base = guardada;
                    cargar(base);
                    return;
                }
                // 2) La dirección escrita a mano en Configuración (si la hay)
                String manual = prefs.getString("manual", "");
                if (!manual.isEmpty() && ping(manual)) {
                    base = manual;
                    prefs.edit().putString("base", manual).apply();
                    cargar(base);
                    return;
                }
                // 3) Preguntar a la WiFi: el faro del servidor responde solo
                ui.post(() -> txtEstado.setText("Buscando ShareTV en tu red…"));
                String lan = descubrirLan();
                if (lan != null) {
                    base = lan;
                    prefs.edit().putString("base", lan).apply();
                    cargar(base);
                    return;
                }
                // 4) Hay redes que se tragan el grito del faro (routers y
                // repetidores que no dejan pasar el broadcast): se barre la
                // red propia por el puerto 8899, que siempre es el mismo.
                ui.post(() -> txtEstado.setText("Escaneando tu red en busca de ShareTV…"));
                String esc = escanearRed();
                if (esc != null) {
                    base = esc;
                    prefs.edit().putString("base", esc).apply();
                    cargar(base);
                    return;
                }
                ui.post(() -> mostrarEstado(
                        "No se encontró el servidor. Revisa que ShareTV esté abierto en tu PC y que este equipo esté en la misma WiFi.", true));
            } catch (Throwable t) {
                ui.post(() -> mostrarEstado("Algo falló buscando el servidor: " + t, true));
            } finally {
                descubriendo = false;
            }
        });
    }

    /** Pregunta por UDP a toda la red local; el faro responde «SHARETV!<puerto>»
     *  y la dirección se aprende del propio paquete (la IP puede cambiar). */
    private String descubrirLan() {
        DatagramSocket s = null;
        WifiManager.MulticastLock lock = null;
        try {
            WifiManager wm = (WifiManager) getApplicationContext().getSystemService(Context.WIFI_SERVICE);
            if (wm != null) {
                lock = wm.createMulticastLock("sharetv");
                lock.setReferenceCounted(false);
                lock.acquire();
            }
            s = new DatagramSocket();
            s.setBroadcast(true);
            s.setSoTimeout(700);
            byte[] preg = "SHARETV?".getBytes("UTF-8");
            java.util.List<InetAddress> destinos = new java.util.ArrayList<>();
            destinos.add(InetAddress.getByName("255.255.255.255"));
            try {
                if (wm != null) {
                    android.net.DhcpInfo di = wm.getDhcpInfo();
                    if (di != null && di.ipAddress != 0) {
                        int b = (di.ipAddress & di.netmask) | ~di.netmask;
                        destinos.add(InetAddress.getByAddress(new byte[]{
                                (byte) (b & 0xff), (byte) ((b >> 8) & 0xff),
                                (byte) ((b >> 16) & 0xff), (byte) ((b >> 24) & 0xff)}));
                    }
                }
            } catch (Exception ignored) { }
            long fin = System.currentTimeMillis() + 2600;
            boolean enviado = false;
            while (System.currentTimeMillis() < fin) {
                if (!enviado) {
                    for (InetAddress d : destinos) {
                        try { s.send(new DatagramPacket(preg, preg.length, d, 8898)); }
                        catch (Exception ignored) { }
                    }
                    enviado = true;
                }
                try {
                    byte[] buf = new byte[64];
                    DatagramPacket p = new DatagramPacket(buf, buf.length);
                    s.receive(p);
                    String r = new String(p.getData(), 0, p.getLength(), "UTF-8").trim();
                    if (r.startsWith("SHARETV!")) {
                        int puerto = 8899;
                        try { puerto = Integer.parseInt(r.substring(8).trim()); } catch (Exception ignored) { }
                        String cand = "http://" + p.getAddress().getHostAddress() + ":" + puerto;
                        if (ping(cand)) return cand;
                    }
                } catch (java.net.SocketTimeoutException te) {
                    enviado = false; // nadie respondió aún: volver a preguntar
                }
            }
        } catch (Throwable ignored) {
        } finally {
            if (s != null) s.close();
            if (lock != null && lock.isHeld()) lock.release();
        }
        return null;
    }

    /* Mi propia IP y el prefijo de mi red (vale por WiFi y también por cable,
       que muchas cajitas van por Ethernet y ahí el WifiManager no sabe nada). */
    private String[] ipYRed() {
        try {
            java.util.Enumeration<java.net.NetworkInterface> nis = java.net.NetworkInterface.getNetworkInterfaces();
            while (nis.hasMoreElements()) {
                java.net.NetworkInterface ni = nis.nextElement();
                if (!ni.isUp() || ni.isLoopback()) continue;
                java.util.Enumeration<java.net.InetAddress> ips = ni.getInetAddresses();
                while (ips.hasMoreElements()) {
                    java.net.InetAddress ip = ips.nextElement();
                    if (ip instanceof java.net.Inet4Address && !ip.isLoopbackAddress() && !ip.isLinkLocalAddress()) {
                        String s = ip.getHostAddress();
                        return new String[]{s, s.substring(0, s.lastIndexOf('.') + 1)};
                    }
                }
            }
        } catch (Throwable ignored) {}
        return null;
    }

    /* Ping corto para el barrido: el de siempre espera 5 s, aquí no hay tiempo. */
    private boolean pingRapido(String baseUrl) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(baseUrl + "/ping").openConnection();
            c.setConnectTimeout(400);
            c.setReadTimeout(900);
            c.setRequestMethod("GET");
            if (c.getResponseCode() != 200) return false;
            java.io.InputStream in = c.getInputStream();
            byte[] buf = new byte[64];
            int n = in.read(buf);
            in.close();
            return n > 0 && new String(buf, 0, n, StandardCharsets.UTF_8).contains("ShareTV");
        } catch (Throwable e) {
            return false;
        } finally {
            if (c != null) c.disconnect();
        }
    }

    /* El barrido: pregunta /ping a cada dirección de mi red por el puerto 8899,
       empezando por los vecinos más cercanos a mi propia IP (las PC suelen
       caer cerca en el DHCP). El primero que se presente como ShareTV gana. */
    private String escanearRed() {
        String[] red = ipYRed();
        if (red == null) return null;
        final String prefijo = red[1];
        final int propio;
        try {
            propio = Integer.parseInt(red[0].substring(red[0].lastIndexOf('.') + 1));
        } catch (Exception e) {
            return null;
        }
        final java.util.concurrent.atomic.AtomicReference<String> hallado =
                new java.util.concurrent.atomic.AtomicReference<>(null);
        java.util.concurrent.ExecutorService pool = java.util.concurrent.Executors.newFixedThreadPool(16);
        for (int d = 1; d < 255; d++) {
            if (propio + d < 255) {
                final int oct = propio + d;
                pool.execute(() -> {
                    if (hallado.get() == null) {
                        String cand = "http://" + prefijo + oct + ":8899";
                        if (pingRapido(cand)) hallado.compareAndSet(null, cand);
                    }
                });
            }
            if (propio - d > 0) {
                final int oct = propio - d;
                pool.execute(() -> {
                    if (hallado.get() == null) {
                        String cand = "http://" + prefijo + oct + ":8899";
                        if (pingRapido(cand)) hallado.compareAndSet(null, cand);
                    }
                });
            }
        }
        pool.shutdown();
        try { pool.awaitTermination(15, java.util.concurrent.TimeUnit.SECONDS); } catch (Exception ignored) {}
        try { pool.shutdownNow(); } catch (Exception ignored) {}
        return hallado.get();
    }

    private void cargar(String url) {
        ui.post(() -> { if (web != null) web.loadUrl(url); });
    }

    /** Vigilante: si la PC cambia de dirección en la red, se cura solo (cada ~15 min). */
    private void vigilar() {
        ui.postDelayed(new Runnable() {
            @Override
            public void run() {
                if (!base.isEmpty()) {
                    hilo.execute(() -> {
                        if (!ping(base)) {
                            String nueva = descubrirLan();
                            if (nueva == null) nueva = escanearRed();
                            if (nueva != null && !nueva.equals(base)) {
                                base = nueva;
                                prefs.edit().putString("base", nueva).apply();
                                cargar(nueva); // recarga con la dirección nueva
                            }
                        }
                    });
                }
                ui.postDelayed(this, 15 * 60 * 1000L);
            }
        }, 15 * 60 * 1000L);
    }

    private boolean ping(String b) {
        String t = httpTexto(b + "/ping", 5000);
        return t != null && t.contains("ShareTV");
    }

    private String httpTexto(String url, int timeoutMs) {
        HttpURLConnection con = null;
        try {
            con = (HttpURLConnection) new URL(url).openConnection();
            con.setConnectTimeout(timeoutMs);
            con.setReadTimeout(timeoutMs);
            con.setRequestProperty("User-Agent", "ShareTV-Android/1.0");
            if (con.getResponseCode() < 200 || con.getResponseCode() >= 300) return null;
            BufferedReader r = new BufferedReader(new InputStreamReader(con.getInputStream(), StandardCharsets.UTF_8));
            StringBuilder sb = new StringBuilder();
            String linea;
            while ((linea = r.readLine()) != null) sb.append(linea);
            r.close();
            return sb.toString();
        } catch (Exception e) {
            return null;
        } finally {
            if (con != null) con.disconnect();
        }
    }

    /* ---------------- El mando ----------------
       Todo el trabajo de foco y flechas vive en la PÁGINA (ya aprobada);
       aquí solo se traduce Atrás → Escape para la página y se cuida que
       Atrás NUNCA cierre la app. */

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_MENU && modo == Modo.WEB) {
            mostrarSetup();
            return true;
        }
        // Atrás NO se consume aquí: debe llegar a onBackPressed (que nunca sale)
        return super.onKeyDown(keyCode, event);
    }

    @Override
    public void onBackPressed() {
        if (modo == Modo.WEB) {
            // La página decide por capas (reproductor → serie → inicio)
            web.evaluateJavascript(
                    "document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',keyCode:27,which:27,bubbles:true,cancelable:true}))",
                    null);
        } else if (modo == Modo.SETUP) {
            if (base != null && !base.isEmpty() && web.getUrl() != null) mostrarWeb();
            else descubrir();
        }
        // En el inicio, Atrás no hace nada: la app no se cierra con Atrás.
    }
}
