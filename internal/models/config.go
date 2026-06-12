package models

import "encoding/json"

func LoadConfig(data []byte) map[string]Tier {
	var tiers map[string]Tier
	_ = json.Unmarshal(data, &tiers)
	return tiers
}
