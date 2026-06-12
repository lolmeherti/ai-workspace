package models

import (
	"os"
	"strings"
)

func ResolveActive(envPath string, tiers map[string]Tier, vram float64) Tier {
	existing, err := os.ReadFile(envPath)
	if err == nil {
		lines := strings.Split(string(existing), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "LLM_MODEL_NAME=") {
				parts := strings.SplitN(line, "=", 2)
				if len(parts) == 2 {
					configuredModel := strings.TrimSpace(parts[1])
					for _, tier := range tiers {
						if tier.Name == configuredModel {
							return tier
						}
					}
				}
			}
		}
	}

	if vram >= 28.0 {
		return tiers["Tier1"]
	} else if vram >= 20.0 {
		return tiers["Tier2"]
	} else if vram >= 14.0 {
		return tiers["Tier3"]
	} else if vram >= 10.0 {
		return tiers["Tier4"]
	}
	return tiers["Tier5"]
}
