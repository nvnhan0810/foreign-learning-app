package domain

import (
	"math"
	"net/url"
	"regexp"
	"sort"
	"strings"
	"time"
)

var videoID = regexp.MustCompile(`^[a-zA-Z0-9_-]{11}$`)

func YouTubeID(raw string) string {
	u, e := url.Parse(raw)
	if e != nil || u.User != nil || (u.Scheme != "https" && u.Scheme != "http") {
		return ""
	}
	host := strings.ToLower(u.Hostname())
	id := ""
	parts := strings.Split(strings.Trim(u.Path, "/"), "/")
	switch host {
	case "youtu.be":
		if len(parts) == 1 {
			id = parts[0]
		}
	case "youtube.com", "www.youtube.com", "m.youtube.com", "www.youtube-nocookie.com":
		if u.Path == "/watch" {
			id = u.Query().Get("v")
		} else if len(parts) == 2 && (parts[0] == "embed" || parts[0] == "shorts" || parts[0] == "live") {
			id = parts[1]
		}
	}
	if !videoID.MatchString(id) {
		return ""
	}
	return id
}

type Segment struct {
	Start float64 `json:"start"`
	End   float64 `json:"end"`
	Text  string  `json:"text"`
}

func NormalizeSegments(raw []Segment) ([]Segment, string, error) {
	if len(raw) > 5000 {
		return nil, "", ErrInvalid
	}
	out := []Segment{}
	texts := []string{}
	for _, s := range raw {
		if math.IsNaN(s.Start) || math.IsNaN(s.End) || math.IsInf(s.Start, 0) || math.IsInf(s.End, 0) || s.Start < 0 || s.End < 0 || len(s.Text) > 20000 {
			return nil, "", ErrInvalid
		}
		s.Text = strings.TrimSpace(s.Text)
		if s.Text == "" {
			continue
		}
		s.Start = math.Round(s.Start*1000) / 1000
		s.End = math.Round(s.End*1000) / 1000
		if s.End <= s.Start {
			s.End = s.Start + .01
		}
		out = append(out, s)
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Start < out[j].Start })
	for _, s := range out {
		texts = append(texts, s.Text)
	}
	return out, strings.Join(texts, " "), nil
}
func NextListen(now time.Time, frequency string, snooze bool) time.Time {
	if snooze {
		return now.Add(time.Hour)
	}
	switch frequency {
	case "daily":
		return now.AddDate(0, 0, 1)
	case "monthly":
		return now.AddDate(0, 1, 0)
	default:
		return now.AddDate(0, 0, 7)
	}
}
