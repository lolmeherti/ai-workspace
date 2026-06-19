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
					configuredModel := unquoteEnvValue(strings.TrimSpace(parts[1]))
						for _, tier := range tiers {
						if tier.Name == configuredModel {
							return tier
						}
					}
				}
			}
		}
	}

	var best Tier
	bestCtx := 0
	vramTokens := int(vram * 1024 * 1024)

	for _, t := range tiers {
		if t.File == "" || t.URL == "" {
			continue
		}
		ctx := t.CtxSize
		if ctx == 0 {
			ctx = ctxForTier(t.Name)
		}
		if ctx <= vramTokens && bestCtx < ctx {
			best = t
			bestCtx = ctx
		}
	}

	if bestCtx > 0 {
		return best
	}

	for _, t := range tiers {
		if t.File != "" && t.URL != "" {
			return t
		}
	}
	return Tier{}
}

func ctxForTier(name string) int {
	switch {
	case strings.Contains(name, "26B") || strings.Contains(name, "qat"):
		return 160 * 1024
	case strings.Contains(name, "E4B-Q8"):
		return 65 * 1024
	case strings.Contains(name, "E4B"):
		return 50 * 1024
	case strings.Contains(name, "E2B"):
		return 45 * 1024
	default:
		return 32768
	}
}

func unquoteEnvValue(v string) string {
	if len(v) >= 2 && v[0] == '"' && v[len(v)-1] == '"' {
		return v[1 : len(v)-1]
	}
	return v
}
