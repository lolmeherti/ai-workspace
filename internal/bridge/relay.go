package bridge

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/url"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool { return true },
}

// FetchResult is the response from a page-fetch request.
type FetchResult struct {
	Status  string           `json:"status"`
	Content *json.RawMessage `json:"content,omitempty"`
	Error   string           `json:"error,omitempty"`
}

// SearchCandidate is one ranked SERP result.
type SearchCandidate struct {
	Position int    `json:"position"`
	Title    string `json:"title"`
	URL      string `json:"url"`
	Snippet  string `json:"snippet"`
}

// SearchResult carries the search outcome.
type SearchResult struct {
	Status     string            `json:"status"`
	Candidates []SearchCandidate `json:"candidates"`
	Error      string            `json:"error,omitempty"`
}

// Relay bridges PHP HTTP requests to the browser extension over WebSocket.
type Relay struct {
	mu       sync.Mutex
	conn     *websocket.Conn
	lastPing time.Time
	pending  map[string]chan json.RawMessage
}

// NewRelay returns an idle relay ready to accept an extension connection.
func NewRelay() *Relay {
	return &Relay{
		pending: make(map[string]chan json.RawMessage),
	}
}

// IsConnected reports whether an extension is connected and has heartbeated
// within the last 60 seconds.
func (r *Relay) IsConnected() bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.conn != nil && time.Since(r.lastPing) < 60*time.Second
}

// ServeWS upgrades the HTTP request to a WebSocket and enters the read
// loop. Blocks for the lifetime of the connection.
func (r *Relay) ServeWS(w http.ResponseWriter, req *http.Request) {
	ws, err := upgrader.Upgrade(w, req, nil)
	if err != nil {
		log.Printf("bridge: ws upgrade failed: %v", err)
		return
	}

	r.mu.Lock()
	if r.conn != nil {
		// Only one extension at a time — close the old one.
		_ = r.conn.Close()
	}
	r.conn = ws
	r.lastPing = time.Now()
	r.mu.Unlock()

	defer func() {
		r.mu.Lock()
		if r.conn == ws {
			r.conn = nil
		}
		// Drain pending channels so blocked HTTP handlers unblock.
		for id, ch := range r.pending {
			close(ch)
			delete(r.pending, id)
		}
		r.mu.Unlock()
		_ = ws.Close()
	}()

	// Read loop — one goroutine, no concurrent reads.
	for {
		_, raw, err := ws.ReadMessage()
		if err != nil {
			if websocket.IsUnexpectedCloseError(err, websocket.CloseGoingAway, websocket.CloseNormalClosure) {
				log.Printf("bridge: ws read error: %v", err)
			}
			return
		}

		var msg struct {
			Type       string          `json:"type"`
			RequestID  string          `json:"request_id"`
			Status     string          `json:"status"`
			TS         int64           `json:"ts,omitempty"`
			RawPayload json.RawMessage `json:"-"`
		}
		if err := json.Unmarshal(raw, &msg); err != nil {
			log.Printf("bridge: bad ws message: %v", err)
			continue
		}

		switch msg.Type {
		case "hello":
			r.mu.Lock()
			r.lastPing = time.Now()
			r.mu.Unlock()

		case "ping":
			now := time.Now()
			if msg.TS > 0 {
				now = time.UnixMilli(msg.TS)
			}
			r.mu.Lock()
			r.lastPing = now
			r.mu.Unlock()

		case "search_result", "fetch_result":
			r.mu.Lock()
			ch, ok := r.pending[msg.RequestID]
			delete(r.pending, msg.RequestID)
			r.mu.Unlock()
			if ok && ch != nil {
				// Forward the raw message so the caller can unmarshal
				// whichever type it expects.
				ch <- raw
			}
		}
	}
}

