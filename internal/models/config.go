package models

import (
	"encoding/json"
	"os"
	"path/filepath"
)

type ModelsFile struct {
	Models map[string]ModelDefinition `json:"models"`
}

func LoadConfig(data []byte, workDir string) map[string]ModelDefinition {
	var file ModelsFile

	userPath := filepath.Join(workDir, "models.json")
	if userBytes, err := os.ReadFile(userPath); err == nil && len(userBytes) > 0 {
		if json.Unmarshal(userBytes, &file) == nil && len(file.Models) > 0 {
			return ValidateAndDerive(file.Models)
		}
	}

	if json.Unmarshal(data, &file) == nil && len(file.Models) > 0 {
		return ValidateAndDerive(file.Models)
	}

	return nil
}

func ValidateAndDerive(defs map[string]ModelDefinition) map[string]ModelDefinition {
	for id, def := range defs {
		def.Capabilities.Vision = def.MMProj != nil && def.MMProj.File != "" && def.MMProj.URL != ""
		defs[id] = def
	}
	return defs
}
