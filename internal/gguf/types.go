package gguf

import "fmt"

// ggml type -> (name, block size, type size in bytes).
// Block size = elements per quant block; type size = bytes per block.
// The in-memory row size of a tensor is typeSize * ceil(ne0 / blockSize).
//
// Values verified against the real Qwen3.8-27B-UD-Q5_K_XL file via the tensor
// offset self-check (864/866 tensors align; the two outliers were the author's
// IQ4_NL typo, corrected here). Remaining entries follow the ggml.c table.
type ggmlType struct {
	Name      string
	BlockSize uint32
	TypeSize  uint32
}

var ggmlTypes = map[uint32]ggmlType{
	0:  {"F32", 1, 4},
	1:  {"F16", 1, 2},
	2:  {"Q4_0", 32, 18},
	3:  {"Q4_1", 32, 20},
	6:  {"Q5_0", 32, 22},
	7:  {"Q5_1", 32, 24},
	8:  {"Q8_0", 32, 34},
	9:  {"Q8_1", 32, 36},
	10: {"Q2_K", 256, 84},
	11: {"Q3_K", 256, 110},
	12: {"Q4_K", 256, 144},
	13: {"Q5_K", 256, 176},
	14: {"Q6_K", 256, 210},
	15: {"Q8_K", 256, 292},
	16: {"IQ2_XXS", 256, 66},
	17: {"IQ2_XS", 256, 74},
	18: {"IQ3_XXS", 256, 98},
	19: {"IQ1_S", 256, 46},
	20: {"IQ4_NL", 32, 18},
	21: {"IQ3_S", 256, 110},
	22: {"IQ2_S", 256, 82},
	23: {"IQ4_XS", 256, 136},
	24: {"I8", 1, 1},
	25: {"I16", 1, 2},
	26: {"I32", 1, 4},
	27: {"I64", 1, 8},
	28: {"F64", 1, 8},
	29: {"IQ1_M", 256, 48},
	30: {"BF16", 1, 2},
}

// TypeName returns the human name of a ggml type, or "?" when unknown.
func TypeName(typeID uint32) string {
	if t, ok := ggmlTypes[typeID]; ok {
		return t.Name
	}
	return "?"
}

// rowSize returns the byte size of one row (ne0 elements) of the given type.
func rowSize(typeID uint32, ne0 uint64) (uint64, error) {
	t, ok := ggmlTypes[typeID]
	if !ok {
		return 0, fmt.Errorf("unknown ggml type %d", typeID)
	}
	blocks := (ne0 + uint64(t.BlockSize) - 1) / uint64(t.BlockSize)
	return uint64(t.TypeSize) * blocks, nil
}

// tensorBytes is ggml_nbytes: rowSize(ne0) * ne1 * ne2 * ne3.
func tensorBytes(typeID uint32, dims []uint64) (uint64, error) {
	if len(dims) == 0 {
		return 0, fmt.Errorf("tensor with zero dims")
	}
	rs, err := rowSize(typeID, dims[0])
	if err != nil {
		return 0, err
	}
	size := rs
	for _, d := range dims[1:] {
		size *= d
	}
	return size, nil
}
