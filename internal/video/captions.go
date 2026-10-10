package video

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"localsy/internal/util"
)

// CaptionReport is what the pipeline is allowed to know at this point: what the video has, what was
// chosen, whether it is any good, and what would happen next. Nothing here downloads audio or
// transcribes — the caller stops at this report (see the guard at the end of InterrogateCaptions).
type CaptionReport struct {
	URL              string    `json:"url"`
	Title            string    `json:"title"`
	VideoID          string    `json:"video_id"`
	Duration         float64   `json:"duration_seconds"`
	DeclaredLanguage string    `json:"declared_language"`
	Language         string    `json:"language"`
	LanguageSource   string    `json:"language_source"`
	HumanTracks      []string  `json:"human_tracks"`
	AutoTrackCount   int       `json:"auto_track_count"`
	ChosenTrack      string    `json:"chosen_track"`
	ChosenKind       string    `json:"chosen_kind"`
	Outcome          string    `json:"outcome"`
	CoveragePercent  float64   `json:"coverage_percent"`
	WordsPerSecond   float64   `json:"words_per_second"`
	Cues             int       `json:"cues"`
	Words            int       `json:"words"`
	Reliable         bool      `json:"reliable"`
	ReliableReason   string    `json:"reliable_reason"`
	NextPath         string    `json:"next_path"`
	Notice           string    `json:"notice"`
	Captions         []Segment `json:"captions,omitempty"`
	TrackFile        string    `json:"track_file,omitempty"`
	// Which client supplied the track: "browser" (the page's own player, the only one YouTube still serves
	// the subtitle bytes to) or "downloader". Reported, never assumed.
	Source string `json:"caption_source,omitempty"`
}

// BrowserCaptions is one caption track fetched by the page's own player, handed over exactly as it arrived.
type BrowserCaptions struct {
	Kind     string // "manual" or "auto" (the vocabulary the report and the web layer use)
	Language string
	Track    string // the player's own track id/name, for the log
	JSON     string // the json3 payload
}

// BrowserCaptionSource asks the browser for the chosen track. A nil source means the downloader is the only
// route — the behaviour before the browser could supply captions at all.
type BrowserCaptionSource func(ctx context.Context, url string) (BrowserCaptions, error)

// The languages the multilingual model handles (its model card). English has its own model, so the
// multilingual one covers the other 24.
var multilingualLanguages = map[string]bool{
	"bg": true, "cs": true, "da": true, "de": true, "el": true, "en": true, "es": true, "et": true,
	"fi": true, "fr": true, "hr": true, "hu": true, "it": true, "lt": true, "lv": true, "mt": true,
	"nl": true, "pl": true, "pt": true, "ro": true, "ru": true, "sk": true, "sl": true, "sv": true,
	"uk": true,
}

// metadataFields asks yt-dlp for exactly what this step needs. The caption tables are deliberately not
// included: YouTube's automatic-caption table for one ordinary nine-minute video is 11.1 MB (hundreds of
// signed format URLs), which is larger than any sensible output window and useless to us — we only need to
// know which languages exist, and the caption listing says that in a few kilobytes.
const metadataFields = "%(id)s	%(title)s	%(duration)s	%(language)s	%(subtitles)j"

type videoMetadata struct {
	id       string
	title    string
	duration float64
	language string
	human    map[string]json.RawMessage
}

// readMetadata asks the downloader the chain names. The launcher's own copy is not on PATH, so a bare
// name here would reach a different binary or none at all.
func readMetadata(ctx context.Context, tools Toolchain, url string) (videoMetadata, error) {
	out := RunChild(ctx, tools.ytDlp(), "--skip-download", "--no-playlist", "--no-warnings", "--print", metadataFields, url)
	if out.Failed() {
		return videoMetadata{}, fmt.Errorf("could not read the video: %s", out.Reason())
	}
	if out.Truncated {
		return videoMetadata{}, errors.New("the video's details were too big to read")
	}

	return parseMetadataLine(out.Stdout)
}

