package gpu

import (
	"bytes"
	"encoding/json"
	"strings"

	"localsy/internal/util"
)

func Detect() (string, float64) {
	cmd := util.RunSilentCommand("powershell", "-Command",
		`Get-CimInstance -ClassName Win32_VideoController -ErrorAction SilentlyContinue | ForEach-Object { `+
			`$c = $_; `+
			`$id = if ($c.PNPDeviceID) { $c.PNPDeviceID.Split('&')[0..1] -join '&' } else { "" }; `+
			`$reg = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Class\{4d36e968-e325-11ce-bfc1-08002be10318}\0*' -ErrorAction SilentlyContinue | Where-Object { $_.MatchingDeviceId -like "*$id*" } | Select-Object -First 1; `+
			`$ram = if ($reg -and $reg.'HardwareInformation.qwMemorySize') { $reg.'HardwareInformation.qwMemorySize' } else { $c.AdapterRAM }; `+
			`[PSCustomObject]@{ Name = $c.Name; AdapterRAM = $ram } `+
		`} | ConvertTo-Json`)

	var out bytes.Buffer
	cmd.Stdout = &out
	_ = cmd.Run()

	type gpuInfo struct {
		Name       string `json:"Name"`
		AdapterRAM int64  `json:"AdapterRAM"`
	}

	var gpus []gpuInfo
	raw := out.Bytes()
	if err := json.Unmarshal(raw, &gpus); err != nil {
		var single gpuInfo
		if err := json.Unmarshal(raw, &single); err == nil {
			gpus = append(gpus, single)
		}
	}

	vendor := "CPU"
	var maxVram int64

	for _, gpu := range gpus {
		nameLower := strings.ToLower(gpu.Name)
		if strings.Contains(nameLower, "nvidia") || strings.Contains(nameLower, "rtx") || strings.Contains(nameLower, "geforce") {
			vendor = "NVIDIA"
			if gpu.AdapterRAM > maxVram {
				maxVram = gpu.AdapterRAM
			}
		} else if strings.Contains(nameLower, "amd") || strings.Contains(nameLower, "radeon") {
			if vendor != "NVIDIA" {
				vendor = "AMD"
				if gpu.AdapterRAM > maxVram {
					maxVram = gpu.AdapterRAM
				}
			}
		}
	}

	vramGB := float64(maxVram) / (1024 * 1024 * 1024)
	return vendor, vramGB
}
