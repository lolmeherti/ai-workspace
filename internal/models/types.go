package models

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

type ModelDefinition struct {
	Name            string                       `json:"name"`
	Model           Artifact                     `json:"model"`
	MMProj          *Artifact                    `json:"mmproj,omitempty"`
	Speculative     *SpeculativeConfig           `json:"speculative,omitempty"`
	ReasoningBudget int                          `json:"reasoning_budget,omitempty"`
	Capabilities    Capabilities                 `json:"capabilities,omitempty"`
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
	ReasoningBudget int
	Speculative     *ResolvedSpeculative
	ExtraArgs       []string
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