func parseMetadataLine(raw string) (videoMetadata, error) {
	var meta videoMetadata

	line := strings.TrimSpace(raw)
	if line == "" {
		return meta, errors.New("yt-dlp said nothing about this video")
	}
	if idx := strings.IndexByte(line, '\n'); idx >= 0 {
		line = line[:idx]
	}

	parts := strings.SplitN(line, "	", 5)
	if len(parts) < 5 {
		return meta, errors.New("yt-dlp's answer was not in the shape asked for")
	}

	meta.id = naToEmpty(parts[0])
	meta.title = naToEmpty(parts[1])
	meta.duration = parseSeconds(naToEmpty(parts[2]))
	meta.language = baseLanguage(naToEmpty(parts[3]))
	meta.human = decodeTrackMap(parts[4])

	return meta, nil
}

func naToEmpty(value string) string {
	value = strings.TrimSpace(value)
	if value == "NA" || value == "null" || value == "None" {
		return ""
	}

	return value
}

func parseSeconds(value string) float64 {
	seconds, err := strconv.ParseFloat(strings.TrimSpace(value), 64)
	if err != nil || seconds < 0 {
		return 0
	}

	return seconds
}

// decodeTrackMap reads one of the two caption tables. yt-dlp prints "null" or "NA" when there is none,
// and either must read as "no tracks" rather than as a parse failure.
func decodeTrackMap(raw string) map[string]json.RawMessage {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" || trimmed == "NA" || trimmed == "null" {
		return nil
	}

	var tracks map[string]json.RawMessage
	if err := json.Unmarshal([]byte(trimmed), &tracks); err != nil {
		return nil
	}

	return tracks
}

// DefaultMaxDurationSeconds is the cap unless the setting says otherwise: 1h 59m.
const DefaultMaxDurationSeconds = 7140.0

// MaxDurationSeconds reads the cap from the launcher's .env, falling back to the default. The setting is
// read here rather than passed in from the wiring so a missing or unreadable file can never mean "no cap".
func MaxDurationSeconds(workDir string) float64 {
	data, err := os.ReadFile(filepath.Join(workDir, ".env"))
	if err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			line = strings.TrimSpace(line)
			if !strings.HasPrefix(line, "VIDEO_MAX_DURATION_SECONDS") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) != 2 {
				continue
			}
			if value, convErr := strconv.ParseFloat(strings.Trim(strings.TrimSpace(parts[1]), `"'`), 64); convErr == nil && value > 0 {
				return value
			}
		}
	}

	return DefaultMaxDurationSeconds
}

// InterrogateCaptions is Stage A, and it stops here: it establishes the video's language, which caption
// track (if any) is worth taking, and whether that track is any good — then returns. No audio is
// downloaded and nothing is transcribed at this point.
//
// A video longer than maxDurationSeconds is refused before anything else happens. The downloader the
// video is read with comes from the caller's chain, never from a name: the launcher's own copy is not
// on PATH, so reading the video must be handed the path the chain resolved.
// InterrogateCaptions reads the video's metadata, its caption listing and the chosen track. A nil browser
// source leaves the downloader as the only caption route; see InterrogateCaptionsWithBrowser.
func InterrogateCaptions(ctx context.Context, tools Toolchain, ws *Workspace, url string, maxDurationSeconds float64) (CaptionReport, error) {
	return InterrogateCaptionsWithBrowser(ctx, tools, ws, url, maxDurationSeconds, nil)
}

