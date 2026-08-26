package models

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"localsy/internal/download"
)

var reservedArgPattern = regexp.MustCompile(`^-?-?(m|alias|ctx-size|mmproj|spec-type|spec-draft-model|spec-draft-n-max|spec-draft-ngl|cache-type-k|cache-type-v|flash-attn|host|port|jinja|reasoning-budget|ngl|parallel)(=.*)?$`)

func ResolveModel(
	modelID string,
	defs map[string]ModelDefinition,
	hw Hardware,
	modelDir string,
	onProgress func(float64),
) (*ResolvedModel, error) {
	if err := ValidateModel(modelID, defs, hw); err != nil {
		return nil, err
	}

	def := defs[modelID]
	profileID, profile := selectProfile(def.Profiles, hw)

	if err := validateExtraArgs(profile.ExtraArgs); err != nil {
		return nil, fmt.Errorf("model %q profile %q: %w", modelID, profileID, err)
	}

	resolved := &ResolvedModel{
		Name:            def.Name,
		ModelPath:       filepath.Join(modelDir, def.Model.File),
		MMProjPath:      "",
		CtxSize:         profile.CtxSize,
		KVCacheType:     profile.KVCacheType,
		FlashAttn:       true,
		ReasoningBudget: def.ReasoningBudget,
		ExtraArgs:       profile.ExtraArgs,
		Speculative:     nil,
	}

	if profile.KVCacheType == "" {
		resolved.KVCacheType = "q8_0"
	}
	if profile.FlashAttn != nil {
		resolved.FlashAttn = *profile.FlashAttn
	}

	if err := downloadArtifact(modelDir, def.Model, onProgress); err != nil {
		return nil, fmt.Errorf("model download failed: %w", err)
	}

	if def.MMProj != nil && def.MMProj.File != "" && def.MMProj.URL != "" {
		resolved.MMProjPath = filepath.Join(modelDir, def.MMProj.File)
		if err := downloadArtifact(modelDir, *def.MMProj, onProgress); err != nil {
			return nil, fmt.Errorf("model %q requires mmproj (vision-capable) but download failed: %w", modelID, err)
		}
	}

	spec := def.Speculative
	if profile.SpeculativeEnabled != nil {
		if !*profile.SpeculativeEnabled {
			spec = nil
		} else if profile.Speculative != nil {
			spec = profile.Speculative
		}
	} else if profile.Speculative != nil {
		spec = profile.Speculative
	}
	if spec != nil && spec.Artifact.File != "" && spec.Artifact.URL != "" {
		specPath := filepath.Join(modelDir, spec.Artifact.File)
		downloadErr := downloadArtifact(modelDir, spec.Artifact, onProgress)
		if downloadErr != nil {
			fmt.Fprintf(os.Stderr, "[models] speculative artifact %s unavailable: %v — disabling speculative decoding\n", spec.Artifact.File, downloadErr)
			spec = nil
		}
		if spec != nil {
			if _, statErr := os.Stat(specPath); statErr != nil {
				fmt.Fprintf(os.Stderr, "[models] speculative artifact %s missing on disk — disabling speculative decoding\n", spec.Artifact.File)
				spec = nil
			}
		}
		if spec != nil {
			resolved.Speculative = &ResolvedSpeculative{
				Path:     specPath,
				Strategy: spec.Strategy,
				NMax:     spec.NMax,
				NGL:      spec.NGL,
			}
			if resolved.Speculative.Strategy == "" {
				resolved.Speculative.Strategy = "draft-mtp"
			}
			if resolved.Speculative.NMax == 0 {
				resolved.Speculative.NMax = 1
			}
		}
	}

	return resolved, nil
}

