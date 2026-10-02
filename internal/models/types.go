package models

import "fmt"

type Artifact struct {
	File string `json:"file"`
	URL  string `json:"url"`
}

type SpeculativeConfig struct {
	Artifact Artifact `json:"artifact"`
	Strategy string   `json:"strategy"`
	NMax     int      `json:"n_max,omitempty"`
	NGL      int      `json:"ngl,omitempty"`
}

type Capabilities struct {
	Vision bool `json:"vision,omitempty"`
}

type Requirements struct {
	VRAMMin float64 `json:"vram_min,omitempty"`
}

type DeploymentProfile struct {
	Requirements       Requirements       `json:"requirements,omitempty"`
	CtxSize            int                `json:"ctx_size"`
	KVCacheType        string             `json:"kv_cache_type,omitempty"`
	FlashAttn          *bool              `json:"flash_attn,omitempty"`
	SpeculativeEnabled *bool              `json:"speculative_enabled,omitempty"`
	Speculative        *SpeculativeConfig `json:"speculative,omitempty"`
	ExtraArgs          []string           `json:"extra_args,omitempty"`
}

// SamplingParams holds author-recommended per-mode sampling values (temperature,
// top_p, top_k, min_p, presence_penalty, ...). Declarative data sourced from
// models.json — Go forwards it verbatim; it does not interpret individual keys.
type SamplingParams map[string]any

// Sampling is the per-mode sampling baseline: "thinking" (chat) and "instruct"
// (mechanical) modes. The real per-model differentiator is mostly top_k (e.g.
// 64 for Gemma vs 20 for Qwen); temperature varies by mode, not model.
type Sampling struct {
	Thinking SamplingParams `json:"thinking,omitempty"`
	Instruct SamplingParams `json:"instruct,omitempty"`
}

// ReasoningPolicy describes how a runtime turns reasoning on/off from the
// request side. Field names the request field/path PHP must write (e.g.
// "reasoning_effort" or "chat_template_kwargs.enable_thinking"); OffValue is the
// value for instruct (thinking hard off). DefaultEffort/EffortMap wire graduated
// effort levels ONLY when a runtime's template reliably supports them.
type ReasoningPolicy struct {
	Field         string         `json:"field"`
	OffValue      any            `json:"off_value"`
	DefaultEffort string         `json:"default_effort,omitempty"`
	EffortMap     map[string]any `json:"effort_map,omitempty"`
}

// RuntimeSpec is the typed "how" for a tested model+template integration — the
// coupled llama.cpp/template behavior that models.json must not hardcode.
// TemplateFile/ReasoningFmt/PreserveReasoning are consumed by Go at server start
// (json:"-"); Reasoning is exported to PHP for request-side control.
type RuntimeSpec struct {
	TemplateFile      string          `json:"-"`
	ReasoningFmt      string          `json:"-"`
	PreserveReasoning bool            `json:"-"`
	Reasoning         ReasoningPolicy `json:"reasoning"`
}

// EngineSpec names an external inference engine that serves a model out of
// process: the launcher starts that engine instead of llama-server and points
// the web layer at its OpenAI-compatible endpoint. Distinct from ModelDefinition
// .Runtime, which describes the template/reasoning integration, not the process.
// Plain paths from the catalog (single-user, sandboxed host).
type EngineSpec struct {
	Type   string `json:"type"`   // engine ID, passed to the wrapper as --engine
	Python string `json:"python"` // interpreter that runs the wrapper
	Script string `json:"script"` // wrapper entry point (e.g. serve/server.py)
	Config string `json:"config"` // per-model engine config JSON
	Port   int    `json:"port"`   // port its OpenAI API listens on
}

func (e *EngineSpec) Validate() error {
	switch {
	case e.Type == "":
		return fmt.Errorf("engine.type is empty")
	case e.Python == "":
		return fmt.Errorf("engine.python is empty")
	case e.Script == "":
		return fmt.Errorf("engine.script is empty")
	case e.Config == "":
		return fmt.Errorf("engine.config is empty")
	case e.Port <= 0 || e.Port > 65535:
		return fmt.Errorf("engine.port %d is not a valid port", e.Port)
	}
	return nil
}

// APIURL is the base URL the PHP layer (inside Docker) must use to reach this
// engine: the Windows host as the containers see it, plus the engine's port.
// The llama-server equivalent is always http://<host>:1234/v1.
func (e *EngineSpec) APIURL(host string) string {
	return fmt.Sprintf("http://%s:%d/v1", host, e.Port)
}

type ModelDefinition struct {
	Name            string                       `json:"name"`
	Engine          *EngineSpec                  `json:"engine,omitempty"`
	Model           Artifact                     `json:"model"`
	MMProj          *Artifact                    `json:"mmproj,omitempty"`
	Speculative     *SpeculativeConfig           `json:"speculative,omitempty"`
	Capabilities    Capabilities                 `json:"capabilities,omitempty"`
	Runtime         string                       `json:"runtime,omitempty"`
	Sampling        Sampling                     `json:"sampling,omitempty"`
	Profiles        map[string]DeploymentProfile `json:"profiles"`
}

type Hardware struct {
	VRAMGB float64
	RAMGB  float64
}

type ResolvedModel struct {
	Name            string
	ModelPath       string
	MMProjPath      string
	CtxSize         int
	KVCacheType     string
	FlashAttn       bool
	Speculative     *ResolvedSpeculative
	ExtraArgs       []string
	Runtime         RuntimeSpec
	Sampling        Sampling
}

type ResolvedSpeculative struct {
	Path     string
	Strategy string
	NMax     int
	NGL      int
}

type GHRelease struct {
	TagName string `json:"tag_name"`
	Assets  []struct {
		Name        string `json:"name"`
		DownloadURL string `json:"browser_download_url"`
	} `json:"assets"`
}
