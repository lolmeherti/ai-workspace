package launcher

import "testing"

// The boot-time failure has to survive being recorded, be visible to the status
// payload, and disappear once the engine starts — otherwise "AI offline" keeps
// explaining a failure that no longer applies.
func TestEngineStartFailureLifecycle(t *testing.T) {
	clearEngineStartError()
	if EngineStartFailure() != nil {
		t.Fatal("expected no failure recorded initially")
	}

	recorded := recordEngineStartError("engine did not start", "detail line")
	if recorded == nil || recorded.Message != "engine did not start" || recorded.Detail != "detail line" {
		t.Fatalf("recorded failure not returned intact: %+v", recorded)
	}

	got := EngineStartFailure()
	if got == nil {
		t.Fatal("EngineStartFailure returned nil after recording")
	}
	if got.Message != "engine did not start" || got.Detail != "detail line" || got.At.IsZero() {
		t.Fatalf("unexpected failure contents: %+v", got)
	}

	clearEngineStartError()
	if EngineStartFailure() != nil {
		t.Fatal("expected the failure to be cleared")
	}
}
