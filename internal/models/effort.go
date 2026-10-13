package models

// EffortLevel is the closed set of reasoning-effort values the app may store and put on the wire. The members mirror
// `reasoning_effort_values` as declared per family in models.json — that declaration is the source of truth, and
// effort_test.go enforces the mirror, so a value that exists on one side and not the other fails the build's tests.
//
// There are two kinds of value set, both declared in models.json and mirrored here by ReasoningValues():
//   - a BINARY family declares `["on", "off"]` — its runtime has no effort map, so thinking is either on or off;
//   - a GRADUATED family declares its ladder (`["low", "medium", "high"]`), which its effort_map translates for the
//     engine.
//
// A value that is neither declared here nor in models.json — an engine alias such as `none`, `xhigh`, `minimal` — is
// not a stored value and must not parse as one.
type EffortLevel string

const (
	EffortOff    EffortLevel = "off"
	EffortOn     EffortLevel = "on"
	EffortLow    EffortLevel = "low"
	EffortMedium EffortLevel = "medium"
	EffortHigh   EffortLevel = "high"
)

// EffortLevels lists every member of the vocabulary. Which of them a given model may hold is declared per family in
// models.json and mirrored by ModelFamily.ReasoningValues — a binary family declares off/on, a graduated one
// low/medium/high.
func EffortLevels() []EffortLevel {
	return []EffortLevel{EffortOff, EffortOn, EffortLow, EffortMedium, EffortHigh}
}

// Rank orders values WITHIN a family's own set — binary: off < on; graduated: low < medium < high. The two sets never
// meet, so a single linear rank is enough for "is this the family's top value". What a value MEANS on a given engine is
// the family's effort_map, which is not this type's business.
func (e EffortLevel) Rank() int {
	switch e {
	case EffortOff:
		return 0
	case EffortLow:
		return 1
	case EffortMedium:
		return 2
	case EffortHigh:
		return 3
	case EffortOn:
		return 4
	default:
		return -1
	}
}

func (e EffortLevel) Valid() bool { return e.Rank() >= 0 }

func (e EffortLevel) String() string { return string(e) }

// EffortLevelFrom parses a stored value, reporting false rather than guessing at an unknown one.
func EffortLevelFrom(s string) (EffortLevel, bool) {
	level := EffortLevel(s)

	return level, level.Valid()
}
