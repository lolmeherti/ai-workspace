package models

import (
	"encoding/json"
	"os"
	"testing"
)

// The catalog is the source of truth, and every entry must be attributed to a family enum. This test is what makes the
// owner's rule enforceable: add a model to models.json without naming its family, or add a runtime that family.go does
// not know, and this fails — instead of some later feature inventing a value to fill the gap.
func TestEveryCatalogEntryIsAttributedToAFamily(t *testing.T) {
	raw, err := os.ReadFile("../../models.json")
	if err != nil {
		t.Fatalf("cannot read the catalog: %v", err)
	}

	var catalog struct {
		Models map[string]struct {
			Runtime string      `json:"runtime"`
			Family  ModelFamily `json:"family"`
		} `json:"models"`
	}
	if err := json.Unmarshal(raw, &catalog); err != nil {
		t.Fatalf("catalog is not valid JSON: %v", err)
	}
	if len(catalog.Models) == 0 {
		t.Fatal("catalog has no models — the test would pass vacuously")
	}

	seen := map[ModelFamily]int{}
	for id, entry := range catalog.Models {
		if !entry.Family.Valid() {
			t.Errorf("model %q declares family %q, which is not a declared family (want one of %v)",
				id, entry.Family, Families())
			continue
		}
		seen[entry.Family]++

		// The declared family must agree with the runtime the entry names — otherwise the two surfaces disagree about
		// what the model is, which is exactly the kind of drift enums exist to prevent.
		fromRuntime, ok := FamilyForRuntime(entry.Runtime)
		if !ok {
			t.Errorf("model %q names runtime %q, which no family claims — attribute the runtime in family.go",
				id, entry.Runtime)
			continue
		}
		if fromRuntime != entry.Family {
			t.Errorf("model %q says family %q but its runtime %q belongs to %q",
				id, entry.Family, entry.Runtime, fromRuntime)
		}
	}

	for _, family := range Families() {
		if seen[family] == 0 {
			t.Logf("note: no catalog entry carries family %q yet", family)
		}
	}
}

// Every runtime the catalog uses must be attributable, and every runtime family.go claims must resolve. A new runtime
// added to runtime.go without a family is a gap this catches.
func TestEveryRuntimeHasAFamily(t *testing.T) {
	for runtime := range runtimes {
		family, ok := FamilyForRuntime(runtime)
		if !ok {
			t.Errorf("runtime %q has no family — add it to familyByRuntime", runtime)
			continue
		}
		if !family.Valid() {
			t.Errorf("runtime %q maps to %q, which is not a declared family", runtime, family)
		}
	}
}
