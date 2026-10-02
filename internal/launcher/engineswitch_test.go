package launcher

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"testing"
	"time"
)

// A cancelled switch must not leave the launcher waiting out the full boot timeout on an
// engine the user already abandoned.
func TestWaitStrataReadyCtxHonoursCancel(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	start := time.Now()
	ready := waitStrataReadyCtx(ctx, 1, 0) // pid 0 skips the process check; nothing listens on port 1
	elapsed := time.Since(start)

	if ready {
		t.Fatalf("expected not-ready for a cancelled context")
	}
	if elapsed > 2*time.Second {
		t.Errorf("cancelled wait returned after %s; expected an immediate return", elapsed)
	}
}

// On a cold start nothing has been switched, so /api/switch-status has to describe the model the
// launcher booted. Without it the web layer cannot resolve the Reasoning control's graduated
// levels and silently falls back to Off/On.
func TestSwitchStatusFallsBackToBootPolicy(t *testing.T) {
	oldSampling, oldPolicy := BootSampling, BootRuntimePolicy
	defer func() { BootSampling, BootRuntimePolicy = oldSampling, oldPolicy }()

	BootSampling = `{"temperature":0.7}`
	BootRuntimePolicy = `{"default_effort":"medium","effort_map":{"low":"low","medium":"medium","high":"xhigh"}}`

	h := &modelsHandler{}

	type statusPayload struct {
		Active        bool   `json:"active"`
		Sampling      string `json:"sampling"`
		RuntimePolicy string `json:"runtime_policy"`
	}

	read := func() statusPayload {
		rec := httptest.NewRecorder()
		h.handleSwitchStatus(rec, httptest.NewRequest("GET", "/api/switch-status", nil))
		var got statusPayload
		if err := json.Unmarshal(rec.Body.Bytes(), &got); err != nil {
			t.Fatalf("status is not JSON: %v (%s)", err, rec.Body.String())
		}
		return got
	}

	got := read()
	if got.RuntimePolicy != BootRuntimePolicy {
		t.Errorf("cold-start status lost the boot runtime policy: got %q", got.RuntimePolicy)
	}
	if got.Sampling != BootSampling {
		t.Errorf("cold-start status lost the boot sampling: got %q", got.Sampling)
	}

	// An in-flight switch reports its own values; the boot fallback must never override them.
	h.switchMu.Lock()
	h.sw = switchStatus{Active: true, Sampling: `{"switch":true}`, RuntimePolicy: `{"switch_policy":true}`}
	h.switchMu.Unlock()

	got = read()
	if got.RuntimePolicy != `{"switch_policy":true}` {
		t.Errorf("in-flight switch policy was overridden by the boot value: got %q", got.RuntimePolicy)
	}
	if got.Sampling != `{"switch":true}` {
		t.Errorf("in-flight switch sampling was overridden by the boot value: got %q", got.Sampling)
	}
}
