package video

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
)

// The listings below are real yt-dlp output, captured from the videos named in the test.
const zooListing = `[info] Available automatic captions for jNQXAC9IVRw:
Language   Name                               Formats
en                                            vtt
de                                            vtt
ab-en      Abkhazian from English             vtt, srt, ttml, srv3, srv2, srv1, json3
aa-en      Afar from English                  vtt, srt, ttml, srv3, srv2, srv1, json3
`

const tagesschauListing = `[info] Available automatic captions for B5tg6FepoMs:
Language                  Name                                                                                             Formats
de-DE                                                                                                                      vtt
ab                        Abkhazian                                                                                        vtt, srt, ttml, srv3, srv2, srv1, json3, vtt, srt, ttml, srv3, srv2, srv1, json3
aa                        Afar                                                                                             vtt, srt, ttml, srv3, srv2, srv1, json3, vtt, srt, ttml, srv3, srv2, srv1, json3
`

const manualFirstListing = `[info] Available subtitles for xyz:
Language  Name  Formats
en        English  vtt
[info] Available automatic captions for xyz:
fr        vtt
ab-fr     Abkhazian from French  vtt, srt, ttml
`

func TestTheVideoLanguageComesOffTheListing(t *testing.T) {
	zoo := parseCaptionListing(zooListing)
	if zoo.original != "en" {
		t.Fatalf("zoo listing gave %q, want en — this is the video that declares no language at all", zoo.original)
	}
	if len(zoo.languages) != 4 {
		t.Fatalf("zoo listing lists %d languages, want 4 (en, de and the two translations)", len(zoo.languages))
	}
	if zoo.languages[0] != "en" || zoo.languages[1] != "de" {
		t.Fatalf("languages = %v, want en and de first", zoo.languages)
	}

	tagesschau := parseCaptionListing(tagesschauListing)
	if tagesschau.original != "de" {
		t.Fatalf("tagesschau listing gave %q, want de", tagesschau.original)
	}

	manual := parseCaptionListing(manualFirstListing)
	if manual.original != "fr" {
		t.Fatalf("listing with a human section first gave %q, want fr", manual.original)
	}
	if len(manual.languages) != 2 {
		t.Fatalf("languages = %v, want the two automatic rows only", manual.languages)
	}

	if got := parseCaptionListing("[info] Available automatic captions for x:\nLanguage Name Formats\n"); got.original != "" || len(got.languages) != 0 {
		t.Fatalf("a listing with no tracks should give nothing, got %+v", got)
	}
	if got := parseCaptionListing("no captions here at all"); got.original != "" || len(got.languages) != 0 {
		t.Fatalf("prose should give nothing, got %+v", got)
	}
}

func TestTheLanguageIsDeclaredOrReadOffTheListing(t *testing.T) {
	if language, source := establishedLanguage("de", "en"); language != "de" {
		t.Fatalf("a declared language should win, got %q (%s)", language, source)
	}
	if language, source := establishedLanguage("", "en"); language != "en" || !strings.Contains(source, "listing") {
		t.Fatalf("with nothing declared the listing should answer: %q %q", language, source)
	}
	if language, source := establishedLanguage("", ""); language != "" || !strings.Contains(source, "could not be established") {
		t.Fatalf("with nothing at all it must say so: %q %q", language, source)
	}
}