// InterrogateCaptionsWithBrowser is InterrogateCaptions with the page's own player available as a caption
// source. It is preferred over the downloader because it is the only client YouTube still serves the
// subtitle bytes to: the same track URL fetched directly comes back 200 with an empty body (measured
// 2026-10-09), which is why a watch could report 0 caption segments while the track plainly existed.
func InterrogateCaptionsWithBrowser(ctx context.Context, tools Toolchain, ws *Workspace, url string, maxDurationSeconds float64, browser BrowserCaptionSource) (CaptionReport, error) {
	report := CaptionReport{URL: url}

	meta, err := readMetadata(ctx, tools, url)
	if err != nil {
		return report, err
	}

	report.VideoID = meta.id
	report.Title = meta.title
	report.Duration = meta.duration
	report.DeclaredLanguage = meta.language
	report.HumanTracks = captionKeys(meta.human)

	if maxDurationSeconds > 0 && overDurationCap(report.Duration, maxDurationSeconds) {
		report.Outcome = "video_too_long"
		report.NextPath = "refused"
		report.Language = meta.language
		report.Notice = fmt.Sprintf("This video is %s long, and I only take on videos up to %s.",
			humanDuration(report.Duration), humanDuration(maxDurationSeconds))

		return report, nil
	}

	// The listing is a few kilobytes and carries both the video's own language (the first unnamed entry of
	// the automatic table) and the languages that exist, which is all the track choice needs.
	listing := RunChild(ctx, tools.ytDlp(), "--list-subs", "--skip-download", "--no-warnings", url)
	captions := parseCaptionListing(listing.Stdout)

	report.Language, report.LanguageSource = establishedLanguage(meta.language, captions.original)
	report.AutoTrackCount = len(captions.languages)

	chosen, kind, reason := chooseTrack(report.Language, report.HumanTracks, captions.languages)
	report.ChosenTrack, report.ChosenKind = chosen, kind
	report.ReliableReason = reason

	if chosen == "" {
		report.Outcome = "no_usable_captions"
		report.NextPath = nextPath(report.Language)
		report.Notice = noCaptionsNotice(report.Language)

		return report, nil
	}

	// The browser's own player first: it is the only client YouTube still serves the subtitle bytes to (the
	// same track URL fetched directly comes back empty), so it is the route that actually produces captions.
	// The downloader stays as the fallback for when the browser route is unavailable.
	var cues []cue
	if browser != nil {
		handed, browserErr := browser(ctx, url)
		if browserErr != nil {
			util.LogPrint("[-] browser captions unavailable: %v\n", browserErr)
		} else if strings.TrimSpace(handed.JSON) != "" {
			parsed, parseErr := parseJSON3(handed.JSON)
			if parseErr != nil {
				util.LogPrint("[-] browser caption track unreadable: %v\n", parseErr)
			} else {
				cues = parsed
				report.Source = "browser"
				if handed.Kind != "" {
					report.ChosenKind = handed.Kind
				}
				if handed.Track != "" {
					report.ChosenTrack = handed.Track
				}
			}
		}
	}

	if cues == nil {
		fetched, err := fetchTrack(ctx, tools, ws, url, chosen)
		if err != nil {
			// Captions exist and were chosen — the fetch failed. Saying "no captions" here would be a lie of
			// the same kind as calling a web page the video's content.
			report.Outcome = "caption_fetch_failed"
			report.ReliableReason = err.Error()
			report.NextPath = nextPath(report.Language)
			report.Notice = captionFetchFailedNotice

			return report, nil
		}
		report.TrackFile = filepath.Base(fetched)
		cues = parseCues(readFile(fetched))
		report.Source = "downloader"
	}

	spoken := unroll(cues)
	report.Captions = cueSegments(spoken)
	report.Cues = len(cues)
	report.Words = countWords(spoken)
	report.CoveragePercent, report.WordsPerSecond = metrics(spoken, report.Duration)
	report.Reliable, report.ReliableReason = reliability(report.CoveragePercent, report.WordsPerSecond, report.Duration)

	report.Outcome = "captions"
	report.Notice = "This video's own captions are complete, so I would take them and transcribe the audio as well."
	if !report.Reliable {
		report.Outcome = "captions_unreliable"
		report.Notice = "This video's captions are incomplete, so I would rely on transcribing the audio."
	}
	report.NextPath = nextPath(report.Language)

	// The stop. Everything past this point — downloading audio, transcribing, merging — is a separate
	// step that has not been authorised yet, and the caller is not given any way to continue from here.
	return report, nil
}

// establishedLanguage answers "what language is this video in", in order of trust: what the video
// declares, then the first unnamed entry of the caption listing (the track every translation is made
// from), then nothing — never a guess, because a guess is how a German translation of an English video
// gets taken for the original.
func establishedLanguage(declared, fromListing string) (string, string) {
	if declared != "" {
		return declared, "declared by the video"
	}
	if fromListing != "" {
		return fromListing, "first entry of the caption listing, which is what the translations translate from"
	}

	return "", "could not be established"
}

