package gguf

import (
	"bytes"
	"encoding/binary"
	"os"
	"testing"
)

func le32(v uint32) []byte { b := make([]byte, 4); binary.LittleEndian.PutUint32(b, v); return b }
func le64(v uint64) []byte { b := make([]byte, 8); binary.LittleEndian.PutUint64(b, v); return b }
func ggufStr(s string) []byte {
	return append(le64(uint64(len(s))), []byte(s)...)
}

type testKV struct {
	key string
	vt  uint32
	val []byte
}

type testTensor struct {
	name string
	dims []uint64
	typ  uint32
}

// buildTestGGUF constructs a minimal valid GGUF v3 header (no tensor data).
func buildTestGGUF() []byte {
	kvs := []testKV{
		{"general.architecture", 8, ggufStr("qwen35")},
		{"qwen35.block_count", 4, le32(65)},
		{"qwen35.nextn_predict_layers", 4, le32(1)},
		{"qwen35.attention.head_count", 4, le32(24)},
		{"qwen35.attention.head_count_kv", 4, le32(4)},
		{"qwen35.attention.key_length", 4, le32(256)},
		{"qwen35.attention.value_length", 4, le32(256)},
		{"qwen35.full_attention_interval", 4, le32(4)},
	}
	tensors := []testTensor{
		{"blk.0.attn_norm.weight", []uint64{5120}, 0},   // F32: 5120*4 = 20480
		{"tok_embd.weight", []uint64{5120, 1000}, 14},     // Q6_K: rowSize 4200 * 1000
	}

	var b bytes.Buffer
	b.WriteString("GGUF")
	b.Write(le32(3))                       // version
	b.Write(le64(uint64(len(tensors))))    // n_tensors
	b.Write(le64(uint64(len(kvs))))        // n_kv
	for _, kv := range kvs {
		b.Write(ggufStr(kv.key))
		b.Write(le32(kv.vt))
		b.Write(kv.val)
	}
	var off uint64
	for _, tn := range tensors {
		b.Write(ggufStr(tn.name))
		b.Write(le32(uint32(len(tn.dims))))
		for _, d := range tn.dims {
			b.Write(le64(d))
		}
		b.Write(le32(tn.typ))
		b.Write(le64(off))
		size, _ := rowSize(tn.typ, tn.dims[0])
		for _, d := range tn.dims[1:] {
			size *= d
		}
		off += size
	}
	return b.Bytes()
}

func TestParseSynthetic(t *testing.T) {
	h, err := Parse(buildTestGGUF())
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if h.Architecture != "qwen35" {
		t.Fatalf("Architecture = %q", h.Architecture)
	}
	if h.BlockCount != 65 || h.NextnPredict != 1 {
		t.Fatalf("BlockCount/Nextn = %d/%d", h.BlockCount, h.NextnPredict)
	}
	if h.HeadCount != 24 || h.HeadCountKV != 4 {
		t.Fatalf("Heads = %d/%d", h.HeadCount, h.HeadCountKV)
	}
	if h.KeyLength != 256 || h.ValueLength != 256 {
		t.Fatalf("Key/Value len = %d/%d", h.KeyLength, h.ValueLength)
	}
	if h.FullAttnInterval != 4 {
		t.Fatalf("FullAttnInterval = %d", h.FullAttnInterval)
	}
	if h.KVLayerCount() != 16 {
		t.Fatalf("KVLayerCount = %d, want 16", h.KVLayerCount())
	}
	// 20480 (F32 norm) + 4200*1000 (Q6_K embd) = 4,220,480
	if h.WeightBytes != 4220480 {
		t.Fatalf("WeightBytes = %d, want 4220480", h.WeightBytes)
	}
	if len(h.Tensors) != 2 {
		t.Fatalf("Tensors = %d, want 2", len(h.Tensors))
	}
}

func TestParseBadMagic(t *testing.T) {
	if _, err := Parse([]byte("XXXX-not-gguf")); err != ErrNotGGUF {
		t.Fatalf("err = %v, want ErrNotGGUF", err)
	}
}

func TestParseTruncated(t *testing.T) {
	full := buildTestGGUF()
	if _, err := Parse(full[:20]); err != ErrTruncated {
		t.Fatalf("err = %v, want ErrTruncated", err)
	}
}

// TestParseRealQwen38 is the golden hardware check. Set GGUF_TEST_PATH to the
// local Qwen3.8-27B-UD-Q5_K_XL.gguf to run it (skipped otherwise — the file is
// ~20 GiB and not committed).
func TestParseRealQwen38(t *testing.T) {
	path := os.Getenv("GGUF_TEST_PATH")
	if path == "" {
		t.Skip("GGUF_TEST_PATH not set")
	}
	h, err := ParseFile(path)
	if err != nil {
		t.Fatalf("ParseFile: %v", err)
	}
	want := uint64(20876938144) // 19.44 GiB
	if h.WeightBytes < want*99/100 || h.WeightBytes > want*101/100 {
		t.Fatalf("WeightBytes = %d, want ~%d (±1%%)", h.WeightBytes, want)
	}
	if h.Architecture != "qwen35" {
		t.Fatalf("Architecture = %q, want qwen35", h.Architecture)
	}
	if h.KVLayerCount() != 16 {
		t.Fatalf("KVLayerCount = %d, want 16", h.KVLayerCount())
	}
}
