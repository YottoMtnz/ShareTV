// ShareTV.exe — la "simple app" de Windows.
// Doble clic: arranca el motor (que vive en Data) en su consola minimizada
// y abre el panel. Si ShareTV ya está abierto, solo abre el panel.
// Sin consola propia (windowsgui): ningún ventanazo negro del lanzador.
package main

import (
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"
	"unsafe"
)

var (
	user32           = syscall.NewLazyDLL("user32.dll")
	procMessageBoxW  = user32.NewProc("MessageBoxW")
	procEnumWindows  = user32.NewProc("EnumWindows")
	procGetWindowPID = user32.NewProc("GetWindowThreadProcessId")
	procShowWindow   = user32.NewProc("ShowWindow")
)

func aviso(texto string) {
	t, _ := syscall.UTF16PtrFromString(texto)
	c, _ := syscall.UTF16PtrFromString("ShareTV")
	procMessageBoxW.Call(0, uintptr(unsafe.Pointer(t)), uintptr(unsafe.Pointer(c)), 0x40)
}

func yaResponde() bool {
	cli := &http.Client{Timeout: 800 * time.Millisecond}
	r, err := cli.Get("http://127.0.0.1:8899/ping")
	if err != nil {
		return false
	}
	defer r.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(r.Body, 64))
	return string(b) != "" && r.StatusCode == 200
}

func abrirPanel() {
	_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", "http://localhost:8899/panel").Start()
}

// Minimiza la ventana de consola del proceso indicado (el motor), como
// hacía el viejo ShareTV.bat con «start /min».
func minimizarConsolaDe(pid int) {
	cb := syscall.NewCallback(func(hwnd uintptr, _ uintptr) uintptr {
		var p uint32
		procGetWindowPID.Call(hwnd, uintptr(unsafe.Pointer(&p)))
		if int(p) == pid {
			procShowWindow.Call(hwnd, 6) // SW_MINIMIZE
			return 0
		}
		return 1
	})
	for i := 0; i < 10; i++ {
		procEnumWindows.Call(cb, 0)
		time.Sleep(250 * time.Millisecond)
	}
}

func main() {
	exe, err := os.Executable()
	if err != nil {
		aviso("No pude encontrar mi propia carpeta.")
		return
	}
	data := filepath.Join(filepath.Dir(exe), "Data")
	php := filepath.Join(data, "php", "php.exe")
	motor := filepath.Join(data, "motor.php")

	if yaResponde() {
		abrirPanel()
		return
	}
	if _, err := os.Stat(php); err != nil {
		aviso("Falta la carpeta Data junto a ShareTV.exe.\nDeja la app y la carpeta Data siempre juntas.")
		return
	}
	cmd := exec.Command(php, motor)
	cmd.Dir = data
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x10} // CREATE_NEW_CONSOLE
	if err := cmd.Start(); err != nil {
		aviso("No pude arrancar el motor de ShareTV.")
		return
	}
	go minimizarConsolaDe(cmd.Process.Pid)
	// Esperar a que el servidor responda (máx. ~6 s) y abrir el panel
	for i := 0; i < 20; i++ {
		if yaResponde() {
			break
		}
		time.Sleep(300 * time.Millisecond)
	}
	abrirPanel()
}
