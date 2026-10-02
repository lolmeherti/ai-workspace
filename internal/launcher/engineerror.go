package launcher

import (
	"sync"
	"time"
)

// EngineStartError is the last boot-time engine failure. It exists so that a
// failure which used to be one log line is observable everywhere it matters: the
// tray tooltip, a message box, and the /api/switch-status payload the web layer
// reads — so "The AI service is offline" can say why instead of naming a guess.
//
// It is cleared as soon as an engine start succeeds, and it is only consulted
// when no model switch is in flight (a switch reports its own errors).
type EngineStartError struct {
	Message string    `json:"message"`
	Detail  string    `json:"detail,omitempty"`
	At      time.Time `json:"at"`
}

var (
	engineStartErrorMu sync.Mutex
	engineStartError   *EngineStartError
)

// recordEngineStartError stores a boot-time failure and returns it, so the caller
// can log and display the same text it publishes.
func recordEngineStartError(message, detail string) *EngineStartError {
	engineStartErrorMu.Lock()
	defer engineStartErrorMu.Unlock()
	engineStartError = &EngineStartError{Message: message, Detail: detail, At: time.Now()}
	return engineStartError
}

// clearEngineStartError marks the engine healthy again.
func clearEngineStartError() {
	engineStartErrorMu.Lock()
	defer engineStartErrorMu.Unlock()
	engineStartError = nil
}

// EngineStartFailure returns the current boot-time failure, or nil when the
// engine started (or none was attempted).
func EngineStartFailure() *EngineStartError {
	engineStartErrorMu.Lock()
	defer engineStartErrorMu.Unlock()
	return engineStartError
}
