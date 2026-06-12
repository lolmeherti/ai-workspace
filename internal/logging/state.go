package logging

import (
	"io"
	"os"
)

var (
	Writer io.Writer = os.Stdout
	File   *os.File
)
