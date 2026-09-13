package gguf

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

// headerWindows are the prefix sizes to try, smallest first. A 27B model's
// header (metadata + tokenizer + tensor list) is typically under 16 MiB; larger
// models with big tokenizers may need more. If a window is too small, Parse
// returns ErrTruncated and the next window is fetched.
var headerWindows = []int64{
	4 << 20,
	8 << 20,
	16 << 20,
	32 << 20,
	64 << 20,
	128 << 20,
}

// FetchHeader downloads only the leading bytes of a GGUF (enough to cover the
// full header) via an HTTP Range request, so required VRAM can be computed
// before the multi-GiB weight file is downloaded.
func FetchHeader(url string) ([]byte, error) {
	var lastErr error
	for _, size := range headerWindows {
		data, err := fetchRange(url, size)
		if err != nil {
			lastErr = err
			continue
		}
		if _, err := Parse(data); err == ErrTruncated {
			lastErr = err
			continue
		}
		return data, nil
	}
	if lastErr == nil {
		lastErr = ErrTruncated
	}
	return nil, fmt.Errorf("gguf: fetch header %s: %w", url, lastErr)
}

func fetchRange(url string, n int64) ([]byte, error) {
	client := &http.Client{Timeout: 60 * time.Second}
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Range", fmt.Sprintf("bytes=0-%d", n-1))

	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusPartialContent: // 206 — server honored the range
		return io.ReadAll(io.LimitReader(resp.Body, n))
	case http.StatusOK: // 200 — server ignored Range; read only the prefix and stop
		return io.ReadAll(io.LimitReader(resp.Body, n))
	default:
		return nil, fmt.Errorf("unexpected status %d", resp.StatusCode)
	}
}

// FetchFileSize returns the total byte size of a remote file via a 1-byte Range
// request (Content-Range total), falling back to Content-Length. Used for the
// mmproj size, which the gate needs before the file is downloaded.
func FetchFileSize(url string) (uint64, error) {
	client := &http.Client{Timeout: 30 * time.Second}
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Range", "bytes=0-0")
	resp, err := client.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1))

	if cr := resp.Header.Get("Content-Range"); cr != "" {
		if i := strings.LastIndex(cr, "/"); i >= 0 {
			if total, err := strconv.ParseUint(strings.TrimSpace(cr[i+1:]), 10, 64); err == nil {
				return total, nil
			}
		}
	}
	if cl := resp.Header.Get("Content-Length"); cl != "" {
		if total, err := strconv.ParseUint(cl, 10, 64); err == nil {
			return total, nil
		}
	}
	return 0, fmt.Errorf("no content size for %s", url)
}

// ParseFile reads the header of a local GGUF file (up to 128 MiB) and parses it.
// The header always precedes the tensor data, so only the prefix is read.
func ParseFile(path string) (*Header, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	const maxHeader = 128 << 20
	data := make([]byte, maxHeader)
	n, err := io.ReadFull(f, data)
	if err != nil && err != io.ErrUnexpectedEOF && err != io.EOF {
		return nil, err
	}
	return Parse(data[:n])
}
