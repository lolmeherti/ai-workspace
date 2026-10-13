package models

// ModelFamily attributes every catalog entry to a model family. It is the enum the owner requires: whenever a model is
// added to models.json it must be attributed to one of these, and a model that carries no family fails the catalog test.
//
// The family is an ATTRIBUTION, not a behaviour: what a model can do about reasoning (a graduated ladder vs a binary
// thinking switch) is declared by its runtime's ReasoningPolicy, which is not this type's business. Families exist so
// tooling, logs and future feature work have one closed set to name, instead of inventing strings per model.
type ModelFamily string

const (
	FamilyGemma  ModelFamily = "gemma"
	FamilyQwen   ModelFamily = "qwen"
	FamilyBonsai ModelFamily = "bonsai"
)

// Families lists every valid member, in the order they should be reported.
func Families() []ModelFamily {
	return []ModelFamily{FamilyGemma, FamilyQwen, FamilyBonsai}
}

// Valid reports whether f is one of the declared families. An empty family is NOT valid: attribution is mandatory.
func (f ModelFamily) Valid() bool {
	switch f {
	case FamilyGemma, FamilyQwen, FamilyBonsai:
		return true
	default:
		return false
	}
}

func (f ModelFamily) String() string { return string(f) }

// ReasoningValues is the family's reasoning value set, mirroring `families.<family>.reasoning.reasoning_effort_values`
// in models.json — the source of truth. effort_test.go asserts the two are equal for every family, so a declaration
// that changes in the catalog without this changing (or the reverse) fails the tests instead of shipping.
func (f ModelFamily) ReasoningValues() []EffortLevel {
	switch f {
	case FamilyGemma, FamilyBonsai:
		return []EffortLevel{EffortOff, EffortOn}
	case FamilyQwen:
		return []EffortLevel{EffortLow, EffortMedium, EffortHigh}
	default:
		return nil
	}
}

// familyByRuntime maps a runtime ID to the family that runtime belongs to. A runtime belongs to exactly one family, so
// a catalog entry that names a runtime can always be attributed; the explicit "family" field in models.json is what the
// tests require, and this map is what the loader falls back to when an entry predates the field.
var familyByRuntime = map[string]ModelFamily{
	"gemma4": FamilyGemma,
	"qwen38": FamilyQwen,
	"qwen35": FamilyQwen,
	"bonsai": FamilyBonsai,
}

// FamilyForRuntime returns the family a runtime belongs to, and false when the runtime is unknown (which is the signal
// that a NEW runtime was added without attributing it).
func FamilyForRuntime(runtime string) (ModelFamily, bool) {
	family, ok := familyByRuntime[runtime]

	return family, ok
}