// ValidateModel verifies a model ID resolves to a deployable profile for the
// given hardware WITHOUT downloading any artifacts. It performs the same
// existence/artifact/profile checks ResolveModel does before its download phase,
// so callers can reject a bad switch request synchronously before deferring the
// (potentially long) download + restart to the background.
func ValidateModel(modelID string, defs map[string]ModelDefinition, hw Hardware) error {
	def, ok := defs[modelID]
	if !ok {
		return fmt.Errorf("model %q not found", modelID)
	}
	if def.Model.File == "" || def.Model.URL == "" {
		return fmt.Errorf("model %q has no artifact configured", modelID)
	}
	if profileID, _ := selectProfile(def.Profiles, hw); profileID == "" {
		return fmt.Errorf("model %q has no profile matching hardware (%.1f GB VRAM)", modelID, hw.VRAMGB)
	}
	return nil
}

func selectProfile(profiles map[string]DeploymentProfile, hw Hardware) (string, DeploymentProfile) {
	type candidate struct {
		id   string
		prof DeploymentProfile
	}
	var candidates []candidate
	for id, p := range profiles {
		if hw.VRAMGB >= p.Requirements.VRAMMin {
			candidates = append(candidates, candidate{id, p})
		}
	}
	if len(candidates) == 0 {
		return "", DeploymentProfile{}
	}
	sort.Slice(candidates, func(i, j int) bool {
		return candidates[i].prof.CtxSize > candidates[j].prof.CtxSize
	})
	return candidates[0].id, candidates[0].prof
}

func validateExtraArgs(args []string) error {
	for _, arg := range args {
		trimmed := strings.TrimLeft(arg, "-")
		if reservedArgPattern.MatchString(trimmed) {
			return fmt.Errorf("extra_arg %q conflicts with a typed configuration field", arg)
		}
	}
	return nil
}

func downloadArtifact(dir string, a Artifact, onProgress func(float64)) error {
	path := filepath.Join(dir, a.File)
	if _, err := os.Stat(path); err == nil {
		return nil
	}
	tmpPath := path + ".tmp"
	if err := download.File(tmpPath, a.URL, onProgress); err != nil {
		_ = os.Remove(tmpPath)
		return err
	}
	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("rename %s -> %s: %w", tmpPath, path, err)
	}
	return nil
}

func ResolveActiveModelID(envPath string, defs map[string]ModelDefinition) string {
	existing, err := os.ReadFile(envPath)
	if err != nil {
		return ""
	}
	lines := strings.Split(string(existing), "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "LLM_MODEL_ID=") {
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				id := strings.Trim(strings.TrimSpace(parts[1]), `"`)
				if _, ok := defs[id]; ok {
					return id
				}
			}
		}
		if strings.HasPrefix(line, "LLM_MODEL_NAME=") {
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				name := strings.Trim(strings.TrimSpace(parts[1]), `"`)
				for id, def := range defs {
					if def.Name == name {
						return id
					}
				}
			}
		}
	}
	return ""
}

func AutoSelectModelID(defs map[string]ModelDefinition, hw Hardware) string {
	var bestID string
	bestCtx := 0
	for id, def := range defs {
		if def.Model.File == "" || def.Model.URL == "" {
			continue
		}
		profID, prof := selectProfile(def.Profiles, hw)
		if profID == "" {
			continue
		}
		if prof.CtxSize > bestCtx {
			bestCtx = prof.CtxSize
			bestID = id
		}
	}
	if bestID != "" {
		return bestID
	}
	for id, def := range defs {
		if def.Model.File != "" && def.Model.URL != "" {
			return id
		}
	}
	return ""
}

func CollectArtifacts(def ModelDefinition, profile DeploymentProfile) []Artifact {
	artifacts := []Artifact{def.Model}
	if def.MMProj != nil && def.MMProj.File != "" {
		artifacts = append(artifacts, *def.MMProj)
	}
	spec := def.Speculative
	if profile.SpeculativeEnabled != nil {
		if !*profile.SpeculativeEnabled {
			spec = nil
		} else if profile.Speculative != nil {
			spec = profile.Speculative
		}
	} else if profile.Speculative != nil {
		spec = profile.Speculative
	}
	if spec != nil && spec.Artifact.File != "" {
		artifacts = append(artifacts, spec.Artifact)
	}
	return artifacts
}
