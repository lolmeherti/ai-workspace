// Package memcalc computes the hard VRAM ceiling a model needs at a given
// context/KV/parallel setting, from a parsed GGUF header. Weights, KV cache and
// recurrent state are exact (from the GGUF hyperparameters + llama.cpp's
// allocation formulas); the one term with no closed form (compute buffer +
// CUDA context + cuda graphs) is the padded constant OverheadMax.
package memcalc

import "localsy/internal/gguf"

// MemOpts is the runtime configuration that affects VRAM.
type MemOpts struct {
	Ctx      int    // context length (tokens)
	KVType   string // "f16", "q8_0", "q8_1", "f32"
	Parallel int    // --parallel (n_seq_max)
	Unified  bool   // --kv-unified (single KV stream)
}

// KVTypeSize returns bytes-per-element for a KV cache type.
func KVTypeSize(kvType string) float64 {
	switch kvType {
	case "f16", "f32_f16", "":
		return 2.0
	case "q8_0":
		return 34.0 / 32.0 // 1.0625
	case "q8_1":
		return 36.0 / 32.0
	case "f32", "f32_f32":
		return 4.0
	default:
		return 2.0
	}
}

// kvStreams is the n_stream factor: 1 under --kv-unified, else n_parallel
// (llama-kv-cache.cpp: n_stream = unified ? 1 : n_seq_max).
func kvStreams(opts MemOpts) int {
	if opts.Unified {
		return 1
	}
	if opts.Parallel <= 0 {
		return 1
	}
	return opts.Parallel
}

// kvBytesPerToken returns KV bytes per token summed over all KV layers.
func kvBytesPerToken(h *gguf.Header, opts MemOpts) float64 {
	elems := float64(h.HeadCountKV) * float64(h.KeyLength+h.ValueLength)
	return elems * KVTypeSize(opts.KVType) * float64(kvStreams(opts)) * float64(h.KVLayerCount())
}

// KVCacheBytes returns the KV cache footprint in bytes for the given context.
func KVCacheBytes(h *gguf.Header, opts MemOpts) uint64 {
	return uint64(kvBytesPerToken(h, opts) * float64(opts.Ctx))
}

// RecurrentBytes returns the recurrent (SSM) state footprint, exact for
// qwen35-style hybrids where the ssm.* keys are present. Per recurrent layer:
//   conv state = d_conv * (d_inner + 2 * n_group * d_state)
//   ssm  state = d_inner^2 / dt_rank   (head_v_dim x head_v_dim x num_v_heads)
// both f32, scaled by --parallel. Returns 0 for dense models (no ssm keys).
func RecurrentBytes(h *gguf.Header, opts MemOpts) uint64 {
	if h.SsmStateSize == 0 || h.SsmInnerSize == 0 {
		return 0
	}
	streams := opts.Parallel
	if streams <= 0 {
		streams = 1
	}
	convChannels := uint64(h.SsmInnerSize) + 2*uint64(h.SsmGroupCount)*uint64(h.SsmStateSize)
	convElems := uint64(h.SsmConvKernel) * convChannels
	ssmElems := uint64(0)
	if h.SsmDtRank > 0 {
		ssmElems = uint64(h.SsmInnerSize) * uint64(h.SsmInnerSize) / uint64(h.SsmDtRank)
	}
	perLayer := (convElems + ssmElems) * 4 // f32
	return perLayer * uint64(h.RecurrentLayerCount()) * uint64(streams)
}

// Required returns the hard VRAM ceiling in bytes for loading the model at the
// given settings. It is deliberately an upper bound: every term is exact except
// OverheadMax, which is padded above the measured value, so a "fits" verdict can
// never under-count.
func Required(h *gguf.Header, mmprojBytes uint64, opts MemOpts) uint64 {
	return h.WeightBytes + mmprojBytes + KVCacheBytes(h, opts) + RecurrentBytes(h, opts) + OverheadMax
}

// MaxContext returns the largest context length (tokens) that fits in freeBytes,
// or 0 if even the fixed terms (weights + mmproj + recurrent + overhead) don't
// fit. This is the hard ceiling surfaced to the settings dropdown.
func MaxContext(h *gguf.Header, mmprojBytes uint64, freeBytes uint64, opts MemOpts) int {
	fixed := h.WeightBytes + mmprojBytes + RecurrentBytes(h, opts) + OverheadMax
	if freeBytes <= fixed {
		return 0
	}
	perToken := kvBytesPerToken(h, opts)
	if perToken <= 0 {
		return 0
	}
	return int(float64(freeBytes-fixed) / perToken)
}