type captionListing struct {
	original  string
	languages []string
}

// parseCaptionListing reads yt-dlp's `--list-subs` output. Two things come out of it:
//
//   - the video's own language: the first row of the automatic table with no name (a translation carries
//     one — "Afar from English" — and offers several formats);
//   - every language that exists, so a track in the video's language can be found. Keys are normalised,
//     because a track is not always the plain code: "de-DE" is German, "ab-en" is Abkhazian.
func parseCaptionListing(listing string) captionListing {
	var found captionListing
	seen := map[string]bool{}
	inAutomatic := false

	for _, raw := range strings.Split(listing, "\n") {
		line := strings.TrimRight(raw, "\r")
		switch {
		case strings.Contains(line, "Available automatic captions"):
			inAutomatic = true
			continue
		case strings.Contains(line, "Available subtitles"), strings.Contains(line, "Available automatic captions") == false && strings.Contains(line, "[info]"):
			inAutomatic = false
			continue
		}
		if !inAutomatic {
			continue
		}

		fields := strings.Fields(line)
		if len(fields) == 0 || fields[0] == "Language" {
			continue
		}

		if code := baseLanguage(fields[0]); code != "" && !seen[code] {
			seen[code] = true
			found.languages = append(found.languages, code)
		}
		if found.original == "" && len(fields) == 2 {
			found.original = baseLanguage(fields[0])
		}
	}

	return found
}

func baseLanguage(code string) string {
	code = strings.ToLower(strings.TrimSpace(code))
	if code == "" {
		return ""
	}
	if idx := strings.IndexByte(code, '-'); idx > 0 {
		code = code[:idx]
	}
	if len(code) != 2 {
		return ""
	}

	return code
}

// chooseTrack takes the video's own language, a human track before an automatic one, and never a
// translation. Track keys are not always the plain code — YouTube hands back "de-DE-wpFMpG569ZU" for a
// German human track — so the match is by language prefix, and an exact match wins when there is one.
func chooseTrack(language string, human, auto []string) (string, string, string) {
	if language == "" {
		return "", "", "the video's language could not be established, so no track can be trusted as the original"
	}

	if key := bestMatch(language, human); key != "" {
		return key, "manual", "manual track in the video's language"
	}
	if key := bestMatch(language, auto); key != "" {
		return key, "auto", "automatic track in the video's language"
	}

	return "", "", "no track in the video's language"
}

func bestMatch(language string, keys []string) string {
	type ranked struct {
		rank int
		key  string
	}
	var found []ranked
	for _, key := range keys {
		low := strings.ToLower(key)
		switch {
		case low == language:
			found = append(found, ranked{0, key})
		case strings.HasPrefix(low, language+"-"):
			found = append(found, ranked{1, key})
		case strings.HasPrefix(low, language):
			found = append(found, ranked{2, key})
		}
	}
	if found == nil {
		return ""
	}
	sort.Slice(found, func(i, j int) bool {
		if found[i].rank != found[j].rank {
			return found[i].rank < found[j].rank
		}

		return found[i].key < found[j].key
	})

	return found[0].key
}

// nextPath says what would run next, so the user is told what the back end can do for them.
func nextPath(language string) string {
	if language == "en" {
		return "transcribe_with_english_model"
	}
	if multilingualLanguages[language] {
		return "transcribe_with_multilingual_model"
	}
	if language == "" {
		return "transcribe_with_multilingual_model"
	}

	return "captions_only_language_unsupported"
}

