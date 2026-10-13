package models

import (
	"encoding/json"
	"os"
	"testing"
)

// The possible values live in models.json; the enums mirror them. This keeps the two honest: a graduated family
// declares a non-empty list of known levels plus an effort_map, a binary family declares neither, and every value the
// vocabulary knows must be declared by some family — which is what catches an invented entry such as `off`.
func TestDeclaredEffortValuesMatchTheVocabulary(t *testing.T) {
	raw, err := os.ReadFile("../../models.json")
	if err != nil {
		t.Fatalf("cannot read the catalog: %v", err)
	}

	var catalog struct {
		Families map[string]struct {
			Reasoning struct {
				Kind    string         `json:"kind"`
				Values  []string       `json:"reasoning_effort_values"`
				Map     map[string]any `json:"effort_map"`
				Default string         `json:"default"`
			} `json:"reasoning"`
		} `json:"families"`
	}
	if err := json.Unmarshal(raw, &catalog); err != nil {
		t.Fatalf("catalog is not valid JSON: %v", err)
	}
	if len(catalog.Families) == 0 {
		t.Fatal("no families declared — the test would pass vacuously")
	}

	declared := map[EffortLevel]bool{}

	for name, family := range catalog.Families {
		for _, v := range family.Reasoning.Values {
			level, ok := EffortLevelFrom(v)
			if !ok {
				t.Errorf("family %q declares value %q, which is not a known EffortLevel", name, v)
				continue
			}
			declared[level] = true
		}

		// The family enum must carry exactly what the catalog declares for this family.
		fam := ModelFamily(name)
		if !fam.Valid() {
			t.Errorf("catalog declares family %q, which is not a declared ModelFamily", name)
			continue
		}
		if len(family.Reasoning.Values) == 0 {
			t.Errorf("family %q declares no reasoning_effort_values — the possible values must be declared, not implied",
				name)
		}
		wantValues := map[string]bool{}
		for _, level := range fam.ReasoningValues() {
			wantValues[level.String()] = true
		}
		gotValues := map[string]bool{}
		for _, v := range family.Reasoning.Values {
			gotValues[v] = true
		}
		for v := range wantValues {
			if !gotValues[v] {
				t.Errorf("family %q does not declare %q, but its enum does", name, v)
			}
		}
		for v := range gotValues {
			if !wantValues[v] {
				t.Errorf("family %q declares %q, which its enum does not", name, v)
			}
		}

		// The kind follows from what the family declares: a binary family maps nothing, a graduated one maps its ladder
		// onto the engine.
		switch family.Reasoning.Kind {
		case "binary":
			if len(family.Reasoning.Map) != 0 {
				t.Errorf("family %q is binary but declares an effort_map", name)
			}
		case "graduated":
			if len(family.Reasoning.Map) == 0 {
				t.Errorf("family %q is graduated but declares no effort_map", name)
			}
			for key := range family.Reasoning.Map {
				if _, ok := EffortLevelFrom(key); !ok {
					t.Errorf("family %q maps %q, which is not a known EffortLevel", name, key)
				}
			}
		default:
			t.Errorf("family %q declares kind %q — want \"binary\" or \"graduated\"", name, family.Reasoning.Kind)
		}
	}

	for _, level := range EffortLevels() {
		if !declared[level] {
			t.Errorf("no family declares %q, but the vocabulary has it — the two must mirror", level)
		}
	}
}

// Engine aliases are wire values, not stored values: they must not parse as declared reasoning values.
func TestWireAliasesAreNotStoredValues(t *testing.T) {
	for _, bogus := range []string{"none", "minimal", "max", "xhigh", "true", "false", ""} {
		if level, ok := EffortLevelFrom(bogus); ok {
			t.Errorf("%q parsed as reasoning value %q — aliases live in the runtime's effort_map, not in the vocabulary",
				bogus, level)
		}
	}
}
