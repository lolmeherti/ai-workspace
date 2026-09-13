// Package gguf parses GGUF file headers — the metadata + tensor list that sit
// at the front of every GGUF file, ahead of the (multi-GiB) tensor data.
// The header is self-contained: it carries every hyperparameter the VRAM
// calculator needs plus the exact shape/quant of every tensor, so required VRAM
// can be computed from a few MiB of header bytes without the weights present.
package gguf

import (
	"encoding/binary"
	"errors"
	"fmt"
	"math"
	"strings"
)

// ErrTruncated means the byte slice ended before the header did. Callers that
// fetch a bounded prefix (e.g. HTTP Range) should fetch a larger window and
// retry.
var ErrTruncated = errors.New("gguf: truncated header")

// ErrNotGGUF means the data does not begin with the GGUF magic.
var ErrNotGGUF = errors.New("gguf: bad magic")

// Tensor is one entry in the GGUF tensor list (shape + quant type only; the
// tensor data itself is not read).
type Tensor struct {
	Name   string
	TypeID uint32
	Dims   []uint64
}

// Header is the parsed GGUF header: the hyperparameters the calculator needs
// plus the summed in-memory weight size.
type Header struct {
	Architecture string
	BlockCount   uint32
	NextnPredict uint32
	HeadCount    uint32
	HeadCountKV  uint32
	KeyLength    uint32
	ValueLength  uint32
	// FullAttnInterval > 0 marks a hybrid: only every Nth layer has KV
	// (qwen35 sets 4). Zero means a dense model (all layers have KV).
	FullAttnInterval uint32
	// Recurrent (SSM) hyperparameters; zero values mean "not a recurrent model".
	SsmConvKernel uint32
	SsmStateSize  uint32
	SsmInnerSize  uint32
	SsmGroupCount uint32
	SsmDtRank     uint32
	// RecurrentLayers, when the GGUF carries an explicit per-layer mask
	// (attention.recurrent_layers), is the author's mask; otherwise nil and the
	// FullAttnInterval rule applies.
	RecurrentLayers []bool

	WeightBytes uint64
	Tensors     []Tensor
}

type reader struct {
	b   []byte
	off int
}

func (r *reader) need(n int) error {
	if r.off+n > len(r.b) {
		return ErrTruncated
	}
	return nil
}

func (r *reader) byte() (byte, error) {
	if err := r.need(1); err != nil {
		return 0, err
	}
	v := r.b[r.off]
	r.off++
	return v, nil
}

func (r *reader) skip(n int) (int, error) {
	if err := r.need(n); err != nil {
		return 0, err
	}
	r.off += n
	return n, nil
}

func (r *reader) u16() (uint16, error) {
	if err := r.need(2); err != nil {
		return 0, err
	}
	v := binary.LittleEndian.Uint16(r.b[r.off:])
	r.off += 2
	return v, nil
}

func (r *reader) u32() (uint32, error) {
	if err := r.need(4); err != nil {
		return 0, err
	}
	v := binary.LittleEndian.Uint32(r.b[r.off:])
	r.off += 4
	return v, nil
}

func (r *reader) u64() (uint64, error) {
	if err := r.need(8); err != nil {
		return 0, err
	}
	v := binary.LittleEndian.Uint64(r.b[r.off:])
	r.off += 8
	return v, nil
}

func (r *reader) str() (string, error) {
	n, err := r.u64()
	if err != nil {
		return "", err
	}
	if n > uint64(len(r.b)) { // sanity: a string can't exceed the buffer
		return "", ErrTruncated
	}
	if err := r.need(int(n)); err != nil {
		return "", err
	}
	s := string(r.b[r.off : r.off+int(n)])
	r.off += int(n)
	return s, nil
}

type arrayMarker struct{}

func (r *reader) readArray() (any, error) {
	elemType, err := r.u32()
	if err != nil {
		return nil, err
	}
	count, err := r.u64()
	if err != nil {
		return nil, err
	}
	// Keep bool arrays (the per-layer recurrent mask); skip everything else.
	if elemType == 7 && count <= 4096 {
		arr := make([]bool, count)
		for i := range arr {
			b, err := r.byte()
			if err != nil {
				return nil, err
			}
			arr[i] = b != 0
		}
		return arr, nil
	}
	if elemType == 8 { // string array (tokenizer) — skip each element
		for i := uint64(0); i < count; i++ {
			if _, err := r.str(); err != nil {
				return nil, err
			}
		}
		return arrayMarker{}, nil
	}
	sizes := map[uint32]int{0: 1, 1: 1, 2: 2, 3: 2, 4: 4, 5: 4, 6: 4, 7: 1, 10: 8, 11: 8, 12: 8}
	sz, ok := sizes[elemType]
	if !ok {
		return nil, fmt.Errorf("gguf: unknown array element type %d", elemType)
	}
	if _, err := r.skip(int(count * uint64(sz))); err != nil {
		return nil, err
	}
	return arrayMarker{}, nil
}

func (r *reader) readValue(vt uint32) (any, error) {
	switch vt {
	case 0:
		return r.byte()
	case 1:
		b, err := r.byte()
		return int8(b), err
	case 2:
		return r.u16()
	case 3:
		v, err := r.u16()
		return int16(v), err
	case 4:
		return r.u32()
	case 5:
		v, err := r.u32()
		return int32(v), err
	case 6:
		v, err := r.u32()
		return math.Float32frombits(v), err
	case 7:
		b, err := r.byte()
		return b != 0, err
	case 8:
		return r.str()
	case 9:
		return r.readArray()
	case 10:
		return r.u64()
	case 11:
		v, err := r.u64()
		return int64(v), err
	case 12:
		v, err := r.u64()
		return math.Float64frombits(v), err
	default:
		return nil, fmt.Errorf("gguf: unknown metadata value type %d", vt)
	}
}

