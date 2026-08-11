package models

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func testDefs(t *testing.T) map[string]ModelDefinition {
	t.Helper()
	data := []byte(`{
  "models": {
    "test-model-big": {
      "name": "Test Model Big",
      "model": {"file": "big.gguf", "url": "https://example.com/big.gguf"},
      "mmproj": {"file": "mmproj.gguf", "url": "https://download.invalid/mmproj.gguf"},
      "reasoning_budget": 4096,
      "profiles": {
        "t1": {"ctx_size": 160000, "requirements": {"vram_min": 32}},
        "t2": {"ctx_size": 60000,  "requirements": {"vram_min": 24}}
      }
    },
    "test-model-small": {
      "name": "Test Model Small",
      "model": {"file": "small.gguf", "url": "https://example.com/small.gguf"},
      "profiles": {
        "t5": {"ctx_size": 40000, "requirements": {"vram_min": 0}}
      }
    },
    "test-model-custom": {
      "name": "Custom Placeholder",
      "model": {"file": "", "url": ""},
      "profiles": {
        "t5": {"ctx_size": 32768, "requirements": {"vram_min": 0}}
      }
    },
    "test-model-extraargs": {
      "name": "ExtraArgs Model",
      "model": {"file": "extra.gguf", "url": "https://example.com/extra.gguf"},
      "profiles": {
        "t1": {"ctx_size": 8000, "extra_args": ["--no-mmap", "--mlock"]}
      }
    },
    "test-model-bad-extraargs": {
      "name": "Bad ExtraArgs",
      "model": {"file": "bad.gguf", "url": "https://example.com/bad.gguf"},
      "profiles": {
        "t1": {"ctx_size": 8000, "extra_args": ["--ctx-size", "9999"]}
      }
    },
    "test-model-spec": {
      "name": "Speculative Model",
      "model": {"file": "spec.gguf", "url": "https://example.com/spec.gguf"},
      "speculative": {
        "artifact": {"file": "draft.gguf", "url": "https://download.invalid/draft.gguf"},
        "strategy": "draft-mtp",
        "n_max": 2,
        "ngl": 999
      },
      "profiles": {
        "t1": {"ctx_size": 8000, "requirements": {"vram_min": 32}},
        "t5": {"ctx_size": 4000, "requirements": {"vram_min": 0}, "speculative_enabled": false}
      }
    }
  }
}`)
	var file ModelsFile
	if err := json.Unmarshal(data, &file); err != nil {
		t.Fatalf("failed to unmarshal test data: %v", err)
	}
	return ValidateAndDerive(file.Models)
}

func TestValidateAndDeriveVision(t *testing.T) {
	defs := testDefs(t)

	if !defs["test-model-big"].Capabilities.Vision {
		t.Error("model with mmproj should have vision=true after derivation")
	}
	if defs["test-model-small"].Capabilities.Vision {
		t.Error("model without mmproj should have vision=false after derivation")
	}
	if defs["test-model-custom"].Capabilities.Vision {
		t.Error("model with empty mmproj should have vision=false after derivation")
	}
}

func TestSelectProfileHighVRAM(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	id, prof := selectProfile(defs["test-model-big"].Profiles, hw)
	if id != "t1" {
		t.Errorf("32GB VRAM should select t1, got %s", id)
	}
	if prof.CtxSize != 160000 {
		t.Errorf("expected ctx 160000, got %d", prof.CtxSize)
	}
}

func TestSelectProfileLowerVRAM(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 24}

	id, prof := selectProfile(defs["test-model-big"].Profiles, hw)
	if id != "t2" {
		t.Errorf("24GB VRAM should select t2, got %s", id)
	}
	if prof.CtxSize != 60000 {
		t.Errorf("expected ctx 60000, got %d", prof.CtxSize)
	}
}

func TestSelectProfileInsufficientVRAM(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 12}

	id, _ := selectProfile(defs["test-model-big"].Profiles, hw)
	if id != "" {
		t.Errorf("12GB VRAM should not match any profile for test-model-big, got %s", id)
	}
}

func TestSelectProfileDeterministic(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 64}

	for i := 0; i < 10; i++ {
		id, _ := selectProfile(defs["test-model-big"].Profiles, hw)
		if id != "t1" {
			t.Errorf("iteration %d: expected t1, got %s", i, id)
		}
	}
}