func (r *Relay) dispatch(ctx context.Context, msg map[string]interface{}, requestID string) (json.RawMessage, error) {
	ch := make(chan json.RawMessage, 1)

	r.mu.Lock()
	if r.conn == nil {
		r.mu.Unlock()
		return nil, fmt.Errorf("bridge: no extension connected")
	}
	r.pending[requestID] = ch
	r.mu.Unlock()

	defer func() {
		r.mu.Lock()
		delete(r.pending, requestID)
		r.mu.Unlock()
	}()

	r.mu.Lock()
	r.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
	err := r.conn.WriteJSON(msg)
	r.mu.Unlock()
	if err != nil {
		return nil, fmt.Errorf("bridge: ws write: %w", err)
	}

	select {
	case raw, ok := <-ch:
		if !ok {
			return nil, fmt.Errorf("bridge: extension disconnected")
		}
		return raw, nil
	case <-ctx.Done():
		return nil, fmt.Errorf("bridge: %w", ctx.Err())
	}
}

// Fetch sends {action:"fetch", url, request_id} over the WebSocket and blocks
// until the extension responds or ctx expires.
// Before dispatch, resolves the hostname and rejects private/loopback/link-local
// IPs to prevent SSRF through the user's real browser.
func (r *Relay) Fetch(ctx context.Context, urlStr, requestID string) (*FetchResult, error) {
	if err := validateHost(urlStr); err != nil {
		return &FetchResult{Status: "rejected", Error: err.Error()}, nil
	}

	raw, err := r.dispatch(ctx, map[string]interface{}{
		"action":     "fetch",
		"url":        urlStr,
		"request_id": requestID,
	}, requestID)
	if err != nil {
		return &FetchResult{Status: "timeout", Error: err.Error()}, nil
	}

	var result FetchResult
	if err := json.Unmarshal(raw, &result); err != nil {
		return &FetchResult{Status: "parse_failed", Error: "invalid fetch_result payload"}, nil
	}
	return &result, nil
}

// Search sends {action:"search", query, request_id} and blocks until the
// extension returns SERP candidates or ctx expires.
func (r *Relay) Search(ctx context.Context, query, requestID string) (*SearchResult, error) {
	raw, err := r.dispatch(ctx, map[string]interface{}{
		"action":     "search",
		"query":      query,
		"request_id": requestID,
	}, requestID)
	if err != nil {
		return &SearchResult{Status: "timeout", Error: err.Error()}, nil
	}

	var result SearchResult
	if err := json.Unmarshal(raw, &result); err != nil {
		return &SearchResult{Status: "parse_failed", Error: "invalid search_result payload"}, nil
	}
	return &result, nil
}

// ── DNS / IP safety ──────────────────────────────────────────────

// validateHost parses the host from urlStr, resolves it via the system
// resolver (the user's real DNS, since the Go binary runs on Windows),
// and rejects the URL if any resolved address is not globally routable.
//
// RESIDUAL RISK — DNS rebinding:
//   The host resolves to public IPs during this check. Between this
//   resolution and Edge's navigation, the DNS record could change to
//   point at a private IP. Manifest V3 extensions cannot inspect the
//   resolved IP synchronously to veto each request (webRequestBlocking
//   is unavailable), so rebinding is theoretically possible. The
//   extension's declarativeNetRequest blocklist catches literal
//   private-IP redirect targets (302 to 192.168.1.1) and content
//   scripts verify location.href before extraction. For a single-user
//   search agent only opening SERP candidates, this residual window
//   is acceptable.
func validateHost(urlStr string) error {
	u, err := url.Parse(urlStr)
	if err != nil {
		return fmt.Errorf("bridge: invalid URL: %w", err)
	}

	host := u.Hostname()
	if host == "" {
		return fmt.Errorf("bridge: URL missing host")
	}

	ips, err := net.DefaultResolver.LookupIP(context.Background(), "ip", host)
	if err != nil {
		return fmt.Errorf("bridge: DNS lookup failed for %s: %w", host, err)
	}
	if len(ips) == 0 {
		return fmt.Errorf("bridge: no addresses resolved for %s", host)
	}

	for _, ip := range ips {
		if !ip.IsGlobalUnicast() {
			return fmt.Errorf("bridge: host %s resolves to non-public IP %s", host, ip)
		}
	}

	return nil
}