func TestBaseLanguage(t *testing.T) {
	for in, want := range map[string]string{
		"de-DE":             "de",
		"de-DE-wpFMpG569ZU": "de",
		"EN":                "en",
		"":                  "",
		"english":           "",
		"zh-Hans":           "zh",
	} {
		if got := baseLanguage(in); got != want {
			t.Fatalf("baseLanguage(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestTrackChoiceNeverYieldsATranslation(t *testing.T) {
	// The German human track is keyed with a suffix; an exact match misses it and would take the
	// automatic track instead.
	key, kind, _ := chooseTrack("de", []string{"de-DE-wpFMpG569ZU"}, []string{"aa", "de", "en"})
	if key != "de-DE-wpFMpG569ZU" || kind != "manual" {
		t.Fatalf("chose %q (%s), want the human German track", key, kind)
	}

	// Human before automatic, in the video's own language.
	key, kind, _ = chooseTrack("en", []string{"en", "de"}, []string{"en"})
	if key != "en" || kind != "manual" {
		t.Fatalf("chose %q (%s), want the human English track", key, kind)
	}

	// No human track: the automatic one in the same language.
	key, kind, _ = chooseTrack("en", nil, []string{"aa", "de", "en", "zz"})
	if key != "en" || kind != "auto" {
		t.Fatalf("chose %q (%s), want the automatic English track", key, kind)
	}

	// An exact language code beats a prefixed one.
	key, _, _ = chooseTrack("de", []string{"de-DE", "de"}, nil)
	if key != "de" {
		t.Fatalf("chose %q, want the exact code", key)
	}

	// A human track in another language is a translation, and is not the original.
	key, _, reason := chooseTrack("en", []string{"de"}, []string{})
	if key != "" {
		t.Fatalf("chose %q, which is a translation of the video, not the video", key)
	}
	if !strings.Contains(reason, "no track in the video's language") {
		t.Fatalf("reason = %q", reason)
	}

	// With no established language, nothing may be chosen.
	key, _, reason = chooseTrack("", []string{"en"}, []string{"en"})
	if key != "" || !strings.Contains(reason, "could not be established") {
		t.Fatalf("an unknown language still chose %q (%s)", key, reason)
	}
}

func TestCueSettingsAreNotWords(t *testing.T) {
	vtt := "WEBVTT\n\n00:00:01.600 --> 00:00:04.000 align:start position:0%\nHello wonderful people\n\ntext\n00:00:04.000 --> 00:00:06.000\n<c>this is</c> a test\n"
	cues := parseCues(vtt)
	if len(cues) != 2 {
		t.Fatalf("got %d cues, want 2", len(cues))
	}
	if cues[0].text != "Hello wonderful people" {
		t.Fatalf("text = %q — the cue settings line was counted as speech", cues[0].text)
	}
	if cues[0].start != 1.6 || cues[0].end != 4.0 {
		t.Fatalf("times = %v-%v, want 1.6-4", cues[0].start, cues[0].end)
	}
	if cues[1].text != "this is a test" {
		t.Fatalf("tags were left in: %q", cues[1].text)
	}
	if countWords(cues) != 7 {
		t.Fatalf("words = %d, want 7", countWords(cues))
	}
}

func TestRollingCaptionsAreUnrolled(t *testing.T) {
	cues := []cue{
		{start: 0, end: 1, text: "Hello"},
		{start: 1, end: 2, text: "Hello wonderful"},
		{start: 2, end: 3, text: "Hello wonderful people"},
		{start: 3, end: 4, text: "Something else"},
	}
	unrolled := unroll(cues)
	if len(unrolled) != 4 {
		t.Fatalf("got %d cues, want 4", len(unrolled))
	}
	if got := countWords(unrolled); got != 5 {
		t.Fatalf("words = %d, want 5 (Hello, wonderful, people, Something, else)", got)
	}
}

func TestReliabilityIsCoverageAndThinness(t *testing.T) {
	reliable, reason := reliability(93.7, 5.4, 971)
	if !reliable {
		t.Fatalf("a track that reaches 94%% of the video was called unreliable: %s", reason)
	}

	// A silent stretch is not a fault: the audio is transcribed anyway, so a gap here is not judged.
	// 60 words over 40 seconds is 1.5 a second and the track reaches the end — the 16-second hole
	// between 6s and 22s must not change that.
	gapped := []cue{
		{start: 0, end: 6, text: strings.TrimSpace(strings.Repeat("alpha ", 20))},
		{start: 22, end: 40, text: strings.TrimSpace(strings.Repeat("beta ", 40))},
	}
	coverage, density := metrics(gapped, 40)
	if coverage != 100 {
		t.Fatalf("fixture coverage = %v, want 100", coverage)
	}
	if reliable, reason = reliability(coverage, density, 40); !reliable {
		t.Fatalf("a 16-second instrumental gap made a good track unreliable: %s", reason)
	}

	reliable, reason = reliability(60, 3, 600)
	if reliable || !strings.Contains(reason, "60%") {
		t.Fatalf("a track that stops at 60%% was not caught: %v %q", reliable, reason)
	}

	reliable, reason = reliability(99, 0.9, 600)
	if reliable || !strings.Contains(reason, "thin") {
		t.Fatalf("a thin track was not caught: %v %q", reliable, reason)
	}

	reliable, reason = reliability(0, 0, 0)
	if reliable || !strings.Contains(reason, "duration") {
		t.Fatalf("a video with no duration was not caught: %v %q", reliable, reason)
	}
}

func TestCoverageAndDensityMath(t *testing.T) {
	cues := []cue{{start: 0, end: 90, text: "one two three"}, {start: 90, end: 100, text: "four five"}}
	coverage, density := metrics(cues, 100)
	if coverage != 100 {
		t.Fatalf("coverage = %v, want 100", coverage)
	}
	if density != 0.05 {
		t.Fatalf("density = %v, want 0.05", density)
	}
	if coverage, density = metrics(nil, 100); coverage != 0 || density != 0 {
		t.Fatalf("no cues should be 0/0, got %v/%v", coverage, density)
	}
	if coverage, density = metrics(cues, 0); coverage != 0 || density != 0 {
		t.Fatalf("no duration should be 0/0, got %v/%v", coverage, density)
	}
}

func TestWhatRunsNext(t *testing.T) {
	for language, want := range map[string]string{
		"en": "transcribe_with_english_model",
		"de": "transcribe_with_multilingual_model",
		"uk": "transcribe_with_multilingual_model",
		"ja": "captions_only_language_unsupported",
		"zh": "captions_only_language_unsupported",
		// An unknown language is the multilingual model's case, not a captions-only one.
		"": "transcribe_with_multilingual_model",
	} {
		if got := nextPath(language); got != want {
			t.Fatalf("nextPath(%q) = %q, want %q", language, got, want)
		}
	}
}

func TestSupportedLanguagesAreTheModelsOwnList(t *testing.T) {
	if len(multilingualLanguages) != 25 {
		t.Fatalf("the multilingual model lists %d languages, expected 25", len(multilingualLanguages))
	}
	for _, code := range []string{"bg", "cs", "da", "de", "el", "en", "es", "et", "fi", "fr", "hr", "hu", "it", "lt", "lv", "mt", "nl", "pl", "pt", "ro", "ru", "sk", "sl", "sv", "uk"} {
		if !multilingualLanguages[code] {
			t.Fatalf("%q is missing from the multilingual model's languages", code)
		}
	}
	if multilingualLanguages["ja"] || multilingualLanguages["ar"] {
		t.Fatal("a language the model does not handle was marked as handled")
	}
}

func TestTheVideoLengthCap(t *testing.T) {
	if overDurationCap(7140, 7140) {
		t.Fatal("a video exactly at the cap was refused")
	}
	if !overDurationCap(7140.5, 7140) {
		t.Fatal("a video half a second over the cap was accepted")
	}
	if !overDurationCap(15120, DefaultMaxDurationSeconds) {
		t.Fatal("a four-hour video was accepted under the default cap")
	}
	if overDurationCap(15120, 0) {
		t.Fatal("a cap of zero should mean no cap, not refuse everything")
	}
	if overDurationCap(0, 7140) {
		t.Fatal("a video whose length is unknown was refused")
	}
	if DefaultMaxDurationSeconds != 7140 {
		t.Fatalf("the default cap is %v, want 7140 (1h 59m)", DefaultMaxDurationSeconds)
	}
}

func TestTheCapComesFromTheSettingsFile(t *testing.T) {
	work := t.TempDir()
	if got := MaxDurationSeconds(work); got != DefaultMaxDurationSeconds {
		t.Fatalf("with no settings the cap = %v, want the default", got)
	}

	write(t, filepath.Join(work, ".env"), "VIDEO_MAX_DURATION_SECONDS=3600\n")
	if got := MaxDurationSeconds(work); got != 3600 {
		t.Fatalf("cap = %v, want 3600", got)
	}

	write(t, filepath.Join(work, ".env"), "LOG_LEVEL=info\nVIDEO_MAX_DURATION_SECONDS=\"5400\"\n")
	if got := MaxDurationSeconds(work); got != 5400 {
		t.Fatalf("a quoted value gave %v, want 5400", got)
	}

	write(t, filepath.Join(work, ".env"), "VIDEO_MAX_DURATION_SECONDS=not-a-number\n")
	if got := MaxDurationSeconds(work); got != DefaultMaxDurationSeconds {
		t.Fatalf("a broken value gave %v, want the default rather than no cap", got)
	}

	write(t, filepath.Join(work, ".env"), "VIDEO_MAX_DURATION_SECONDS=0\n")
	if got := MaxDurationSeconds(work); got != DefaultMaxDurationSeconds {
		t.Fatalf("a zero gave %v, want the default rather than no cap", got)
	}
}

func TestLengthsAreSpokenTheWayPeopleReadThem(t *testing.T) {
	for seconds, want := range map[float64]string{
		7140:  "1h 59m",
		15120: "4h 12m",
		1140:  "19m",
		3600:  "1h 00m",
		59:    "0m",
	} {
		if got := humanDuration(seconds); got != want {
			t.Fatalf("humanDuration(%v) = %q, want %q", seconds, got, want)
		}
	}
}

func TestWhatTheUserIsTold(t *testing.T) {
	if got := noCaptionsNotice("de"); !strings.Contains(got, "transcribe the audio") {
		t.Fatalf("a German video with no captions should mean transcribing: %q", got)
	}

	unsupported := noCaptionsNotice("ja")
	if !strings.Contains(unsupported, "Japanese") || !strings.Contains(unsupported, "cannot watch this particular video") {
		t.Fatalf("an unsupported language with no captions should be the honest dead end: %q", unsupported)
	}

	if got := noCaptionsNotice(""); !strings.Contains(got, "could not tell what language") || !strings.Contains(got, "multilingual model") {
		t.Fatalf("an unknown language should say what it did about it: %q", got)
	}

	if languageName("de") != "German" || languageName("uk") != "Ukrainian" {
		t.Fatal("language names are wrong")
	}
	if languageName("xx") != "xx" {
		t.Fatal("an unknown language code should be passed through, not blanked")
	}
}

func TestCaptionsThatExistButCouldNotBeFetchedAreNotCalledMissing(t *testing.T) {
	// Three states must stay distinguishable: no captions at all, captions that are not in the video's
	// language, and captions that were there but could not be downloaded (a rate limit, for one).
	missing := noCaptionsNotice("en")
	if missing == captionFetchFailedNotice {
		t.Fatal("a failed fetch and a missing caption track share one message")
	}
	if strings.Contains(captionFetchFailedNotice, "has no captions") {
		t.Fatalf("the fetch-failed message claims the video has no captions: %q", captionFetchFailedNotice)
	}
	if !strings.Contains(captionFetchFailedNotice, "found captions") {
		t.Fatalf("the fetch-failed message does not say the captions exist: %q", captionFetchFailedNotice)
	}

	unsupported := noCaptionsNotice("ja")
	if unsupported == captionFetchFailedNotice || !strings.Contains(unsupported, "cannot watch this particular video") {
		t.Fatalf("an unsupported language with no captions is a different state again: %q", unsupported)
	}
}

func TestMetadataIsReadFromTheFieldsAskedFor(t *testing.T) {
	line := "jNQXAC9IVRw	Me at the zoo	19	NA	{\"de\":[]}"
	meta, err := parseMetadataLine(line)
	if err != nil {
		t.Fatalf("parseMetadataLine: %v", err)
	}
	if meta.id != "jNQXAC9IVRw" || meta.title != "Me at the zoo" {
		t.Fatalf("id/title = %q/%q", meta.id, meta.title)
	}
	if meta.duration != 19 {
		t.Fatalf("duration = %v, want 19", meta.duration)
	}
	if meta.language != "" {
		t.Fatalf("a declared language of NA should read as none, got %q", meta.language)
	}
	if len(meta.human) != 1 {
		t.Fatalf("human tracks = %d, want 1", len(meta.human))
	}

	// A declared language is normalised, and a video with no track table is not a parse failure.
	if meta, err = parseMetadataLine("x	title	100	de-DE	NA"); err != nil || meta.language != "de" {
		t.Fatalf("declared language handling: %v %q", err, meta.language)
	}
	if len(meta.human) != 0 {
		t.Fatal("NA should read as no tracks")
	}

	// Several videos on one output (a search): the first is the one that was asked for.
	if meta, err = parseMetadataLine("first	title	100		null\nsecond	other	200		null"); err != nil || meta.id != "first" {
		t.Fatalf("multi-result handling: %v %q", err, meta.id)
	}

	for _, bad := range []string{"", "   ", "onlyonefield", "a	b	c"} {
		if _, err := parseMetadataLine(bad); err == nil {
			t.Fatalf("parseMetadataLine(%q) should have failed", bad)
		}
	}
}

func TestAnOverlongVideoNotice(t *testing.T) {
	if !overDurationCap(15120, DefaultMaxDurationSeconds) {
		t.Fatal("four hours should be refused under the default cap")
	}
	notice := "This video is " + humanDuration(15120) + " long, and I only take on videos up to " + humanDuration(DefaultMaxDurationSeconds) + "."
	if notice != "This video is 4h 12m long, and I only take on videos up to 1h 59m." {
		t.Fatalf("notice = %q", notice)
	}
}

func TestATruncatedOutputIsSaidOutLoud(t *testing.T) {
	writer := &tailWriter{max: 10}
	_, _ = writer.Write([]byte("12345678901234567890"))
	if !writer.dropped {
		t.Fatal("a writer that threw bytes away did not record it")
	}
	if writer.String() != "1234567890" && writer.String() != "1234567890"[:10] {
		t.Fatalf("tail = %q", writer.String())
	}
	if len(writer.String()) != 10 {
		t.Fatalf("kept %d bytes, want 10", len(writer.String()))
	}

	small := &tailWriter{max: 10}
	_, _ = small.Write([]byte("abc"))
	if small.dropped {
		t.Fatal("a writer that lost nothing claimed it did")
	}
}

// The captions step — the metadata read, the caption listing and the track download all live here —
// must call the downloader the chain resolved, never a bare name. The launcher's own copy is not on
// PATH: it is a path in the chain, so a name would reach a different binary or none at all. A chain
// naming a path that cannot run therefore has to fail naming that path.
func TestTheCaptionsStepUsesTheDownloaderTheChainResolved(t *testing.T) {
	work := t.TempDir()
	ws, err := NewWorkspace(work, "captions")
	if err != nil {
		t.Fatalf("workspace: %v", err)
	}
	defer ws.Cleanup()

	missing := filepath.Join(work, "nowhere", "yt-dlp.exe")
	if _, err := InterrogateCaptions(context.Background(), Toolchain{YtDlp: missing}, ws, "https://youtu.be/jNQXAC9IVRw", DefaultMaxDurationSeconds); err == nil {
		t.Fatal("a captions step whose downloader cannot run reported success")
	} else if !strings.Contains(err.Error(), missing) {
		t.Fatalf("the captions step did not name the downloader it was given: %v", err)
	}
}
