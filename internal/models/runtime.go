package models

import (
	"encoding/json"
	"fmt"
)

// runtimes maps a models.json "runtime" ID to a RuntimeSpec — the typed "how"
// for a tested model+template integration. An ID is added only after its
// template + tool-call wire format are audited (the gate); unknown IDs fail
// resolve rather than silently falling back.
//
// Reasoning off-switch (reasoning_effort="none") is verified live on Bonsai
// 27B (2026-08-29): llama.cpp translates the OpenAI-compat field to
// enable_thinking=false, yielding 0 reasoning chars + direct content. Omitting
// the field reproduces the empty-answer bug under a tight max_tokens.
// chat_template_kwargs.enable_thinking=false is an equivalent, more direct
// path (also verified) — llama.cpp forwards request chat_template_kwargs as
// template variables.
//
// qwen38 is the only runtime that overrides the template today: the official
// uploader-embedded template is broken (xhigh default burns the token budget
// to empty content; enable_thinking=false hard-crashes; JSON-string tool
// arguments crash). It uses the fixed community template (froggeric v22.x) +
// deepseek reasoning extraction + preserve-reasoning, and its graduated
// effort (low/medium/xhigh via chat_template_kwargs.reasoning_effort) is
// reliable there.
var runtimes = map[string]RuntimeSpec{
	"qwen38": {
		TemplateFile:      "chat_template.jinja",
		ReasoningFmt:      "deepseek",
		PreserveReasoning: true,
		Reasoning: ReasoningPolicy{
			Field:         "chat_template_kwargs.reasoning_effort",
			OffValue:      "none",
			DefaultEffort:  "medium",
			EffortMap:      map[string]any{"low": "low", "medium": "medium", "high": "xhigh"},
		},
	},
	// Embedded Qwen3-style template reads enable_thinking (no graduated
	// effort); off via the OpenAI-compat reasoning_effort field.
	"qwen35": {Reasoning: ReasoningPolicy{Field: "reasoning_effort", OffValue: "none"}},
	// Gemma 4: embedded template reads enable_thinking (thinking via
	// <|channel>thought markers). Off-mechanism confirmed live (2026-09-13)
	// on Gemma 4 IQ4_NL + E4B Q8: reasoning_effort=none -> 0 reasoning.
	"gemma4": {Reasoning: ReasoningPolicy{Field: "reasoning_effort", OffValue: "none"}},
	// Bonsai 27B: Qwen3-style template, off-switch verified live.
	"bonsai": {Reasoning: ReasoningPolicy{Field: "reasoning_effort", OffValue: "none"}},
}

// ResolveRuntime returns the spec for a runtime ID, rejecting unknown IDs.
func ResolveRuntime(id string) (RuntimeSpec, error) {
	spec, ok := runtimes[id]
	if !ok {
		return RuntimeSpec{}, fmt.Errorf("unknown runtime %q", id)
	}
	return spec, nil
}

// RuntimePolicyJSON serializes the request-side reasoning policy as
// {"reasoning": {...}} for the PHP layer. Always returns a valid JSON object
// (at minimum "{}") so the .env export round-trips cleanly.
func (s RuntimeSpec) RuntimePolicyJSON() string {
	b, err := json.Marshal(struct {
		Reasoning ReasoningPolicy `json:"reasoning"`
	}{Reasoning: s.Reasoning})
	if err != nil {
		return "{}"
	}
	return string(b)
}

// SamplingJSON serializes the resolved per-mode sampling map for the PHP layer.
// Returns "" when the model declares no sampling overrides.
func (m *ResolvedModel) SamplingJSON() string {
	if len(m.Sampling.Thinking) == 0 && len(m.Sampling.Instruct) == 0 {
		return ""
	}
	b, err := json.Marshal(m.Sampling)
	if err != nil {
		return ""
	}
	return string(b)
}