// Parse reads a GGUF header from a byte slice (the whole header must be
// present; a truncated slice returns ErrTruncated).
func Parse(data []byte) (*Header, error) {
	r := &reader{b: data}
	if err := r.need(4); err != nil {
		return nil, err
	}
	if string(r.b[:4]) != "GGUF" {
		return nil, ErrNotGGUF
	}
	r.off = 4

	if _, err := r.u32(); err != nil { // version
		return nil, err
	}
	nTensors, err := r.u64()
	if err != nil {
		return nil, err
	}
	nKV, err := r.u64()
	if err != nil {
		return nil, err
	}

	meta := make(map[string]any, nKV)
	for i := uint64(0); i < nKV; i++ {
		key, err := r.str()
		if err != nil {
			return nil, err
		}
		vt, err := r.u32()
		if err != nil {
			return nil, err
		}
		val, err := r.readValue(vt)
		if err != nil {
			return nil, err
		}
		meta[key] = val
	}

	h := &Header{Tensors: make([]Tensor, 0, nTensors)}
	for i := uint64(0); i < nTensors; i++ {
		name, err := r.str()
		if err != nil {
			return nil, err
		}
		nd, err := r.u32()
		if err != nil {
			return nil, err
		}
		if nd == 0 || nd > 4 {
			return nil, fmt.Errorf("gguf: tensor %q has %d dims", name, nd)
		}
		dims := make([]uint64, nd)
		for j := range dims {
			if dims[j], err = r.u64(); err != nil {
				return nil, err
			}
		}
		typeID, err := r.u32()
		if err != nil {
			return nil, err
		}
		if _, err := r.u64(); err != nil { // file offset, unused
			return nil, err
		}
		size, err := tensorBytes(typeID, dims)
		if err != nil {
			return nil, err
		}
		h.WeightBytes += size
		h.Tensors = append(h.Tensors, Tensor{Name: name, TypeID: typeID, Dims: dims})
	}

	h.fillFromMeta(meta)
	return h, nil
}

func (h *Header) fillFromMeta(meta map[string]any) {
	h.Architecture, _ = meta["general.architecture"].(string)
	h.BlockCount = u32BySuffix(meta, ".block_count")
	h.NextnPredict = u32BySuffix(meta, ".nextn_predict_layers")
	h.HeadCount = u32BySuffix(meta, ".attention.head_count")
	h.HeadCountKV = u32BySuffix(meta, ".attention.head_count_kv")
	h.KeyLength = u32BySuffix(meta, ".attention.key_length")
	h.ValueLength = u32BySuffix(meta, ".attention.value_length")
	h.FullAttnInterval = u32BySuffix(meta, ".full_attention_interval")
	h.SsmConvKernel = u32BySuffix(meta, ".ssm.conv_kernel")
	h.SsmStateSize = u32BySuffix(meta, ".ssm.state_size")
	h.SsmInnerSize = u32BySuffix(meta, ".ssm.inner_size")
	h.SsmGroupCount = u32BySuffix(meta, ".ssm.group_count")
	h.SsmDtRank = u32BySuffix(meta, ".ssm.time_step_rank")

	if arr, ok := findBySuffix(meta, ".attention.recurrent_layers"); ok {
		if mask, ok := arr.([]bool); ok {
			h.RecurrentLayers = mask
		}
	}

	// Defaults per the GGUF/llama.cpp spec.
	if h.HeadCountKV == 0 {
		h.HeadCountKV = h.HeadCount
	}
	if h.KeyLength == 0 {
		if emb := u32BySuffix(meta, ".embedding_length"); emb > 0 && h.HeadCount > 0 {
			h.KeyLength = emb / h.HeadCount
		}
	}
	if h.ValueLength == 0 {
		h.ValueLength = h.KeyLength
	}
}

// MainLayers is the number of trunk layers (block_count minus any MTP/nextn
// prediction layers, which share the main KV cache and add none of their own).
func (h *Header) MainLayers() uint32 {
	if h.NextnPredict >= h.BlockCount {
		return h.BlockCount
	}
	return h.BlockCount - h.NextnPredict
}

// KVLayerCount is the number of layers that allocate a KV cache entry.
func (h *Header) KVLayerCount() uint32 {
	n := h.MainLayers()
	if len(h.RecurrentLayers) > 0 {
		kv := uint32(0)
		for i := uint32(0); i < n && int(i) < len(h.RecurrentLayers); i++ {
			if !h.RecurrentLayers[i] {
				kv++
			}
		}
		return kv
	}
	if h.FullAttnInterval > 0 {
		return n / h.FullAttnInterval
	}
	return n
}

// RecurrentLayerCount is the number of layers with a recurrent (SSM) state
// rather than a KV cache.
func (h *Header) RecurrentLayerCount() uint32 {
	n := h.MainLayers()
	kv := h.KVLayerCount()
	if kv > n {
		return 0
	}
	return n - kv
}

func u32BySuffix(meta map[string]any, suffix string) uint32 {
	if v, ok := findBySuffix(meta, suffix); ok {
		return toU32(v)
	}
	return 0
}

func findBySuffix(meta map[string]any, suffix string) (any, bool) {
	for k, v := range meta {
		if strings.HasSuffix(k, suffix) {
			return v, true
		}
	}
	return nil, false
}

func toU32(v any) uint32 {
	switch t := v.(type) {
	case uint32:
		return t
	case uint64:
		return uint32(t)
	case int32:
		return uint32(t)
	case int64:
		return uint32(t)
	case float64:
		return uint32(t)
	case float32:
		return uint32(t)
	case int:
		return uint32(t)
	}
	return 0
}
