package video

import (
	"context"
	"sort"
)

// stretch is a span of time: one speech region, or a place where speech stops.
type stretch struct {
	start float64
	end   float64
}

// chunk is one piece of audio handed to the transcriber. Its identity travels with it — index, times
// and the file it was cut to — so a result is tied back to the piece it came from by that identity
// rather than by its position in an array that something else may have reordered or dropped from.
type chunk struct {
	Index  int
	Start  float64
	End    float64
	Path   string
	Forced bool // cut inside speech, so its neighbour overlaps it
}

const (
	// silencePad is added around a chunk that begins or ends at a pause, so a word right at the edge is
	// not clipped. It is quiet audio, so two neighbours reaching into it cannot say the same word twice.
	silencePad = 0.25
	// forcedOverlap is carried when a chunk has to be cut inside continuous speech, where a word
	// really can straddle the seam.
	forcedOverlap = 2.0
)

// planChunks turns the speech regions the detector found into the pieces that get transcribed: regions
// are merged up to the length cap, so a seam only lands inside speech when one stretch of speech is
// longer than a single chunk can hold.
func planChunks(regions []stretch, total float64) []chunk {
	return planChunksWithCap(regions, total, sliceLength)
}

// planChunksWithCap plans the pieces that get transcribed: every speech region the detector found is one
// chunk, so two utterances are never joined into one pass and the silence between them is never carried
// into a chunk. The cap applies to a single region only — one too long for a single pass is split inside
// speech into even pieces with the reach-back. A cap of zero means no splitting at all.
func planChunksWithCap(regions []stretch, total, cap float64) []chunk {
	if len(regions) == 0 || total <= 0 {
		return nil
	}

	// The padding is added to every chunk at the end, so the span built here has to leave room for it:
	// otherwise a chunk grows past the length the model was measured to accept.
	span := cap - 2*silencePad

	var chunks []chunk

	for _, region := range regions {
		length := region.end - region.start

		// Only a region longer than one pass is cut, and it is cut inside speech — the one place a word
		// can straddle a seam — into even pieces, because a fixed step leaves a degenerate sliver at the
		// end that overlaps its neighbour by more than it says.
		if cap > 0 && length > span {
			pieces := 1
			for float64(pieces)*span-float64(pieces-1)*forcedOverlap < length {
				pieces++
			}
			pieceLength := (length + float64(pieces-1)*forcedOverlap) / float64(pieces)

			for index := 0; index < pieces; index++ {
				from := region.start + float64(index)*(pieceLength-forcedOverlap)
				to := from + pieceLength
				if index == pieces-1 {
					to = region.end
				}
				chunks = append(chunks, chunk{Start: from, End: to, Forced: true})
			}

			continue
		}

		// A region that fits is one chunk, whole and alone: neighbours are never joined, so a seam cannot
		// fall in the silence between two utterances.
		chunks = append(chunks, chunk{Start: region.start, End: region.end})
	}

	// Identity is assigned after ordering, once, so every later stage refers to the same piece by the
	// same number.
	return padChunks(chunks, total)
}

// planAudio asks the speech detector where the speech is and plans the chunks from its answer. It
// returns found as false only when the detector ran and heard no speech at all — a video with nobody
// talking has no local witness, which is a finding, not a failure. When the detector itself is
// unavailable the fixed grid is used instead, so a missing model degrades the watch rather than
// breaking it.
func planAudio(ctx context.Context, tools Toolchain, models ASRModels, ws *Workspace, wav string, total float64) (chunks []chunk, found bool) {
	return planAudioWithCap(ctx, tools, models, ws, wav, total, sliceLength)
}

func planAudioWithCap(ctx context.Context, tools Toolchain, models ASRModels, ws *Workspace, wav string, total, cap float64) (chunks []chunk, found bool) {
	regions, err := findSpeech(ctx, tools, models, ws, wav)
	if err != nil {
		return gridChunksWithCap(total, cap), true
	}
	if len(regions) == 0 {
		return nil, false
	}

	return planChunksWithCap(regions, total, cap), true
}

// padChunks orders the pieces and puts their identity and their padding on them.
func padChunks(chunks []chunk, total float64) []chunk {
	if len(chunks) == 0 {
		return nil
	}

	sort.Slice(chunks, func(i, j int) bool { return chunks[i].Start < chunks[j].Start })
	for index := range chunks {
		chunks[index].Index = index
		chunks[index].Start -= silencePad
		if chunks[index].Start < 0 {
			chunks[index].Start = 0
		}
		chunks[index].End += silencePad
		if chunks[index].End > total || chunks[index].End < chunks[index].Start {
			chunks[index].End = total
		}
	}

	return chunks
}

// gridChunks is the plain fixed grid, used when the speech detector is unavailable.
func gridChunks(total float64) []chunk {
	return gridChunksWithCap(total, sliceLength)
}

func gridChunksWithCap(total, cap float64) []chunk {
	slices := planSlicesWithCap(total, cap)
	chunks := make([]chunk, 0, len(slices))
	for index, slice := range slices {
		chunks = append(chunks, chunk{Index: index, Start: slice.start, End: slice.end})
	}

	return chunks
}
