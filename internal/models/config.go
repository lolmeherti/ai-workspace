package models

import "encoding/json"

type ModelsFile struct {
	Models map[string]ModelDefinition `json:"models"`
}

// LoadConfig parses the embedded models.json. The model catalog is baked into
// the binary at build time (//go:embed models.json in main.go) and is the single
// source of truth — no on-disk override is consulted.
func LoadConfig(data []byte) map[string]ModelDefinition {
	var file ModelsFile
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
