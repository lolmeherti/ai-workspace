package memcalc

// OverheadMax is the hard ceiling for the llama.cpp runtime overhead that has
// no closed form: the compute buffer, CUDA context, cuda graphs, recurrent-state
// rollback copies, the WDDM desktop share, and allocator fragmentation. It is NOT
// estimated from any catalog — it is measured from the running instance and
// padded UP so a "fits" verdict can never under-count.
//
// Measured on the RTX 5090 (2026-09-13) with Qwen3.8-27B-UD-Q5_K_XL @ 128K f16
// unified, parallel 4:
//
//	31.17 GiB used (nvidia-smi)
//	− 19.44 GiB weights (GGUF parse)
//	−  0.87 GiB mmproj
//	−  7.81 GiB KV cache
//	−  0.59 GiB recurrent state (computed separately by RecurrentBytes)
//	=  2.46 GiB compute buffer + CUDA context + graphs + desktop
//
// Padded to 4 GiB. Bump by hand at the same time a new llama.cpp build is
// installed (re-measure with the same nvidia-smi subtraction).
const OverheadMax = 4 * 1024 * 1024 * 1024 // 4 GiB
