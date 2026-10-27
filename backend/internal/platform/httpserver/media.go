package httpserver

import (
	"encoding/json"
	authpg "flc/backend/internal/identity/infrastructure/postgres"
	listen "flc/backend/internal/listening/domain"
	"fmt"
	"github.com/jackc/pgx/v5"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf8"
)

func (h *LearningHandler) registerMedia(m *http.ServeMux) {
	m.HandleFunc("GET /home/media", h.mediaList)
	m.HandleFunc("GET /home/media/{id}", h.mediaShow)
	m.HandleFunc("GET /home/media/{id}/transcript/edit", h.mediaShow)
	m.HandleFunc("PUT /home/media/{id}/transcript", h.mediaTranscript)
	m.HandleFunc("DELETE /home/media/{id}", h.mediaDelete)
	m.HandleFunc("POST /home/media/youtube/preview", h.mediaPreview)
	m.HandleFunc("POST /home/media/youtube", h.mediaCreate)
	m.HandleFunc("GET /home/media/{id}/audio", h.mediaAudio)
	m.HandleFunc("GET /api/media-items", h.mediaList)
	m.HandleFunc("POST /api/media-items", h.mediaCreate)
	m.HandleFunc("GET /api/media-items/due", h.mediaList)
	m.HandleFunc("GET /api/media-items/{id}", h.mediaShow)
	m.HandleFunc("PUT /api/media-items/{id}", h.mediaUpdate)
	m.HandleFunc("PATCH /api/media-items/{id}", h.mediaUpdate)
	m.HandleFunc("DELETE /api/media-items/{id}", h.mediaDelete)
	m.HandleFunc("POST /api/media-items/{id}/listened", h.mediaListened)
	m.HandleFunc("POST /api/listening/media/youtube-preview", h.mediaPreview)
	m.HandleFunc("POST /api/listening/media", h.mediaCreate)
	m.HandleFunc("GET /api/listening/media/{id}", h.mediaShow)
	m.HandleFunc("PUT /api/listening/media/{id}/transcript", h.mediaTranscript)
	m.HandleFunc("GET /api/listening/media/{id}/audio", h.mediaAudio)
}
func api(r *http.Request) bool { return strings.HasPrefix(r.URL.Path, "/api/") }
func (h *LearningHandler) mediaItem(r *http.Request, user, id int64) (map[string]any, error) {
	var raw []byte
	e := h.Pool.QueryRow(r.Context(), `SELECT row_to_json(m) FROM media_items m WHERE user_id=$1 AND id=$2`, user, id).Scan(&raw)
	if e != nil {
		return nil, e
	}
	var item map[string]any
	e = json.Unmarshal(raw, &item)
	if e != nil {
		return nil, e
	}
	difficulty, _ := item["difficulty"].(string)
	label := "Intermediate"
	if difficulty == "beginner" {
		label = "Beginner"
	} else if difficulty == "advanced" {
		label = "Advanced"
	}
	item["difficulty_label"] = label
	audio, _ := item["audio_path"].(string)
	item["has_audio"] = audio != ""
	return item, nil
}
func (h *LearningHandler) mediaList(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	query := `SELECT COALESCE(json_agg(row_to_json(m) ORDER BY m.next_listen_at,m.id DESC),'[]') FROM media_items m WHERE user_id=$1`
	if strings.HasSuffix(r.URL.Path, "/due") {
		query += ` AND is_active=true AND (next_listen_at IS NULL OR next_listen_at<=now())`
	}
	var raw json.RawMessage
	if e := h.Pool.QueryRow(r.Context(), query, u.ID).Scan(&raw); e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		jsonResponse(w, 200, map[string]any{"data": raw})
		return
	}
	h.Auth.Page(w, r, "Media/Index", map[string]any{"items": raw}, &u)
}
func (h *LearningHandler) mediaShow(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	item, e := h.mediaItem(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	if api(r) {
		jsonResponse(w, 200, map[string]any{"data": item})
		return
	}
	page := "Media/Show"
	if strings.HasSuffix(r.URL.Path, "/transcript/edit") {
		page = "Media/EditTranscript"
		if item["transcript_segments"] == nil {
			if text, _ := item["transcript"].(string); text != "" {
				item["transcript_segments"] = []listen.Segment{{Start: 0, End: 2, Text: text}}
			} else {
				item["transcript_segments"] = []listen.Segment{}
			}
		}
	}
	options, e := h.sessionOptions(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	h.Auth.Page(w, r, page, map[string]any{"item": item, "sessionOptions": options}, &u)
}
func (h *LearningHandler) mediaPreview(w http.ResponseWriter, r *http.Request) {
	if _, ok := h.Auth.WriteUser(w, r); !ok {
		return
	}
	var in struct {
		URL string `json:"url"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	id := listen.YouTubeID(in.URL)
	if id == "" {
		learningError(w, fmt.Errorf("invalid YouTube URL"))
		return
	}
	preview := youtubePreview(r, id)
	jsonResponse(w, 200, map[string]any{"data": preview})
}
func youtubePreview(r *http.Request, id string) map[string]any {
	watch := "https://www.youtube.com/watch?v=" + id
	result := map[string]any{"title": "YouTube video", "url": watch, "video_id": id, "thumbnail_url": "https://i.ytimg.com/vi/" + id + "/hqdefault.jpg", "author_name": nil}
	req, e := http.NewRequestWithContext(r.Context(), "GET", "https://www.youtube.com/oembed?"+url.Values{"url": {watch}, "format": {"json"}}.Encode(), nil)
	if e != nil {
		return result
	}
	resp, e := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if e != nil {
		return result
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return result
	}
	var data struct {
		Title  string `json:"title"`
		Author string `json:"author_name"`
	}
	if json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&data) == nil {
		if strings.TrimSpace(data.Title) != "" {
			result["title"] = strings.TrimSpace(data.Title)
		}
		result["author_name"] = data.Author
	}
	return result
}

type mediaInput struct {
	Title       string  `json:"title"`
	URL         string  `json:"url"`
	Type        string  `json:"type"`
	Language    string  `json:"language"`
	Frequency   string  `json:"frequency"`
	Difficulty  string  `json:"difficulty"`
	Notes       *string `json:"notes"`
	Transcript  *string `json:"transcript"`
	Active      *bool   `json:"is_active"`
	AutoProcess *bool   `json:"auto_process"`
}

func validMedia(in *mediaInput) error {
	in.Title = strings.TrimSpace(in.Title)
	if in.Language == "" {
		in.Language = "en"
	}
	if in.Frequency == "" {
		in.Frequency = "weekly"
	}
	if in.Difficulty == "" {
		in.Difficulty = "intermediate"
	}
	if in.Title == "" || utf8.RuneCountInString(in.Title) > 255 || len(in.URL) > 2048 || len(in.Language) > 10 || in.Type != "youtube" && in.Type != "audio" && in.Type != "mp3" {
		return listen.ErrInvalid
	}
	if in.Frequency != "daily" && in.Frequency != "weekly" && in.Frequency != "monthly" {
		return listen.ErrInvalid
	}
	if in.Difficulty != "beginner" && in.Difficulty != "intermediate" && in.Difficulty != "advanced" {
		return listen.ErrInvalid
	}
	if in.Transcript != nil && len(*in.Transcript) > 500000 {
		return listen.ErrInvalid
	}
	return nil
}
func (h *LearningHandler) mediaCreate(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in mediaInput
	var audioPath string
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		r.Body = http.MaxBytesReader(w, r.Body, 55<<20)
		if e := r.ParseMultipartForm(2 << 20); e != nil {
			learningError(w, fmt.Errorf("invalid audio upload (maximum 50MB)"))
			return
		}
		defer r.MultipartForm.RemoveAll()
		in = mediaInput{Title: r.FormValue("title"), Type: r.FormValue("type"), URL: r.FormValue("url"), Language: r.FormValue("language"), Frequency: r.FormValue("frequency"), Difficulty: r.FormValue("difficulty")}
		if value := r.FormValue("transcript"); value != "" {
			in.Transcript = &value
		}
		if value := r.FormValue("notes"); value != "" {
			in.Notes = &value
		}
		if r.FormValue("auto_process") == "false" || r.FormValue("auto_process") == "0" {
			value := false
			in.AutoProcess = &value
		}
		if in.Type != "youtube" {
			file, header, e := r.FormFile("audio")
			if e != nil {
				learningError(w, fmt.Errorf("audio file required"))
				return
			}
			defer file.Close()
			if header.Size > 50<<20 {
				learningError(w, fmt.Errorf("audio exceeds 50MB"))
				return
			}
			prefix := make([]byte, 512)
			n, _ := file.Read(prefix)
			mime := http.DetectContentType(prefix[:n])
			allowed := strings.HasPrefix(mime, "audio/") || mime == "video/mp4"
			if !allowed {
				learningError(w, fmt.Errorf("unsupported audio format"))
				return
			}
			if _, e = file.Seek(0, 0); e != nil {
				learningError(w, e)
				return
			}
			random, e := authpg.Random()
			if e != nil {
				learningError(w, e)
				return
			}
			audioPath = fmt.Sprintf("audio/%d/%s", u.ID, random)
			path := filepath.Join(h.MediaRoot, audioPath)
			if e = os.MkdirAll(filepath.Dir(path), 0700); e != nil {
				learningError(w, e)
				return
			}
			out, e := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
			if e != nil {
				learningError(w, e)
				return
			}
			_, e = io.Copy(out, io.LimitReader(file, 50<<20+1))
			closeErr := out.Close()
			if e != nil || closeErr != nil {
				_ = os.Remove(path)
				learningError(w, fmt.Errorf("could not store audio"))
				return
			}
			in.URL = "file://" + filepath.Base(header.Filename)
		}
	} else {
		if decode(w, r, &in) != nil {
			learningError(w, listen.ErrInvalid)
			return
		}
	}
	if r.URL.Path == "/home/media/youtube" {
		in.Type = "youtube"
		if in.Title == "" {
			in.Title = "YouTube video"
		}
	}
	cleanup := true
	defer func() {
		if cleanup && audioPath != "" {
			_ = os.Remove(filepath.Join(h.MediaRoot, audioPath))
		}
	}()
	if e := validMedia(&in); e != nil {
		learningError(w, e)
		return
	}
	sourceID := listen.YouTubeID(in.URL)
	if in.Type == "youtube" {
		if sourceID == "" {
			learningError(w, fmt.Errorf("invalid YouTube URL"))
			return
		}
		in.URL = "https://www.youtube.com/watch?v=" + sourceID
	} else if audioPath == "" {
		parsed, e := url.Parse(in.URL)
		if e != nil || parsed.User != nil || parsed.Host == "" || parsed.Scheme != "https" && parsed.Scheme != "http" {
			learningError(w, fmt.Errorf("provide an audio upload or HTTP audio URL"))
			return
		}
	}
	active := in.Active == nil || *in.Active
	next := listen.NextListen(time.Now().UTC(), in.Frequency, false)
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	var id int64
	e = tx.QueryRow(r.Context(), `INSERT INTO media_items(user_id,title,url,type,source_id,audio_path,audio_disk,language,frequency,difficulty,notes,transcript,is_active,next_listen_at,analysis_status,question_bank_status,question_bank_count,created_at,updated_at) VALUES($1,$2,$3,$4,NULLIF($5,''),NULLIF($6,''),'local',$7,$8,$9,$10,$11,$12,$13,'pending','pending',0,now(),now()) RETURNING id`, u.ID, in.Title, in.URL, in.Type, sourceID, audioPath, in.Language, in.Frequency, in.Difficulty, in.Notes, in.Transcript, active, next).Scan(&id)
	if e != nil {
		learningError(w, e)
		return
	}
	if in.AutoProcess == nil || *in.AutoProcess {
		_, e = tx.Exec(r.Context(), `INSERT INTO go_media_jobs(media_item_id) VALUES($1) ON CONFLICT DO NOTHING`, id)
		if e != nil {
			learningError(w, e)
			return
		}
	}
	if e = tx.Commit(r.Context()); e != nil {
		learningError(w, e)
		return
	}
	cleanup = false
	if api(r) {
		item, e := h.mediaItem(r, u.ID, id)
		if e != nil {
			learningError(w, e)
			return
		}
		jsonResponse(w, 201, map[string]any{"data": item, "message": "Media saved."})
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/home/media/%d", id), 303)
}
func (h *LearningHandler) mediaTranscript(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	id := paramID(r)
	if _, e := h.mediaItem(r, u.ID, id); e != nil {
		learningError(w, e)
		return
	}
	var in struct {
		Segments   *[]listen.Segment `json:"segments"`
		Transcript *string           `json:"transcript"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	var segments []listen.Segment
	var text *string
	if in.Segments != nil {
		normalized, joined, e := listen.NormalizeSegments(*in.Segments)
		if e != nil {
			learningError(w, e)
			return
		}
		segments = normalized
		if joined != "" {
			text = &joined
		}
	} else {
		text = in.Transcript
		if text != nil && len(*text) > 500000 {
			learningError(w, listen.ErrInvalid)
			return
		}
	}
	raw, e := json.Marshal(segments)
	if e != nil {
		learningError(w, e)
		return
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	_, e = tx.Exec(r.Context(), `UPDATE media_items SET transcript=$3,transcript_segments=$4,analysis_status='pending',question_bank_status='pending',updated_at=now() WHERE user_id=$1 AND id=$2`, u.ID, id, text, raw)
	if e == nil {
		_, e = tx.Exec(r.Context(), `INSERT INTO go_media_jobs(media_item_id) VALUES($1) ON CONFLICT(media_item_id) DO UPDATE SET available_at=now(),attempts=0`, id)
	}
	if e == nil {
		e = tx.Commit(r.Context())
	}
	if e != nil {
		learningError(w, e)
		return
	}
	if api(r) || r.Header.Get("X-Inertia") == "" {
		item, e := h.mediaItem(r, u.ID, id)
		if e != nil {
			learningError(w, e)
			return
		}
		jsonResponse(w, 200, map[string]any{"data": item, "message": "Transcript saved."})
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/home/media/%d", id), 303)
}
func (h *LearningHandler) mediaDelete(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	tag, e := h.Pool.Exec(r.Context(), `DELETE FROM media_items WHERE user_id=$1 AND id=$2`, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	if tag.RowsAffected() == 0 {
		learningError(w, pgx.ErrNoRows)
		return
	}
	if api(r) {
		jsonResponse(w, 200, map[string]string{"message": "Media removed."})
		return
	}
	http.Redirect(w, r, "/home/media", 303)
}
func (h *LearningHandler) mediaAudio(w http.ResponseWriter, r *http.Request) {
	u, ok := h.readUser(w, r)
	if !ok {
		return
	}
	item, e := h.mediaItem(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	relative, _ := item["audio_path"].(string)
	if relative == "" {
		http.NotFound(w, r)
		return
	}
	disk, _ := item["audio_disk"].(string)
	if disk != "" && disk != "local" {
		jsonResponse(w, 503, map[string]string{"message": "This audio storage disk is not mounted."})
		return
	}
	root, e := filepath.EvalSymlinks(h.MediaRoot)
	if e != nil {
		http.NotFound(w, r)
		return
	}
	path, e := filepath.EvalSymlinks(filepath.Join(root, relative))
	if e != nil {
		http.NotFound(w, r)
		return
	}
	rel, e := filepath.Rel(root, path)
	if e != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		http.NotFound(w, r)
		return
	}
	file, e := os.Open(path)
	if e != nil {
		http.NotFound(w, r)
		return
	}
	defer file.Close()
	info, e := file.Stat()
	if e != nil || !info.Mode().IsRegular() {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Cache-Control", "private, no-store")
	http.ServeContent(w, r, filepath.Base(path), info.ModTime(), file)
}
func (h *LearningHandler) mediaListened(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in struct {
		Snooze bool `json:"snooze_one_hour"`
	}
	if decode(w, r, &in) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	tx, e := h.Pool.Begin(r.Context())
	if e != nil {
		learningError(w, e)
		return
	}
	defer tx.Rollback(r.Context())
	var frequency string
	e = tx.QueryRow(r.Context(), `SELECT frequency FROM media_items WHERE user_id=$1 AND id=$2 FOR UPDATE`, u.ID, paramID(r)).Scan(&frequency)
	if e == nil {
		_, e = tx.Exec(r.Context(), `INSERT INTO listen_logs(user_id,media_item_id,listened_at,created_at,updated_at) VALUES($1,$2,now(),now(),now())`, u.ID, paramID(r))
	}
	if e == nil {
		_, e = tx.Exec(r.Context(), `UPDATE media_items SET next_listen_at=$3,updated_at=now() WHERE user_id=$1 AND id=$2`, u.ID, paramID(r), listen.NextListen(time.Now().UTC(), frequency, in.Snooze))
	}
	if e == nil {
		e = tx.Commit(r.Context())
	}
	if e != nil {
		learningError(w, e)
		return
	}
	item, e := h.mediaItem(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": item})
}
func (h *LearningHandler) mediaUpdate(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	item, e := h.mediaItem(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	raw, _ := json.Marshal(item)
	var in mediaInput
	if json.Unmarshal(raw, &in) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	var updates map[string]json.RawMessage
	if decode(w, r, &updates) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	encoded, _ := json.Marshal(updates)
	if json.Unmarshal(encoded, &in) != nil {
		learningError(w, listen.ErrInvalid)
		return
	}
	if e = validMedia(&in); e != nil {
		learningError(w, e)
		return
	}
	source := listen.YouTubeID(in.URL)
	if in.Type == "youtube" && source == "" {
		learningError(w, listen.ErrInvalid)
		return
	}
	active := in.Active == nil || *in.Active
	_, e = h.Pool.Exec(r.Context(), `UPDATE media_items SET title=$3,url=$4,type=$5,frequency=$6,difficulty=$7,notes=$8,is_active=$9,source_id=NULLIF($10,''),next_listen_at=CASE WHEN frequency<>$6 THEN $11 ELSE next_listen_at END,updated_at=now() WHERE user_id=$1 AND id=$2`, u.ID, paramID(r), in.Title, in.URL, in.Type, in.Frequency, in.Difficulty, in.Notes, active, source, listen.NextListen(time.Now().UTC(), in.Frequency, false))
	if e != nil {
		learningError(w, e)
		return
	}
	item, e = h.mediaItem(r, u.ID, paramID(r))
	if e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": item})
}
