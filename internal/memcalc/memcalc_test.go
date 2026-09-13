package memcalc

import (
	"testing"

	"localsy/internal/gguf"
)

// qwen38 returns a header matching the real Qwen3.8-27B-UD-Q5_K_XL GGUF
// (values read from the file's metadata).
func qwen38() *gguf.Header {
	return &gguf.Header{
		Architecture:     "qwen35",
		BlockCount:       65,
		NextnPredict:     1,
		HeadCount:        24,
		HeadCountKV:      4,
		KeyLength:        256,
		ValueLength:      256,
		FullAttnInterval: 4,
		SsmConvKernel:    4,
		SsmStateSize:     128,
		SsmInnerSize:     6144,
		SsmGroupCount:    16,
		SsmDtRank:        48,
		WeightBytes:      20876938144, // 19.44 GiB (file size)
	}
}

func TestLayerCounts(t *testing.T) {
	h := qwen38()
	if got := h.KVLayerCount(); got != 16 {
		t.Fatalf("KVLayerCount = %d, want 16", got)
	}
	if got := h.RecurrentLayerCount(); got != 48 {
		t.Fatalf("RecurrentLayerCount = %d, want 48", got)
	}
}

func TestLayerCountsExplicitMask(t *testing.T) {
	// 4 layers, recurrent mask marks layers 0 and 2 recurrent -> 2 KV layers.
	h := &gguf.Header{
		BlockCount:      4,
		HeadCount:       4,
		HeadCountKV:     1,
		KeyLength:       64,
		ValueLength:     64,
		RecurrentLayers: []bool{true, false, true, false},
	}
	if got := h.KVLayerCount(); got != 2 {
		t.Fatalf("KVLayerCount (mask) = %d, want 2", got)
	}
}

func TestKVCacheBytes(t *testing.T) {
	h := qwen38()

	f16Unified := MemOpts{Ctx: 128000, KVType: "f16", Parallel: 4, Unified: true}
	if got := KVCacheBytes(h, f16Unified); got != 8388608000 {
		t.Fatalf("KV f16 unified = %d, want 8388608000", got)
	}

	f16Split := MemOpts{Ctx: 128000, KVType: "f16", Parallel: 4, Unified: false}
	if got := KVCacheBytes(h, f16Split); got != 33554432000 {
		t.Fatalf("KV f16 split(4) = %d, want 33554432000", got)
	}

	q8 := MemOpts{Ctx: 128000, KVType: "q8_0", Parallel: 4, Unified: true}
	if got := KVCacheBytes(h, q8); got != 4456448000 {
		t.Fatalf("KV q8_0 unified = %d, want 4456448000", got)
	}
}

func TestRecurrentBytes(t *testing.T) {
	h := qwen38()
	// conv = 4*(6144 + 2*16*128) = 40960 elems; ssm = 6144*6144/48 = 786432 elems.
	// per layer f32 = (40960+786432)*4 = 3309568; * 48 layers * 4 parallel.
	want := uint64(3309568) * 48 * 4
	if got := RecurrentBytes(h, MemOpts{Parallel: 4}); got != want {
		t.Fatalf("RecurrentBytes = %d, want %d", got, want)
	}
}

func TestMaxContextRoundTrip(t *testing.T) {
	h := qwen38()
	opts := MemOpts{KVType: "f16", Parallel: 4, Unified: true}
	fixed := h.WeightBytes + RecurrentBytes(h, opts) + OverheadMax
	perToken := uint64(4 * 512 * 2 * 16) // n_head_kv * (k+v) * f16 * 16 layers = 65536
	for ctx := 0; ctx <= 200000; ctx += 10000 {
		free := fixed + uint64(ctx)*perToken
		if got := MaxContext(h, 0, free, opts); got != ctx {
			t.Fatalf("MaxContext(free=%d) = %d, want %d", free, got, ctx)
		}
	}
}

func TestRequiredExceedsWeights(t *testing.T) {
	h := qwen38()
	opts := MemOpts{Ctx: 128000, KVType: "f16", Parallel: 4, Unified: true}
	required := Required(h, 0, opts)
	if required <= h.WeightBytes {
		t.Fatalf("Required(%d) = %d, must exceed weights %d", opts.Ctx, required, h.WeightBytes)
	}
	// With the real mmproj, 128K f16 must exceed the RTX 5090's 32607 MiB total —
	// this is the exact overflow that shipped (the gate must refuse it).
	required = Required(h, 931146432, opts) // 0.87 GiB BF16 mmproj
	total5090 := uint64(32607) * 1024 * 1024
	if required <= total5090 {
		t.Fatalf("Required(128K)+mmproj = %d, must exceed 5090 total %d", required, total5090)
	}
	// And a lower ctx (65536) must fit comfortably.
	opts.Ctx = 65536
	if required := Required(h, 931146432, opts); required > total5090 {
		t.Fatalf("Required(64K)+mmproj = %d, must fit in %d", required, total5090)
	}
}
