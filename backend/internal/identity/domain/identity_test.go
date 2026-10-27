package domain

import "testing"

func TestMobileRedirectAllowlist(t *testing.T) {
	for _, uri := range []string{"flc://oauth-callback", "https://abc.chromiumapp.org/", "http://localhost:9876/callback", "http://127.0.0.1:7000/callback"} {
		if !ValidMobileRedirect(uri) {
			t.Fatal(uri)
		}
	}
	for _, uri := range []string{"https://evil.example/", "https://abc.chromiumapp.org.evil.example", "javascript:alert(1)", "https://u:p@abc.chromiumapp.org/", "flc://evil", "//localhost/", "http://abc.chromiumapp.org/"} {
		if ValidMobileRedirect(uri) {
			t.Fatal(uri)
		}
	}
}
func TestNextPath(t *testing.T) {
	for _, v := range []string{"//evil.example", "/home\\evil", "/home/%2f%2fevil", "https://evil.example", "/admin", "/homeevil"} {
		if SafeNext(v) != "" {
			t.Fatal(v)
		}
	}
	if SafeNext("/lookup?q=hello") != "/lookup?q=hello" {
		t.Fatal("lost query")
	}
}
func TestEmailAllowlist(t *testing.T) {
	if !Allowed(" Person@Example.com ", false, []string{"*@example.com"}) {
		t.Fatal("domain rejected")
	}
	if Allowed("person@sub.example.com", false, []string{"*@example.com"}) {
		t.Fatal("subdomain allowed")
	}
}