func fetchTrack(ctx context.Context, tools Toolchain, ws *Workspace, url, lang string) (string, error) {
	out, err := ws.File("captions.vtt")
	if err != nil {
		return "", err
	}
	pattern := strings.TrimSuffix(out, ".vtt") + ".%(ext)s"

	fetch := RunChild(ctx, tools.ytDlp(), "--skip-download", "--no-playlist", "--no-warnings",
		"--write-subs", "--write-auto-subs", "--sub-langs", lang, "--sub-format", "vtt",
		"-o", pattern, url)
	if fetch.Failed() {
		return "", errors.New("the caption track could not be downloaded: " + fetch.Reason())
	}

	matches, _ := filepath.Glob(strings.TrimSuffix(out, ".vtt") + ".*")
	for _, match := range matches {
		if strings.HasSuffix(match, ".vtt") {
			return match, nil
		}
	}

	return "", errors.New("the caption track was not written")
}

type cue struct {
	start float64
	end   float64
	text  string
}

var (
	cueTiming  = regexp.MustCompile(`(\d+):(\d{2}):(\d{2})[.,](\d{1,3})\s*-->\s*(\d+):(\d{2}):(\d{2})[.,](\d{1,3})`)
	cueSetting = regexp.MustCompile(`\b(align|position|line|size|region|vertical|timestamp|voice|priority):\S*`)
	vttTag     = regexp.MustCompile(`<[^>]+>`)
)

// parseCues reads a VTT. Cue settings ride on the timestamp line ("align:start position:0%") and read as
// speech if they are not stripped — they inflate every word count they touch.
func parseCues(content string) []cue {
	var cues []cue
	for _, block := range regexp.MustCompile(`\r?\n\s*\r?\n`).Split(content, -1) {
		m := cueTiming.FindStringSubmatch(block)
		if m == nil {
			continue
		}
		end := cueTiming.FindStringIndex(block)
		body := block[end[1]:]
		body = cueSetting.ReplaceAllString(body, " ")
		body = vttTag.ReplaceAllString(body, "")
		body = strings.NewReplacer("&nbsp;", " ", "&amp;", "&", "&gt;", ">", "&lt;", "<").Replace(body)
		body = strings.Join(strings.Fields(body), " ")
		if body == "" {
			continue
		}
		cues = append(cues, cue{
			start: clockToSeconds(m[1], m[2], m[3], m[4]),
			end:   clockToSeconds(m[5], m[6], m[7], m[8]),
			text:  body,
		})
	}

	return cues
}

// unroll drops the rolled-over prefix of automatic captions, where each cue repeats what the previous
// one already said plus a few new words.
func unroll(cues []cue) []cue {
	var out []cue
	previous := ""
	for _, c := range cues {
		words := c.text
		if previous != "" && strings.HasPrefix(c.text, previous) {
			words = strings.TrimSpace(strings.TrimPrefix(c.text, previous))
		}
		if words != "" {
			out = append(out, cue{start: c.start, end: c.end, text: words})
		}
		previous = c.text
	}

	return out
}

func countWords(cues []cue) int {
	total := 0
	for _, c := range cues {
		total += len(strings.Fields(c.text))
	}

	return total
}

// metrics reports how much of the video the track covers and how dense it is. A silent stretch is not
// counted against it: the audio is transcribed anyway, so a gap in the captions is filled by that.
func metrics(cues []cue, duration float64) (coveragePercent, wordsPerSecond float64) {
	if duration <= 0 || len(cues) == 0 {
		return 0, 0
	}
	last := 0.0
	for _, c := range cues {
		if c.end > last {
			last = c.end
		}
	}

	return last / duration * 100, float64(countWords(cues)) / duration
}

func reliability(coveragePercent, wordsPerSecond, duration float64) (bool, string) {
	if duration <= 0 {
		return false, "the video has no usable duration"
	}
	var why []string
	if coveragePercent < 90 {
		why = append(why, fmt.Sprintf("stops at %.0f%% of the video", coveragePercent))
	}
	if wordsPerSecond < 1.5 {
		why = append(why, fmt.Sprintf("thin, %.2f words a second", wordsPerSecond))
	}
	if len(why) > 0 {
		return false, strings.Join(why, " and ")
	}

	return true, "reaches the end of the video and is not thin"
}

// captionFetchFailedNotice is for captions that exist and were chosen but could not be downloaded — a rate
// limit, a dropped connection. It is deliberately not the no-captions wording: the two are different states
// and the user is owed the difference.
const captionFetchFailedNotice = "I found captions for this video but could not fetch them just now, so I would transcribe the audio instead."