func TestResolveModelVisionFailsWithoutMMProj(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "big.gguf"), []byte("fake"), 0644)

	_, err := ResolveModel("test-model-big", defs, hw, modelDir, nil)
	if err == nil {
		t.Fatal("expected error when mmproj download fails for vision model")
	}
	if !strings.Contains(err.Error(), "mmproj") {
		t.Errorf("expected mmproj-related error, got: %v", err)
	}
}

func TestResolveModelNoMMProjOK(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "small.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-small", defs, hw, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.MMProjPath != "" {
		t.Errorf("non-vision model should have empty MMProjPath, got %q", resolved.MMProjPath)
	}
}

func TestResolveModelNotFound(t *testing.T) {
	defs := testDefs(t)
	_, err := ResolveModel("nonexistent", defs, Hardware{VRAMGB: 32}, t.TempDir(), nil)
	if err == nil {
		t.Fatal("expected error for unknown model")
	}
}

func TestResolveModelNoProfileMatch(t *testing.T) {
	defs := testDefs(t)
	_, err := ResolveModel("test-model-big", defs, Hardware{VRAMGB: 8}, t.TempDir(), nil)
	if err == nil {
		t.Fatal("expected error when no profile matches hardware")
	}
}

func TestResolveModelEmptyArtifact(t *testing.T) {
	defs := testDefs(t)
	_, err := ResolveModel("test-model-custom", defs, Hardware{VRAMGB: 32}, t.TempDir(), nil)
	if err == nil {
		t.Fatal("expected error for model with empty artifact")
	}
}

func TestResolveModelReasoningBudget(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "small.gguf"), []byte("fake"), 0644)
	os.WriteFile(filepath.Join(modelDir, "big.gguf"), []byte("fake"), 0644)
	os.WriteFile(filepath.Join(modelDir, "mmproj.gguf"), []byte("fake"), 0644)

	resolvedWith, err := ResolveModel("test-model-big", defs, hw, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolvedWith.ReasoningBudget != 4096 {
		t.Errorf("model with reasoning_budget=4096 should propagate, got %d", resolvedWith.ReasoningBudget)
	}

	resolvedWithout, err := ResolveModel("test-model-small", defs, hw, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolvedWithout.ReasoningBudget != 0 {
		t.Errorf("model without reasoning_budget should get 0, got %d", resolvedWithout.ReasoningBudget)
	}
}

func TestValidateExtraArgsOK(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "extra.gguf"), []byte("fake"), 0644)

	_, err := ResolveModel("test-model-extraargs", defs, hw, modelDir, nil)
	if err != nil {
		t.Errorf("valid extra_args should not cause error: %v", err)
	}
}

func TestValidateExtraArgsRejectsReserved(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "bad.gguf"), []byte("fake"), 0644)

	_, err := ResolveModel("test-model-bad-extraargs", defs, hw, modelDir, nil)
	if err == nil {
		t.Fatal("expected error for extra_args containing --ctx-size")
	}
}

func TestValidateExtraArgsRejectsShortForm(t *testing.T) {
	err := validateExtraArgs([]string{"-m", "some-model.gguf"})
	if err == nil {
		t.Error("expected error for -m")
	}
}

func TestValidateExtraArgsRejectsEqualsForm(t *testing.T) {
	err := validateExtraArgs([]string{"--ctx-size=4096"})
	if err == nil {
		t.Error("expected error for --ctx-size=4096")
	}
}

func TestValidateExtraArgsRejectsSpecFlags(t *testing.T) {
	for _, arg := range []string{"--spec-type", "--spec-draft-model", "--spec-draft-n-max", "--spec-draft-ngl"} {
		err := validateExtraArgs([]string{arg, "value"})
		if err == nil {
			t.Errorf("expected error for %s", arg)
		}
	}
}

