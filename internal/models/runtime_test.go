package models

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveRuntimeKnown(t *testing.T) {
	// qwen38: fixed template + deepseek format + preserve + graduated effort.
	spec, err := ResolveRuntime("qwen38")
	if err != nil {
		t.Fatalf("expected qwen38 to resolve, got error: %v", err)
	}
	if spec.TemplateFile == "" || spec.ReasoningFmt != "deepseek" || !spec.PreserveReasoning {
		t.Errorf("qwen38 should use fixed template + deepseek + preserve, got %+v", spec)
	}
	if spec.Reasoning.Field != "chat_template_kwargs.reasoning_effort" || spec.Reasoning.OffValue != "none" {
		t.Errorf("qwen38 reasoning policy: got %+v", spec.Reasoning)
	}
	if spec.Reasoning.DefaultEffort != "medium" || spec.Reasoning.EffortMap["high"] != "xhigh" || spec.Reasoning.EffortMap["low"] != "low" {
		t.Errorf("qwen38 effort mapping: got %+v", spec.Reasoning)
	}

	// qwen35/gemma4/bonsai: embedded templates, off via reasoning_effort=none,
	// no server-side overrides.
	for _, id := range []string{"qwen35", "gemma4", "bonsai"} {
		spec, err := ResolveRuntime(id)
		if err != nil {
			t.Fatalf("expected runtime %q to resolve, got error: %v", id, err)
		}
		if spec.Reasoning.Field != "reasoning_effort" || spec.Reasoning.OffValue != "none" {
			t.Errorf("runtime %q: got %+v", id, spec.Reasoning)
		}
		if spec.TemplateFile != "" || spec.ReasoningFmt != "" || spec.PreserveReasoning {
			t.Errorf("runtime %q: server-side overrides must be empty, got %+v", id, spec)
		}
	}
}

func TestResolveRuntimeUnknown(t *testing.T) {
	if _, err := ResolveRuntime("does-not-exist"); err == nil {
		t.Fatal("expected error for unknown runtime ID")
	}
}

func TestResolveModelRuntimePropagates(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "runtime.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-runtime", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.Runtime.Reasoning.Field != "chat_template_kwargs.reasoning_effort" {
		t.Errorf("expected chat_template_kwargs.reasoning_effort field, got %q", resolved.Runtime.Reasoning.Field)
	}
	if resolved.Runtime.Reasoning.OffValue != "none" {
		t.Errorf("expected off_value none, got %v", resolved.Runtime.Reasoning.OffValue)
	}
	if resolved.Runtime.Reasoning.DefaultEffort != "medium" {
		t.Errorf("expected default_effort medium, got %q", resolved.Runtime.Reasoning.DefaultEffort)
	}
	if resolved.Runtime.Reasoning.EffortMap["high"] != "xhigh" {
		t.Errorf("expected high -> xhigh, got %v", resolved.Runtime.Reasoning.EffortMap["high"])
	}
	if resolved.Runtime.TemplateFile != "chat_template.jinja" {
		t.Errorf("expected template file chat_template.jinja, got %q", resolved.Runtime.TemplateFile)
	}
	if got := resolved.Sampling.Thinking["temperature"]; got != 1.0 {
		t.Errorf("expected thinking temperature 1.0, got %v", got)
	}
	if got := resolved.Sampling.Instruct["temperature"]; got != 0.7 {
		t.Errorf("expected instruct temperature 0.7, got %v", got)
	}
}

func TestResolveModelUnknownRuntimeFails(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "badruntime.gguf"), []byte("fake"), 0644)

	_, err := ResolveModel("test-model-bad-runtime", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err == nil {
		t.Fatal("expected error for model with unknown runtime")
	}
	if !strings.Contains(err.Error(), "unknown runtime") {
		t.Errorf("expected 'unknown runtime' in error, got: %v", err)
	}
}

func TestResolveModelNoRuntimeOK(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "small.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-small", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resolved.Runtime.Reasoning.Field != "" {
		t.Errorf("model without runtime should have empty reasoning field, got %q", resolved.Runtime.Reasoning.Field)
	}
	if resolved.SamplingJSON() != "" {
		t.Errorf("model without sampling should produce empty SamplingJSON, got %q", resolved.SamplingJSON())
	}
}

func TestRuntimePolicyJSONShape(t *testing.T) {
	spec, err := ResolveRuntime("bonsai")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	got := spec.RuntimePolicyJSON()
	for _, want := range []string{`"reasoning"`, `"field"`, `"reasoning_effort"`, `"off_value"`, `"none"`} {
		if !strings.Contains(got, want) {
			t.Errorf("RuntimePolicyJSON %q missing %q", got, want)
		}
	}
	if !strings.HasPrefix(got, "{") || !strings.HasSuffix(got, "}") {
		t.Errorf("RuntimePolicyJSON should be a JSON object, got %q", got)
	}

	// qwen38 policy carries the effort steering fields.
	spec38, err := ResolveRuntime("qwen38")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	got38 := spec38.RuntimePolicyJSON()
	for _, want := range []string{`"chat_template_kwargs.reasoning_effort"`, `"default_effort"`, `"medium"`, `"effort_map"`, `"xhigh"`} {
		if !strings.Contains(got38, want) {
			t.Errorf("qwen38 RuntimePolicyJSON %q missing %q", got38, want)
		}
	}
}

func TestSamplingJSONShape(t *testing.T) {
	defs := testDefs(t)

	tmpDir := t.TempDir()
	modelDir := filepath.Join(tmpDir, "models")
	os.MkdirAll(modelDir, 0755)
	os.WriteFile(filepath.Join(modelDir, "runtime.gguf"), []byte("fake"), 0644)

	resolved, err := ResolveModel("test-model-runtime", defs, Hardware{VRAMGB: 32}, modelDir, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	got := resolved.SamplingJSON()
	for _, want := range []string{`"thinking"`, `"instruct"`, `"temperature"`} {
		if !strings.Contains(got, want) {
			t.Errorf("SamplingJSON %q missing %q", got, want)
		}
	}
}

func TestValidateExtraArgsRejectsRuntimeFlags(t *testing.T) {
	for _, arg := range []string{"--chat-template-file", "--reasoning-format", "--reasoning-preserve"} {
		err := validateExtraArgs([]string{arg})
		if err == nil {
			t.Errorf("expected error for reserved arg %q", arg)
		}
		err = validateExtraArgs([]string{arg, "value"})
		if err == nil {
			t.Errorf("expected error for reserved arg %q with value", arg)
		}
	}
}