// noCaptionsNotice is what the user hears when no caption track can be trusted — and, when the language
// is one neither transcription model handles, that is the end of the road for this video.
func noCaptionsNotice(language string) string {
	switch {
	case language == "":
		return "I could not tell what language this video is in, and it has no captions I can use, so I would transcribe the audio with the multilingual model."
	case multilingualLanguages[language]:
		return "This video has no captions I can use, so I would transcribe the audio."
	default:
		return fmt.Sprintf("This video is in %s, which neither transcription model handles, and it has no captions I can use — so I cannot watch this particular video, though I can watch others.", languageName(language))
	}
}

// languageName is the plain name where we have one; the code is better than nothing rather than a blank.
func languageName(code string) string {
	if name, ok := languageNames[code]; ok {
		return name
	}

	return code
}

// languageNames covers the 25 the models handle plus the languages people most often actually hit, so the
// message can name the language instead of showing a bare code. Support is decided by
// multilingualLanguages; this map is only for reading.
var languageNames = map[string]string{
	"bg": "Bulgarian", "cs": "Czech", "da": "Danish", "de": "German", "el": "Greek", "en": "English",
	"es": "Spanish", "et": "Estonian", "fi": "Finnish", "fr": "French", "hr": "Croatian", "hu": "Hungarian",
	"it": "Italian", "lt": "Lithuanian", "lv": "Latvian", "mt": "Maltese", "nl": "Dutch", "pl": "Polish",
	"pt": "Portuguese", "ro": "Romanian", "ru": "Russian", "sk": "Slovak", "sl": "Slovenian", "sv": "Swedish",
	"uk": "Ukrainian",
	"ar": "Arabic", "bn": "Bengali", "ca": "Catalan", "fa": "Persian", "he": "Hebrew", "hi": "Hindi",
	"id": "Indonesian", "is": "Icelandic", "ja": "Japanese", "ka": "Georgian", "kk": "Kazakh", "ko": "Korean",
	"mk": "Macedonian", "ms": "Malay", "no": "Norwegian", "sq": "Albanian", "sr": "Serbian", "ta": "Tamil",
	"th": "Thai", "tr": "Turkish", "ur": "Urdu", "uz": "Uzbek", "vi": "Vietnamese", "zh": "Chinese",
}

// overDurationCap is the refusal rule: longer than the setting means the watch is not taken on. A cap of
// zero or less means no cap, and an unknown duration is never grounds for refusing.
func overDurationCap(durationSeconds, capSeconds float64) bool {
	return capSeconds > 0 && durationSeconds > capSeconds
}

// humanDuration speaks the way a person reads a length: "1h 59m", "4h 12m", "19m".
func humanDuration(seconds float64) string {
	total := int(seconds + 0.5)
	hours := total / 3600
	minutes := (total % 3600) / 60
	if hours > 0 {
		return fmt.Sprintf("%dh %02dm", hours, minutes)
	}

	return fmt.Sprintf("%dm", minutes)
}

func clockToSeconds(h, m, s, ms string) float64 {
	hh, _ := strconv.Atoi(h)
	mm, _ := strconv.Atoi(m)
	ss, _ := strconv.Atoi(s)
	milli, _ := strconv.Atoi(ms)

	return float64(hh*3600+mm*60+ss) + float64(milli)/1000
}

func readFile(path string) string {
	data, err := os.ReadFile(path)
	if err != nil {
		return ""
	}

	return string(data)
}

func sortedKeys[T any](m map[string]T) []string {
	keys := make([]string, 0, len(m))
	for key := range m {
		keys = append(keys, key)
	}
	sort.Strings(keys)

	return keys
}

// captionKeys drops the entries that are not captions: a live stream lists its chat as a "subtitle",
// which would otherwise read as a human track on a video that has none.
func captionKeys(m map[string]json.RawMessage) []string {
	var keys []string
	for _, key := range sortedKeys(m) {
		if strings.Contains(strings.ToLower(key), "live_chat") {
			continue
		}
		keys = append(keys, key)
	}

	return keys
}
