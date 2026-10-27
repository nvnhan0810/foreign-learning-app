package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestEnvIsDataAndPreservesProcessOverrides(t *testing.T) {
	path := filepath.Join(t.TempDir(), ".env")
	if e := os.WriteFile(path, []byte("FLC_TEST_CONFIG='$(not-a-command)'\nFLC_TEST_OVERRIDE=file\n"), 0600); e != nil {
		t.Fatal(e)
	}
	t.Setenv("FLC_TEST_OVERRIDE", "process")
	t.Cleanup(func() { _ = os.Unsetenv("FLC_TEST_CONFIG") })
	if e := LoadEnv(path); e != nil {
		t.Fatal(e)
	}
	if os.Getenv("FLC_TEST_CONFIG") != "$(not-a-command)" || os.Getenv("FLC_TEST_OVERRIDE") != "process" {
		t.Fatal("environment parsing modified a literal value or process override")
	}
}
