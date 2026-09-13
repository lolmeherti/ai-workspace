package gpu

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strconv"
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

// FreeVRAMBytes returns the currently-free GPU memory in bytes via nvidia-smi
// (NVML), or an error if the query fails (non-NVIDIA / no driver / not running).
func FreeVRAMBytes() (uint64, error) {
	return nvidiaSmiMem("memory.free")
}

// TotalVRAMBytes returns the total GPU memory in bytes via nvidia-smi. This is
// the number the VRAM gate compares against: at switch time the OLD model is
// still resident (so "free" is misleadingly small), but it is killed before the
// new one loads, so the new model has the full total (minus the desktop, which
// memcalc.OverheadMax absorbs). AdapterRAM is not used — it lies ~2 GiB low.
func TotalVRAMBytes() (uint64, error) {
	return nvidiaSmiMem("memory.total")
}

func nvidiaSmiMem(field string) (uint64, error) {
	cmd := util.RunSilentCommand("nvidia-smi",
		"--query-gpu="+field, "--format=csv,noheader,nounits")
	var out bytes.Buffer
	cmd.Stdout = &out
	if err := cmd.Run(); err != nil {
		return 0, fmt.Errorf("nvidia-smi %s query failed: %w", field, err)
	}
	first := strings.TrimSpace(strings.Split(out.String(), "\n")[0])
	mb, err := strconv.ParseUint(first, 10, 64)
	if err != nil {
		return 0, fmt.Errorf("parse nvidia-smi %s %q: %w", field, first, err)
	}
	return mb * 1024 * 1024, nil
}
