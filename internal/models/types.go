package models

type Tier struct {
	Name       string `json:"name"`
	File       string `json:"file"`
	URL        string `json:"url"`
	MMProjFile string `json:"mmproj_file,omitempty"`
	MMProjURL  string `json:"mmproj_url,omitempty"`
}

type GHRelease struct {
	TagName string `json:"tag_name"`
	Assets  []struct {
		Name        string `json:"name"`
		DownloadURL string `json:"browser_download_url"`
	} `json:"assets"`
}
