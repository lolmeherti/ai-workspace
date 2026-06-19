package models

import (
	"encoding/json"
	"os"
	"path/filepath"
)

func LoadConfig(data []byte, workDir string) map[string]Tier {
	tiers := make(map[string]Tier)

	userPath := filepath.Join(workDir, "models.json")
	if userBytes, err := os.ReadFile(userPath); err == nil && len(userBytes) > 0 {
		_ = json.Unmarshal(userBytes, &tiers)
	} else {
		_ = json.Unmarshal(data, &tiers)
	}

	return tiers
}
