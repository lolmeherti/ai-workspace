package download

import (
	"archive/zip"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type ProgressWriter struct {
	io.Writer
	Total      int64
	Length     int64
	LastPct    float64
	OnProgress func(float64)
}

func (pw *ProgressWriter) Write(p []byte) (int, error) {
	n, err := pw.Writer.Write(p)
	pw.Total += int64(n)

	if pw.Length > 0 && pw.OnProgress != nil {
		pct := float64(pw.Total) / float64(pw.Length) * 100
		if pct-pw.LastPct >= 1.0 || pct == 100 {
			pw.LastPct = pct
			pw.OnProgress(pct)
		}
	}
	return n, err
}

func File(filepath string, url string, onProgress func(float64)) error {
	out, err := os.Create(filepath)
	if err != nil {
		return err
	}
	defer out.Close()

	resp, err := http.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	contentLength, _ := strconv.ParseInt(resp.Header.Get("Content-Length"), 10, 64)
	pw := &ProgressWriter{
		Writer:     out,
		Length:     contentLength,
		OnProgress: onProgress,
	}

	_, err = io.Copy(pw, resp.Body)
	return err
}

func Unzip(src string, dest string) error {
	r, err := zip.OpenReader(src)
	if err != nil {
		return err
	}
	defer r.Close()

	cleanDest := strings.ToLower(filepath.Clean(dest))

	for _, f := range r.File {
		fpath := filepath.Join(dest, filepath.Clean(f.Name))
		cleanFpath := strings.ToLower(filepath.Clean(fpath))
		if !strings.HasPrefix(cleanFpath, cleanDest) {
			continue
		}

		if f.FileInfo().IsDir() {
			_ = os.MkdirAll(fpath, os.ModePerm)
			continue
		}

		if err = os.MkdirAll(filepath.Dir(fpath), os.ModePerm); err != nil {
			return err
		}

		outFile, err := os.OpenFile(fpath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.Mode())
		if err != nil {
			return err
		}

		rc, err := f.Open()
		if err != nil {
			outFile.Close()
			return err
		}

		_, err = io.Copy(outFile, rc)
		outFile.Close()
		rc.Close()
		if err != nil {
			return err
		}
	}
	return nil
}