func TestValidateExtraArgsRejectsAllReserved(t *testing.T) {
	reserved := []string{
		"-m", "--alias", "--ctx-size", "--mmproj",
		"--spec-type", "--spec-draft-model", "--spec-draft-n-max", "--spec-draft-ngl",
		"--cache-type-k", "--cache-type-v", "--flash-attn",
		"--host", "--port", "--jinja", "--reasoning-budget",
		"--ngl", "--parallel",
	}
	for _, arg := range reserved {
		err := validateExtraArgs([]string{arg})
		if err == nil {
			t.Errorf("expected error for reserved arg %q", arg)
		}
		err = validateExtraArgs([]string{arg, "dummy"})
		if err == nil {
			t.Errorf("expected error for reserved arg %q with value", arg)
		}
	}
}

func TestResolveActiveModelIDFromID(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	envPath := filepath.Join(tmpDir, ".env")
	os.WriteFile(envPath, []byte("LLM_MODEL_ID=test-model-big\n"), 0644)

	id := ResolveActiveModelID(envPath, defs)
	if id != "test-model-big" {
		t.Errorf("expected test-model-big, got %q", id)
	}
}

func TestResolveActiveModelIDFallbackName(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	envPath := filepath.Join(tmpDir, ".env")
	os.WriteFile(envPath, []byte("LLM_MODEL_NAME=Test Model Small\n"), 0644)

	id := ResolveActiveModelID(envPath, defs)
	if id != "test-model-small" {
		t.Errorf("expected test-model-small, got %q", id)
	}
}

func TestResolveActiveModelIDMissingReturnsEmpty(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	envPath := filepath.Join(tmpDir, ".env")
	os.WriteFile(envPath, []byte("LLM_MODEL_ID=nonexistent-model\n"), 0644)

	id := ResolveActiveModelID(envPath, defs)
	if id != "" {
		t.Errorf("expected empty string for nonexistent model, got %q", id)
	}
}

func TestAutoSelectModelIDHighestCtx(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 32}

	id := AutoSelectModelID(defs, hw)
	if id != "test-model-big" {
		t.Errorf("32GB VRAM should pick test-model-big (ctx 160000), got %s", id)
	}
}

func TestAutoSelectModelIDLowerVRAM(t *testing.T) {
	defs := testDefs(t)
	hw := Hardware{VRAMGB: 12}

	id := AutoSelectModelID(defs, hw)
	if id != "test-model-small" {
		t.Errorf("12GB VRAM should pick test-model-small, got %s", id)
	}
}

func TestSpeculativeProfileOverride(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "spec.gguf"), []byte("fake"), 0644)
	os.WriteFile(filepath.Join(modelDir, "draft.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-spec", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.Speculative == nil {
		t.Fatal("t1 profile should enable speculative (model-level default)")
	}
	if resolved.Speculative.NMax != 2 {
		t.Errorf("expected n_max=2, got %d", resolved.Speculative.NMax)
	}

	resolvedT5, err := ResolveModel("test-model-spec", defs, Hardware{VRAMGB: 8}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolvedT5.Speculative != nil {
		t.Error("t5 profile with speculative_enabled:false should disable speculative")
	}
}

func TestSpeculativeArtifactMissing(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "spec.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-spec", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.Speculative != nil {
		t.Error("missing draft artifact should disable speculative")
	}
}

func TestKVCacheDefault(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "small.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-small", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.KVCacheType != "q8_0" {
		t.Errorf("expected default KV cache q8_0, got %q", resolved.KVCacheType)
	}
}

func TestFlashAttnDefault(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "small.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-small", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !resolved.FlashAttn {
		t.Error("expected flash_attn=true by default")
	}
}

func TestLoadConfigUserOverride(t *testing.T) {
	embeddedData := []byte(`{"models":{"embedded-only":{"name":"Embedded","model":{"file":"e.gguf","url":"http://e"},"profiles":{"t1":{"ctx_size":1000}}}}}`)

	userData := []byte(`{"models":{"user-model":{"name":"User","model":{"file":"u.gguf","url":"http://u"},"profiles":{"t1":{"ctx_size":2000}}}}}`)

	tmpDir := t.TempDir()
	os.WriteFile(filepath.Join(tmpDir, "models.json"), userData, 0644)

	defs := LoadConfig(embeddedData, tmpDir)
	if defs == nil {
		t.Fatal("LoadConfig returned nil")
	}
	if _, ok := defs["user-model"]; !ok {
		t.Error("user override should take precedence over embedded")
	}
	if _, ok := defs["embedded-only"]; ok {
		t.Error("embedded model should not appear when user override exists")
	}
}
